<?php

declare(strict_types=1);

/**
 * Calor dirigido (observer → target): enemistad/amistad respecto a otra cuenta.
 * Reglas de paso (según especificación):
 *   - heat > 1: subir empeorar +1, suavizar/mejorar hacia 1 con −1
 *   - heat ≈ 1: empeorar +1, mejorar −0.1
 *   - heat < 1: ambos sentidos en pasos de 0.1
 * Rango [BOT_HEAT_MIN, BOT_HEAT_MAX].
 *
 * === CAMBIOS EN ESTA RAMA (feat/heat-system) ===
 * 1. botHeatDecayOneStepTowardNeutral → botHeatDecayResetToNeutral:
 *    tras una semana sin interacción, el heat vuelve directamente a 1.0.
 * 2. botHeatDecayTick simplificado: selecciona TODAS las filas vencidas
 *    (sin filtrar por heat != 1) y las resetea a 1.0.
 * 3. Nuevo helper botHeatOnSpyDetected para cuando detectamos sonda enemiga.
 * 4. Nuevo helper botHeatOnDefeat para cuando perdemos una batalla.
 * 5. Nuevo helper botHeatAllianceReduce para reducir heat entre alianzas.
 */
if (!function_exists('botHeatTableName')) {
    function botHeatTableName(string $prefix): string
    {
        return $prefix . 'bot_pair_heat';
    }
}

