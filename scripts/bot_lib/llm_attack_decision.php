<?php

declare(strict_types=1);

/**
 * Ataques de alto riesgo/rentabilidad: cola LLM asíncrona (attack_decision_v1).
 * Umbrales: BOT_LLM_ATTACK_NET_LOOT_MIN (500k), ratio ganancia/pérdidas > BOT_LLM_ATTACK_RATIO_GT (2).
 *
 * Fases: initial (antes de armar espía recheck) y recheck (tras simulación fresca).
 */

require_once __DIR__ . '/llm_jobs.php';
require_once __DIR__ . '/llm_inbox.php';
require_once __DIR__ . '/heat.php';
require_once __DIR__ . '/travel.php';

if (!defined('BOT_LLM_ATTACK_NET_LOOT_MIN')) {
    define('BOT_LLM_ATTACK_NET_LOOT_MIN', 500_000);
}
if (!defined('BOT_LLM_ATTACK_RATIO_GT')) {
    define('BOT_LLM_ATTACK_RATIO_GT', 2.0);
}

if (!function_exists('botLlmAttackIsHighStakes')) {
    function botLlmAttackIsHighStakes(int $netLoot, float $ratio): bool
    {
        return $netLoot > (int) BOT_LLM_ATTACK_NET_LOOT_MIN
            && $ratio > (float) BOT_LLM_ATTACK_RATIO_GT;
    }
}

if (!function_exists('botLlmAttackSkill')) {
    function botLlmAttackSkill(): string
    {
        return 'attack_decision_v1';
    }
}

if (!function_exists('botLlmAttackSystemPrompt')) {
    function botLlmAttackSystemPrompt(): string
    {
        return <<<'SYS'
Eres asesor estratégico de combate en un juego espacial (misiones de ataque tipo raid).
Recibes un JSON en el mensaje user con "phase" ("initial" o "recheck"), "strike" (simulación previa) y "context" (perfil, stats, alianzas, calor, presión de flotas/slots, colas de hangar/edificio, historial REAL de raids contra esa víctima si existe, etc.).
Tu tarea: decidir si merece la pena ejecutar el ataque ahora (riesgo vs beneficio, diplomacia, guerra/NAP, calor, diferencia de puntos, si el imperio ya tiene muchas flotas en vuelo o poco margen de slots, si las colas de astillero dificultan reponer pérdidas, y si el historial contra ESE jugador muestra raids costosas o fallidos). No hables de ACS ni flotas conjuntas; solo ataque en solitario.
Responde SOLO con un JSON válido (sin markdown), una sola pieza, esquema exacto:
{"attack":true|false,"reason_short":"..."}
- attack=true solo si conviene arriesgar la flota a la luz del contexto.
- attack=false si el riesgo diplomático, represalias, falta de slots/colas, mal historial reciente contra ese jugador, o incoherencias con el perfil del bot lo desaconsejan a pesar del ratio económico simulado.
reason_short: máximo ~200 caracteres, español.
SYS;
    }
}

if (!function_exists('botLlmAttackUserName')) {
    function botLlmAttackUserName(mysqli $db, string $prefix, int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }
        $res = $db->query(
            "SELECT `user_name` FROM `{$prefix}users` WHERE `user_id` = {$userId} LIMIT 1"
        );
        if (!$res) {
            return '';
        }
        $row = $res->fetch_assoc();
        $res->free();

        return is_array($row) ? (string) ($row['user_name'] ?? '') : '';
    }
}

if (!function_exists('botLlmAttackFilterRaidLogVsUser')) {
    /**
     * Entradas más recientes del log cuyo target_user_id coincide (máx. $limit).
     *
     * @param list<array<string, mixed>> $log
     * @return list<array<string, mixed>>
     */
    function botLlmAttackFilterRaidLogVsUser(array $log, int $victimUserId, int $limit): array
    {
        if ($victimUserId <= 0 || $limit <= 0 || $log === []) {
            return [];
        }
        $out = [];
        for ($i = count($log) - 1; $i >= 0 && count($out) < $limit; $i--) {
            $row = $log[$i];
            if (!is_array($row)) {
                continue;
            }
            if ((int) ($row['target_user_id'] ?? 0) === $victimUserId) {
                $out[] = $row;
            }
        }

        return $out;
    }
}

