<?php

declare(strict_types=1);

/**
 * Buzón jugador→bot: USER (4) o MP tipo GENERAL (5) enviados desde el chat
 * (remitente con enlace game.php?page=galaxy). Cola LLM asíncrona.
 * Contexto extra (stats, rangos, alianzas, hostilidad reciente): BOT_LLM_INBOX_CONTEXT_HOURS (1–168, default 24).
 *
 * - El bot encola jobs en estado pedido (sin esperar a Ollama).
 * - El worker pasa a procesando → respondido | fallido.
 * - El bot aplica respondido → done (envía MP o ignora y marca leído).
 */

require_once __DIR__ . '/llm_jobs.php';
require_once __DIR__ . '/diplomacy.php';

if (!function_exists('botLlmInboxMaxPerTick')) {
    function botLlmInboxMaxPerTick(): int
    {
        $v = getenv('BOT_LLM_INBOX_MAX_PER_TICK');
        if ($v !== false && is_numeric($v)) {
            return max(0, min(20, (int) $v));
        }

        return 3;
    }
}

if (!function_exists('botLlmInboxMaxApplyPerTick')) {
    function botLlmInboxMaxApplyPerTick(): int
    {
        $v = getenv('BOT_LLM_INBOX_MAX_APPLY_PER_TICK');
        if ($v !== false && is_numeric($v)) {
            return max(0, min(50, (int) $v));
        }

        return 8;
    }
}

if (!function_exists('botLlmTruncateUtf8')) {
    function botLlmTruncateUtf8(string $s, int $maxBytes): string
    {
        if ($maxBytes <= 0) {
            return '';
        }
        if (strlen($s) <= $maxBytes) {
            return $s;
        }

        return substr($s, 0, $maxBytes);
    }
}

if (!function_exists('botLlmInsertUserMessage')) {
    /**
     * Inserta un MP tipo USER (4), mismo esquema que el Messenger del juego.
     */
    function botLlmInsertUserMessage(
        mysqli $db,
        string $prefix,
        int $receiverUserId,
        int $senderUserId,
        string $fromLabel,
        string $subject,
        string $body
    ): bool {
        $tbl = $prefix . 'messages';
        $now = time();
        $type = 4;
        $fromEsc = $db->real_escape_string(botLlmTruncateUtf8($fromLabel, 127));
        $subEsc = $db->real_escape_string(botLlmTruncateUtf8($subject, 65535));
        $bodyEsc = $db->real_escape_string(botLlmTruncateUtf8($body, 65535));

        $sql = "INSERT INTO `{$tbl}` SET
            `message_receiver` = {$receiverUserId},
            `message_sender` = {$senderUserId},
            `message_time` = {$now},
            `message_type` = {$type},
            `message_from` = '{$fromEsc}',
            `message_subject` = '{$subEsc}',
            `message_text` = '{$bodyEsc}'";

        return (bool) $db->query($sql);
    }
}

if (!function_exists('botLlmInboxSystemPrompt')) {
    function botLlmInboxSystemPrompt(): string
    {
        return <<<'SYS'
Eres un jugador de un juego espacial (mensajería in-game). Responde en el idioma del mensaje entrante si es claro; si no, en español.
Recibes un JSON en el mensaje user con "incoming_message" (el MP) y "context". En context, recipient_bot es SIEMPRE la cuenta del bot que recibe el mensaje y debe responder; message_sender es SIEMPRE el jugador humano que escribió el MP. Las estadísticas (puntos/ranking) de recipient_bot van solo en context.recipient_bot; las del remitente humano solo en context.message_sender — no mezcles ni dupliques datos entre ambos.
Úsalo para calibrar tono y contenido: no tienes que ser siempre cordial; ante guerra, hostilidad reciente o estilo "raider" puedes ser más duro, irónico o breve; en la misma alianza o perfil "granja" puedes ser más colaborativo. Nunca incites al odio real, a ilegalidad ni filtrar datos personales fuera del juego; no inventes hechos que contradigan el contexto.
Debes responder SOLO con un JSON válido (sin markdown), una sola línea o bloque, con este esquema exacto:
{"action":"reply"|"ignore","subject":"...","body":"..."}
- Si action es "ignore": subject y body pueden ser cadenas vacías.
- Si action es "reply": subject breve (máx ~120 caracteres), body (máx ~2000 caracteres) sin HTML; tono acorde al contexto descrito arriba.
SYS;
    }
}

