<?php

declare(strict_types=1);

namespace App\Http\Controllers\Adm;

use App\Core\BaseController;
use App\Core\Database;
use App\Libraries\Adm\AdministrationLib as Administration;
use App\Libraries\FormatLib as Format;

class BotstatsController extends BaseController
{
    /**
     * Permissions in `admin_permissions` use short keys such as `tasks` or
     * `errors`, but Administration::authorization(__CLASS__) compares against
     * `taskscontroller`. Using a synthetic FQCN ending in `\Botstats` makes
     * the cleaned module name `botstats`, which matches the JSON key.
     */
    private const PERMISSION_CLASS_SYNTHETIC = 'App\\Http\\Controllers\\Adm\\Botstats';

    /** @var list<string> */
    private const METRIC_COLUMNS = [
        'loops_processed',
        'spies_sent',
        'attacks_launched',
        'attacks_armed',
        'attacks_recheck_confirmed',
        'attacks_aborted_sim_lose',
        'attacks_aborted_low_loot',
        'attacks_completed',
        'total_loot_returned',
        'real_ships_lost_value',
        'cargo_pressure_signals',
        'big_cargos_queued_under_pressure',
        'fleet_slot_full_events',
        'spies_skipped_no_slot',
        'attacks_skipped_no_slot',
        'alliances_created',
        'alliances_dissolved',
        'alliance_applications_sent',
        'alliance_applications_accepted',
        'alliance_applications_rejected_received',
        'alliance_applicants_accepted',
        'alliance_applicants_rejected',
        'alliance_members_kicked',
        'alliance_left_voluntary',
        'alliance_skip_cooldown',
        'alliance_skip_no_candidate',
        'alliance_desperate_applies',
        'alliance_request_expired',
        'alliances_ownership_transferred',
        'alliances_ownership_received',
        'alliances_dissolved_for_better_alt',
        'alliance_create_anti_rebound_skips',
        'ally_bot_transports_sent',
        'ally_human_need_requests_sent',
        'ally_human_request_fulfilled',
        'acs_groups_created',
        'acs_groups_joined',
        'acs_skipped_no_war',
        'acs_skipped_no_target',
        'acs_skipped_no_fleet',
        'acs_skipped_late',
        'acs_insert_failed',
        'diplo_pressure_recorded',
        'diplo_war_declared',
        'diplo_peace_proposed',
        'diplo_peace_accepted',
        'diplo_peace_bribed',
        'diplo_nap_proposed',
        'diplo_nap_accepted',
        'diplo_nap_broken',
        'diplo_cooldown_applied',
    ];

