<?php

declare(strict_types=1);

require_once __DIR__ . '/alliance_codec.php';

interface BotAlliancePolicy
{
    /**
     * @return array{action:string, reason:string, params:array<string, mixed>}
     */
    public function decideCreate(array $ctx): array;

    /**
     * @param array<int, array<string, mixed>> $candidates Pre-sorted best-first
     * @return array{action:string, reason:string, params:array<string, mixed>}
     */
    public function decideApply(array $ctx, array $candidates): array;

    /**
     * @param array<string, mixed> $applicant
     * @return array{action:string, reason:string, params:array<string, mixed>}
     */
    public function decideAcceptApplicant(array $ctx, array $applicant): array;

    /**
     * @return array{action:string, reason:string, params:array<string, mixed>}
     */
    public function decideLeave(array $ctx): array;

    /**
     * @param array<string, mixed> $member
     * @return array{action:string, reason:string, params:array<string, mixed>}
     */
    public function decideKick(array $ctx, array $member): array;

    /**
     * @return array{action:string, reason:string, params:array<string, mixed>}
     */
    public function decideDissolve(array $ctx): array;

    /**
     * @return array{name:string, tag:string, soft_text:string}
     */
    public function generateAllianceIdentity(array $ctx): array;

    /**
     * Decide if ownership should transfer away from an inactive bot owner
     * to one of the listed eligible bot members. The caller is responsible
     * for executing the SQL only when the returned candidate matches the
     * current bot's user_id (deterministic election).
     *
     * @param array<int, array<string, mixed>> $eligibleBotMembers
     * @return array{action:string, reason:string, params:array<string, mixed>}
     */
    public function decideOwnershipTransfer(array $ctx, array $eligibleBotMembers): array;
}

final class BotAlliancePolicyRules implements BotAlliancePolicy
{
    public function decideCreate(array $ctx): array
    {
        $score = self::computeFounderSelfScore($ctx);
        $min = (float) (defined('BOT_ALLIANCE_FOUND_MIN_SELF_SCORE') ? BOT_ALLIANCE_FOUND_MIN_SELF_SCORE : 7.0);
        if ($score < $min) {
            return ['action' => 'skip', 'reason' => 'founder_self_score_low', 'params' => ['score' => $score, 'min' => $min]];
        }

        return ['action' => 'create', 'reason' => 'founder_self_score_ok', 'params' => ['score' => $score]];
    }

    public function decideApply(array $ctx, array $candidates): array
    {
        if ($candidates === []) {
            return ['action' => 'noop', 'reason' => 'no_candidates', 'params' => []];
        }
        $top = $candidates[0];
        $aid = (int) ($top['alliance_id'] ?? 0);
        if ($aid <= 0) {
            return ['action' => 'noop', 'reason' => 'invalid_top', 'params' => []];
        }

        return ['action' => 'apply', 'reason' => 'best_candidate', 'params' => ['alliance_id' => $aid]];
    }

    public function decideAcceptApplicant(array $ctx, array $applicant): array
    {
        $isBot = !empty($applicant['is_bot_applicant']);
        $meta = $ctx['alliance_meta'] ?? null;
        $req = is_array($meta) && isset($meta['requirements']) && is_array($meta['requirements'])
            ? $meta['requirements']
            : [];

        if (!$isBot) {
            $total = (float) ($applicant['total_points'] ?? 0);
            if ($total <= 0) {
                return ['action' => 'reject', 'reason' => 'human_zero_points', 'params' => []];
            }

            return ['action' => 'accept', 'reason' => 'human_permissive', 'params' => []];
        }

        if (!self::applicantMeetsNumericRequirements($req, $applicant)) {
            return ['action' => 'reject', 'reason' => 'numeric_requirements', 'params' => []];
        }

        $ownerPers = (string) ($ctx['personality'] ?? 'flotero');
        $appPers = (string) ($applicant['personality'] ?? 'flotero');
        if (!self::ownerAcceptsApplicantPersonality($ownerPers, $appPers)) {
            return ['action' => 'reject', 'reason' => 'personality_mismatch', 'params' => []];
        }

        $wl = $req['personality_whitelist'] ?? null;
        if (is_array($wl) && $wl !== [] && !in_array($appPers, $wl, true)) {
            return ['action' => 'reject', 'reason' => 'whitelist', 'params' => []];
        }

        $swl = $req['style_whitelist'] ?? null;
        $appStyle = (string) ($applicant['style'] ?? 'granja');
        if (is_array($swl) && $swl !== [] && !in_array($appStyle, $swl, true)) {
            return ['action' => 'reject', 'reason' => 'style_whitelist', 'params' => []];
        }

        return ['action' => 'accept', 'reason' => 'bot_checks_ok', 'params' => []];
    }

