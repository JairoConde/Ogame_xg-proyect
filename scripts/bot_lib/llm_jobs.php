<?php

declare(strict_types=1);

/**
 * Cola MySQL para peticiones LLM: el bot solo pasa por estados pedido / done;
 * el worker usa procesando / respondido / fallido.
 *
 * Estados:
 *   pedido      — el bot encoló la petición
 *   procesando  — el worker la tomó y está llamando al LLM
 *   respondido  — el LLM devolvió texto; falta que el bot ejecute la acción
 *   done        — el bot aplicó el resultado
 *   fallido     — error (timeout, JSON, red…); se puede re-encolar desde el skill
 *
 * Si un job queda en procesando (p. ej. el contenedor se reinició a mitad de la
 * llamada a Ollama), el worker lo vuelve a pedido tras BOT_LLM_STALE_PROCESSING_SEC
 * segundos sin actualizar updated_at (por defecto 300).
 */

require_once __DIR__ . '/llm_ollama.php';

if (!function_exists('botLlmJobTableName')) {
    function botLlmJobTableName(string $prefix): string
    {
        return $prefix . 'bot_llm_job';
    }
}

if (!function_exists('botLlmJobTableExists')) {
    function botLlmJobTableExists(mysqli $db, string $prefix): bool
    {
        static $cache = [];

        if (array_key_exists($prefix, $cache)) {
            return $cache[$prefix];
        }

        $physical = $db->real_escape_string(botLlmJobTableName($prefix));
        $res = $db->query(
            "SELECT COUNT(*) AS `c` FROM information_schema.`TABLES`
             WHERE `TABLE_SCHEMA` = DATABASE()
               AND `TABLE_NAME` = '{$physical}'
             LIMIT 1"
        );
        if (!$res) {
            return false;
        }
        $row = $res->fetch_assoc();
        $res->free();
        $cache[$prefix] = is_array($row) && (int) ($row['c'] ?? 0) > 0;

        return $cache[$prefix];
    }
}

if (!function_exists('botLlmJobLatestStatus')) {
    /**
     * Estado del job más reciente para (bot, skill, dedupe), o null si no hay filas.
     */
    function botLlmJobLatestStatus(
        mysqli $db,
        string $prefix,
        int $botUserId,
        string $skill,
        string $dedupeKey
    ): ?string {
        $tbl = botLlmJobTableName($prefix);
        $skillEsc = $db->real_escape_string(substr($skill, 0, 64));
        $dedupeEsc = $db->real_escape_string(substr($dedupeKey, 0, 160));
        $res = $db->query(
            "SELECT `status` FROM `{$tbl}`
             WHERE `bot_user_id` = {$botUserId}
               AND `skill` = '{$skillEsc}'
               AND `dedupe_key` = '{$dedupeEsc}'
             ORDER BY `job_id` DESC
             LIMIT 1"
        );
        if (!$res) {
            return null;
        }
        $row = $res->fetch_assoc();
        $res->free();
        if (!is_array($row)) {
            return null;
        }

        return (string) ($row['status'] ?? '');
    }
}

if (!function_exists('botLlmJobRequeueFallido')) {
    /**
     * Reutiliza la fila fallida como nuevo pedido (mismo dedupe).
     */
    function botLlmJobRequeueFallido(
        mysqli $db,
        string $prefix,
        int $botUserId,
        string $skill,
        string $dedupeKey,
        string $requestPayload,
        int $now
    ): bool {
        $tbl = botLlmJobTableName($prefix);
        $skillEsc = $db->real_escape_string(substr($skill, 0, 64));
        $dedupeEsc = $db->real_escape_string(substr($dedupeKey, 0, 160));
        $reqEsc = $db->real_escape_string($requestPayload);

        $sql = "UPDATE `{$tbl}` SET
            `status` = 'pedido',
            `request_payload` = '{$reqEsc}',
            `response_payload` = NULL,
            `error_text` = NULL,
            `claim_nonce` = NULL,
            `updated_at` = {$now}
            WHERE `bot_user_id` = {$botUserId}
              AND `skill` = '{$skillEsc}'
              AND `dedupe_key` = '{$dedupeEsc}'
              AND `status` = 'fallido'
            LIMIT 1";

        $db->query($sql);

        return $db->affected_rows > 0;
    }
}