if (!function_exists('botLlmInboxContextWindowSeconds')) {
    function botLlmInboxContextWindowSeconds(): int
    {
        $v = getenv('BOT_LLM_INBOX_CONTEXT_HOURS');
        if ($v !== false && is_numeric($v)) {
            return max(1, min(168, (int) $v)) * 3600;
        }

        return 86400;
    }
}

if (!function_exists('botLlmInboxSchemaTableExists')) {
    function botLlmInboxSchemaTableExists(mysqli $db, string $physicalTable): bool
    {
        static $cache = [];

        if (array_key_exists($physicalTable, $cache)) {
            return $cache[$physicalTable];
        }
        $esc = $db->real_escape_string($physicalTable);
        $res = $db->query(
            "SELECT 1 FROM information_schema.`TABLES`
             WHERE `TABLE_SCHEMA` = DATABASE()
               AND `TABLE_NAME` = '{$esc}'
             LIMIT 1"
        );
        $cache[$physicalTable] = (bool) ($res && $res->fetch_row());
        if ($res) {
            $res->free();
        }

        return $cache[$physicalTable];
    }
}

if (!function_exists('botLlmInboxStyleDescriptionEs')) {
    function botLlmInboxStyleDescriptionEs(string $style): string
    {
        switch ($style) {
            case 'bunker':
                return 'Perfil defensivo (bunker): prioriza defensas y aguantar ataques.';
            case 'raider':
                return 'Perfil de flotas (raider): prioriza naves de ataque y presión.';
            case 'granja':
            default:
                return 'Perfil económico (granja): prioriza minas, infra y crecimiento.';
        }
    }
}

if (!function_exists('botLlmInboxUserStatsPointsAndRanks')) {
    /**
     * Puntos y rangos de highscore por categoría (tabla users_statistics).
     *
     * @return array<string, int>
     */
    function botLlmInboxUserStatsPointsAndRanks(mysqli $db, string $prefix, int $userId): array
    {
        $out = [
            'buildings_points' => 0,
            'defenses_points' => 0,
            'ships_points' => 0,
            'technology_points' => 0,
            'total_points' => 0,
            'buildings_rank' => 0,
            'defenses_rank' => 0,
            'ships_rank' => 0,
            'technology_rank' => 0,
            'total_rank' => 0,
        ];
        if ($userId <= 0) {
            return $out;
        }
        $tbl = $prefix . 'users_statistics';
        $res = $db->query(
            "SELECT `user_statistic_buildings_points`, `user_statistic_defenses_points`,
                    `user_statistic_ships_points`, `user_statistic_technology_points`,
                    `user_statistic_total_points`,
                    `user_statistic_buildings_rank`, `user_statistic_defenses_rank`,
                    `user_statistic_ships_rank`, `user_statistic_technology_rank`,
                    `user_statistic_total_rank`
             FROM `{$tbl}`
             WHERE `user_statistic_user_id` = {$userId}
             LIMIT 1"
        );
        if (!$res) {
            return $out;
        }
        $row = $res->fetch_assoc();
        $res->free();
        if (!is_array($row)) {
            return $out;
        }
        $out['buildings_points'] = (int) round((float) ($row['user_statistic_buildings_points'] ?? 0));
        $out['defenses_points'] = (int) round((float) ($row['user_statistic_defenses_points'] ?? 0));
        $out['ships_points'] = (int) round((float) ($row['user_statistic_ships_points'] ?? 0));
        $out['technology_points'] = (int) round((float) ($row['user_statistic_technology_points'] ?? 0));
        $out['total_points'] = (int) round((float) ($row['user_statistic_total_points'] ?? 0));
        $out['buildings_rank'] = (int) ($row['user_statistic_buildings_rank'] ?? 0);
        $out['defenses_rank'] = (int) ($row['user_statistic_defenses_rank'] ?? 0);
        $out['ships_rank'] = (int) ($row['user_statistic_ships_rank'] ?? 0);
        $out['technology_rank'] = (int) ($row['user_statistic_technology_rank'] ?? 0);
        $out['total_rank'] = (int) ($row['user_statistic_total_rank'] ?? 0);

        return $out;
    }
}