    public function decideLeave(array $ctx): array
    {
        if (!empty($ctx['is_owner'])) {
            return ['action' => 'noop', 'reason' => 'owner_cannot_leave', 'params' => []];
        }
        $ownerInactive = (int) ($ctx['owner_inactive_seconds'] ?? 0);
        $sevenDays = 86400 * 7;
        if ($ownerInactive >= $sevenDays) {
            return ['action' => 'leave', 'reason' => 'owner_inactive', 'params' => ['owner_inactive_seconds' => $ownerInactive]];
        }
        $members = (int) ($ctx['member_count'] ?? 1);
        if ($members <= 1) {
            return ['action' => 'leave', 'reason' => 'alone_effective', 'params' => []];
        }

        return ['action' => 'noop', 'reason' => 'stable', 'params' => []];
    }

    public function decideKick(array $ctx, array $member): array
    {
        // Grace period for new members: a bot that was just accepted must
        // not be evaluated for kick on the same review cycle, otherwise the
        // owner would accept and immediately expel anyone who is slightly
        // below its inactivity threshold, leading to dissolve-by-empty.
        $joinedAt = (int) ($member['user_ally_register_time'] ?? 0);
        $nowTs = (int) ($ctx['now'] ?? time());
        $grace = defined('BOT_ALLIANCE_NEW_MEMBER_GRACE_SECONDS')
            ? (int) BOT_ALLIANCE_NEW_MEMBER_GRACE_SECONDS
            : 3600;
        if ($joinedAt > 0 && ($nowTs - $joinedAt) < $grace) {
            return ['action' => 'noop', 'reason' => 'new_member_grace', 'params' => []];
        }

        $meta = $ctx['alliance_meta'] ?? null;
        $req = is_array($meta) && isset($meta['requirements']) && is_array($meta['requirements'])
            ? $meta['requirements']
            : [];
        if (!self::applicantMeetsNumericRequirements($req, $member)) {
            return ['action' => 'kick', 'reason' => 'below_requirements', 'params' => ['user_id' => (int) ($member['user_id'] ?? 0)]];
        }
        $maxInactive = (int) ($req['max_inactive_seconds'] ?? 0);
        if ($maxInactive > 0) {
            $last = (int) ($member['user_onlinetime'] ?? 0);
            if ($last > 0 && ($nowTs - $last) > $maxInactive) {
                return ['action' => 'kick', 'reason' => 'inactive', 'params' => ['user_id' => (int) ($member['user_id'] ?? 0)]];
            }
        }

        return ['action' => 'noop', 'reason' => 'member_ok', 'params' => []];
    }

