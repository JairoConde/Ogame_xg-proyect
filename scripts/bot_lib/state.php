<?php

declare(strict_types=1);

/**
 * Bot persistent state library.
 *
 * Provides:
 *  - botSeed(): deterministic per-bot seed, stable across runs.
 *  - botRng(): deterministic float [0,1) keyed by (seed, key).
 *  - botRngInt(): deterministic integer in [min,max] keyed by (seed, key).
 *  - botRngPick(): deterministic pick from a list keyed by (seed, key).
 *  - loadBotState() / saveBotState(): hydrate/persist the bot_state row.
 *  - ensureBotState(): create + initialize on first run for a given user.
 *
 * Notes:
 *  - The seed is derived from user_id + user_name so it is stable but unique.
 *  - botRng uses keyed hashing (sha256 truncated) so distinct keys produce
 *    independent streams while remaining reproducible.
 *  - JSON columns are decoded eagerly into associative arrays at load time.
 */
if (!function_exists('botSeed')) {
    function botSeed(int $userId, string $userName): int
    {
        // 64-bit positive seed derived from a stable hash of user identity.
        $hash = hash('sha256', $userId . '|' . $userName);
        $hex = substr($hash, 0, 15);

        return (int) hexdec($hex);
    }
}

if (!function_exists('botRng')) {
    function botRng(int $seed, string $key): float
    {
        $hash = hash('sha256', $seed . '|' . $key);
        $hex = substr($hash, 0, 13);
        $int = hexdec($hex);

        return (float) ($int / hexdec('fffffffffffff'));
    }
}

if (!function_exists('botRngInt')) {
    function botRngInt(int $seed, string $key, int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }
        $r = botRng($seed, $key);

        return $min + (int) floor($r * ($max - $min + 1));
    }
}

if (!function_exists('botRngPick')) {
    /**
     * Deterministic pick from a non-empty array.
     * @template T
     * @param array<int, T> $options
     * @return T
     */
    function botRngPick(int $seed, string $key, array $options)
    {
        if (empty($options)) {
            throw new InvalidArgumentException('botRngPick: empty options');
        }
        $values = array_values($options);
        $idx = botRngInt($seed, $key, 0, count($values) - 1);

        return $values[$idx];
    }
}

if (!function_exists('botRngShuffle')) {
    /**
     * Deterministic shuffle (Fisher-Yates with botRng).
     * @param array<int, mixed> $list
     * @return array<int, mixed>
     */
    function botRngShuffle(int $seed, string $key, array $list): array
    {
        $values = array_values($list);
        $n = count($values);
        for ($i = $n - 1; $i > 0; $i--) {
            $j = botRngInt($seed, $key . ':sh:' . $i, 0, $i);
            $tmp = $values[$i];
            $values[$i] = $values[$j];
            $values[$j] = $tmp;
        }

        return $values;
    }
}

if (!function_exists('botRngWeightedPick')) {
    /**
     * Deterministic weighted pick.
     * @param array<int, array{weight: float, value: mixed}> $candidates
     * @return mixed|null
     */
    function botRngWeightedPick(int $seed, string $key, array $candidates)
    {
        $total = 0.0;
        foreach ($candidates as $c) {
            $w = (float) ($c['weight'] ?? 0.0);
            if ($w > 0) {
                $total += $w;
            }
        }
        if ($total <= 0) {
            return null;
        }
        $roll = botRng($seed, $key) * $total;
        $acc = 0.0;
        foreach ($candidates as $c) {
            $w = (float) ($c['weight'] ?? 0.0);
            if ($w <= 0) {
                continue;
            }
            $acc += $w;
            if ($roll <= $acc) {
                return $c['value'] ?? null;
            }
        }

        // Fallback (should not happen due to floating point edge).
        return $candidates[count($candidates) - 1]['value'] ?? null;
    }
}