if (!function_exists('botLlmInboxBotPersonaFromDb')) {
    /**
     * @return array{bot_archetype: string, bot_personality: string}
     */
    function botLlmInboxBotPersonaFromDb(mysqli $db, string $prefix, int $botId): array
    {
        $defaults = ['bot_archetype' => 'balanced', 'bot_personality' => 'flotero'];
        if ($botId <= 0 || !botLlmInboxSchemaTableExists($db, $prefix . 'bot_state')) {
            return $defaults;
        }
        $tbl = $prefix . 'bot_state';
        $res = $db->query(
            "SELECT `bot_archetype`, `bot_personality` FROM `{$tbl}` WHERE `bot_user_id` = {$botId} LIMIT 1"
        );
        if (!$res) {
            return $defaults;
        }
        $row = $res->fetch_assoc();
        $res->free();
        if (!is_array($row)) {
            return $defaults;
        }

        return [
            'bot_archetype' => (string) ($row['bot_archetype'] ?? $defaults['bot_archetype']),
            'bot_personality' => (string) ($row['bot_personality'] ?? $defaults['bot_personality']),
        ];
    }
}

if (!function_exists('botLlmInboxReportsInvolvingBothSince')) {
    function botLlmInboxReportsInvolvingBothSince(
        mysqli $db,
        string $prefix,
        int $userA,
        int $userB,
        int $sinceTs
    ): bool {
        if ($userA <= 0 || $userB <= 0) {
            return false;
        }
        $tbl = $prefix . 'reports';
        if (!botLlmInboxSchemaTableExists($db, $tbl)) {
            return false;
        }
        $res = $db->query(
            "SELECT `report_rid` FROM `{$tbl}`
             WHERE `report_time` >= {$sinceTs}
               AND FIND_IN_SET({$userA}, `report_owners`) > 0
               AND FIND_IN_SET({$userB}, `report_owners`) > 0
             LIMIT 1"
        );
        if (!$res) {
            return false;
        }
        $ok = $res->fetch_assoc() !== null;
        $res->free();

        return $ok;
    }
}

if (!function_exists('botLlmInboxHostileFleetBetweenSince')) {
    /**
     * Flota hostil del remitente hacia el bot aún en vuelo (misiones 1 ataque, 2 ACS, 6 espionaje).
     */
    function botLlmInboxHostileFleetBetweenSince(
        mysqli $db,
        string $prefix,
        int $senderId,
        int $botId,
        int $sinceTs
    ): bool {
        if ($senderId <= 0 || $botId <= 0) {
            return false;
        }
        $tbl = $prefix . 'fleets';
        $res = $db->query(
            "SELECT 1 FROM `{$tbl}`
             WHERE `fleet_owner` = {$senderId}
               AND `fleet_target_owner` = {$botId}
               AND `fleet_mission` IN (1, 2, 6)
               AND `fleet_creation` >= {$sinceTs}
             LIMIT 1"
        );
        if (!$res) {
            return false;
        }
        $ok = $res->fetch_assoc() !== null;
        $res->free();

        return $ok;
    }
}

if (!function_exists('botLlmInboxSpyActivityNoticeSince')) {
    /**
     * Aviso in-game de espionaje (message_type 0) recibido por el bot que menciona al espía por nombre.
     */
    function botLlmInboxSpyActivityNoticeSince(
        mysqli $db,
        string $prefix,
        int $botId,
        string $senderUserName,
        int $sinceTs
    ): bool {
        if ($botId <= 0 || $senderUserName === '') {
            return false;
        }
        $tbl = $prefix . 'messages';
        $needle = $db->real_escape_string($senderUserName);
        $res = $db->query(
            "SELECT 1 FROM `{$tbl}`
             WHERE `message_receiver` = {$botId}
               AND `message_type` = 0
               AND `message_time` >= {$sinceTs}
               AND LOCATE('{$needle}', `message_text`) > 0
             LIMIT 1"
        );
        if (!$res) {
            return false;
        }
        $ok = $res->fetch_assoc() !== null;
        $res->free();

        return $ok;
    }
}