if (!function_exists('botLlmJobInsertPedido')) {
    /**
     * Inserta fila en estado pedido, o reencola la última fallida.
     * No duplica si ya hay pedido/procesando/respondido o si el último estado es done.
     *
     * @return int 0 si no insertó / no reencoló; job_id si sí
     */
    function botLlmJobInsertPedido(
        mysqli $db,
        string $prefix,
        int $botUserId,
        string $skill,
        string $dedupeKey,
        string $requestPayload,
        int $now
    ): int {
        if ($botUserId <= 0 || $skill === '' || $dedupeKey === '') {
            return 0;
        }

        $latest = botLlmJobLatestStatus($db, $prefix, $botUserId, $skill, $dedupeKey);
        if (in_array($latest, ['pedido', 'procesando', 'respondido', 'done'], true)) {
            return 0;
        }
        if ($latest === 'fallido') {
            if (botLlmJobRequeueFallido($db, $prefix, $botUserId, $skill, $dedupeKey, $requestPayload, $now)) {
                $tbl = botLlmJobTableName($prefix);
                $skillEsc = $db->real_escape_string(substr($skill, 0, 64));
                $dedupeEsc = $db->real_escape_string(substr($dedupeKey, 0, 160));
                $res = $db->query(
                    "SELECT `job_id` FROM `{$tbl}`
                     WHERE `bot_user_id` = {$botUserId}
                       AND `skill` = '{$skillEsc}'
                       AND `dedupe_key` = '{$dedupeEsc}'
                       AND `status` = 'pedido'
                     ORDER BY `job_id` DESC
                     LIMIT 1"
                );
                if ($res && ($row = $res->fetch_assoc())) {
                    $res->free();

                    return (int) $row['job_id'];
                }
            }

            return 0;
        }

        $tbl = botLlmJobTableName($prefix);
        $skillEsc = $db->real_escape_string(substr($skill, 0, 64));
        $dedupeEsc = $db->real_escape_string(substr($dedupeKey, 0, 160));
        $reqEsc = $db->real_escape_string($requestPayload);

        $sql = "INSERT INTO `{$tbl}`
            (`bot_user_id`, `skill`, `dedupe_key`, `status`, `request_payload`, `created_at`, `updated_at`)
            VALUES ({$botUserId}, '{$skillEsc}', '{$dedupeEsc}', 'pedido', '{$reqEsc}', {$now}, {$now})";

        if (!$db->query($sql)) {
            return 0;
        }

        return (int) $db->insert_id;
    }
}

if (!function_exists('botLlmJobMarkDone')) {
    function botLlmJobMarkDone(mysqli $db, string $prefix, int $jobId, int $now): void
    {
        if ($jobId <= 0) {
            return;
        }
        $tbl = botLlmJobTableName($prefix);
        $db->query(
            "UPDATE `{$tbl}` SET
                `status` = 'done',
                `claim_nonce` = NULL,
                `updated_at` = {$now}
             WHERE `job_id` = {$jobId} AND `status` = 'respondido'
             LIMIT 1"
        );
    }
}

if (!function_exists('botLlmJobMarkFallidoFromRespondido')) {
    /** Si el bot no puede aplicar la respuesta, evita bucle infinito. */
    function botLlmJobMarkFallidoFromRespondido(
        mysqli $db,
        string $prefix,
        int $jobId,
        string $error,
        int $now
    ): void {
        if ($jobId <= 0) {
            return;
        }
        $tbl = botLlmJobTableName($prefix);
        $errEsc = $db->real_escape_string(substr($error, 0, 1020));
        $db->query(
            "UPDATE `{$tbl}` SET
                `status` = 'fallido',
                `error_text` = '{$errEsc}',
                `claim_nonce` = NULL,
                `updated_at` = {$now}
             WHERE `job_id` = {$jobId} AND `status` = 'respondido'
             LIMIT 1"
        );
    }
}

if (!function_exists('botLlmWorkerClaimOnePedido')) {
    /**
     * Pasa un trabajo de pedido → procesando. Devuelve la fila o null.
     *
     * @return array<string, mixed>|null
     */
    function botLlmWorkerClaimOnePedido(mysqli $db, string $prefix, int $now): ?array
    {
        $tbl = botLlmJobTableName($prefix);
        $nonce = bin2hex(random_bytes(16));
        $nonceEsc = $db->real_escape_string($nonce);

        $db->query(
            "UPDATE `{$tbl}` SET
                `status` = 'procesando',
                `claim_nonce` = '{$nonceEsc}',
                `updated_at` = {$now}
             WHERE `status` = 'pedido'
             ORDER BY `job_id` ASC
             LIMIT 1"
        );
        if ($db->affected_rows < 1) {
            return null;
        }

        $res = $db->query(
            "SELECT * FROM `{$tbl}` WHERE `claim_nonce` = '{$nonceEsc}' LIMIT 1"
        );
        if (!$res) {
            return null;
        }
        $row = $res->fetch_assoc();
        $res->free();

        return is_array($row) ? $row : null;
    }
}

