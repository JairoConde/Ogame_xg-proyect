<?php

declare(strict_types=1);

/**
 * Calor dirigido (observer → target): enemistad/amistad respecto a otra cuenta.
 * Reglas de paso (según especificación):
 *   - heat > 1: subir empeorar +1, suavizar/mejorar hacia 1 con −1
 *   - heat ≈ 1: empeorar +1, mejorar −0.1
 *   - heat < 1: ambos sentidos en pasos de 0.1
 * Rango [BOT_HEAT_MIN, BOT_HEAT_MAX].
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

if (!function_exists('botHeatDecayOneStepTowardNeutral')) {
    /** Tiempo sin choque: un paso hacia 1 (neutro). */
    function botHeatDecayOneStepTowardNeutral(float $heat): float
    {
        $h = botHeatClamp($heat);
        if ($h > 1.005) {
            return botHeatImprove($h);
        }
        if ($h < 0.995) {
            return botHeatWorsen($h);
        }

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
     * Hasta decay_batch filas del observer: un paso hacia 1 si llevan decay_sec sin actualizar.
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
               AND (`heat` > 1.005 OR `heat` < 0.995)
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
            $next = botHeatDecayOneStepTowardNeutral($h);
            if (abs($next - $h) < 0.0001) {
                continue;
            }
            botHeatUpsert($db, $prefix, $observerUserId, $tid, $next, $now);
            $n++;
        }
        $res->free();

        return $n;
    }
}
