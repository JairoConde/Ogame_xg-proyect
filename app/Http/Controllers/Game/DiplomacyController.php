<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Core\BaseController;
use App\Core\Database;
use App\Libraries\Functions;
use App\Libraries\TimingLibrary as Timing;
use App\Libraries\Users;

/**
 * Diplomacy tab: wars, pacts, and leader-only proposals (NAP / peace).
 */
class DiplomacyController extends BaseController
{
    public const MODULE_ID = 25;

    private const PROPOSAL_TTL_SECONDS = 604_800;

    private Database $db;

    /** @var bool|null Lazily detected: `xgp_alliance_diplomacy_proposal` exists. */
    private ?bool $proposalTableExists = null;

    public function __construct()
    {
        parent::__construct();
        Users::checkSession();
        parent::loadLang(['game/diplomacy']);
        $this->db = new Database();
    }

    public function index(): void
    {
        Functions::moduleMessage(Functions::isModuleAccesible(self::MODULE_ID));

        $action = (string) (filter_input(INPUT_GET, 'action') ?? '');
        switch ($action) {
            case 'declare':
                $this->showDeclareForm();

                break;
            case 'declare_post':
                $this->handleDeclarePost();

                break;
            case 'propose_nap':
                $this->showProposeNapForm();

                break;
            case 'propose_nap_post':
                $this->handleProposeNapPost();

                break;
            case 'propose_peace':
                $this->showProposePeaceForm();

                break;
            case 'propose_peace_post':
                $this->handleProposePeacePost();

                break;
            case 'accept_proposal_post':
                $this->handleAcceptProposalPost();

                break;
            case 'reject_proposal_post':
                $this->handleRejectProposalPost();

                break;
            default:
                $this->showOverview();
        }
    }

    private function isOwner(): bool
    {
        $allyId = (int) ($this->user['user_ally_id'] ?? 0);
        if ($allyId <= 0) {
            return false;
        }
        $row = $this->db->queryFetch(
            'SELECT `alliance_owner` FROM `' . ALLIANCE . "` WHERE `alliance_id` = '" . $allyId . "' LIMIT 1"
        );

        return is_array($row) && (int) ($row['alliance_owner'] ?? 0) === (int) $this->user['user_id'];
    }

    /**
     * @return array{0: \mysqli, 1: string}
     */
    private function loadDiplomacyLib(): array
    {
        $libDir = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'bot_lib';
        require_once $libDir . DIRECTORY_SEPARATOR . 'safety.php';
        require_once $libDir . DIRECTORY_SEPARATOR . 'diplomacy.php';

        return [$this->db->getConnection(), $this->db->getPrefix()];
    }

    private function allianceDiplomacyProposalTableExists(): bool
    {
        if ($this->proposalTableExists !== null) {
            return $this->proposalTableExists;
        }
        $physicalName = $this->db->getPrefix() . 'alliance_diplomacy_proposal';
        $row = $this->db->queryFetch(
            'SELECT COUNT(*) AS `c` FROM information_schema.`TABLES`
             WHERE `TABLE_SCHEMA` = DATABASE()
               AND `TABLE_NAME` = \'' . $this->db->escapeValue($physicalName) . '\'
             LIMIT 1'
        );
        $this->proposalTableExists = is_array($row) && (int) ($row['c'] ?? 0) > 0;

        return $this->proposalTableExists;
    }

    /**
     * Proposal NAP/peace UI needs the migration table. Message and stop if missing.
     */
    private function requireProposalsTableOrAbort(): bool
    {
        if ($this->allianceDiplomacyProposalTableExists()) {
            return true;
        }
        Functions::message($this->langs->line('di_proposals_table_missing'), 'game.php?page=diplomacy', 5, true);

        return false;
    }

    private function purgeExpiredProposals(): void
    {
        if (!$this->allianceDiplomacyProposalTableExists()) {
            return;
        }
        $now = time();
        $this->db->query(
            'DELETE FROM `' . ALLIANCE_DIPLOMACY_PROPOSAL . "` WHERE `expires_at` < '" . $now . "'"
        );
    }