if (!function_exists('botLlmInboxAllianceTags')) {
    /**
     * @return array<int, array{tag: string, name: string}>
     */
    function botLlmInboxAllianceTags(mysqli $db, string $prefix, int ...$allyIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $allyIds), static fn (int $x): bool => $x > 0)));
        if ($ids === []) {
            return [];
        }
        $tbl = $prefix . 'alliance';
        if (!botLlmInboxSchemaTableExists($db, $tbl)) {
            return [];
        }
        $list = implode(',', $ids);
        $out = [];
        $res = $db->query(
            "SELECT `alliance_id`, `alliance_tag`, `alliance_name` FROM `{$tbl}` WHERE `alliance_id` IN ({$list})"
        );
        if (!$res) {
            return [];
        }
        while ($row = $res->fetch_assoc()) {
            $aid = (int) ($row['alliance_id'] ?? 0);
            if ($aid > 0) {
                $out[$aid] = [
                    'tag' => (string) ($row['alliance_tag'] ?? ''),
                    'name' => (string) ($row['alliance_name'] ?? ''),
                ];
            }
        }
        $res->free();

        return $out;
    }
}

if (!function_exists('botLlmInboxDiplomacyPressureMax')) {
    function botLlmInboxDiplomacyPressureMax(
        mysqli $db,
        string $prefix,
        int $allyA,
        int $allyB
    ): ?int {
        if ($allyA <= 0 || $allyB <= 0) {
            return null;
        }
        $tbl = $prefix . 'alliance_diplomacy_pressure';
        if (!botLlmInboxSchemaTableExists($db, $tbl)) {
            return null;
        }
        $res = $db->query(
            "SELECT MAX(`pressure`) AS `p` FROM `{$tbl}`
             WHERE (`victim_alliance_id` = {$allyA} AND `attacker_alliance_id` = {$allyB})
                OR (`victim_alliance_id` = {$allyB} AND `attacker_alliance_id` = {$allyA})"
        );
        if (!$res) {
            return null;
        }
        $row = $res->fetch_assoc();
        $res->free();
        if (!is_array($row) || $row['p'] === null) {
            return null;
        }

        return (int) $row['p'];
    }
}