    private function prettyDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '—';
        }
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            return floor($seconds / 60) . 'm ' . ($seconds % 60) . 's';
        }
        $h = floor($seconds / 3600);
        $m = floor(($seconds % 3600) / 60);

        return $h . 'h ' . $m . 'm';
    }

    private string $alert = '';

    public function __construct()
    {
        parent::__construct();

        Administration::checkSession();

        parent::loadLang(['adm/global', 'adm/menu', 'adm/botstats']);
    }

    public function index(): void
    {
        if (!Administration::authorization(self::PERMISSION_CLASS_SYNTHETIC, (int) $this->user['user_authlevel'])) {
            die(Administration::noAccessMessage($this->langs->line('no_permissions')));
        }

        $this->runAction();
        $this->buildPage();
    }

    private function runAction(): void
    {
        $doCleanup = filter_input(INPUT_POST, 'cleanup_logs', FILTER_UNSAFE_RAW);
        if ($doCleanup === 'yes') {
            $logPhp = XGP_ROOT . 'scripts' . DIRECTORY_SEPARATOR . 'bot_lib' . DIRECTORY_SEPARATOR . 'logging.php';
            if (is_readable($logPhp)) {
                require_once $logPhp;
                if (function_exists('botLogsForceCleanup')) {
                    $summary = botLogsForceCleanup();
                    $n = (int) ($summary['deleted'] ?? 0);
                    $bytes = (int) ($summary['bytes_freed'] ?? 0);
                    $this->alert = Administration::saveMessage(
                        'ok',
                        sprintf(
                            $this->langs->line('bs_cleanup_done'),
                            $n,
                            Format::prettyBytes($bytes)
                        )
                    );
                }
            }
        }
    }

    private function buildPage(): void
    {
        $logPhp = XGP_ROOT . 'scripts' . DIRECTORY_SEPARATOR . 'bot_lib' . DIRECTORY_SEPARATOR . 'logging.php';
        if (is_readable($logPhp)) {
            require_once $logPhp;
        }
        $retention = defined('BOT_LOG_RETENTION_DAYS') ? (int) BOT_LOG_RETENTION_DAYS : 2;
        $interval = defined('BOT_LOG_CLEANUP_INTERVAL_SECONDS') ? (int) BOT_LOG_CLEANUP_INTERVAL_SECONDS : 3600;

        $db = new Database();
        $bots = $db->queryFetchAll(
            'SELECT b.`bot_user_id`, u.`user_name`, u.`user_ally_id`,
                    a.`alliance_tag`, a.`alliance_owner`, b.`bot_quirks`
             FROM `' . BOT_STATE . '` AS b
             INNER JOIN `' . USERS . '` AS u ON u.`user_id` = b.`bot_user_id`
             LEFT JOIN `' . ALLIANCE . '` AS a ON a.`alliance_id` = u.`user_ally_id`
             ORDER BY b.`bot_user_id` ASC'
        );

        $botAlliancesRows = $db->queryFetchAll(
            'SELECT a.`alliance_id`, a.`alliance_tag`, a.`alliance_name`, a.`alliance_owner`, a.`alliance_request`,
                    (SELECT COUNT(*) FROM `' . USERS . '` u WHERE u.`user_ally_id` = a.`alliance_id`) AS `members`,
                    (SELECT COUNT(*) FROM `' . USERS . '` u2 WHERE u2.`user_ally_request` = a.`alliance_id`) AS `pending`
             FROM `' . ALLIANCE . '` AS a
             WHERE a.`alliance_request` LIKE \'%"bot_managed":true%\'
                OR a.`alliance_request` LIKE \'%"bot_managed": true%\'
             ORDER BY a.`alliance_id` ASC'
        );
        if (!is_array($botAlliancesRows)) {
            $botAlliancesRows = [];
        }

        if (!is_array($bots)) {
            $bots = [];
        }

        $metricHeaderCells = '';
        foreach (self::METRIC_COLUMNS as $col) {
            $label = $this->langs->line('bs_metric_' . $col);
            $metricHeaderCells .= '<th class="text-right small">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</th>';
        }

        $now = time();
        $tbody = '';
        $allianceTotals = [
            'sent' => 0,
            'accepted' => 0,
            'rejected_received' => 0,
            'applicants_accepted' => 0,
            'applicants_rejected' => 0,
            'members_kicked' => 0,
            'join_seconds_sum' => 0,
            'join_count' => 0,
            'transfers' => 0,
        ];
        foreach ($bots as $row) {
            $uid = (int) ($row['bot_user_id'] ?? 0);
            $name = (string) ($row['user_name'] ?? '');
            $allyIdUser = (int) ($row['user_ally_id'] ?? 0);
            $allyTag = (string) ($row['alliance_tag'] ?? '');
            $allyOwner = (int) ($row['alliance_owner'] ?? 0);
            $allyCell = '<span class="text-muted">—</span>';
            if ($allyIdUser > 0 && $allyTag !== '') {
                $tagH = htmlspecialchars($allyTag, ENT_QUOTES, 'UTF-8');
                $allyCell = '<code>' . $tagH . '</code>';
                if ($allyOwner === $uid) {
                    $allyCell .= ' <span class="badge badge-info">'
                        . htmlspecialchars($this->langs->line('bs_alliance_owner'), ENT_QUOTES, 'UTF-8')
                        . '</span>';
                }
            }
            $quirks = [];
            if (!empty($row['bot_quirks'])) {
                $decoded = json_decode((string) $row['bot_quirks'], true);
                if (is_array($decoded)) {
                    $quirks = $decoded;
                }
            }
            $metrics = is_array($quirks['metrics'] ?? null) ? $quirks['metrics'] : [];
            $pending = is_array($quirks['pending_attacks'] ?? null) ? $quirks['pending_attacks'] : [];
            $intelN = is_array($quirks['intel'] ?? null) ? count($quirks['intel']) : 0;

            $allianceTotals['sent'] += (int) ($metrics['alliance_applications_sent'] ?? 0);
            $allianceTotals['accepted'] += (int) ($metrics['alliance_applications_accepted'] ?? 0);
            $allianceTotals['rejected_received'] += (int) ($metrics['alliance_applications_rejected_received'] ?? 0);
            $allianceTotals['applicants_accepted'] += (int) ($metrics['alliance_applicants_accepted'] ?? 0);
            $allianceTotals['applicants_rejected'] += (int) ($metrics['alliance_applicants_rejected'] ?? 0);
            $allianceTotals['members_kicked'] += (int) ($metrics['alliance_members_kicked'] ?? 0);
            $allianceTotals['join_seconds_sum'] += (int) ($metrics['alliance_apply_to_join_seconds_sum'] ?? 0);
            $allianceTotals['join_count'] += (int) ($metrics['alliance_apply_to_join_count'] ?? 0);
            // Each ownership change emits one `received` on the new owner
            // and one `transferred` on the old owner. To avoid double-
            // counting events, we sum only one side here.
            $allianceTotals['transfers'] += (int) ($metrics['alliances_ownership_received'] ?? 0);

            $cp = is_array($quirks['cargo_pressure'] ?? null) ? $quirks['cargo_pressure'] : null;
            $cpCell = '<span class="text-muted">—</span>';
            if ($cp !== null) {
                $expiresAt = (int) ($cp['expires_at'] ?? 0);
                $skips = (int) ($cp['skips'] ?? 0);
                $isActive = $expiresAt > $now && $skips > 0;
                $expiresHuman = $expiresAt > 0 ? date('Y-m-d H:i', $expiresAt) : '-';
                if ($isActive) {
                    $cpCell = '<span class="badge badge-warning">'
                        . htmlspecialchars($this->langs->line('bs_cp_active'), ENT_QUOTES, 'UTF-8')
                        . '</span> <small>×' . $skips . '</small>'
                        . '<br><small class="text-muted">'
                        . htmlspecialchars($this->langs->line('bs_cp_expires') . ': ' . $expiresHuman, ENT_QUOTES, 'UTF-8')
                        . '</small>';
                } else {
                    $cpCell = '<span class="badge badge-secondary">'
                        . htmlspecialchars($this->langs->line('bs_cp_expired'), ENT_QUOTES, 'UTF-8')
                        . '</span> <small class="text-muted">×' . $skips . '</small>';
                }
            }

            $tbody .= '<tr>';
            $tbody .= '<td>' . $uid . '</td>';
            $tbody .= '<td>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</td>';
            $tbody .= '<td class="text-center">' . $allyCell . '</td>';
            $tbody .= '<td class="text-center">' . count($pending) . '</td>';
            $tbody .= '<td class="text-center">' . $intelN . '</td>';
            $tbody .= '<td class="text-center">' . $cpCell . '</td>';
            foreach (self::METRIC_COLUMNS as $col) {
                $v = (int) ($metrics[$col] ?? 0);
                $tbody .= '<td class="text-right">' . $v . '</td>';
            }
            $tbody .= '</tr>';
        }

        if ($tbody === '') {
            $tbody = '<tr><td colspan="' . (6 + count(self::METRIC_COLUMNS)) . '" class="text-muted">'
                . htmlspecialchars($this->langs->line('bs_no_bots'), ENT_QUOTES, 'UTF-8')
                . '</td></tr>';
        }

        $logDir = XGP_ROOT . 'storage/logs/bots';
        $logRows = '';
        $totalBytes = 0;
        if (is_dir($logDir)) {
            $files = glob($logDir . '/*.log') ?: [];
            usort($files, static function (string $a, string $b): int {
                return strcmp($b, $a);
            });
            foreach ($files as $path) {
                if (!is_file($path)) {
                    continue;
                }
                $bn = basename($path);
                if ($bn === '' || (isset($bn[0]) && $bn[0] === '.')) {
                    continue;
                }
                $sz = (int) (@filesize($path) ?: 0);
                $totalBytes += $sz;
                $mt = (int) (@filemtime($path) ?: 0);
                $age = $mt > 0 ? Format::prettyTimeAgo(date('Y-m-d H:i:s', $mt)) : '-';
                $logRows .= '<tr>'
                    . '<td><code>' . htmlspecialchars($bn, ENT_QUOTES, 'UTF-8') . '</code></td>'
                    . '<td class="text-right">' . Format::prettyBytes($sz) . '</td>'
                    . '<td>' . ($mt > 0 ? htmlspecialchars(date('Y-m-d H:i:s', $mt), ENT_QUOTES, 'UTF-8') : '-') . '</td>'
                    . '<td>' . htmlspecialchars($age, ENT_QUOTES, 'UTF-8') . '</td>'
                    . '</tr>';
            }
        }
        if ($logRows === '') {
            $logRows = '<tr><td colspan="4" class="text-muted">'
                . htmlspecialchars($this->langs->line('bs_no_log_files'), ENT_QUOTES, 'UTF-8')
                . '</td></tr>';
        }

        $totalBotLed = count($botAlliancesRows);
        $totalBotLedMembers = 0;
        $totalBotLedPending = 0;
        foreach ($botAlliancesRows as $ar) {
            $totalBotLedMembers += (int) ($ar['members'] ?? 0);
            $totalBotLedPending += (int) ($ar['pending'] ?? 0);
        }

        $applySuccess = $allianceTotals['sent'] > 0
            ? round(100.0 * $allianceTotals['accepted'] / $allianceTotals['sent'], 1)
            : 0.0;
        $totalReviewed = $allianceTotals['applicants_accepted'] + $allianceTotals['applicants_rejected'];
        $rejectRatio = $totalReviewed > 0
            ? round(100.0 * $allianceTotals['applicants_rejected'] / $totalReviewed, 1)
            : 0.0;
        $kickRatio = $allianceTotals['applicants_accepted'] > 0
            ? round(100.0 * $allianceTotals['members_kicked'] / $allianceTotals['applicants_accepted'], 1)
            : 0.0;
        $avgApplyToJoin = $allianceTotals['join_count'] > 0
            ? (int) round($allianceTotals['join_seconds_sum'] / $allianceTotals['join_count'])
            : 0;

        $rows = [];
        $rows[] = [$this->langs->line('bs_health_bot_led_alliances'), (string) $totalBotLed];
        $rows[] = [$this->langs->line('bs_health_bot_led_members'), (string) $totalBotLedMembers];
        $rows[] = [$this->langs->line('bs_health_bot_led_pending'), (string) $totalBotLedPending];
        $rows[] = [
            $this->langs->line('bs_health_apply_success'),
            $allianceTotals['accepted'] . ' / ' . $allianceTotals['sent']
                . ' (' . number_format($applySuccess, 1) . '%)',
        ];
        $rows[] = [
            $this->langs->line('bs_health_reject_ratio'),
            $allianceTotals['applicants_rejected'] . ' / ' . $totalReviewed
                . ' (' . number_format($rejectRatio, 1) . '%)',
        ];
        $rows[] = [
            $this->langs->line('bs_health_kick_ratio'),
            $allianceTotals['members_kicked'] . ' / ' . $allianceTotals['applicants_accepted']
                . ' (' . number_format($kickRatio, 1) . '%)',
        ];
        $rows[] = [
            $this->langs->line('bs_health_avg_apply_to_join'),
            $avgApplyToJoin > 0 ? $this->prettyDuration($avgApplyToJoin) : '—',
        ];
        $rows[] = [$this->langs->line('bs_health_ownership_transfers'), (string) $allianceTotals['transfers']];

        $allianceHealthRows = '';
        foreach ($rows as [$label, $value]) {
            $allianceHealthRows .= '<tr>'
                . '<th class="text-left small">' . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . '</th>'
                . '<td class="text-right"><code>' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</code></td>'
                . '</tr>';
        }

        $botAlliancesTbody = '';
        foreach ($botAlliancesRows as $ar) {
            $aid = (int) ($ar['alliance_id'] ?? 0);
            $tag = htmlspecialchars((string) ($ar['alliance_tag'] ?? ''), ENT_QUOTES, 'UTF-8');
            $mem = (int) ($ar['members'] ?? 0);
            $pend = (int) ($ar['pending'] ?? 0);
            $meta = null;
            $rawReq = (string) ($ar['alliance_request'] ?? '');
            if ($rawReq !== '') {
                $decoded = json_decode($rawReq, true);
                $meta = is_array($decoded) ? $decoded : null;
            }
            $reqSummary = '—';
            if (is_array($meta) && isset($meta['requirements']) && is_array($meta['requirements'])) {
                $r = $meta['requirements'];
                $reqSummary = 'T≥' . (int) ($r['min_total_points'] ?? 0)
                    . ' M≥' . (int) ($r['min_military_points'] ?? 0);
            }
            $botAlliancesTbody .= '<tr>'
                . '<td>' . $aid . '</td>'
                . '<td><code>' . $tag . '</code></td>'
                . '<td class="text-center">' . $mem . '</td>'
                . '<td class="text-center">' . $pend . '</td>'
                . '<td><small class="text-muted">' . htmlspecialchars($reqSummary, ENT_QUOTES, 'UTF-8') . '</small></td>'
                . '</tr>';
        }
        if ($botAlliancesTbody === '') {
            $botAlliancesTbody = '<tr><td colspan="5" class="text-muted">'
                . htmlspecialchars($this->langs->line('bs_no_bot_alliances'), ENT_QUOTES, 'UTF-8')
                . '</td></tr>';
        }

        $this->page->displayAdmin(
            $this->template->set(
                'adm/botstats_view',
                array_merge(
                    $this->langs->language,
                    [
                        'alert' => $this->alert,
                        'metric_header_cells' => $metricHeaderCells,
                        'bot_metrics_tbody' => $tbody,
                        'log_files_tbody' => $logRows,
                        'log_disk_total' => Format::prettyBytes($totalBytes),
                        'log_retention_days' => $retention,
                        'log_cleanup_interval' => $interval,
                        'bs_retention_hint' => sprintf(
                            $this->langs->line('bs_retention_hint_fmt'),
                            $retention,
                            $interval
                        ),
                        'bs_logs_help' => sprintf($this->langs->line('bs_logs_help_fmt'), $retention),
                        'bot_alliances_tbody' => $botAlliancesTbody,
                        'alliance_health_rows' => $allianceHealthRows,
                        'cleanup_data_msg' => htmlspecialchars(
                            $this->langs->line('bs_cleanup_confirm'),
                            ENT_QUOTES,
                            'UTF-8'
                        ),
                    ]
                )
            )
        );
    }
}