if (!function_exists('botLlmAttackSummarizeHangarBuild')) {
    /**
     * Resumen ligero de colas de hangar y de edificio en planetas del bot.
     *
     * @param array<int, array<string, mixed>> $planets
     * @return array<string, int>
     */
    function botLlmAttackSummarizeHangarBuild(array $planets): array
    {
        $withHang = 0;
        $hangBatches = 0;
        $withBuild = 0;
        foreach ($planets as $p) {
            if (!is_array($p)) {
                continue;
            }
            $hidRaw = (string) ($p['planet_b_hangar_id'] ?? '');
            $hid = trim($hidRaw, ",; \t\n\r\0\x0B");
            if ($hid !== '' && $hid !== '0') {
                $withHang++;
                $hangBatches += substr_count($hidRaw, ';');
            }
            $bid = trim((string) ($p['planet_b_building_id'] ?? ''), ",; \t\n\r\0\x0B");
            if ($bid !== '' && $bid !== '0') {
                $withBuild++;
            }
        }

        return [
            'planets_observed' => count($planets),
            'planets_with_hangar_queue' => $withHang,
            'approx_hangar_semicolon_batches' => $hangBatches,
            'planets_with_building_queue' => $withBuild,
        ];
    }
}

if (!function_exists('botLlmAttackSummarizeFleetAndRaids')) {
    /**
     * Slots de flota, flotas de ataque en seguimiento y historial de raids.
     *
     * @param array<string, mixed> $botQuirks
     * @return array<string, mixed>
     */
    function botLlmAttackSummarizeFleetAndRaids(
        mysqli $db,
        string $prefix,
        int $botUserId,
        array $researchRow,
        array $planets,
        array $botQuirks,
        int $victimUserId
    ): array {
        $slots = [
            'used' => 0,
            'max' => 0,
            'free' => 0,
            'note_es' => 'sin datos de investigación (computer tech) para calcular slots',
        ];
        if ($botUserId > 0 && $researchRow !== [] && function_exists('botAttackFleetSlotsInfo')) {
            $slots = botAttackFleetSlotsInfo($db, $prefix, $botUserId, $researchRow);
            $slots['interpretation_es'] = 'used = filas en fleets del bot (todas las misiones en vuelo); max = 1 + computer_tech; free = margen antes de que el motor rechace nuevas flotas.';
        }

        $inFlight = is_array($botQuirks['attacks_in_flight'] ?? null)
            ? $botQuirks['attacks_in_flight']
            : [];
        $outbound = 0;
        $returning = 0;
        foreach ($inFlight as $e) {
            if (!is_array($e)) {
                continue;
            }
            $st = (string) ($e['status'] ?? 'outbound');
            if ($st === 'returning') {
                $returning++;
            } else {
                $outbound++;
            }
        }

        $fullLog = is_array($botQuirks['raid_outcome_log'] ?? null)
            ? $botQuirks['raid_outcome_log']
            : [];
        $vsVictim = botLlmAttackFilterRaidLogVsUser($fullLog, $victimUserId, 8);
        $tailAny = $fullLog === [] ? [] : array_slice($fullLog, -3);

        return [
            'fleet_slots' => $slots,
            'tracked_attack_fleets' => [
                'outbound' => $outbound,
                'returning' => $returning,
                'total_tracked' => count($inFlight),
                'legend_es' => 'Solo misiones de ataque que el bot registró al lanzar; no incluye transportes u otras misiones.',
            ],
            'hangar_and_build_queues' => botLlmAttackSummarizeHangarBuild($planets),
            'raid_outcomes_vs_this_victim_newest_first' => $vsVictim,
            'recent_raid_outcomes_any_opponent_tail' => $tailAny,
            'raid_log_legend_es' => 'raid_outcome_log: resultados REALES al volver la flota (loot_real, pérdidas de naves en valor, outcome). vs_this_victim filtra por user_id del dueño del planeta atacado. Tail = últimas 3 entradas globales.',
        ];
    }
}