    public function decideDissolve(array $ctx): array
    {
        $members = (int) ($ctx['member_count'] ?? 0);
        $row = $ctx['alliance_row'] ?? null;
        $registerTime = is_array($row) ? (int) ($row['alliance_register_time'] ?? 0) : 0;
        $now = (int) ($ctx['now'] ?? time());
        $grace = defined('BOT_ALLIANCE_DISSOLVE_GRACE_SECONDS')
            ? (int) BOT_ALLIANCE_DISSOLVE_GRACE_SECONDS
            : 3600;
        $young = $registerTime > 0 && ($now - $registerTime) < $grace;

        // Even within the young grace, a lone founder should dissolve
        // if there is a clearly better alternative (an alliance with
        // at least BETTER_ALT_MIN_MEMBERS members the bot could apply
        // to). The orchestrator populates `better_alternative_member_count`
        // with the max member_count among compatible candidates.
        $betterAltMembers = (int) ($ctx['better_alternative_member_count'] ?? 0);
        $betterAltMin = defined('BOT_ALLIANCE_BETTER_ALT_MIN_MEMBERS')
            ? (int) BOT_ALLIANCE_BETTER_ALT_MIN_MEMBERS
            : 3;

        if ($members <= 1) {
            if ($young) {
                if ($betterAltMembers >= $betterAltMin) {
                    return [
                        'action' => 'dissolve',
                        'reason' => 'better_alternative_in_grace',
                        'params' => ['better_alt_members' => $betterAltMembers],
                    ];
                }

                return ['action' => 'noop', 'reason' => 'young_alliance_grace', 'params' => []];
            }

            return ['action' => 'dissolve', 'reason' => 'only_founder', 'params' => []];
        }
        $activeMembers = (int) ($ctx['active_members_last_7d'] ?? $members);
        if ($members > 1 && $activeMembers <= 1) {
            if ($young) {
                return ['action' => 'noop', 'reason' => 'young_alliance_grace', 'params' => []];
            }

            return ['action' => 'dissolve', 'reason' => 'dead_alliance', 'params' => []];
        }

        return ['action' => 'noop', 'reason' => 'keep', 'params' => []];
    }

    /**
     * Returns true if the bot dissolved its own alliance recently and
     * shouldn't be allowed to create a new one yet. Pure helper so it
     * can be unit-tested without touching the database.
     *
     * @param array<string, mixed> $allianceQuirk Decoded `bot_quirks.alliance`
     */
    public static function isWithinAntiRebound(array $allianceQuirk, int $now): bool
    {
        $last = (int) ($allianceQuirk['last_dissolved_at'] ?? 0);
        if ($last <= 0) {
            return false;
        }
        $ttl = defined('BOT_ALLIANCE_ANTI_REBOUND_SECONDS')
            ? (int) BOT_ALLIANCE_ANTI_REBOUND_SECONDS
            : 3600;

        return ($now - $last) < $ttl;
    }

    public function decideOwnershipTransfer(array $ctx, array $eligibleBotMembers): array
    {
        if (empty($ctx['owner_is_bot'])) {
            return ['action' => 'noop', 'reason' => 'owner_not_bot', 'params' => []];
        }
        $inactive = (int) ($ctx['owner_inactive_seconds'] ?? 0);
        $threshold = defined('BOT_ALLIANCE_OWNER_INACTIVE_TRANSFER_SECONDS')
            ? (int) BOT_ALLIANCE_OWNER_INACTIVE_TRANSFER_SECONDS
            : 86400 * 14;
        if ($inactive < $threshold) {
            return ['action' => 'noop', 'reason' => 'owner_active', 'params' => []];
        }
        if ($eligibleBotMembers === []) {
            return ['action' => 'noop', 'reason' => 'no_candidate', 'params' => []];
        }

        usort($eligibleBotMembers, static function (array $a, array $b): int {
            $sa = (float) ($a['transfer_score'] ?? 0.0);
            $sb = (float) ($b['transfer_score'] ?? 0.0);
            if ($sa === $sb) {
                return ((int) ($a['user_id'] ?? 0)) <=> ((int) ($b['user_id'] ?? 0));
            }

            return $sb <=> $sa;
        });

        $top = $eligibleBotMembers[0];

        return [
            'action' => 'transfer',
            'reason' => 'owner_inactive',
            'params' => [
                'new_owner_id' => (int) ($top['user_id'] ?? 0),
                'inactive_seconds' => $inactive,
            ],
        ];
    }