if (!function_exists('botHeatTableExists')) {
    function botHeatTableExists(mysqli $db, string $prefix): bool
    {
        static $cache = [];

        if (array_key_exists($prefix, $cache)) {
            return $cache[$prefix];
        }

        $physical = $db->real_escape_string(botHeatTableName($prefix));
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

if (!function_exists('botHeatConstants')) {
    /**
     * @return array{min: float, max: float, decay_sec: int, decay_batch: int}
     */
    function botHeatConstants(): array
    {
        $min = 0.1;
        $max = 10.0;
        $decaySec = 86400;
        $v = getenv('BOT_HEAT_DECAY_INTERVAL_SEC');
        if ($v !== false && is_numeric($v)) {
            $decaySec = max(300, min(30 * 86400, (int) $v));
        }
        $batch = 40;
        $v2 = getenv('BOT_HEAT_DECAY_BATCH');
        if ($v2 !== false && is_numeric($v2)) {
            $batch = max(1, min(200, (int) $v2));
        }

        return ['min' => $min, 'max' => $max, 'decay_sec' => $decaySec, 'decay_batch' => $batch];
    }
}

if (!function_exists('botHeatClamp')) {
    function botHeatClamp(float $h): float
    {
        $c = botHeatConstants();

        return max($c['min'], min($c['max'], round($h, 2)));
    }
}

if (!function_exists('botHeatWorsen')) {
    /** Un paso hacia más tensión (p. ej. recibir ataque del target). */
    function botHeatWorsen(float $heat): float
    {
        $h = botHeatClamp($heat);
        if ($h > 1.005) {
            return botHeatClamp($h + 1.0);
        }
        if ($h >= 0.995 && $h <= 1.005) {
            return botHeatClamp($h + 1.0);
        }

        return botHeatClamp($h + 0.1);
    }
}

if (!function_exists('botHeatImprove')) {
    /** Un paso hacia menos tensión / más cordialidad (p. ej. ayuda recibida). */
    function botHeatImprove(float $heat): float
    {
        $h = botHeatClamp($heat);
        if ($h > 1.005) {
            return botHeatClamp($h - 1.0);
        }
        if ($h >= 0.995 && $h <= 1.005) {
            return botHeatClamp($h - 0.1);
        }

        return botHeatClamp($h - 0.1);
    }
}

if (!function_exists('botHeatDecayResetToNeutral')) {
    /**
     * Decay semanal: resetea el heat a 1.0 (neutro) directamente,
     * independientemente del valor actual.
     */
    function botHeatDecayResetToNeutral(float $heat): float
    {
        return 1.0;
    }
}

if (!function_exists('botHeatGet')) {
    function botHeatGet(mysqli $db, string $prefix, int $observerUserId, int $targetUserId): ?float
    {
        if ($observerUserId <= 0 || $targetUserId <= 0 || $observerUserId === $targetUserId) {
            return null;
        }
        if (!botHeatTableExists($db, $prefix)) {
            return null;
        }
        $tbl = botHeatTableName($prefix);
        $res = $db->query(
            "SELECT `heat` FROM `{$tbl}`
             WHERE `observer_user_id` = {$observerUserId} AND `target_user_id` = {$targetUserId}
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

        return (float) ($row['heat'] ?? 1.0);
    }
}

if (!function_exists('botHeatUpsert')) {
    function botHeatUpsert(mysqli $db, string $prefix, int $observerUserId, int $targetUserId, float $heat, int $now): void
    {
        if ($observerUserId <= 0 || $targetUserId <= 0 || $observerUserId === $targetUserId) {
            return;
        }
        if (!botHeatTableExists($db, $prefix)) {
            return;
        }
        $tbl = botHeatTableName($prefix);
        $h = botHeatClamp($heat);
        $db->query(
            "INSERT INTO `{$tbl}` (`observer_user_id`, `target_user_id`, `heat`, `updated_at`)
             VALUES ({$observerUserId}, {$targetUserId}, {$h}, {$now})
             ON DUPLICATE KEY UPDATE `heat` = VALUES(`heat`), `updated_at` = VALUES(`updated_at`)"
        );
    }
}

if (!function_exists('botHeatOnInboundHostile')) {
    /**
     * El observer recibió hostilidad del target (atacante): sube calor observer→target.
     */
    function botHeatOnInboundHostile(
        mysqli $db,
        string $prefix,
        int $observerUserId,
        int $attackerUserId,
        int $now
    ): void {
        if ($observerUserId <= 0 || $attackerUserId <= 0 || $observerUserId === $attackerUserId) {
            return;
        }
        if (!botHeatTableExists($db, $prefix)) {
            return;
        }
        $cur = botHeatGet($db, $prefix, $observerUserId, $attackerUserId);
        $base = $cur ?? 1.0;
        botHeatUpsert($db, $prefix, $observerUserId, $attackerUserId, botHeatWorsen($base), $now);
    }
}

if (!function_exists('botHeatOnSpyDetected')) {
    /**
     * El observer detectó una sonda de espionaje enviada por el target.
     * Efecto más leve que un ataque: worsen leve.
     */
    function botHeatOnSpyDetected(
        mysqli $db,
        string $prefix,
        int $observerUserId,
        int $spyUserId,
        int $now
    ): void {
        if ($observerUserId <= 0 || $spyUserId <= 0 || $observerUserId === $spyUserId) {
            return;
        }
        if (!botHeatTableExists($db, $prefix)) {
            return;
        }
        $cur = botHeatGet($db, $prefix, $observerUserId, $spyUserId);
        $base = $cur ?? 1.0;
        $h = botHeatClamp($base);
        // worsen leve: siempre +0.1 independientemente del rango
        botHeatUpsert($db, $prefix, $observerUserId, $spyUserId, botHeatClamp($h + 0.1), $now);
    }
}

if (!function_exists('botHeatOnDefeat')) {
    /**
     * El observer perdió una batalla contra el target (defensas destruidas
     * o flota perdida). Efecto fuerte: worsen +2.
     */
    function botHeatOnDefeat(
        mysqli $db,
        string $prefix,
        int $observerUserId,
        int $attackerUserId,
        int $now
    ): void {
        if ($observerUserId <= 0 || $attackerUserId <= 0 || $observerUserId === $attackerUserId) {
            return;
        }
        if (!botHeatTableExists($db, $prefix)) {
            return;
        }
        $cur = botHeatGet($db, $prefix, $observerUserId, $attackerUserId);
        $base = $cur ?? 1.0;
        botHeatUpsert($db, $prefix, $observerUserId, $attackerUserId, botHeatClamp($base + 2.0), $now);
    }
}

if (!function_exists('botHeatOnMessageReceived')) {
    /**
     * Procesa un heat_delta devuelto por el LLM inbox scanner.
     * Valores positivos = worsen, negativos = improve.
     */
    function botHeatOnMessageReceived(
        mysqli $db,
        string $prefix,
        int $observerUserId,
        int $targetUserId,
        float $heatDelta,
        int $now
    ): void {
        if ($observerUserId <= 0 || $targetUserId <= 0 || $observerUserId === $targetUserId) {
            return;
        }
        if (!botHeatTableExists($db, $prefix)) {
            return;
        }
        $cur = botHeatGet($db, $prefix, $observerUserId, $targetUserId);
        $base = $cur ?? 1.0;
        botHeatUpsert($db, $prefix, $observerUserId, $targetUserId, botHeatClamp($base + $heatDelta), $now);
    }
}

if (!function_exists('botHeatAllianceReduce')) {
    /**
     * Reduce el heat entre TODOS los miembros de dos alianzas en un factor (0.0–1.0).
     * Ej: factor=0.80 reduce el heat un 80% hacia ambos lados.
     * Útil tras firmar la paz.
     */
    function botHeatAllianceReduce(
        mysqli $db,
        string $prefix,
        int $allyA,
        int $allyB,
        float $factor,
        int $now
    ): void {
        if ($allyA <= 0 || $allyB <= 0 || $allyA === $allyB) {
            return;
        }
        if (!botHeatTableExists($db, $prefix)) {
            return;
        }
        $factor = max(0.0, min(1.0, $factor));
        $tbl = botHeatTableName($prefix);

        foreach ([$allyA, $allyB] as $observerAlly) {
            $targetAlly = $observerAlly === $allyA ? $allyB : $allyA;
            $res = $db->query(
                "SELECT h.`observer_user_id`, h.`target_user_id`, h.`heat`
                 FROM `{$tbl}` h
                 JOIN `{$prefix}users` uo ON uo.`user_id` = h.`observer_user_id`
                 JOIN `{$prefix}users` ut ON ut.`user_id` = h.`target_user_id`
                 WHERE uo.`user_ally_id` = {$observerAlly}
                   AND ut.`user_ally_id` = {$targetAlly}
                   AND h.`heat` != 1.0"
            );
            if (!$res) {
                continue;
            }
            while ($row = $res->fetch_assoc()) {
                $obId = (int) ($row['observer_user_id'] ?? 0);
                $tgId = (int) ($row['target_user_id'] ?? 0);
                $h = (float) ($row['heat'] ?? 1.0);
                if ($obId <= 0 || $tgId <= 0) {
                    continue;
                }
                $reduced = 1.0 + ($h - 1.0) * (1.0 - $factor);
                botHeatUpsert($db, $prefix, $obId, $tgId, botHeatClamp($reduced), $now);
            }
            $res->free();
        }
    }
}

if (!function_exists('botHeatOnHelpReceived')) {
    /**
     * El observer recibe ayuda (recursos) enviada por target: baja calor observer→target.
     */
    function botHeatOnHelpReceived(
        mysqli $db,
        string $prefix,
        int $observerUserId,
        int $helperUserId,
        int $now
    ): void {
        if ($observerUserId <= 0 || $helperUserId <= 0 || $observerUserId === $helperUserId) {
            return;
        }
        if (!botHeatTableExists($db, $prefix)) {
            return;
        }
        $cur = botHeatGet($db, $prefix, $observerUserId, $helperUserId);
        $base = $cur ?? 1.0;
        botHeatUpsert($db, $prefix, $observerUserId, $helperUserId, botHeatImprove($base), $now);
    }
}

if (!function_exists('botHeatDecayTick')) {
    /**
     * Resetea a 1.0 todas las filas del observer que lleven
     * decay_sec sin actualizarse (decay semanal).
     *
     * @return int filas tocadas
     */
    function botHeatDecayTick(mysqli $db, string $prefix, int $observerUserId, int $now): int
    {
        if ($observerUserId <= 0 || !botHeatTableExists($db, $prefix)) {
            return 0;
        }
        $c = botHeatConstants();
        $cutoff = $now - $c['decay_sec'];
        $tbl = botHeatTableName($prefix);
        $batch = $c['decay_batch'];
        $res = $db->query(
            "SELECT `target_user_id`, `heat` FROM `{$tbl}`
             WHERE `observer_user_id` = {$observerUserId}
               AND `updated_at` <= {$cutoff}
             ORDER BY `updated_at` ASC
             LIMIT {$batch}"
        );
        if (!$res) {
            return 0;
        }
        $n = 0;
        while ($row = $res->fetch_assoc()) {
            if (!is_array($row)) {
                continue;
            }
            $tid = (int) ($row['target_user_id'] ?? 0);
            $h = (float) ($row['heat'] ?? 1.0);
            if ($tid <= 0) {
                continue;
            }
            if (abs($h - 1.0) < 0.0001) {
                continue; // Ya está en 1.0, no tocar
            }
            botHeatUpsert($db, $prefix, $observerUserId, $tid, 1.0, $now);
            $n++;
        }
        $res->free();

        return $n;
    }
}