if (!function_exists('botStateTable')) {
    function botStateTable(string $prefix): string
    {
        return $prefix . 'bot_state';
    }
}

if (!function_exists('loadBotState')) {
    /**
     * @return array<string, mixed>|null
     */
    function loadBotState(mysqli $db, string $prefix, int $userId): ?array
    {
        $table = botStateTable($prefix);
        $sql = "SELECT * FROM `{$table}` WHERE `bot_user_id` = " . $userId . ' LIMIT 1';
        $result = $db->query($sql);
        if (!$result) {
            return null;
        }
        $row = $result->fetch_assoc();
        $result->free();
        if (!is_array($row)) {
            return null;
        }
        foreach (['bot_personal_targets', 'bot_defense_recipe', 'bot_research_order', 'bot_planet_roles', 'bot_quirks'] as $jsonCol) {
            $raw = $row[$jsonCol] ?? null;
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                $row[$jsonCol] = is_array($decoded) ? $decoded : [];
            } else {
                $row[$jsonCol] = [];
            }
        }

        return $row;
    }
}

if (!function_exists('saveBotStateFields')) {
    /**
     * Updates a subset of fields on bot_state. JSON-typed fields can be passed
     * as arrays and will be encoded automatically.
     *
     * @param array<string, mixed> $fields
     */
    function saveBotStateFields(mysqli $db, string $prefix, int $userId, array $fields): bool
    {
        if (empty($fields)) {
            return true;
        }

        $jsonCols = ['bot_personal_targets', 'bot_defense_recipe', 'bot_research_order', 'bot_planet_roles', 'bot_quirks'];
        $sets = [];
        foreach ($fields as $col => $value) {
            $safeCol = preg_replace('/[^a-z0-9_]/i', '', (string) $col);
            if ($safeCol === '' || $safeCol !== $col) {
                continue;
            }
            if (in_array($safeCol, $jsonCols, true)) {
                $encoded = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value;
                $sets[] = "`{$safeCol}` = '" . $db->real_escape_string((string) $encoded) . "'";
            } elseif (is_int($value) || is_float($value)) {
                $sets[] = "`{$safeCol}` = " . $value;
            } elseif ($value === null) {
                $sets[] = "`{$safeCol}` = NULL";
            } else {
                $sets[] = "`{$safeCol}` = '" . $db->real_escape_string((string) $value) . "'";
            }
        }

        if (empty($sets)) {
            return true;
        }

        $sets[] = '`bot_updated_at` = ' . time();

        $table = botStateTable($prefix);
        $sql = "UPDATE `{$table}` SET " . implode(', ', $sets) . ' WHERE `bot_user_id` = ' . $userId . ' LIMIT 1';

        return (bool) $db->query($sql);
    }
}