if (!function_exists('botLlmAttackBuildStrikeContext')) {
    /**
     * @param array<string, mixed> $profile
     *
     * @return array<string, mixed>
     */
    function botLlmAttackBuildStrikeContext(
        mysqli $db,
        string $prefix,
        int $botUserId,
        string $botName,
        int $victimUserId,
        string $victimName,
        array $profile,
        string $profileName,
        int $now,
        string $phase,
        int $targetPlanetId,
        string $targetCoords,
        int $netLoot,
        int $myLosses,
        float $ratio,
        float $ratioThreshold,
        int $minLoot,
        string $winner,
        int $simRounds,
        array $researchRow = [],
        array $planets = [],
        array $botQuirks = []
    ): array {
        $since = $now - botLlmInboxContextWindowSeconds();
        $windowHours = (int) round(botLlmInboxContextWindowSeconds() / 3600);

        $botAlly = 0;
        $victimAlly = 0;
        $rb = $db->query("SELECT `user_ally_id` FROM `{$prefix}users` WHERE `user_id` = {$botUserId} LIMIT 1");
        if ($rb && ($xb = $rb->fetch_assoc())) {
            $botAlly = (int) ($xb['user_ally_id'] ?? 0);
        }
        if ($rb) {
            $rb->free();
        }
        $rv = $db->query("SELECT `user_ally_id` FROM `{$prefix}users` WHERE `user_id` = {$victimUserId} LIMIT 1");
        if ($rv && ($xv = $rv->fetch_assoc())) {
            $victimAlly = (int) ($xv['user_ally_id'] ?? 0);
        }
        if ($rv) {
            $rv->free();
        }

        $style = (string) ($profile['bot_style'] ?? 'granja');
        $tags = botLlmInboxAllianceTags($db, $prefix, $botAlly, $victimAlly);
        $persona = botLlmInboxBotPersonaFromDb($db, $prefix, $botUserId);

        $relation = 'sin_alianza_ambos';
        $relationEs = 'Ninguno en alianza o solo uno.';
        if ($botAlly > 0 && $botAlly === $victimAlly) {
            $relation = 'misma_alianza';
            $t = $tags[$botAlly]['tag'] ?? '';
            $relationEs = 'Misma alianza' . ($t !== '' ? " ({$t})" : '') . '.';
        } elseif ($botAlly > 0 && $victimAlly > 0) {
            $status = botLlmInboxDiplomacyStatusBetween($db, $prefix, $botAlly, $victimAlly, $now);
            $atWar = function_exists('botDiplomacyIsAtWar') && botDiplomacyIsAtWar($db, $prefix, $botAlly, $victimAlly);
            $pressure = botLlmInboxDiplomacyPressureMax($db, $prefix, $botAlly, $victimAlly);
            if ($atWar || $status === 'war') {
                $relation = 'guerra';
                $relationEs = 'Alianzas en guerra o estado war.';
            } elseif ($status === 'nap') {
                $relation = 'nap';
                $relationEs = 'Pacto NAP entre alianzas.';
            } elseif ($status === 'neutral') {
                $relation = 'neutral';
                $relationEs = 'Neutro registrado.';
            } else {
                $relation = 'distintas';
                $relationEs = 'Alianzas distintas sin fila explícita.';
            }
            if ($pressure !== null && $pressure > 0) {
                $relationEs .= ' Presión diplomática aprox.: ' . $pressure . '.';
            }
        }

        $hostileFleet = botLlmInboxHostileFleetBetweenSince($db, $prefix, $victimUserId, $botUserId, $since);
        $battleReport = botLlmInboxReportsInvolvingBothSince($db, $prefix, $botUserId, $victimUserId, $since);

        $heatBotToVictim = botHeatGet($db, $prefix, $botUserId, $victimUserId);
        $heatVictimToBot = botHeatGet($db, $prefix, $victimUserId, $botUserId);

        return [
            'window_hours' => $windowHours,
            'phase' => $phase,
            'strike' => [
                'target_planet_id' => $targetPlanetId,
                'target_coords' => $targetCoords,
                'victim_user_id' => $victimUserId,
                'victim_user_name' => $victimName,
                'sim_winner' => $winner,
                'sim_rounds_used' => $simRounds,
                'net_loot_estimate' => $netLoot,
                'attacker_losses_value_estimate' => $myLosses,
                'profit_to_loss_ratio' => round($ratio, 4),
                'ratio_threshold_profile' => round($ratioThreshold, 4),
                'min_loot_gate' => $minLoot,
            ],
            'attacker_bot' => [
                'user_id' => $botUserId,
                'user_name' => $botName,
                'profile_name' => $profileName,
                'stats_points_and_ranks' => botLlmInboxUserStatsPointsAndRanks($db, $prefix, $botUserId),
                'alliance' => [
                    'ally_id' => $botAlly,
                    'tag' => $tags[$botAlly]['tag'] ?? '',
                    'name' => $tags[$botAlly]['name'] ?? '',
                ],
                'persona' => [
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
            'victim_player' => [
                'user_id' => $victimUserId,
                'user_name' => $victimName,
                'stats_points_and_ranks' => botLlmInboxUserStatsPointsAndRanks($db, $prefix, $victimUserId),
                'alliance' => [
                    'ally_id' => $victimAlly,
                    'tag' => $tags[$victimAlly]['tag'] ?? '',
                    'name' => $tags[$victimAlly]['name'] ?? '',
                ],
            ],
            'pair_heat' => [
                'legend_es' => 'heat=1 neutro; >1 el bot ve más enemistad hacia la víctima; <1 más amistad. Rango 0.1–10.',
                'bot_to_victim' => $heatBotToVictim,
                'victim_to_bot' => $heatVictimToBot,
            ],
            'alliances' => [
                'relation_key' => $relation,
                'summary_es' => $relationEs,
            ],
            'recent_hostility_window' => [
                'victim_hostile_fleet_to_bot' => $hostileFleet,
                'combat_report_both_in_window' => $battleReport,
            ],
            'fleet_production_and_raid_history' => botLlmAttackSummarizeFleetAndRaids(
                $db,
                $prefix,
                $botUserId,
                $researchRow,
                $planets,
                $botQuirks,
                $victimUserId
            ),
        ];
    }
}

if (!function_exists('botLlmAttackParseModelJson')) {
    /**
     * @return array{attack: bool, reason_short: string}|null
     */
    function botLlmAttackParseModelJson(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $jsonFlags = defined('JSON_INVALID_UTF8_IGNORE') ? JSON_INVALID_UTF8_IGNORE : 0;
        $decoded = json_decode($raw, true, 512, $jsonFlags);
        if (is_array($decoded) && array_key_exists('attack', $decoded)) {
            return [
                'attack' => (bool) $decoded['attack'],
                'reason_short' => isset($decoded['reason_short']) ? (string) $decoded['reason_short'] : '',
            ];
        }
        if (preg_match('/\{[^{}]*"attack"\s*:\s*(true|false)[^{}]*\}/is', $raw, $m)) {
            $decoded = json_decode($m[0], true, 512, $jsonFlags);
            if (is_array($decoded) && array_key_exists('attack', $decoded)) {
                return [
                    'attack' => (bool) $decoded['attack'],
                    'reason_short' => isset($decoded['reason_short']) ? (string) $decoded['reason_short'] : '',
                ];
            }
        }

        return null;
    }
}

if (!function_exists('botLlmAttackExtractDecisionFromResponsePayload')) {
    /**
     * @return array{attack: bool, reason_short: string}|null
     */
    function botLlmAttackExtractDecisionFromResponsePayload(string $responsePayload): ?array
    {
        $jsonFlags = defined('JSON_INVALID_UTF8_IGNORE') ? JSON_INVALID_UTF8_IGNORE : 0;
        $wrap = json_decode($responsePayload, true, 512, $jsonFlags);
        if (!is_array($wrap)) {
            return null;
        }
        $raw = (string) ($wrap['raw_model_text'] ?? '');

        return botLlmAttackParseModelJson($raw);
    }
}

if (!function_exists('botLlmAttackTryEnqueue')) {
    /**
     * @param array<string, mixed> $profile
     *
     * @return 'queued'|'duplicate'|'fail'
     */
    function botLlmAttackTryEnqueue(
        mysqli $db,
        string $prefix,
        int $botUserId,
        string $botName,
        string $dedupeKey,
        array $profile,
        string $profileName,
        int $now,
        string $phase,
        int $targetPlanetId,
        string $targetCoords,
        int $victimUserId,
        string $victimName,
        int $netLoot,
        int $myLosses,
        float $ratio,
        float $ratioThreshold,
        int $minLoot,
        string $simWinner,
        int $simRounds,
        array $researchRow = [],
        array $planets = [],
        array $botQuirks = []
    ): string {
        if ($botUserId <= 0 || !botLlmIsEnabled() || !botLlmJobTableExists($db, $prefix)) {
            return 'fail';
        }

        $ctx = botLlmAttackBuildStrikeContext(
            $db,
            $prefix,
            $botUserId,
            $botName,
            $victimUserId,
            $victimName,
            $profile,
            $profileName,
            $now,
            $phase,
            $targetPlanetId,
            $targetCoords,
            $netLoot,
            $myLosses,
            $ratio,
            $ratioThreshold,
            $minLoot,
            $simWinner,
            $simRounds,
            $researchRow,
            $planets,
            $botQuirks
        );

        $userObj = [
            'skill' => botLlmAttackSkill(),
            'context' => $ctx,
        ];
        $userJson = json_encode($userObj, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($userJson === false) {
            return 'fail';
        }

        $requestPayload = json_encode(
            [
                'system' => botLlmAttackSystemPrompt(),
                'user' => $userJson,
            ],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($requestPayload === false) {
            return 'fail';
        }

        $jid = botLlmJobInsertPedido($db, $prefix, $botUserId, botLlmAttackSkill(), $dedupeKey, $requestPayload, $now);
        if ($jid > 0) {
            return 'queued';
        }

        return 'duplicate';
    }
}

if (!function_exists('botLlmAttackApplyRespondidoJobs')) {
    /**
     * @param array<int, array<string, mixed>> $planets
     * @param array<string, mixed> $profile
     *
     * @return array{logs: list<string>, metrics_delta: array<string, int>}
     */
    function botLlmAttackApplyRespondidoJobs(
        mysqli $db,
        string $prefix,
        array $user,
        array $planets,
        array &$state,
        array &$shipsByPlanet,
        array $profile,
        string $profileName,
        array $researchRow,
        array $pricelist,
        float $universeSpeed,
        int $now
    ): array {
        $out = ['logs' => [], 'metrics_delta' => []];
        $botId = (int) ($user['user_id'] ?? 0);
        $botName = (string) ($user['user_name'] ?? '');
        if ($botId <= 0 || !botLlmIsEnabled() || !botLlmJobTableExists($db, $prefix)) {
            return $out;
        }

        $tblJ = botLlmJobTableName($prefix);
        $skillEsc = $db->real_escape_string(botLlmAttackSkill());
        $max = 6;
        $res = $db->query(
            "SELECT * FROM `{$tblJ}`
             WHERE `bot_user_id` = {$botId}
               AND `skill` = '{$skillEsc}'
               AND `status` = 'respondido'
             ORDER BY `job_id` ASC
             LIMIT {$max}"
        );
        if (!$res) {
            return $out;
        }
        $jobs = [];
        while ($j = $res->fetch_assoc()) {
            $jobs[] = $j;
        }
        $res->free();

        $quirks = is_array($state['bot_quirks'] ?? null) ? $state['bot_quirks'] : [];
        $gates = is_array($quirks['attack_llm_gate'] ?? null) ? $quirks['attack_llm_gate'] : [];
        $pending = is_array($quirks['pending_attacks'] ?? null) ? $quirks['pending_attacks'] : [];

        foreach ($jobs as $job) {
            $jobId = (int) $job['job_id'];
            $decision = botLlmAttackExtractDecisionFromResponsePayload((string) ($job['response_payload'] ?? ''));
            if ($decision === null) {
                botLlmJobMarkFallidoFromRespondido($db, $prefix, $jobId, 'JSON modelo inválido', $now);
                $out['logs'][] = "llm_attack: job {$jobId} fallido (parse)";
                $out['metrics_delta']['llm_attack_apply_fail']
                    = ($out['metrics_delta']['llm_attack_apply_fail'] ?? 0) + 1;

                continue;
            }

            $req = json_decode((string) ($job['request_payload'] ?? ''), true, 512, JSON_INVALID_UTF8_IGNORE);
            if (!is_array($req)) {
                botLlmJobMarkDone($db, $prefix, $jobId, $now);

                continue;
            }
            $inner = json_decode((string) ($req['user'] ?? ''), true, 512, JSON_INVALID_UTF8_IGNORE);
            $ctxInner = is_array($inner['context'] ?? null) ? $inner['context'] : [];
            $phase = isset($ctxInner['phase']) ? (string) $ctxInner['phase'] : 'initial';
            if ($phase === '') {
                $phase = 'initial';
            }
            $dedupe = (string) ($job['dedupe_key'] ?? '');

            if (!$decision['attack']) {
                if ($phase === 'initial') {
                    $tpid = (int) ($inner['context']['strike']['target_planet_id'] ?? 0);
                    if ($tpid > 0 && isset($gates[$tpid])) {
                        unset($gates[$tpid]);
                    }
                } else {
                    foreach ($pending as $tpid => $plan) {
                        $lr = $plan['llm_recheck'] ?? null;
                        if (is_array($lr) && (string) ($lr['dedupe'] ?? '') === $dedupe) {
                            $shipMix = is_array($plan['ship_mix'] ?? null) ? $plan['ship_mix'] : [];
                            $sourcePid = (int) ($plan['source_planet_id'] ?? 0);
                            if ($sourcePid > 0 && isset($shipsByPlanet[$sourcePid])) {
                                foreach ($shipMix as $shipId => $cnt) {
                                    $shipsByPlanet[$sourcePid][(int) $shipId]
                                        = (int) ($shipsByPlanet[$sourcePid][(int) $shipId] ?? 0) + (int) $cnt;
                                }
                            }
                            unset($pending[$tpid]);
                            $out['logs'][] = "llm_attack: recheck LLM rechaza target={$tpid} ({$decision['reason_short']})";
                            $out['metrics_delta']['llm_attack_recheck_abort']
                                = ($out['metrics_delta']['llm_attack_recheck_abort'] ?? 0) + 1;

                            break;
                        }
                    }
                }
                botLlmJobMarkDone($db, $prefix, $jobId, $now);
                $out['logs'][] = 'llm_attack: job ' . $jobId . ' aplicado (no atacar)';
                $out['metrics_delta']['llm_attack_declined']
                    = ($out['metrics_delta']['llm_attack_declined'] ?? 0) + 1;

                continue;
            }

            // attack === true
            if ($phase === 'initial') {
                $tpid = (int) ($inner['context']['strike']['target_planet_id'] ?? 0);
                $gate = is_array($gates[$tpid] ?? null) ? $gates[$tpid] : null;
                if ($gate === null || $tpid <= 0) {
                    botLlmJobMarkDone($db, $prefix, $jobId, $now);
                    $out['logs'][] = "llm_attack: job {$jobId} sin gate, ignorado";

                    continue;
                }
                $target = botAttackFindTargetRowFromCandidates($db, $prefix, $botId, $tpid);
                if ($target === null) {
                    unset($gates[$tpid]);
                    botLlmJobMarkDone($db, $prefix, $jobId, $now);
                    $out['logs'][] = "llm_attack: job {$jobId} objetivo {$tpid} ya no existe";

                    continue;
                }
                $sourcePid = (int) ($gate['source_planet_id'] ?? 0);
                $shipMix = is_array($gate['ship_mix'] ?? null) ? $gate['ship_mix'] : [];
                $sourcePlanet = null;
                foreach ($planets as $p) {
                    if ((int) $p['planet_id'] === $sourcePid) {
                        $sourcePlanet = $p;

                        break;
                    }
                }
                if ($sourcePlanet === null || $shipMix === []) {
                    unset($gates[$tpid]);
                    botLlmJobMarkDone($db, $prefix, $jobId, $now);
                    $out['logs'][] = "llm_attack: job {$jobId} sin fuente o mix";

                    continue;
                }

                $probeCount = defined('BOT_SPY_PROBES_PER_MISSION') ? (int) BOT_SPY_PROBES_PER_MISSION : 4;
                $recheckFleetId = botAttackInsertSpy(
                    $db,
                    $prefix,
                    $user,
                    $sourcePlanet,
                    $target,
                    $probeCount,
                    $researchRow,
                    $universeSpeed
                );
                if ($recheckFleetId === null) {
                    unset($gates[$tpid]);
                    botLlmJobMarkDone($db, $prefix, $jobId, $now);
                    $out['logs'][] = "llm_attack: job {$jobId} insert spy falló";

                    continue;
                }

                $sourceCoordsForRecheck = [
                    'galaxy' => (int) $sourcePlanet['planet_galaxy'],
                    'system' => (int) $sourcePlanet['planet_system'],
                    'planet' => (int) $sourcePlanet['planet_planet'],
                ];
                $targetCoordsForRecheck = [
                    'galaxy' => (int) $target['planet_galaxy'],
                    'system' => (int) $target['planet_system'],
                    'planet' => (int) $target['planet_planet'],
                ];
                $recheckEstimate = botTravelEstimate(
                    $sourceCoordsForRecheck,
                    $targetCoordsForRecheck,
                    [210 => $probeCount],
                    $researchRow,
                    $universeSpeed
                );
                $recheckArrival = $now + max(1, (int) $recheckEstimate['duration']);

                $pending[$tpid] = [
                    'armed_at' => $now,
                    'recheck_fleet_id' => (int) $recheckFleetId,
                    'recheck_arrival' => $recheckArrival,
                    'source_planet_id' => $sourcePid,
                    'ship_mix' => $shipMix,
                    'ratio_threshold' => (float) ($gate['ratio_threshold'] ?? 0.0),
                    'min_loot' => (int) ($gate['min_loot'] ?? 0),
                    'estimated_loot' => (int) ($gate['estimated_loot'] ?? 0),
                    'estimated_losses' => (int) ($gate['estimated_losses'] ?? 0),
                    'target_planet_id' => $tpid,
                    'llm_high_stakes' => true,
                ];
                if (isset($shipsByPlanet[$sourcePid][210])) {
                    $shipsByPlanet[$sourcePid][210] = max(
                        0,
                        (int) $shipsByPlanet[$sourcePid][210] - $probeCount
                    );
                }
                unset($gates[$tpid]);
                botLlmJobMarkDone($db, $prefix, $jobId, $now);
                $out['logs'][] = "llm_attack: job {$jobId} aprobado, armado recheck target={$tpid}";
                $out['metrics_delta']['llm_attack_armed_after_llm']
                    = ($out['metrics_delta']['llm_attack_armed_after_llm'] ?? 0) + 1;
            } else {
                // recheck approve: clear llm_recheck barrier
                $matched = false;
                foreach ($pending as $tpid => $plan) {
                    $lr = $plan['llm_recheck'] ?? null;
                    if (is_array($lr) && (string) ($lr['dedupe'] ?? '') === $dedupe) {
                        unset($pending[$tpid]['llm_recheck']);
                        $pending[$tpid]['llm_recheck_ok'] = true;
                        $matched = true;
                        $out['logs'][] = "llm_attack: job {$jobId} recheck aprobado target={$tpid}";
                        $out['metrics_delta']['llm_attack_recheck_ok']
                            = ($out['metrics_delta']['llm_attack_recheck_ok'] ?? 0) + 1;

                        break;
                    }
                }
                if (!$matched) {
                    $out['logs'][] = "llm_attack: job {$jobId} recheck sin plan coincidente";
                }
                botLlmJobMarkDone($db, $prefix, $jobId, $now);
            }
        }

        $quirks['attack_llm_gate'] = $gates;
        $quirks['pending_attacks'] = $pending;
        $state['bot_quirks'] = $quirks;

        return $out;
    }
}

if (!function_exists('botAttackFindTargetRowFromCandidates')) {
    /**
     * @return array<string, mixed>|null
     */
    function botAttackFindTargetRowFromCandidates(mysqli $db, string $prefix, int $botUserId, int $targetPlanetId): ?array
    {
        if ($targetPlanetId <= 0) {
            return null;
        }
        $res = $db->query(
            "SELECT p.*, u.`user_id`, u.`user_name`, u.`user_ally_id`
             FROM `{$prefix}planets` AS p
             INNER JOIN `{$prefix}users` AS u ON u.`user_id` = p.`planet_user_id`
             WHERE p.`planet_id` = {$targetPlanetId}
               AND p.`planet_user_id` <> {$botUserId}
             LIMIT 1"
        );
        if (!$res) {
            return null;
        }
        $row = $res->fetch_assoc();
        $res->free();

        return is_array($row) ? $row : null;
    }
}
