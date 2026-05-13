<?php

declare(strict_types=1);

/**
 * Bootstrap for the bot unit-test suite.
 *
 * The CLI bot has its own bootstrap (scripts/bootstrap.php) that opens a
 * MySQL connection on require, so we cannot reuse it from PHPUnit. We
 * mirror the subset of setup the bot library actually needs:
 *
 *   - Stub minimal $_SERVER values config/constants.php expects under CLI.
 *   - Define XGP_ROOT (consumed by App\Core\Database when any framework
 *     class is loaded transitively).
 *   - Load config/config.php and config/constants.php so MAX_SYSTEM_IN_GALAXY,
 *     CONFIGS_PATH, CORE_PATH, etc. are defined.
 *   - Load Composer's autoloader so App\Libraries\* classes resolve.
 *   - Load the Objects collection so $resource / $ProdGrid / $pricelist
 *     end up in $GLOBALS, which the bot lib reads from.
 *   - Load every scripts/bot_lib/*.php module.
 *
 * It does NOT load scripts/simple_rule_bot.php (that file has top-level
 * code that opens a database connection and parses CLI argv).
 */
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? 'cli';

$root = realpath(__DIR__ . '/../..') . DIRECTORY_SEPARATOR;
if (!defined('XGP_ROOT')) {
    define('XGP_ROOT', $root);
}

require_once $root . 'config' . DIRECTORY_SEPARATOR . 'config.php';
require_once $root . 'config' . DIRECTORY_SEPARATOR . 'constants.php';
require_once $root . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

// Pull objects_collection.php into the global scope so $pricelist,
// $resource and $ProdGrid become available via $GLOBALS for travel.php
// (botCargoCapacity reads $GLOBALS['pricelist']) and personality.php.
require_once $root . 'app' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'objects_collection.php';

require_once $root . 'scripts' . DIRECTORY_SEPARATOR . 'bot_lib' . DIRECTORY_SEPARATOR . 'util.php';
require_once $root . 'scripts' . DIRECTORY_SEPARATOR . 'bot_lib' . DIRECTORY_SEPARATOR . 'safety.php';
require_once $root . 'scripts' . DIRECTORY_SEPARATOR . 'bot_lib' . DIRECTORY_SEPARATOR . 'state.php';
require_once $root . 'scripts' . DIRECTORY_SEPARATOR . 'bot_lib' . DIRECTORY_SEPARATOR . 'personality.php';
require_once $root . 'scripts' . DIRECTORY_SEPARATOR . 'bot_lib' . DIRECTORY_SEPARATOR . 'decisions.php';
require_once $root . 'scripts' . DIRECTORY_SEPARATOR . 'bot_lib' . DIRECTORY_SEPARATOR . 'strategy.php';
require_once $root . 'scripts' . DIRECTORY_SEPARATOR . 'bot_lib' . DIRECTORY_SEPARATOR . 'logging.php';
require_once $root . 'scripts' . DIRECTORY_SEPARATOR . 'bot_lib' . DIRECTORY_SEPARATOR . 'travel.php';
require_once $root . 'scripts' . DIRECTORY_SEPARATOR . 'bot_lib' . DIRECTORY_SEPARATOR . 'colonization.php';
require_once $root . 'scripts' . DIRECTORY_SEPARATOR . 'bot_lib' . DIRECTORY_SEPARATOR . 'transport.php';
require_once $root . 'scripts' . DIRECTORY_SEPARATOR . 'bot_lib' . DIRECTORY_SEPARATOR . 'combat.php';
require_once $root . 'scripts' . DIRECTORY_SEPARATOR . 'bot_lib' . DIRECTORY_SEPARATOR . 'attack.php';
require_once $root . 'scripts' . DIRECTORY_SEPARATOR . 'bot_lib' . DIRECTORY_SEPARATOR . 'alliance.php';
require_once $root . 'scripts' . DIRECTORY_SEPARATOR . 'bot_lib' . DIRECTORY_SEPARATOR . 'diplomacy.php';
require_once $root . 'scripts' . DIRECTORY_SEPARATOR . 'bot_lib' . DIRECTORY_SEPARATOR . 'diplomacy_actions.php';
require_once $root . 'scripts' . DIRECTORY_SEPARATOR . 'bot_lib' . DIRECTORY_SEPARATOR . 'acs_attack.php';
require_once $root . 'scripts' . DIRECTORY_SEPARATOR . 'bot_lib' . DIRECTORY_SEPARATOR . 'ally_logistics.php';
