<?php

declare(strict_types=1);

/**
 * Per-bot logging helpers.
 *
 * Each bot writes to its own file under
 * `storage/logs/bots/<user_name>_YYYY-MM-DD.log` (daily rotation). Legacy
 * files `<user_name>.log` are still cleaned up by retention but new writes
 * always use the dated suffix so lines without a full date in the body
 * remain unambiguous.
 *
 * Old logs are removed automatically: files whose embedded date (or
 * mtime for legacy `.log`) is older than `BOT_LOG_RETENTION_DAYS` calendar
 * days are deleted. Cleanup runs at most every `BOT_LOG_CLEANUP_INTERVAL_SECONDS`
 * to avoid overhead on every log line.
 *
 * If the storage directory is not writable, logging is silently disabled to
 * avoid breaking the bot.
 */
if (!defined('BOT_LOG_RETENTION_DAYS')) {
    // Delete bot log files older than this many calendar midnights.
    // 0 = keep only today's logs (yesterday's are deleted at midnight).
    define('BOT_LOG_RETENTION_DAYS', 0);
}

if (!defined('BOT_LOG_CLEANUP_INTERVAL_SECONDS')) {
    // Minimum gap between automatic retention passes triggered from writes.
    define('BOT_LOG_CLEANUP_INTERVAL_SECONDS', 3600);
}

if (!function_exists('botLogDirectory')) {
    function botLogDirectory(): string
    {
        $base = dirname(__DIR__, 2) . '/storage/logs/bots';
        if (!is_dir($base)) {
            @mkdir($base, 0775, true);
        }

        return $base;
    }
}

if (!function_exists('botLogFile')) {
    /**
     * Path to today's (or given day's) log file for a bot user name.
     *
     * @param int|null $forDayTimestamp Unix ts used only to pick the calendar
     *                                   day (default: now).
     */
    function botLogFile(string $userName, ?int $forDayTimestamp = null): string
    {
        $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $userName) ?: 'unknown';
        $day = date('Y-m-d', $forDayTimestamp ?? time());

        return botLogDirectory() . '/' . $safeName . '_' . $day . '.log';
    }
}

if (!function_exists('botLogsCleanupExpired')) {
    /**
     * Deletes rotated bot logs older than the retention window.
     *
     * Dated files: `name_YYYY-MM-DD.log` — deleted if the embedded date is
     * strictly before the calendar day reached by subtracting
     * `$retentionDays` midnights from `$referenceTime`.
     *
     * Legacy files: `name.log` (no `_date` suffix) — deleted if mtime is
     * older than the same cutoff instant.
     *
     * @param int|null         $retentionDays null = BOT_LOG_RETENTION_DAYS
     * @param string|null      $directory     override directory (unit tests)
     * @param int|null         $referenceTime unix ts (tests)
     * @return array{deleted:int, bytes_freed:int, files:list<array{name:string, reason:string}>}
     */
    function botLogsCleanupExpired(?int $retentionDays = null, ?string $directory = null, ?int $referenceTime = null): array
    {
        $dir = $directory ?? botLogDirectory();
        $out = ['deleted' => 0, 'bytes_freed' => 0, 'files' => []];
        if (!is_dir($dir)) {
            return $out;
        }

        $days = $retentionDays ?? (defined('BOT_LOG_RETENTION_DAYS') ? (int) BOT_LOG_RETENTION_DAYS : 2);
        $days = max(0, $days);
        $now = $referenceTime ?? time();

        $cutoff = new \DateTimeImmutable('@' . $now);
        $cutoff = $cutoff->setTime(0, 0, 0)->modify('-' . $days . ' days');
        $cutoffStr = $cutoff->format('Y-m-d');
        $cutoffTs = $cutoff->getTimestamp();

        $pattern = $dir . '/*.log';
        foreach (glob($pattern) ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }
            $base = basename($path);
            if ($base === '' || (isset($base[0]) && $base[0] === '.')) {
                continue;
            }

            $shouldDelete = false;
            $reason = '';
            if (preg_match('/^(.+)_(\d{4}-\d{2}-\d{2})\.log$/', $base, $m)) {
                if ($m[2] < $cutoffStr) {
                    $shouldDelete = true;
                    $reason = 'dated<' . $cutoffStr;
                }
            } else {
                $mt = @filemtime($path) ?: 0;
                if ($mt > 0 && $mt < $cutoffTs) {
                    $shouldDelete = true;
                    $reason = 'legacy_mtime';
                }
            }

            if (!$shouldDelete) {
                continue;
            }

            $size = @filesize($path) ?: 0;
            if (@unlink($path)) {
                $out['deleted']++;
                $out['bytes_freed'] += (int) $size;
                $out['files'][] = ['name' => $base, 'reason' => $reason];
            }
        }

        return $out;
    }
}

if (!function_exists('botLogsCleanupMarkerPath')) {
    function botLogsCleanupMarkerPath(): string
    {
        return botLogDirectory() . '/.last_retention_cleanup';
    }
}

if (!function_exists('botLogsCleanupMaybe')) {
    /**
     * Throttled retention pass (invoked from botLogWriteRaw).
     */
    function botLogsCleanupMaybe(): void
    {
        $marker = botLogsCleanupMarkerPath();
        $interval = defined('BOT_LOG_CLEANUP_INTERVAL_SECONDS')
            ? (int) BOT_LOG_CLEANUP_INTERVAL_SECONDS
            : 3600;
        $now = time();
        $last = @filemtime($marker);
        if ($last !== false && ($now - $last) < max(60, $interval)) {
            return;
        }
        botLogsCleanupExpired();
        @touch($marker);
    }
}

if (!function_exists('botLogsForceCleanup')) {
    /**
     * Runs retention immediately (e.g. admin "clean now" button). Ignores
     * the throttle marker.
     *
     * @return array{deleted:int, bytes_freed:int, files:list<array{name:string, reason:string}>}
     */
    function botLogsForceCleanup(): array
    {
        $summary = botLogsCleanupExpired();
        @touch(botLogsCleanupMarkerPath());

        return $summary;
    }
}

if (!function_exists('botLogHeader')) {
    /**
     * Writes a session header for the bot to its log file.
     *
     * @param array<string, mixed> $state
     */
    function botLogHeader(string $userName, array $state, int $loopIndex, string $loopLabel): void
    {
        $line = sprintf(
            '[%s] ==== loop %d/%s | seed=%s archetype=%s personality=%s focus=%s actions_per_loop=%d ====',
            date('Y-m-d H:i:s'),
            $loopIndex,
            $loopLabel,
            (string) ($state['bot_seed'] ?? '?'),
            (string) ($state['bot_archetype'] ?? '?'),
            (string) ($state['bot_personality'] ?? '?'),
            (string) ($state['bot_current_focus'] ?? '?'),
            (int) ($state['bot_actions_per_loop'] ?? 0)
        );
        botLogWriteRaw($userName, $line);
    }
}

if (!function_exists('botLog')) {
    function botLog(string $userName, string $message): void
    {
        $line = '[' . date('H:i:s') . '] ' . $message;
        botLogWriteRaw($userName, $line);
    }
}

if (!function_exists('botLogWriteRaw')) {
    function botLogWriteRaw(string $userName, string $line): void
    {
        botLogsCleanupMaybe();
        $file = botLogFile($userName);
        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('botLogActions')) {
    /**
     * @param array<int, string> $logs
     */
    function botLogActions(string $userName, array $logs): void
    {
        if (empty($logs)) {
            return;
        }
        foreach ($logs as $entry) {
            botLog($userName, $entry);
        }
    }
}