if (!function_exists('botLlmWorkerFinishOk')) {
    function botLlmWorkerFinishOk(
        mysqli $db,
        string $prefix,
        int $jobId,
        string $responsePayload,
        int $now
    ): void {
        if ($jobId <= 0) {
            return;
        }
        $tbl = botLlmJobTableName($prefix);
        $respEsc = $db->real_escape_string($responsePayload);
        $db->query(
            "UPDATE `{$tbl}` SET
                `status` = 'respondido',
                `response_payload` = '{$respEsc}',
                `error_text` = NULL,
                `claim_nonce` = NULL,
                `updated_at` = {$now}
             WHERE `job_id` = {$jobId} AND `status` = 'procesando'
             LIMIT 1"
        );
    }
}

if (!function_exists('botLlmWorkerFinishError')) {
    function botLlmWorkerFinishError(
        mysqli $db,
        string $prefix,
        int $jobId,
        string $error,
        int $now
    ): void {
        if ($jobId <= 0) {
            return;
        }
        $tbl = botLlmJobTableName($prefix);
        $errEsc = $db->real_escape_string(substr($error, 0, 1020));
        $db->query(
            "UPDATE `{$tbl}` SET
                `status` = 'fallido',
                `error_text` = '{$errEsc}',
                `claim_nonce` = NULL,
                `updated_at` = {$now}
             WHERE `job_id` = {$jobId} AND `status` = 'procesando'
             LIMIT 1"
        );
    }
}

if (!function_exists('botLlmWorkerReleaseStaleProcessing')) {
    /**
     * Jobs en procesando sin tocar updated_at suelen ser reinicios del worker a
     * mitad de Ollama. Los devuelve a pedido para reintentar.
     */
    function botLlmWorkerReleaseStaleProcessing(mysqli $db, string $prefix, int $now): int
    {
        $v = getenv('BOT_LLM_STALE_PROCESSING_SEC');
        if ($v !== false && is_numeric($v)) {
            $stale = max(120, min(86400, (int) $v));
        } else {
            $stale = 300;
        }
        $cutoff = $now - $stale;
        $tbl = botLlmJobTableName($prefix);
        $db->query(
            "UPDATE `{$tbl}` SET
                `status` = 'pedido',
                `claim_nonce` = NULL,
                `updated_at` = {$now}
             WHERE `status` = 'procesando'
               AND `updated_at` < {$cutoff}"
        );

        return $db->affected_rows;
    }
}

if (!function_exists('botLlmWorkerProcessOne')) {
    /**
     * Toma un pedido, llama a Ollama y deja respondido o fallido.
     *
     * @return list<string> líneas de log
     */
    function botLlmWorkerProcessOne(mysqli $db, string $prefix, int $now): array
    {
        $logs = [];
        if (!botLlmIsEnabled()) {
            return $logs;
        }
        if (!botLlmJobTableExists($db, $prefix)) {
            $logs[] = 'llm_worker: tabla bot_llm_job ausente (ejecuta migrate_create_bot_llm_job.php)';

            return $logs;
        }

        $released = botLlmWorkerReleaseStaleProcessing($db, $prefix, $now);
        if ($released > 0) {
            $logs[] = "llm_worker: liberados {$released} job(s) en procesando obsoletos";
        }

        $row = botLlmWorkerClaimOnePedido($db, $prefix, $now);
        if ($row === null) {
            return $logs;
        }

        $jobId = (int) $row['job_id'];
        $skill = (string) ($row['skill'] ?? '');
        $reqRaw = (string) ($row['request_payload'] ?? '');
        $decoded = json_decode($reqRaw, true);
        if (!is_array($decoded)) {
            botLlmWorkerFinishError($db, $prefix, $jobId, 'request_payload no es JSON válido', $now);
            $logs[] = "llm_worker: job {$jobId} fallido (payload)";

            return $logs;
        }

        $system = (string) ($decoded['system'] ?? '');
        $user = (string) ($decoded['user'] ?? '');
        if ($system === '' || $user === '') {
            botLlmWorkerFinishError($db, $prefix, $jobId, 'falta system o user en request_payload', $now);
            $logs[] = "llm_worker: job {$jobId} fallido (prompt vacío)";

            return $logs;
        }

        $raw = botLlmOllamaChat($system, $user, $jobId);
        if ($raw === null) {
            botLlmWorkerFinishError($db, $prefix, $jobId, 'sin respuesta de Ollama (red/timeout)', $now);
            $logs[] = "llm_worker: job {$jobId} fallido (ollama)";

            return $logs;
        }

        $wrap = json_encode(
            ['raw_model_text' => $raw, 'skill' => $skill],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($wrap === false) {
            botLlmWorkerFinishError($db, $prefix, $jobId, 'no se pudo serializar respuesta', $now);

            return $logs;
        }

        botLlmWorkerFinishOk($db, $prefix, $jobId, $wrap, $now);
        $logs[] = "llm_worker: job {$jobId} respondido (skill={$skill})";

        return $logs;
    }
}
