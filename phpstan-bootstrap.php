<?php

/**
 * PHPStan bootstrap stub for static analysis.
 * Defines constants that are normally set at runtime by public/index.php.
 */
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
define('XGP_ROOT', __DIR__ . DIRECTORY_SEPARATOR);

// Now include the real constants (they depend on XGP_ROOT)
require_once __DIR__ . '/config/constants.php';

/*
 * --------------------------------------------------------------------------
 * Stubs for bot library functions (scripts/bot_lib/*.php) that are
 * loaded at runtime by DiplomacyController and AllianceDiplomacyFleetHook.
 * --------------------------------------------------------------------------
 */

if (!function_exists('botDiplomacyIsAtWar')) {
    function botDiplomacyIsAtWar(mysqli $db, string $prefix, int $a, int $b): bool { return false; }
}
if (!function_exists('botDiplomacyAllianceIsLosingSideOfWar')) {
    function botDiplomacyAllianceIsLosingSideOfWar(int $myAllyId, int $a, int $b, int $a2b, int $b2a): bool { return false; }
}
if (!function_exists('botDiplomacyPeaceBribeOfferedByLoser')) {
    function botDiplomacyPeaceBribeOfferedByLoser(int $myAllyId, int $a, int $b, int $a2b, int $b2a): int { return 0; }
}
if (!function_exists('botDiplomacyPeaceBribeRequiredForReceiver')) {
    function botDiplomacyPeaceBribeRequiredForReceiver(int $receiverAllyId, int $loserAllyId, int $a2b, int $b2a): int { return 0; }
}
if (!function_exists('botDiplomacyDeclareWar')) {
    function botDiplomacyDeclareWar(mysqli $db, string $prefix, int $declarerAllyId, int $targetAllyId, int $declarerUserId, int $now): bool { return false; }
}
if (!function_exists('botDiplomacyUpsertNap')) {
    function botDiplomacyUpsertNap(mysqli $db, string $prefix, int $a, int $b, int $userId, ?int $durationSec, int $now): bool { return false; }
}
if (!function_exists('botDiplomacySignPeace')) {
    function botDiplomacySignPeace(mysqli $db, string $prefix, int $a, int $b, int $userId, string $reason, array $meta, int $now): bool { return false; }
}
if (!function_exists('botDiplomacyRecordAttack')) {
    function botDiplomacyRecordAttack(mysqli $db, string $prefix, int $attackerAlly, int $victimAlly, int $pressureUnit, int $now): void {}
}
if (!function_exists('botDiplomacyAddDamage')) {
    function botDiplomacyAddDamage(mysqli $db, string $prefix, int $fromAlly, int $toAlly, int $damage, int $now): void {}
}
if (!function_exists('botCombatLossesValue')) {
    function botCombatLossesValue(array $lossesById, array $pricelist): int { return 0; }
}
if (!function_exists('get_config')) {
    /**
     * @return array<string, mixed>
     */
    function get_config(): array { return []; }
}