if (!function_exists('botLlmInboxDiplomacyStatusBetween')) {
    /**
     * Estado bilateral entre alianzas (war / nap / neutral) si existe fila vigente.
     */
    function botLlmInboxDiplomacyStatusBetween(
        mysqli $db,
        string $prefix,
        int $allyA,
        int $allyB,
        int $now
    ): ?string {
        if ($allyA <= 0 || $allyB <= 0 || $allyA === $allyB) {
            return null;
        }
        $tbl = $prefix . 'alliance_diplomacy';
        if (!botLlmInboxSchemaTableExists($db, $tbl)) {
            return null;
        }
        $pair = botDiplomacyNormalisePair($allyA, $allyB);
        if ($pair === null) {
            return null;
        }
        $res = $db->query(
            "SELECT `status` FROM `{$tbl}`
             WHERE `alliance_a` = {$pair[0]} AND `alliance_b` = {$pair[1]}
               AND (`expires_at` = 0 OR `expires_at` > {$now})
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

if (!function_exists('botLlmInboxBuildContextBlock')) {
    /**
     * @param array<string, mixed> $profile
     *
     * @return array<string, mixed>
     */
    function botLlmInboxBuildContextBlock(
        mysqli $db,
        string $prefix,
        int $botId,
        string $botName,
        int $senderId,
        string $senderUserName,
        array $profile,
        string $profileName,
        int $now
    ): array {
        $since = $now - botLlmInboxContextWindowSeconds();
        $windowHours = (int) round(botLlmInboxContextWindowSeconds() / 3600);

        $botAlly = 0;
        $senderAlly = 0;
        $rb = $db->query("SELECT `user_ally_id` FROM `{$prefix}users` WHERE `user_id` = {$botId} LIMIT 1");
        if ($rb && ($xb = $rb->fetch_assoc())) {
            $botAlly = (int) ($xb['user_ally_id'] ?? 0);
        }
        if ($rb) {
            $rb->free();
        }
        $rs = $db->query("SELECT `user_ally_id` FROM `{$prefix}users` WHERE `user_id` = {$senderId} LIMIT 1");
        if ($rs && ($xs = $rs->fetch_assoc())) {
            $senderAlly = (int) ($xs['user_ally_id'] ?? 0);
        }
        if ($rs) {
            $rs->free();
        }

        $persona = botLlmInboxBotPersonaFromDb($db, $prefix, $botId);
        $style = (string) ($profile['bot_style'] ?? 'granja');
        $tags = botLlmInboxAllianceTags($db, $prefix, $botAlly, $senderAlly);

        $relation = 'sin_alianza_ambos';
        $relation_es = 'Ninguno de los dos está en alianza.';
        if ($botAlly > 0 && $botAlly === $senderAlly) {
            $relation = 'misma_alianza';
            $t = $tags[$botAlly]['tag'] ?? '';
            $relation_es = 'Sois de la misma alianza' . ($t !== '' ? " (etiqueta: {$t})." : '.');
        } elseif ($botAlly > 0 && $senderAlly > 0) {
            $status = botLlmInboxDiplomacyStatusBetween($db, $prefix, $botAlly, $senderAlly, $now);
            $atWar = botDiplomacyIsAtWar($db, $prefix, $botAlly, $senderAlly);
            $pressure = botLlmInboxDiplomacyPressureMax($db, $prefix, $botAlly, $senderAlly);
            if ($atWar || $status === 'war') {
                $relation = 'guerra';
                $relation_es = 'Vuestras alianzas están en guerra o tensión bélica declarada.';
            } elseif ($status === 'nap') {
                $relation = 'pacto_nap';
                $relation_es = 'Hay pacto de no agresión (NAP) entre alianzas.';
            } elseif ($status === 'neutral') {
                $relation = 'neutral';
                $relation_es = 'Alianzas distintas; relación diplomática neutra registrada.';
            } else {
                $relation = 'alianzas_distintas';
                $relation_es = 'Alianzas distintas; sin fila diplomática explícita (se asume tensión moderada por competencia).';
            }
            if ($pressure !== null && $pressure > 0) {
                $relation_es .= ' Presión diplomática acumulada (aprox.): ' . $pressure . '.';
            }
        } elseif ($botAlly > 0 xor $senderAlly > 0) {
            $relation = 'uno_sin_alianza';
            $relation_es = 'Uno pertenece a alianza y el otro no; no hay marco diplomático bilateral.';
        }

        $hostileFleet = botLlmInboxHostileFleetBetweenSince($db, $prefix, $senderId, $botId, $since);
        $battleReport = botLlmInboxReportsInvolvingBothSince($db, $prefix, $senderId, $botId, $since);
        $spyNotice = botLlmInboxSpyActivityNoticeSince($db, $prefix, $botId, $senderUserName, $since);

        return [
            'window_hours' => $windowHours,
            'stats_legend_es' => 'recipient_bot = cuenta del bot que recibe el MP (respondes como ese jugador). message_sender = quien envió el MP; sus puntos/rangos solo en message_sender.stats_points_and_ranks. En stats_points_and_ranks, total_points/total_rank son el global; buildings_* es solo edificios (no confundir cifras de categorías distintas).',
            'recipient_bot' => [
                'user_id' => $botId,
                'user_name' => $botName,
                'stats_points_and_ranks' => botLlmInboxUserStatsPointsAndRanks($db, $prefix, $botId),
                'alliance' => [
                    'ally_id' => $botAlly,
                    'tag' => $tags[$botAlly]['tag'] ?? '',
                    'name' => $tags[$botAlly]['name'] ?? '',
                ],
                'persona' => [
                    'profile_name' => $profileName,
                    'bot_style' => $style,
                    'bot_style_es' => botLlmInboxStyleDescriptionEs($style),
                    'aggressiveness_1_to_5' => (int) ($profile['aggressiveness'] ?? 3),
                    'eco_focus' => (float) ($profile['eco_focus'] ?? 0.7),
                    'defense_focus' => (float) ($profile['defense_focus'] ?? 0.2),
                    'research_focus' => (float) ($profile['research_focus'] ?? 0.6),
                    'bot_archetype' => $persona['bot_archetype'],
                    'bot_personality' => $persona['bot_personality'],
                ],
            ],
            'message_sender' => [
                'user_id' => $senderId,
                'user_name' => $senderUserName,
                'stats_points_and_ranks' => botLlmInboxUserStatsPointsAndRanks($db, $prefix, $senderId),
                'alliance' => [
                    'ally_id' => $senderAlly,
                    'tag' => $tags[$senderAlly]['tag'] ?? '',
                    'name' => $tags[$senderAlly]['name'] ?? '',
                ],
            ],
            'recent_hostility' => [
                'sender_hostile_fleet_to_bot_in_window' => $hostileFleet,
                'combat_report_involving_both_in_window' => $battleReport,
                'spy_activity_notice_to_bot_in_window' => $spyNotice,
            ],
            'alliances' => [
                'relation_key' => $relation,
                'summary_es' => $relation_es,
            ],
        ];
    }
}

if (!function_exists('botLlmInboxApplyRespondidoJobs')) {
    /**
     * @return list<string>
     */
    function botLlmInboxApplyRespondidoJobs(
        mysqli $db,
        string $prefix,
        array $user,
        string $profileName,
        array $profile,
        int $now
    ): array {
        $logs = [];
        $botId = (int) ($user['user_id'] ?? 0);
        $botName = (string) ($user['user_name'] ?? '');
        if ($botId <= 0 || $botName === '') {
            return $logs;
        }

        $tblJ = botLlmJobTableName($prefix);
        $skillEsc = $db->real_escape_string('inbox_reply_v1');
        $max = botLlmInboxMaxApplyPerTick();
        if ($max <= 0) {
            return $logs;
        }

        // Jobs fallidos solo por el bug que leía incoming_message en la raíz en vez de dentro de user.
        $bugEsc = $db->real_escape_string('falta incoming_message en request');
        $db->query(
            "UPDATE `{$tblJ}` SET
                `status` = 'respondido',
                `error_text` = NULL,
                `updated_at` = {$now}
             WHERE `bot_user_id` = {$botId}
               AND `skill` = '{$skillEsc}'
               AND `status` = 'fallido'
               AND `error_text` = '{$bugEsc}'
               AND COALESCE(`response_payload`, '') <> ''"
        );
        $recovered = $db->affected_rows;
        if ($recovered > 0) {
            $logs[] = "llm_inbox: recuperados {$recovered} job(s) fallido→respondido (reintento aplicar)";
        }

        $res = $db->query(
            "SELECT * FROM `{$tblJ}`
             WHERE `bot_user_id` = {$botId}
               AND `skill` = '{$skillEsc}'
               AND `status` = 'respondido'
             ORDER BY `job_id` ASC
             LIMIT {$max}"
        );
        if (!$res) {
            return $logs;
        }
        $jobs = [];
        while ($j = $res->fetch_assoc()) {
            $jobs[] = $j;
        }
        $res->free();

        $tblM = $prefix . 'messages';

        foreach ($jobs as $job) {
            $jobId = (int) $job['job_id'];
            $jsonFlags = defined('JSON_INVALID_UTF8_IGNORE') ? JSON_INVALID_UTF8_IGNORE : 0;
            $req = json_decode((string) ($job['request_payload'] ?? ''), true, 512, $jsonFlags);
            $respWrap = json_decode((string) ($job['response_payload'] ?? ''), true, 512, $jsonFlags);
            if (!is_array($req) || !is_array($respWrap)) {
                botLlmJobMarkFallidoFromRespondido($db, $prefix, $jobId, 'payload corrupto al aplicar', $now);
                $logs[] = "llm_inbox: job {$jobId} marcado fallido (payload)";

                continue;
            }

            $raw = (string) ($respWrap['raw_model_text'] ?? '');
            $parsed = botLlmExtractJsonObject($raw);
            if ($parsed === null) {
                botLlmJobMarkFallidoFromRespondido($db, $prefix, $jobId, 'JSON del modelo ilegible al aplicar', $now);
                $logs[] = "llm_inbox: job {$jobId} marcado fallido (json modelo)";

                continue;
            }

            // request_payload es { system, user } donde user es JSON string con incoming_message dentro.
            $incoming = $req['incoming_message'] ?? null;
            if (!is_array($incoming) && isset($req['user'])) {
                $u = $req['user'];
                if (is_string($u)) {
                    $inner = json_decode($u, true, 512, $jsonFlags);
                    $incoming = is_array($inner) ? ($inner['incoming_message'] ?? null) : null;
                } elseif (is_array($u)) {
                    $incoming = $u['incoming_message'] ?? null;
                }
            }
            if (!is_array($incoming)) {
                botLlmJobMarkFallidoFromRespondido($db, $prefix, $jobId, 'falta incoming_message en request', $now);
                $logs[] = "llm_inbox: job {$jobId} fallido (incoming)";

                continue;
            }

            $mid = (int) ($incoming['message_id'] ?? 0);
            if ($mid <= 0) {
                botLlmJobMarkDone($db, $prefix, $jobId, $now);
                $logs[] = "llm_inbox: job {$jobId} done (sin message_id)";

                continue;
            }

            $chk = $db->query(
                "SELECT `message_id`, `message_sender`, `message_read`
                 FROM `{$tblM}`
                 WHERE `message_id` = {$mid}
                   AND `message_receiver` = {$botId}
                   AND (
                     `message_type` = 4
                     OR (`message_type` = 5 AND `message_from` LIKE '%game.php?page=galaxy%')
                   )
                 LIMIT 1"
            );
            $mrow = $chk ? $chk->fetch_assoc() : null;
            if ($chk) {
                $chk->free();
            }
            if (!is_array($mrow)) {
                botLlmJobMarkDone($db, $prefix, $jobId, $now);
                $logs[] = "llm_inbox: job {$jobId} done (mensaje ya no existe)";

                continue;
            }
            if ((int) $mrow['message_read'] !== 0) {
                botLlmJobMarkDone($db, $prefix, $jobId, $now);
                $logs[] = "llm_inbox: job {$jobId} done (mensaje ya leído)";

                continue;
            }

            $senderId = (int) $mrow['message_sender'];
            $action = strtolower(trim((string) ($parsed['action'] ?? 'ignore')));

            if ($action === 'reply') {
                $subOut = trim((string) ($parsed['subject'] ?? 'Re:'));
                $bodyOut = trim((string) ($parsed['body'] ?? ''));
                if ($bodyOut === '') {
                    $db->query("UPDATE `{$tblM}` SET `message_read` = 1 WHERE `message_id` = {$mid} LIMIT 1");
                    botLlmJobMarkDone($db, $prefix, $jobId, $now);
                    $logs[] = "llm_inbox: job {$jobId} done (reply vacío → leído)";

                    continue;
                }
                $subOut = botLlmTruncateUtf8($subOut, 200);
                $bodyOut = botLlmTruncateUtf8($bodyOut, 8000);
                $fromLabel = botLlmTruncateUtf8($botName, 120);
                if (botLlmInsertUserMessage($db, $prefix, $senderId, $botId, $fromLabel, $subOut, $bodyOut)) {
                    $db->query("UPDATE `{$tblM}` SET `message_read` = 1 WHERE `message_id` = {$mid} LIMIT 1");
                    botLlmJobMarkDone($db, $prefix, $jobId, $now);
                    $logs[] = "llm_inbox: job {$jobId} done (respuesta enviada a user_id={$senderId})";
                } else {
                    botLlmJobMarkFallidoFromRespondido($db, $prefix, $jobId, 'falló INSERT respuesta MP', $now);
                    $logs[] = "llm_inbox: job {$jobId} fallido al insertar MP";
                }
            } else {
                $db->query("UPDATE `{$tblM}` SET `message_read` = 1 WHERE `message_id` = {$mid} LIMIT 1");
                botLlmJobMarkDone($db, $prefix, $jobId, $now);
                $logs[] = "llm_inbox: job {$jobId} done (ignore)";
            }
        }

        return $logs;
    }
}

if (!function_exists('botLlmInboxEnqueuePedidos')) {
    /**
     * @return list<string>
     */
    function botLlmInboxEnqueuePedidos(
        mysqli $db,
        string $prefix,
        array $user,
        string $profileName,
        array $profile,
        int $now
    ): array {
        $logs = [];
        $max = botLlmInboxMaxPerTick();
        if ($max <= 0) {
            return $logs;
        }

        $botId = (int) ($user['user_id'] ?? 0);
        $botName = (string) ($user['user_name'] ?? '');
        if ($botId <= 0 || $botName === '') {
            return $logs;
        }

        $tblM = $prefix . 'messages';
        $tblU = $prefix . 'users';
        $sql = "SELECT m.`message_id`, m.`message_sender`, m.`message_subject`, m.`message_text`,
                       m.`message_from`, m.`message_time`,
                       u.`user_name` AS `sender_user_name`
                FROM `{$tblM}` m
                LEFT JOIN `{$tblU}` u ON u.`user_id` = m.`message_sender`
                WHERE m.`message_receiver` = {$botId}
                  AND (
                    m.`message_type` = 4
                    OR (m.`message_type` = 5 AND m.`message_from` LIKE '%game.php?page=galaxy%')
                  )
                  AND m.`message_read` = 0
                  AND m.`message_sender` > 0
                  AND m.`message_sender` <> {$botId}
                ORDER BY m.`message_id` ASC
                LIMIT {$max}";

        $res = $db->query($sql);
        if (!$res) {
            $logs[] = 'llm_inbox: query encolado failed';

            return $logs;
        }
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
        $res->free();
        if ($rows === []) {
            return $logs;
        }

        $system = botLlmInboxSystemPrompt();

        foreach ($rows as $row) {
            $mid = (int) $row['message_id'];
            $dedupe = 'inbox:' . $mid;

            $incoming = [
                'message_id' => $mid,
                'sender_user_id' => (int) $row['message_sender'],
                'sender_user_name' => (string) ($row['sender_user_name'] ?? ''),
                'message_from' => (string) ($row['message_from'] ?? ''),
                'subject' => (string) ($row['message_subject'] ?? ''),
                'text' => (string) ($row['message_text'] ?? ''),
            ];
            $senderId = (int) $row['message_sender'];
            $senderName = (string) ($row['sender_user_name'] ?? '');
            $context = botLlmInboxBuildContextBlock(
                $db,
                $prefix,
                $botId,
                $botName,
                $senderId,
                $senderName,
                $profile,
                $profileName,
                $now
            );
            $userObj = [
                'skill' => 'inbox_reply_v1',
                'incoming_message' => $incoming,
                'context' => $context,
            ];
            $userJson = json_encode($userObj, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($userJson === false) {
                $logs[] = "llm_inbox: skip encolar message_id={$mid} (json)";

                continue;
            }

            $requestPayload = json_encode(
                [
                    'system' => $system,
                    'user' => $userJson,
                ],
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            );
            if ($requestPayload === false) {
                continue;
            }

            $jid = botLlmJobInsertPedido($db, $prefix, $botId, 'inbox_reply_v1', $dedupe, $requestPayload, $now);
            if ($jid > 0) {
                $logs[] = "llm_inbox: encolado job_id={$jid} message_id={$mid}";
            }
        }

        return $logs;
    }
}

if (!function_exists('botLlmInboxTick')) {
    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $profile
     *
     * @return list<string>
     */
    function botLlmInboxTick(
        mysqli $db,
        string $prefix,
        array $user,
        string $profileName,
        array $profile
    ): array {
        $logs = [];
        if (!botLlmIsEnabled()) {
            return $logs;
        }
        if (!botLlmJobTableExists($db, $prefix)) {
            $logs[] = 'llm_inbox: falta tabla bot_llm_job — ejecuta php scripts/migrate_create_bot_llm_job.php';

            return $logs;
        }

        $now = time();
        $logs = array_merge($logs, botLlmInboxApplyRespondidoJobs($db, $prefix, $user, $profileName, $profile, $now));
        $logs = array_merge($logs, botLlmInboxEnqueuePedidos($db, $prefix, $user, $profileName, $profile, $now));

        return $logs;
    }
}
