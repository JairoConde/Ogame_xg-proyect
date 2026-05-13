<?php

declare(strict_types=1);

/**
 * Common CLI bootstrap for the scripts under scripts/.
 *
 * Why this exists:
 *  - PHP CLI does not populate $_SERVER the way the web server does. Several
 *    files under config/ (notably constants.php) read $_SERVER['HTTP_HOST']
 *    and $_SERVER['SERVER_NAME'] when defining BASE_PATH / GAMEURL. Without
 *    a stub, those defines emit warnings and produce nonsense values.
 *  - Every CLI script needs the same minimal pre-load: stubs, config and
 *    constants. Centralizing it here means a future change (e.g. another
 *    $_SERVER key, a new config file) only touches one place.
 *
 * Usage from any CLI script under scripts/:
 *
 *     require __DIR__ . '/bootstrap.php';
 *
 * After this returns:
 *  - $_SERVER has the minimum keys required by config/constants.php.
 *  - All defines from config/config.php and config/constants.php are loaded.
 *  - The vendor autoloader is loaded if present (so namespaced classes from
 *    app/ can be used without per-script wiring).
 *
 * What this DOES NOT load:
 *  - app/Core/objects_collection.php: only the bot needs it. Scripts that
 *    require it (e.g. simple_rule_bot.php) load it themselves after.
 *
 * Idempotent: safe to require multiple times (config/constants files use
 * define()/define-equivalents that no-op on re-entry, and the autoloader
 * uses require_once internally).
 */
if (php_sapi_name() !== 'cli') {
    // Hard guard: this bootstrap is for command-line scripts only. If it is
    // ever required from a web request by mistake, abort early so we don't
    // mask web-side configuration with stubs.
    fwrite(STDERR, "scripts/bootstrap.php must only be loaded from the CLI.\n");
    exit(1);
}

// $_SERVER stubs required by config/constants.php under CLI.
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? 'localhost';
// SCRIPT_NAME is normally populated by PHP itself in CLI (the script path),
// but we guard against degenerate environments just in case.
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? ($argv[0] ?? 'cli');

// XGP_ROOT is defined by every web entry point under public/ before booting.
// CLI scripts skip that entry point, so any framework class that ends up
// loading App\Core\Database (which requires XGP_ROOT . CONFIGS_PATH . 'config.php'
// in its constructor) would crash with "undefined constant XGP_ROOT". We mirror
// the value used in public/index.php so paths resolve identically.
if (!defined('XGP_ROOT')) {
    define('XGP_ROOT', realpath(dirname(__DIR__)) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/constants.php';

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
