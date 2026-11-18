<?php
session_start();
require 'db.php';
/** @var PDO $pdo */

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId   = (int)$_SESSION['user_id'];
$savedMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email       = trim($_POST['email'] ?? '');
    $notifyGames = isset($_POST['notify_games_simmed']) ? 1 : 0;
    $notifyInj   = isset($_POST['notify_injuries']) ? 1 : 0;
    $notifyTrade = isset($_POST['notify_trade_block']) ? 1 : 0;

    $upStmt = $pdo->prepare("
        UPDATE users
        SET email = :email,
            notify_games_simmed = :ng::boolean,
            notify_injuries = :ni::boolean,
            notify_trade_block = :nt::boolean
        WHERE user_id = :uid
    ");
    $upStmt->execute(array(
        'email' => $email,
        'ng'    => $notifyGames,
        'ni'    => $notifyInj,
        'nt'    => $notifyTrade,
        'uid'   => $userId
    ));

    $savedMsg = "Email & notification preferences updated!";
}

$stmt = $pdo->prepare("
    SELECT u.username, u.email, u.notify_games_simmed, u.notify_injuries, u.notify_trade_block, t.name AS team_name
    FROM users u
    LEFT JOIN teams t ON u.team_id = t.team_id
    WHERE u.user_id = ?
");
$stmt->execute(array($userId));
$user = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>GM Notification Settings - HFNHL</title>
    <style>
        body { font-family: Arial, sans-serif; background: #0f172a; color: #f8fafc; padding: 20px; max-width: 720px; margin: auto; }
        nav { background: #020617; padding: 12px 18px; border-radius: 8px; margin-bottom: 24px; display: flex; gap: 20px; border: 1px solid #334155; align-items: center; }
        nav a { color: #38bdf8; text-decoration: none; font-weight: bold; }
        .card { background: #1e293b; padding: 26px; border-radius: 10px; border: 1px solid #334155; }
        h1 { color: #38bdf8; margin-top: 0; font-size: 1.6em; }
        .sub { color: #94a3b8; margin-bottom: 20px; font-size: 0.95em; }
        .alert { background: #14532d; color: #86efac; padding: 12px 16px; border-radius: 6px; border: 1px solid #22c55e; margin-bottom: 18px; font-weight: bold; }
        label.field-label { display: block; font-weight: bold; margin-bottom: 6px; color: #e2e8f0; }
        input[type=email] { width: 100%; padding: 10px 12px; border-radius: 6px; border: 1px solid #475569; background: #0f172a; color: #fff; font-size: 1em; box-sizing: border-box; margin-bottom: 22px; }
        .toggle-row { display: flex; align-items: flex-start; gap: 12px; padding: 14px; background: #0f172a; border: 1px solid #334155; border-radius: 8px; margin-bottom: 12px; cursor: pointer; }
        .toggle-row input[type=checkbox] { width: 18px; height: 18px; margin-top: 2px; accent-color: #38bdf8; }
        .toggle-title { font-weight: bold; color: #f8fafc; display: block; }
        .toggle-desc { color: #94a3b8; font-size: 0.88em; margin-top: 3px; display: block; }
        button { background: #0284c7; color: #fff; border: none; padding: 12px 24px; border-radius: 6px; font-weight: bold; font-size: 1em; cursor: pointer; margin-top: 10px; }
        button:hover { background: #0369a1; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>

<div class="card">
    <h1>Email &amp; Alert Preferences</h1>
    <div class="sub">GM Account: <strong><?= htmlspecialchars($user['username']) ?></strong> (<?= htmlspecialchars($user['team_name'] ?? 'Commissioner') ?>)</div>

    <?php if ($savedMsg): ?>
        <div class="alert"><?= htmlspecialchars($savedMsg) ?></div>
    <?php endif; ?>

    <form method="post">
        <label class="field-label" for="email">GM Email Address</label>
        <input type="email" id="email" name="email" placeholder="you@example.com" value="<?= htmlspecialchars($user['email'] ?? '') ?>">

        <label class="toggle-row">
            <input type="checkbox" name="notify_games_simmed" <?= !empty($user['notify_games_simmed']) ? 'checked' : '' ?>>
            <span>
                    <span class="toggle-title">Games Simmed &amp; Daily Scoreboard</span>
                    <span class="toggle-desc">Receive an email summary whenever a night of HFNHL games finishes simulating.</span>
                </span>
        </label>

        <label class="toggle-row">
            <input type="checkbox" name="notify_injuries" <?= !empty($user['notify_injuries']) ? 'checked' : '' ?>>
            <span>
                    <span class="toggle-title">Team Injury &amp; Recovery Updates</span>
                    <span class="toggle-desc">Get alerted immediately if one of your players is injured or cleared to return so you can adjust your lines.</span>
                </span>
        </label>

        <label class="toggle-row">
            <input type="checkbox" name="notify_trade_block" <?= !empty($user['notify_trade_block']) ? 'checked' : '' ?>>
            <span>
                    <span class="toggle-title">League Trade Block Alerts</span>
                    <span class="toggle-desc">Receive batch digest emails when another GM updates their Trade Block or shopping list.</span>
                </span>
        </label>

        <button type="submit">Save Notification Preferences</button>
    </form>
</div>
</body>
</html>