    /**
     * @return list<int>
     */
    private function pendingPeaceTargets(int $fromAllianceId): array
    {
        if (!$this->allianceDiplomacyProposalTableExists()) {
            return [];
        }
        $now = time();
        $rows = $this->db->queryFetchAll(
            'SELECT `to_alliance_id` FROM `' . ALLIANCE_DIPLOMACY_PROPOSAL . "`
             WHERE `from_alliance_id` = '" . $fromAllianceId . "'
               AND `kind` = 'peace'
               AND `expires_at` > '" . $now . "'"
        );
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = (int) ($r['to_alliance_id'] ?? 0);
        }

        return $out;
    }

    private function showOverview(): void
    {
        $allyId = (int) ($this->user['user_ally_id'] ?? 0);

        $this->purgeExpiredProposals();
        if ($allyId > 0) {
            $this->loadDiplomacyLib();
        }

        $wars = $this->loadActiveDiplomacy('war');
        $pacts = $this->loadActiveDiplomacy('nap');

        $owner = $this->isOwner();
        $pendingPeace = $owner && $allyId > 0 ? $this->pendingPeaceTargets($allyId) : [];

        $warsRows = [];
        foreach ($wars as $w) {
            $declarer = (int) $w['declared_by'];
            $a = (int) $w['alliance_a'];
            $b = (int) $w['alliance_b'];
            $left = $declarer === $a ? $a : ($declarer === $b ? $b : $a);
            $right = $left === $a ? $b : $a;
            $dLeft = $left === $a ? (int) $w['damage_a_to_b'] : (int) $w['damage_b_to_a'];
            $dRight = $left === $a ? (int) $w['damage_b_to_a'] : (int) $w['damage_a_to_b'];

            $peaceLink = '';
            if ($owner && $allyId > 0) {
                $other = $allyId === $left ? $right : $left;
                $isLosing = botDiplomacyAllianceIsLosingSideOfWar(
                    $allyId,
                    $a,
                    $b,
                    (int) $w['damage_a_to_b'],
                    (int) $w['damage_b_to_a']
                );
                $hasPending = in_array($other, $pendingPeace, true);
                $canProposePeace = $this->allianceDiplomacyProposalTableExists();
                if ($isLosing && $canProposePeace && !$hasPending) {
                    $peaceLink = '<a href="game.php?page=diplomacy&amp;action=propose_peace&amp;enemy_id='
                        . $other . '" class="button">' . htmlspecialchars((string) $this->langs->line('di_propose_peace')) . '</a>';
                } elseif ($isLosing && $canProposePeace && $hasPending) {
                    $peaceLink = '<span class="fineprint">' . htmlspecialchars((string) $this->langs->line('di_peace_pending')) . '</span>';
                }
            }

            $warsRows[] = [
                'left_id' => $left,
                'left_tag' => $this->allianceTag($left),
                'right_id' => $right,
                'right_tag' => $this->allianceTag($right),
                'since' => Timing::formatExtendedDate((int) $w['since']),
                'damage_left' => $dLeft,
                'damage_right' => $dRight,
                'peace_link' => $peaceLink,
            ];
        }

        $pactsRows = [];
        foreach ($pacts as $p) {
            $a = (int) $p['alliance_a'];
            $b = (int) $p['alliance_b'];
            $expires = (int) $p['expires_at'];
            $pactsRows[] = [
                'left_id' => $a,
                'left_tag' => $this->allianceTag($a),
                'right_id' => $b,
                'right_tag' => $this->allianceTag($b),
                'since' => Timing::formatExtendedDate((int) $p['since']),
                'expires' => $expires > 0 ? Timing::formatExtendedDate($expires) : '-',
            ];
        }

        $declareButton = $owner
            ? '<a href="game.php?page=diplomacy&action=declare" class="button">'
                . htmlspecialchars((string) $this->langs->line('di_declare_war')) . '</a>'
            : '';

        $napButton = '';
        if ($owner && $allyId > 0 && $this->allianceDiplomacyProposalTableExists()) {
            $napButton = '<a href="game.php?page=diplomacy&action=propose_nap" class="button">'
                . htmlspecialchars((string) $this->langs->line('di_propose_nap')) . '</a>';
        }

        $proposalSetupNotice = '';
        if ($owner && $allyId > 0 && !$this->allianceDiplomacyProposalTableExists()) {
            $proposalSetupNotice = '<p class="error">' . htmlspecialchars((string) $this->langs->line('di_proposals_table_missing')) . '</p>';
        }

        $leaderStrip = $proposalSetupNotice
            . (($declareButton !== '' || $napButton !== '')
                ? '<p style="text-align:right;">' . $declareButton . ' &nbsp; ' . $napButton . '</p>'
                : '');

        [$incomingRows, $outgoingRows] = $allyId > 0
            ? $this->buildProposalRows($allyId)
            : [[], []];

        $this->page->display(
            $this->template->set(
                'game/diplomacy_view',
                array_merge(
                    [
                        'wars_rows' => $warsRows,
                        'pacts_rows' => $pactsRows,
                        'declare_button' => '',
                        'leader_strip' => $leaderStrip,
                        'incoming_proposals' => $incomingRows,
                        'outgoing_proposals' => $outgoingRows,
                    ],
                    $this->langs->language
                )
            )
        );
    }

    /**
     * @return array{0: list<array<string, string>>, 1: list<array<string, string>>}
     */
    private function buildProposalRows(int $myAllyId): array
    {
        if (!$this->allianceDiplomacyProposalTableExists()) {
            return [[], []];
        }
        $now = time();
        $incoming = [];
        $rowsIn = $this->db->queryFetchAll(
            'SELECT `proposal_id`, `from_alliance_id`, `kind`, `payload`, `expires_at`
             FROM `' . ALLIANCE_DIPLOMACY_PROPOSAL . "`
             WHERE `to_alliance_id` = '" . $myAllyId . "' AND `expires_at` > '" . $now . "'
             ORDER BY `created_at` DESC"
        );
        if (!is_array($rowsIn)) {
            $rowsIn = [];
        }
        foreach ($rowsIn as $r) {
            $incoming[] = $this->formatProposalRow($r, true);
        }

        $outgoing = [];
        $rowsOut = $this->db->queryFetchAll(
            'SELECT `proposal_id`, `to_alliance_id`, `kind`, `payload`, `expires_at`
             FROM `' . ALLIANCE_DIPLOMACY_PROPOSAL . "`
             WHERE `from_alliance_id` = '" . $myAllyId . "' AND `expires_at` > '" . $now . "'
             ORDER BY `created_at` DESC"
        );
        if (!is_array($rowsOut)) {
            $rowsOut = [];
        }
        foreach ($rowsOut as $r) {
            $outgoing[] = $this->formatProposalRow($r, false);
        }

        return [$incoming, $outgoing];
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, string>
     */
    private function formatProposalRow(array $r, bool $incoming): array
    {
        $id = (int) ($r['proposal_id'] ?? 0);
        $otherId = $incoming ? (int) ($r['from_alliance_id'] ?? 0) : (int) ($r['to_alliance_id'] ?? 0);
        $kind = (string) ($r['kind'] ?? '');
        $payload = [];
        if (!empty($r['payload'])) {
            $decoded = json_decode((string) $r['payload'], true);
            $payload = is_array($decoded) ? $decoded : [];
        }
        $kindLabel = $kind === 'peace'
            ? (string) $this->langs->line('di_kind_peace')
            : (string) $this->langs->line('di_kind_nap');
        $detail = '';
        if ($kind === 'peace') {
            $offered = (int) ($payload['offered'] ?? 0);
            $detail = ' ' . strtr((string) $this->langs->line('di_peace_offer_detail'), [':amount' => (string) $offered]);
        } elseif ($kind === 'nap') {
            $dur = (int) ($payload['duration_sec'] ?? 0);
            $days = $dur > 0 ? (int) round($dur / 86400) : 0;
            $detail = ' ' . strtr((string) $this->langs->line('di_nap_duration_days'), [':days' => (string) max(1, $days)]);
        }
        $expires = Timing::formatExtendedDate((int) ($r['expires_at'] ?? 0));

        $actions = '';
        if ($incoming && $this->isOwner()) {
            $actions = '<form method="post" action="game.php?page=diplomacy&amp;action=accept_proposal_post" style="display:inline;">'
                . '<input type="hidden" name="proposal_id" value="' . $id . '" />'
                . '<button type="submit" class="button">' . htmlspecialchars((string) $this->langs->line('di_accept')) . '</button></form> '
                . '<form method="post" action="game.php?page=diplomacy&amp;action=reject_proposal_post" style="display:inline;">'
                . '<input type="hidden" name="proposal_id" value="' . $id . '" />'
                . '<button type="submit">' . htmlspecialchars((string) $this->langs->line('di_reject')) . '</button></form>';
        }

        return [
            'other_tag' => $this->allianceTag($otherId),
            'kind_label' => htmlspecialchars($kindLabel . $detail),
            'expires' => htmlspecialchars($expires),
            'actions' => $actions,
        ];
    }

    private function showDeclareForm(): void
    {
        if (!$this->isOwner()) {
            Functions::message($this->langs->line('di_only_owner'), 'game.php?page=diplomacy', 2, true);
        }
        $myAlly = (int) $this->user['user_ally_id'];

        $rows = $this->db->queryFetchAll(
            'SELECT `alliance_id`, `alliance_name`, `alliance_tag` FROM `' . ALLIANCE
            . "` WHERE `alliance_id` <> '" . $myAlly . "' ORDER BY `alliance_tag` ASC"
        );
        $options = '';
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $options .= '<option value="' . (int) $row['alliance_id'] . '">['
                    . htmlspecialchars((string) $row['alliance_tag']) . '] '
                    . htmlspecialchars((string) $row['alliance_name']) . '</option>';
            }
        }

        $cooldown = '';
        $cdRow = $this->db->queryFetch(
            'SELECT `until_at` FROM `' . ALLIANCE_DIPLOMACY_COOLDOWN
            . "` WHERE `alliance_id` = '" . $myAlly . "' AND `kind` = 'war_block' LIMIT 1"
        );
        if (is_array($cdRow) && (int) ($cdRow['until_at'] ?? 0) > time()) {
            $cooldown = '<p class="error">' . $this->langs->line('di_war_block_active') . '</p>';
        }

        $this->page->display(
            $this->template->set(
                'game/diplomacy_declare_view',
                array_merge(
                    [
                        'options' => $options,
                        'cooldown_notice' => $cooldown,
                    ],
                    $this->langs->language
                )
            )
        );
    }

    private function handleDeclarePost(): void
    {
        if (!$this->isOwner()) {
            Functions::message($this->langs->line('di_only_owner'), 'game.php?page=diplomacy', 2, true);
        }
        $myAlly = (int) $this->user['user_ally_id'];
        $target = (int) filter_input(INPUT_POST, 'target_alliance_id', FILTER_VALIDATE_INT);
        if ($target <= 0 || $target === $myAlly) {
            Functions::redirect('game.php?page=diplomacy&action=declare');
        }

        $libDir = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'bot_lib';
        require_once $libDir . DIRECTORY_SEPARATOR . 'safety.php';
        require_once $libDir . DIRECTORY_SEPARATOR . 'diplomacy.php';

        $ok = botDiplomacyDeclareWar(
            $this->db->getConnection(),
            $this->db->getPrefix(),
            $myAlly,
            $target,
            (int) $this->user['user_id'],
            time()
        );

        if (!$ok) {
            Functions::message($this->langs->line('di_declare_failed'), 'game.php?page=diplomacy', 2, true);
        }

        Functions::redirect('game.php?page=diplomacy');
    }

    private function showProposeNapForm(): void
    {
        if (!$this->isOwner()) {
            Functions::message($this->langs->line('di_only_owner'), 'game.php?page=diplomacy', 2, true);
        }
        if (!$this->requireProposalsTableOrAbort()) {
            return;
        }
        $this->loadDiplomacyLib();
        $myAlly = (int) $this->user['user_ally_id'];

        $rows = $this->db->queryFetchAll(
            'SELECT `alliance_id`, `alliance_name`, `alliance_tag` FROM `' . ALLIANCE
            . "` WHERE `alliance_id` <> '" . $myAlly . "' ORDER BY `alliance_tag` ASC"
        );
        $options = '';
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $tid = (int) $row['alliance_id'];
                if (botDiplomacyIsAtWar($this->db->getConnection(), $this->db->getPrefix(), $myAlly, $tid)) {
                    continue;
                }
                $options .= '<option value="' . $tid . '">['
                    . htmlspecialchars((string) $row['alliance_tag']) . '] '
                    . htmlspecialchars((string) $row['alliance_name']) . '</option>';
            }
        }

        if ($options === '') {
            Functions::message($this->langs->line('di_nap_no_targets'), 'game.php?page=diplomacy', 3, true);

            return;
        }

        $this->page->display(
            $this->template->set(
                'game/diplomacy_propose_nap_view',
                array_merge(
                    [
                        'options' => $options,
                    ],
                    $this->langs->language
                )
            )
        );
    }

    private function handleProposeNapPost(): void
    {
        if (!$this->isOwner()) {
            Functions::message($this->langs->line('di_only_owner'), 'game.php?page=diplomacy', 2, true);
        }
        if (!$this->requireProposalsTableOrAbort()) {
            return;
        }
        [$mysqli, $prefix] = $this->loadDiplomacyLib();
        $myAlly = (int) $this->user['user_ally_id'];
        $target = (int) filter_input(INPUT_POST, 'target_alliance_id', FILTER_VALIDATE_INT);
        $days = (int) filter_input(INPUT_POST, 'duration_days', FILTER_VALIDATE_INT);
        if ($target <= 0 || $target === $myAlly) {
            Functions::redirect('game.php?page=diplomacy&action=propose_nap');
        }
        if (botDiplomacyIsAtWar($mysqli, $prefix, $myAlly, $target)) {
            Functions::message($this->langs->line('di_nap_blocked_war'), 'game.php?page=diplomacy', 2, true);
        }

        $days = max(1, min(30, $days > 0 ? $days : 7));
        $durationSec = $days * 86400;
        $now = time();
        $expires = $now + self::PROPOSAL_TTL_SECONDS;
        $payload = $this->db->escapeValue(json_encode(['duration_sec' => $durationSec], JSON_THROW_ON_ERROR));

        $sql = 'INSERT INTO `' . ALLIANCE_DIPLOMACY_PROPOSAL . '`
            (`from_alliance_id`, `to_alliance_id`, `kind`, `payload`, `created_at`, `expires_at`)
            VALUES (\'' . $myAlly . '\', \'' . $target . '\', \'nap\', \'' . $payload . '\', \'' . $now . '\', \'' . $expires . '\')
            ON DUPLICATE KEY UPDATE
                `payload` = VALUES(`payload`),
                `created_at` = VALUES(`created_at`),
                `expires_at` = VALUES(`expires_at`)';
        if (!$this->db->query($sql)) {
            Functions::message($this->langs->line('di_proposal_failed'), 'game.php?page=diplomacy', 2, true);
        }

        Functions::redirect('game.php?page=diplomacy');
    }

    private function showProposePeaceForm(): void
    {
        if (!$this->isOwner()) {
            Functions::message($this->langs->line('di_only_owner'), 'game.php?page=diplomacy', 2, true);
        }
        if (!$this->requireProposalsTableOrAbort()) {
            return;
        }
        [$mysqli, $prefix] = $this->loadDiplomacyLib();
        $myAlly = (int) $this->user['user_ally_id'];
        $enemy = (int) filter_input(INPUT_GET, 'enemy_id', FILTER_VALIDATE_INT);
        if ($enemy <= 0 || $enemy === $myAlly) {
            Functions::redirect('game.php?page=diplomacy');
        }
        if (!botDiplomacyIsAtWar($mysqli, $prefix, $myAlly, $enemy)) {
            Functions::message($this->langs->line('di_not_at_war'), 'game.php?page=diplomacy', 2, true);
        }

        $row = $this->db->queryFetch(
            'SELECT `alliance_a`, `alliance_b`, `damage_a_to_b`, `damage_b_to_a`
             FROM `' . ALLIANCE_DIPLOMACY . "`
             WHERE `status` = 'war'
               AND ((`alliance_a` = '" . $myAlly . "' AND `alliance_b` = '" . $enemy . "')
                 OR (`alliance_a` = '" . $enemy . "' AND `alliance_b` = '" . $myAlly . "'))
             LIMIT 1"
        );
        if (!is_array($row)) {
            Functions::redirect('game.php?page=diplomacy');
        }
        $a = (int) $row['alliance_a'];
        $b = (int) $row['alliance_b'];
        $a2b = (int) $row['damage_a_to_b'];
        $b2a = (int) $row['damage_b_to_a'];
        if (!botDiplomacyAllianceIsLosingSideOfWar($myAlly, $a, $b, $a2b, $b2a)) {
            Functions::message($this->langs->line('di_peace_only_loser'), 'game.php?page=diplomacy', 2, true);
        }

        $offered = botDiplomacyPeaceBribeOfferedByLoser($myAlly, $a, $b, $a2b, $b2a);
        $required = botDiplomacyPeaceBribeRequiredForReceiver($enemy, $myAlly, $a2b, $b2a);

        $this->page->display(
            $this->template->set(
                'game/diplomacy_propose_peace_view',
                array_merge(
                    [
                        'enemy_tag' => $this->allianceTag($enemy),
                        'enemy_id' => $enemy,
                        'offered' => (string) $offered,
                        'required_peer' => (string) $required,
                    ],
                    $this->langs->language
                )
            )
        );
    }

    private function handleProposePeacePost(): void
    {
        if (!$this->isOwner()) {
            Functions::message($this->langs->line('di_only_owner'), 'game.php?page=diplomacy', 2, true);
        }
        if (!$this->requireProposalsTableOrAbort()) {
            return;
        }
        [$mysqli, $prefix] = $this->loadDiplomacyLib();
        $myAlly = (int) $this->user['user_ally_id'];
        $enemy = (int) filter_input(INPUT_POST, 'enemy_id', FILTER_VALIDATE_INT);
        if ($enemy <= 0 || $enemy === $myAlly) {
            Functions::redirect('game.php?page=diplomacy');
        }
        if (!botDiplomacyIsAtWar($mysqli, $prefix, $myAlly, $enemy)) {
            Functions::message($this->langs->line('di_not_at_war'), 'game.php?page=diplomacy', 2, true);
        }

        $row = $this->db->queryFetch(
            'SELECT `alliance_a`, `alliance_b`, `damage_a_to_b`, `damage_b_to_a`
             FROM `' . ALLIANCE_DIPLOMACY . "`
             WHERE `status` = 'war'
               AND ((`alliance_a` = '" . $myAlly . "' AND `alliance_b` = '" . $enemy . "')
                 OR (`alliance_a` = '" . $enemy . "' AND `alliance_b` = '" . $myAlly . "'))
             LIMIT 1"
        );
        if (!is_array($row)) {
            Functions::redirect('game.php?page=diplomacy');
        }
        $a = (int) $row['alliance_a'];
        $b = (int) $row['alliance_b'];
        $a2b = (int) $row['damage_a_to_b'];
        $b2a = (int) $row['damage_b_to_a'];
        if (!botDiplomacyAllianceIsLosingSideOfWar($myAlly, $a, $b, $a2b, $b2a)) {
            Functions::message($this->langs->line('di_peace_only_loser'), 'game.php?page=diplomacy', 2, true);
        }

        $offered = botDiplomacyPeaceBribeOfferedByLoser($myAlly, $a, $b, $a2b, $b2a);
        $now = time();
        $expires = $now + self::PROPOSAL_TTL_SECONDS;
        $payload = $this->db->escapeValue(json_encode(['offered' => $offered], JSON_THROW_ON_ERROR));

        $sql = 'INSERT INTO `' . ALLIANCE_DIPLOMACY_PROPOSAL . '`
            (`from_alliance_id`, `to_alliance_id`, `kind`, `payload`, `created_at`, `expires_at`)
            VALUES (\'' . $myAlly . '\', \'' . $enemy . '\', \'peace\', \'' . $payload . '\', \'' . $now . '\', \'' . $expires . '\')
            ON DUPLICATE KEY UPDATE
                `payload` = VALUES(`payload`),
                `created_at` = VALUES(`created_at`),
                `expires_at` = VALUES(`expires_at`)';
        if (!$this->db->query($sql)) {
            Functions::message($this->langs->line('di_proposal_failed'), 'game.php?page=diplomacy', 2, true);
        }

        Functions::redirect('game.php?page=diplomacy');
    }

    private function handleAcceptProposalPost(): void
    {
        if (!$this->isOwner()) {
            Functions::message($this->langs->line('di_only_owner'), 'game.php?page=diplomacy', 2, true);
        }
        if (!$this->requireProposalsTableOrAbort()) {
            return;
        }
        [$mysqli, $prefix] = $this->loadDiplomacyLib();
        $myAlly = (int) $this->user['user_ally_id'];
        $proposalId = (int) filter_input(INPUT_POST, 'proposal_id', FILTER_VALIDATE_INT);
        if ($proposalId <= 0) {
            Functions::redirect('game.php?page=diplomacy');
        }

        $now = time();
        $p = $this->db->queryFetch(
            'SELECT `proposal_id`, `from_alliance_id`, `to_alliance_id`, `kind`, `payload`
             FROM `' . ALLIANCE_DIPLOMACY_PROPOSAL . "`
             WHERE `proposal_id` = '" . $proposalId . "'
               AND `to_alliance_id` = '" . $myAlly . "'
               AND `expires_at` > '" . $now . "'
             LIMIT 1"
        );
        if (!is_array($p)) {
            Functions::message($this->langs->line('di_proposal_missing'), 'game.php?page=diplomacy', 2, true);

            return;
        }

        $fromAlly = (int) $p['from_alliance_id'];
        $kind = (string) $p['kind'];
        $payload = [];
        if (!empty($p['payload'])) {
            $decoded = json_decode((string) $p['payload'], true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        if ($kind === 'nap') {
            if (botDiplomacyIsAtWar($mysqli, $prefix, $myAlly, $fromAlly)) {
                Functions::message($this->langs->line('di_nap_blocked_war'), 'game.php?page=diplomacy', 2, true);
            }
            $dur = (int) ($payload['duration_sec'] ?? 0);
            $dur = $dur > 0 ? $dur : null;
            if (!botDiplomacyUpsertNap($mysqli, $prefix, $myAlly, $fromAlly, (int) $this->user['user_id'], $dur, $now)) {
                Functions::message($this->langs->line('di_nap_accept_failed'), 'game.php?page=diplomacy', 2, true);
            }
        } elseif ($kind === 'peace') {
            if (!botDiplomacyIsAtWar($mysqli, $prefix, $myAlly, $fromAlly)) {
                Functions::message($this->langs->line('di_not_at_war'), 'game.php?page=diplomacy', 2, true);
            }
            $row = $this->db->queryFetch(
                'SELECT `damage_a_to_b`, `damage_b_to_a` FROM `' . ALLIANCE_DIPLOMACY . "`
                 WHERE `status` = 'war'
                   AND ((`alliance_a` = '" . $myAlly . "' AND `alliance_b` = '" . $fromAlly . "')
                     OR (`alliance_a` = '" . $fromAlly . "' AND `alliance_b` = '" . $myAlly . "'))
                 LIMIT 1"
            );
            if (!is_array($row)) {
                Functions::redirect('game.php?page=diplomacy');
            }
            $a2b = (int) $row['damage_a_to_b'];
            $b2a = (int) $row['damage_b_to_a'];
            $offered = (int) ($payload['offered'] ?? 0);
            $required = botDiplomacyPeaceBribeRequiredForReceiver($myAlly, $fromAlly, $a2b, $b2a);
            if ($offered < $required || $required <= 0) {
                Functions::message($this->langs->line('di_peace_offer_too_low'), 'game.php?page=diplomacy', 2, true);
            }
            if (!botDiplomacySignPeace(
                $mysqli,
                $prefix,
                $myAlly,
                $fromAlly,
                (int) $this->user['user_id'],
                'accept_peace',
                ['offered' => $offered, 'required' => $required, 'source' => 'web_proposal'],
                $now
            )) {
                Functions::message($this->langs->line('di_peace_accept_failed'), 'game.php?page=diplomacy', 2, true);
            }
        } else {
            Functions::redirect('game.php?page=diplomacy');
        }

        $this->db->query(
            'DELETE FROM `' . ALLIANCE_DIPLOMACY_PROPOSAL . "` WHERE `proposal_id` = '" . $proposalId . "' LIMIT 1"
        );

        Functions::redirect('game.php?page=diplomacy');
    }

    private function handleRejectProposalPost(): void
    {
        if (!$this->isOwner()) {
            Functions::message($this->langs->line('di_only_owner'), 'game.php?page=diplomacy', 2, true);
        }
        if (!$this->requireProposalsTableOrAbort()) {
            return;
        }
        $myAlly = (int) $this->user['user_ally_id'];
        $proposalId = (int) filter_input(INPUT_POST, 'proposal_id', FILTER_VALIDATE_INT);
        if ($proposalId <= 0) {
            Functions::redirect('game.php?page=diplomacy');
        }
        $this->db->query(
            'DELETE FROM `' . ALLIANCE_DIPLOMACY_PROPOSAL . "`
             WHERE `proposal_id` = '" . $proposalId . "' AND `to_alliance_id` = '" . $myAlly . "' LIMIT 1"
        );
        Functions::redirect('game.php?page=diplomacy');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadActiveDiplomacy(string $status): array
    {
        $now = time();
        $statusEsc = $this->db->escapeValue($status);
        $rows = $this->db->queryFetchAll(
            'SELECT `alliance_a`, `alliance_b`, `since`, `expires_at`, `declared_by`,
                    `damage_a_to_b`, `damage_b_to_a`
             FROM `' . ALLIANCE_DIPLOMACY . "`
             WHERE `status` = '" . $statusEsc . "'
               AND (`expires_at` = 0 OR `expires_at` > '" . $now . "')
             ORDER BY `since` DESC"
        );

        return is_array($rows) ? $rows : [];
    }

    private function allianceTag(int $id): string
    {
        $row = $this->db->queryFetch(
            'SELECT `alliance_tag`, `alliance_name` FROM `' . ALLIANCE
            . "` WHERE `alliance_id` = '" . $id . "' LIMIT 1"
        );
        if (!is_array($row)) {
            return '#' . $id;
        }

        return htmlspecialchars('[' . (string) $row['alliance_tag'] . '] ' . (string) $row['alliance_name']);
    }
}
