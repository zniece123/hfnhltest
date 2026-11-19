<?php
require 'db.php';
require_once 'sim_engine.php';
/** @var PDO $pdo */

ensureSimSchema($pdo);

$daysToSim = isset($argv[1]) ? (int)$argv[1] : 185;
$enforce   = false;

echo "Starting batch simulation for {$daysToSim} days...\n";
$startTime = microtime(true);

for ($i = 1; $i <= $daysToSim; $i++) {
    $res = advanceSimDay($pdo, $enforce);

    if (!$res['ok']) {
        echo "\n[STOPPED on Day {$i}]: {$res['msg']}\n";
        break;
    }

    if ($i % 10 === 0 || $i === $daysToSim) {
        echo "Day {$i}/{$daysToSim} -> {$res['msg']}\n";
    }
}

$elapsed = round(microtime(true) - $startTime, 2);
echo "Batch simulation finished in {$elapsed} seconds!\n";

