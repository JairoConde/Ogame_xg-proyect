<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Libraries\BattleEngine\Core\BattleReport;
use App\Libraries\BattleEngine\Models\PlayerGroup;
use mysqli;

/**
 * Mirrors scripts/bot_lib/attack.php diplomacy bookkeeping for human raids:
 * pressure row + war damage columns when a battle mission finishes.
 */
final class AllianceDiplomacyFleetHook
{
    /**
     * @param array<string, mixed> $attackerUserRow  Must include user_ally_id
     * @param array<string, mixed> $defenderUserRow Must include user_ally_id
     * @param array<string, mixed> $steal           metal/crystal/deuterium stolen
     * @param array<int, array<string, int|float>> $pricelist
     */
    public static function afterBattleRaid(
        mysqli $db,
        string $prefix,
        array $attackerUserRow,
        array $defenderUserRow,
        BattleReport $report,
        PlayerGroup $afterBattleAttackers,
        array $steal,
        array $pricelist,
        ?int $now = null
    ): void {
        $now = $now ?? time();

        $libDir = XGP_ROOT . 'scripts' . DIRECTORY_SEPARATOR . 'bot_lib' . DIRECTORY_SEPARATOR;
        require_once $libDir . 'safety.php';
        require_once $libDir . 'diplomacy.php';
        require_once $libDir . 'combat.php';

        $attackerAlly = (int) ($attackerUserRow['user_ally_id'] ?? 0);
        $victimAlly = (int) ($defenderUserRow['user_ally_id'] ?? 0);
        if ($attackerAlly <= 0 || $victimAlly <= 0 || $attackerAlly === $victimAlly) {
            return;
        }

        $loot = (int) ($steal['metal'] ?? 0) + (int) ($steal['crystal'] ?? 0) + (int) ($steal['deuterium'] ?? 0);

        $attackersBefore = $report->getRound('START')->getAfterBattleAttackers();
        $attackersLost = $report->getPlayersLostShips($attackersBefore, $afterBattleAttackers);
        $lossesById = self::playerGroupToLossesByUnit($attackersLost);
        $attackerLossValue = botCombatLossesValue($lossesById, $pricelist);

        $pressureUnit = $loot + $attackerLossValue;
        if ($pressureUnit > 0) {
            botDiplomacyRecordAttack($db, $prefix, $attackerAlly, $victimAlly, $pressureUnit, $now);
        }
        if ($loot > 0) {
            botDiplomacyAddDamage($db, $prefix, $attackerAlly, $victimAlly, $loot, $now);
        }
        if ($attackerLossValue > 0) {
            botDiplomacyAddDamage($db, $prefix, $victimAlly, $attackerAlly, $attackerLossValue, $now);
        }
    }

    /**
     * @return array<int, int>
     */
    private static function playerGroupToLossesByUnit(PlayerGroup $lost): array
    {
        $byId = [];
        foreach ($lost->getIterator() as $player) {
            foreach ($player->getIterator() as $fleet) {
                foreach ($fleet->getIterator() as $idShipType => $shipType) {
                    $tid = (int) $idShipType;
                    $cnt = (int) round($shipType->getCount());
                    if ($cnt > 0) {
                        $byId[$tid] = ($byId[$tid] ?? 0) + $cnt;
                    }
                }
            }
        }

        return $byId;
    }
}
