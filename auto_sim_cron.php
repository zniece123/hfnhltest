<?php
require 'db.php';
require_once 'sim_engine.php';
/** @var PDO $pdo */

ensureSimSchema($pdo);

$nowEst   = new DateTime('now', new DateTimeZone('America/Toronto'));
$todayStr = $nowEst->format('Y-m-d');
$curTime  = $nowEst->format('H:i');

if (getLeagueSetting($pdo, 'auto_sim_enabled', '0') !== '1') {
    die("Auto-sim disabled.\n");
}
if (getLeagueSetting($pdo, 'last_auto_sim_date', '') === $todayStr) {
    die("Already ran auto-sim today ({$todayStr}).\n");
}

$targetTime = getLeagueSetting($pdo, 'sim_time_est', '20:00');
if ($curTime < $targetTime) {
    die("Waiting until {$targetTime} EST (current: {$curTime} EST).\n");
}

$enforce = (getLeagueSetting($pdo, 'enforce_compliance', '1') === '1');
$res     = advanceSimDay($pdo, $enforce);

if ($res['ok']) {
    setLeagueSetting($pdo, 'last_auto_sim_date', $todayStr);
    echo "SUCCESS: {$res['msg']}\n";
} else {
    echo "PAUSED: {$res['msg']}\n";
}

