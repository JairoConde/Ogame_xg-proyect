<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * Rewrites every xgp_fleets.fleet_array row that was stored in the
 * legacy "shipId,count;shipId,count;" format into the canonical PHP
 * serialize() payload that FleetsLib::getFleetShipsArray() consumes.
 *
 * Older bot transports used the wrong format and would crash the
 * engine on return. Safe to re-run: rows that already start with "a:"
 * are skipped.
 */
if (!class_exists('mysqli')) {
    fwrite(STDERR, "The mysqli extension is not enabled in this PHP runtime.\n");
    exit(1);
}

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_error) {
    fwrite(STDERR, "Database connection failed: {$mysqli->connect_error}\n");
    exit(1);
}
$mysqli->set_charset('utf8');

$prefix = DB_PREFIX;
$tbl = $prefix . 'fleets';

$res = $mysqli->query(
    "SELECT `fleet_id`, `fleet_array` FROM `{$tbl}`
     WHERE `fleet_array` IS NOT NULL
       AND `fleet_array` <> ''
       AND `fleet_array` NOT LIKE 'a:%'"
);
if (!$res) {
    fwrite(STDERR, "Query failed: {$mysqli->error}\n");
    $mysqli->close();
    exit(1);
}

$updated = 0;
$skipped = 0;
$bad = [];
while ($row = $res->fetch_assoc()) {
    $fleetId = (int) $row['fleet_id'];
    $raw = trim((string) $row['fleet_array']);
    if ($raw === '') {
        $skipped++;

        continue;
    }
    // Expected legacy format: "shipId,count;shipId,count;"
    $pairs = array_filter(explode(';', $raw), static fn (string $p) => $p !== '');
    $mix = [];
    $ok = true;
    foreach ($pairs as $pair) {
        $parts = explode(',', $pair);
        if (count($parts) !== 2 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
            $ok = false;

            break;
        }
        $mix[(int) $parts[0]] = (int) $parts[1];
    }
    if (!$ok || $mix === []) {
        $bad[] = $fleetId;

        continue;
    }
    $newArray = serialize($mix);
    $escaped = $mysqli->real_escape_string($newArray);
    $upd = $mysqli->query(
        "UPDATE `{$tbl}` SET `fleet_array` = '{$escaped}'
         WHERE `fleet_id` = {$fleetId} LIMIT 1"
    );
    if ($upd) {
        $updated++;
    } else {
        $bad[] = $fleetId;
    }
}
$res->free();

echo "Rewrote {$updated} fleet rows from legacy to serialize().";
if ($skipped > 0) {
    echo " Skipped {$skipped} empty rows.";
}
if (!empty($bad)) {
    echo ' Could not parse: ' . implode(',', $bad) . '.';
}
echo "\n";
$mysqli->close();