    public static function computeTransferScore(array $member): float
    {
        $total = (float) ($member['total_points'] ?? 0);
        $mil = (float) ($member['military_points'] ?? 0);
        $tech = (float) ($member['research_points'] ?? 0);
        $age = (int) ($member['user_ally_register_time'] ?? 0);

        return (log(1.0 + max(0.0, $total)) * 1.0)
            + (log(1.0 + max(0.0, $mil)) * 0.5)
            + (log(1.0 + max(0.0, $tech)) * 0.25)
            + ($age > 0 ? 1.0 : 0.0);
    }

    public function generateAllianceIdentity(array $ctx): array
    {
        $seed = (int) ($ctx['seed'] ?? 0);
        $name = (string) ($ctx['user_name'] ?? 'Bot');
        $slug = preg_replace('/[^a-zA-Z0-9]/', '', $name) ?: 'Bot';
        $slug = substr($slug, 0, 4);
        $num = botRngInt($seed, 'ally_tag', 10, 99);
        $tag = strtoupper($slug . $num);
        if (strlen($tag) < 3) {
            $tag = 'B' . $num;
        }
        $tag = substr($tag, 0, 8);
        $disp = 'Alliance ' . $name;

        return [
            'name' => substr($disp, 0, 30),
            'tag' => $tag,
            'soft_text' => 'Bot-managed alliance. Apply if you meet the posted requirements.',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     * @return array<int, array<string, mixed>>
     */
    public static function filterCandidatesForApply(array $ctx, array $candidates, bool $desperate): array
    {
        $maxMembers = defined('BOT_ALLIANCE_APPLY_MAX_MEMBERS')
            ? (int) BOT_ALLIANCE_APPLY_MAX_MEMBERS
            : 10;
        $out = [];
        foreach ($candidates as $row) {
            if (self::rowRejected($ctx, (int) ($row['alliance_id'] ?? 0))) {
                continue;
            }
            if (!self::allianceAcceptsApplications($row)) {
                continue;
            }
            // Cap to avoid applying to large alliances (typically human-led
            // with already-saturated rosters). Applies both in normal and
            // desperate mode: even in desperate mode a bot would rather be
            // alone than spam-apply to a 50-member human alliance.
            if ((int) ($row['member_count'] ?? 0) > $maxMembers) {
                continue;
            }
            if ($desperate) {
                if (self::applicantMeetsNumericRequirementsForRow($ctx, $row)) {
                    $out[] = $row;
                }

                continue;
            }
            if (!self::applicantMeetsAllianceRow($ctx, $row)) {
                continue;
            }
            if (!self::personalityFitForAlliance($ctx, $row)) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function sortCandidatesByScore(array $ctx, array $rows, bool $desperate): array
    {
        usort($rows, static function (array $a, array $b) use ($ctx, $desperate): int {
            $sa = self::scoreCandidate($ctx, $a, $desperate);
            $sb = self::scoreCandidate($ctx, $b, $desperate);
            if ($sa === $sb) {
                return ((int) ($b['member_count'] ?? 0)) <=> ((int) ($a['member_count'] ?? 0));
            }

            return $sb <=> $sa;
        });

        return $rows;
    }

    public static function computeFounderSelfScore(array $ctx): float
    {
        $p = (string) ($ctx['personality'] ?? 'flotero');
        $a = (string) ($ctx['archetype'] ?? 'balanced');
        $s = (string) ($ctx['style'] ?? 'granja');
        $total = (float) ($ctx['stats']['total'] ?? 0);
        $mil = (float) ($ctx['stats']['military'] ?? 0);
        $ships = (float) ($ctx['stats']['ships'] ?? 0);
        $score = 3.0;
        if ($total >= 50000) {
            $score += 2.0;
        } elseif ($total >= 15000) {
            $score += 1.0;
        }
        if ($mil >= 5000) {
            $score += 2.0;
        } elseif ($mil >= 1500) {
            $score += 1.0;
        }
        if ($ships >= 3000) {
            $score += 1.0;
        }
        if ($p === 'cazador' || $p === 'flotero') {
            $score += 1.5;
        }
        if ($a === 'turbo' || $a === 'opportunist') {
            $score += 1.0;
        }
        if ($s === 'raider') {
            $score += 0.5;
        }
        if ($p === 'minero' || $p === 'tecnologico') {
            $score -= 2.0;
        }
        if ($a === 'turtle') {
            $score -= 1.0;
        }

        return max(0.0, $score);
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildFounderMeta(array $ctx): array
    {
        return [
            'bot_managed' => true,
            'version' => 1,
            'created_at' => (int) ($ctx['now'] ?? time()),
            'founder_personality' => (string) ($ctx['personality'] ?? 'flotero'),
            'founder_archetype' => (string) ($ctx['archetype'] ?? 'balanced'),
            'founder_style' => (string) ($ctx['style'] ?? 'granja'),
            'requirements' => self::defaultRequirementsForFounder($ctx),
            'soft_text' => 'Bot-managed alliance.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultRequirementsForFounder(array $ctx): array
    {
        $p = (string) ($ctx['personality'] ?? 'flotero');
        $total = (float) ($ctx['stats']['total'] ?? 0);
        $mil = (float) ($ctx['stats']['military'] ?? 0);
        $baseTotal = (int) max(500, min(8000, (int) ($total * 0.08)));
        $baseMil = (int) max(200, min(4000, (int) ($mil * 0.12)));
        $whitelist = ['flotero', 'cazador'];
        $styleWl = ['raider', 'granja'];
        if ($p === 'minero' || $p === 'defensor') {
            $whitelist = ['minero', 'defensor', 'tecnologico', 'flotero'];
            $styleWl = ['granja', 'bunker', 'raider'];
            $baseMil = (int) max(0, min(1500, $baseMil / 2));
        } elseif ($p === 'tecnologico') {
            $whitelist = ['tecnologico', 'minero', 'flotero'];
            $styleWl = ['granja', 'bunker', 'raider'];
        }

        return [
            'min_total_points' => $baseTotal,
            'min_military_points' => $baseMil,
            'min_fleet_points' => (int) max(0, (int) ($baseMil * 0.4)),
            'min_research_points' => ($p === 'tecnologico') ? 300 : 0,
            'personality_whitelist' => $whitelist,
            'style_whitelist' => $styleWl,
            'max_inactive_seconds' => 172800,
        ];
    }

    /**
     * @param array<string, mixed> $req
     * @param array<string, mixed> $applicant
     */
    public static function applicantMeetsNumericRequirements(array $req, array $applicant): bool
    {
        $total = (float) ($applicant['total_points'] ?? 0);
        $mil = (float) ($applicant['military_points'] ?? 0);
        $fleet = (float) ($applicant['fleet_points'] ?? 0);
        $tech = (float) ($applicant['research_points'] ?? 0);
        if ($total < (float) ($req['min_total_points'] ?? 0)) {
            return false;
        }
        if ($mil < (float) ($req['min_military_points'] ?? 0)) {
            return false;
        }
        if ($fleet < (float) ($req['min_fleet_points'] ?? 0)) {
            return false;
        }
        if ($tech < (float) ($req['min_research_points'] ?? 0)) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $row Alliance row with alliance_request
     */
    private static function applicantMeetsAllianceRow(array $ctx, array $row): bool
    {
        $meta = botAllianceParseRequirements((string) ($row['alliance_request'] ?? ''));
        $req = is_array($meta) && isset($meta['requirements']) && is_array($meta['requirements'])
            ? $meta['requirements']
            : [];
        $app = self::ctxToApplicantArray($ctx);
        if ($req === []) {
            return true;
        }

        return self::applicantMeetsNumericRequirements($req, $app);
    }

    /**
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $row
     */
    private static function applicantMeetsNumericRequirementsForRow(array $ctx, array $row): bool
    {
        $meta = botAllianceParseRequirements((string) ($row['alliance_request'] ?? ''));
        $req = is_array($meta) && isset($meta['requirements']) && is_array($meta['requirements'])
            ? $meta['requirements']
            : [];

        return self::applicantMeetsNumericRequirements($req, self::ctxToApplicantArray($ctx));
    }

    /**
     * @return array<string, mixed>
     */
    private static function ctxToApplicantArray(array $ctx): array
    {
        return [
            'total_points' => (float) ($ctx['stats']['total'] ?? 0),
            'military_points' => (float) ($ctx['stats']['military'] ?? 0),
            'fleet_points' => (float) ($ctx['stats']['ships'] ?? 0),
            'research_points' => (float) ($ctx['stats']['research'] ?? 0),
            'personality' => (string) ($ctx['personality'] ?? 'flotero'),
            'style' => (string) ($ctx['style'] ?? 'granja'),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function allianceAcceptsApplications(array $row): bool
    {
        return ((int) ($row['alliance_request_notallow'] ?? 0)) === 1;
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private static function rowRejected(array $ctx, int $allianceId): bool
    {
        if ($allianceId <= 0) {
            return true;
        }
        $rb = $ctx['rejected_by'] ?? [];
        if (!is_array($rb)) {
            return false;
        }
        $exp = (int) ($rb[(string) $allianceId] ?? 0);
        $now = (int) ($ctx['now'] ?? time());

        return $exp > $now;
    }

    /**
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $row
     */
    private static function personalityFitForAlliance(array $ctx, array $row): bool
    {
        $meta = botAllianceParseRequirements((string) ($row['alliance_request'] ?? ''));
        $req = is_array($meta) && isset($meta['requirements']) && is_array($meta['requirements'])
            ? $meta['requirements']
            : [];
        $p = (string) ($ctx['personality'] ?? 'flotero');
        $s = (string) ($ctx['style'] ?? 'granja');
        $wl = $req['personality_whitelist'] ?? null;
        if (is_array($wl) && $wl !== [] && !in_array($p, $wl, true)) {
            return false;
        }
        $swl = $req['style_whitelist'] ?? null;
        if (is_array($swl) && $swl !== [] && !in_array($s, $swl, true)) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $row
     */
    private static function scoreCandidate(array $ctx, array $row, bool $desperate): float
    {
        $members = (int) ($row['member_count'] ?? 0);
        $meta = botAllianceParseRequirements((string) ($row['alliance_request'] ?? ''));
        $botLed = $meta !== null;
        $score = 1.0 + min(5.0, $members / 4.0);
        if ($botLed) {
            $score += 0.5;
        }
        $p = (string) ($ctx['personality'] ?? 'flotero');
        if ($p === 'cazador' || $p === 'flotero') {
            $mil = (float) ($row['stat_military'] ?? 0);
            $score += min(3.0, log(1.0 + max(0.0, $mil)) / 3.0);
        }
        if ($p === 'minero' || $p === 'defensor') {
            $tot = (float) ($row['stat_total'] ?? 0);
            $score += min(2.5, log(1.0 + max(0.0, $tot)) / 4.0);
        }
        if ($p === 'tecnologico') {
            $tech = (float) ($row['stat_research'] ?? 0);
            $score += min(2.0, log(1.0 + max(0.0, $tech)) / 3.0);
        }
        if ($desperate) {
            $score += 0.25;
        }

        return $score;
    }

    private static function ownerAcceptsApplicantPersonality(string $ownerP, string $appP): bool
    {
        if ($ownerP === 'defensor' && $appP === 'cazador') {
            return false;
        }
        if ($ownerP === 'cazador' && ($appP === 'minero' || $appP === 'defensor')) {
            return false;
        }

        return true;
    }
}