if (!function_exists('ensureBotState')) {
    /**
     * Ensures a bot_state row exists for the user. If missing, creates one
     * with defaults derived from the seed (archetype, personality and base
     * caps). Loaders for personal_targets / defense_recipe / research_order
     * will populate those fields lazily later (see personality module).
     *
     * @return array<string, mixed>
     */
    function ensureBotState(
        mysqli $db,
        string $prefix,
        int $userId,
        string $userName,
        string $botStyle
    ): array {
        $existing = loadBotState($db, $prefix, $userId);
        if ($existing !== null) {
            return $existing;
        }

        $seed = botSeed($userId, $userName);

        // Archetype is orthogonal to bot_style. Distribution is biased so most
        // bots are 'balanced' but each universe has a healthy variety.
        $archetype = botRngWeightedPick($seed, 'archetype', [
            ['weight' => 4.0, 'value' => 'balanced'],
            ['weight' => 2.0, 'value' => 'turtle'],
            ['weight' => 2.0, 'value' => 'turbo'],
            ['weight' => 1.5, 'value' => 'opportunist'],
        ]) ?? 'balanced';

        // Personality (the *base trait* that persists even when focus rotates).
        // It biases priorities permanently. Distribution depends on bot_style.
        switch ($botStyle) {
            case 'raider':
                $personalityWeights = [
                    ['weight' => 3.0, 'value' => 'flotero'],
                    ['weight' => 1.5, 'value' => 'cazador'],
                    ['weight' => 1.0, 'value' => 'tecnologico'],
                    ['weight' => 0.5, 'value' => 'minero'],
                ];

                break;
            case 'bunker':
                $personalityWeights = [
                    ['weight' => 3.0, 'value' => 'defensor'],
                    ['weight' => 1.5, 'value' => 'tecnologico'],
                    ['weight' => 1.0, 'value' => 'minero'],
                    ['weight' => 0.5, 'value' => 'flotero'],
                ];

                break;
            default: // granja
                $personalityWeights = [
                    ['weight' => 3.0, 'value' => 'minero'],
                    ['weight' => 1.5, 'value' => 'tecnologico'],
                    ['weight' => 1.0, 'value' => 'flotero'],
                    ['weight' => 0.5, 'value' => 'defensor'],
                ];

                break;
        }
        $personality = botRngWeightedPick($seed, 'personality', $personalityWeights) ?? 'minero';

        // Per-bot loop activity tunables (seeded but bounded).
        $actionsPerLoop = botRngInt($seed, 'actions_per_loop', 1, 4);
        $skipChanceX100 = botRngInt($seed, 'skip_chance', 5, 30);

        $now = time();
        $table = botStateTable($prefix);
        $insertSql = "INSERT INTO `{$table}` (
            `bot_user_id`, `bot_seed`, `bot_archetype`, `bot_personality`,
            `bot_actions_per_loop`, `bot_skip_chance_x100`,
            `bot_current_focus`, `bot_focus_started_at`, `bot_focus_until`,
            `bot_created_at`, `bot_updated_at`
        ) VALUES (
            " . $userId . ',
            ' . $seed . ",
            '" . $db->real_escape_string($archetype) . "',
            '" . $db->real_escape_string($personality) . "',
            " . $actionsPerLoop . ',
            ' . $skipChanceX100 . ",
            'eco',
            " . $now . ',
            ' . ($now + 3 * 24 * 3600) . ',
            ' . $now . ',
            ' . $now . '
        )';

        if (!$db->query($insertSql)) {
            // If an error occurred (race or table missing), bubble up by
            // returning a synthetic in-memory state. The caller must handle
            // saves carefully; downstream code will degrade gracefully.
            return [
                'bot_user_id' => $userId,
                'bot_seed' => $seed,
                'bot_archetype' => $archetype,
                'bot_personality' => $personality,
                'bot_personal_targets' => [],
                'bot_defense_recipe' => [],
                'bot_research_order' => [],
                'bot_planet_roles' => [],
                'bot_quirks' => [],
                'bot_current_focus' => 'eco',
                'bot_focus_started_at' => $now,
                'bot_focus_until' => $now + 3 * 24 * 3600,
                'bot_actions_per_loop' => $actionsPerLoop,
                'bot_skip_chance_x100' => $skipChanceX100,
                'bot_last_attacked_at' => 0,
                'bot_attack_reactive_until' => 0,
                'bot_long_term_goal' => null,
                'bot_long_term_progress' => 0,
                'bot_long_term_target' => 0,
                'bot_total_actions' => 0,
                'bot_last_action_at' => 0,
                'bot_next_session_at' => 0,
                'bot_session_actions_left' => 0,
                'bot_last_session_end' => 0,
                'bot_active_window_start' => 0,
                'bot_active_window_end' => 23,
                '__synthetic' => true,
            ];
        }

        $loaded = loadBotState($db, $prefix, $userId);
        if ($loaded === null) {
            throw new RuntimeException("Failed to load freshly inserted bot_state for user {$userId}");
        }

        return $loaded;
    }
}
