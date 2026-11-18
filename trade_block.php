<?php
require 'db.php';
require 'mailer.php';
/** @var PDO $pdo */

$isLoggedIn = isset($_SESSION['user_id']);
$myTeamId   = (int)($_SESSION['team_id'] ?? 0);
$savedMsg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isLoggedIn && $myTeamId > 0) {
    $selectedPids = array_map('intval', $_POST['block_pids'] ?? array());
    $playerNotes  = $_POST['pnote'] ?? array();
    $teamNeeds    = trim($_POST['team_needs'] ?? '');

    $pdo->prepare("DELETE FROM trade_block WHERE team_id = ?")->execute(array($myTeamId));

    $ins = $pdo->prepare("INSERT INTO trade_block (player_id, team_id, player_note) VALUES (?, ?, ?)");
    $listedNames = array();

    foreach ($selectedPids as $pid) {
        if ($pid <= 0) continue;
        $note = trim($playerNotes[$pid] ?? '');
        $ins->execute(array($pid, $myTeamId, $note));

        $pInfo = $pdo->prepare("SELECT first_name, last_name, position, overall_rating FROM players WHERE player_id = ? AND team_id = ?");
        $pInfo->execute(array($pid, $myTeamId));
        if ($row = $pInfo->fetch()) {
            $line = "- {$row['first_name']} {$row['last_name']} ({$row['position']}, {$row['overall_rating']} OVR)";
            if ($note !== '') $line .= " — Note: {$note}";
            $listedNames[] = $line;
        }
    }

    $pdo->prepare("
        INSERT INTO trade_block_notes (team_id, team_needs, updated_at)
        VALUES (:tid, :needs, NOW())
        ON CONFLICT (team_id) DO UPDATE SET team_needs = EXCLUDED.team_needs, updated_at = NOW()
    ")->execute(array('tid' => $myTeamId, 'needs' => $teamNeeds));

    if (!empty($listedNames) || $teamNeeds !== '') {
        $tName = $pdo->query("SELECT name FROM teams WHERE team_id = {$myTeamId}")->fetchColumn();
        $emails = $pdo->query("SELECT email FROM users WHERE notify_trade_block = TRUE AND email <> '' AND team_id <> {$myTeamId}")->fetchAll(PDO::FETCH_COLUMN);

        $body  = "{$tName} updated their HFNHL Trade Block:\n\n";
        $body .= !empty($listedNames) ? "Players Available:\n" . implode("\n", $listedNames) . "\n\n" : "";
        $body .= $teamNeeds !== '' ? "Team Needs / Shopping List:\n{$teamNeeds}\n\n" : "";
        $body .= "Log in to the HFNHL Trade Center to submit an offer.";

        sendLeagueEmail($emails, "HFNHL Trade Block Update: {$tName}", $body);
    }

    $savedMsg = "Your Trade Block has been updated and subscribed GMs have been notified!";
}

$blockRows = $pdo->query("
    SELECT tb.player_id, tb.team_id, tb.player_note, tb.added_at,
           p.first_name, p.last_name, p.position, p.age, p.overall_rating, p.salary, p.contract_years,
           t.name AS team_name, t.abbreviation,
           tbn.team_needs
    FROM trade_block tb
    JOIN players p ON tb.player_id = p.player_id
    JOIN teams t ON tb.team_id = t.team_id
    LEFT JOIN trade_block_notes tbn ON tb.team_id = tbn.team_id
    ORDER BY t.name ASC, p.overall_rating DESC
")->fetchAll();

$myPlayers = array();
$myBlockIds = array();
$myNeeds = '';
if ($isLoggedIn && $myTeamId > 0) {
    $mp = $pdo->prepare("SELECT player_id, first_name, last_name, position, age, overall_rating, roster_status FROM players WHERE team_id = ? ORDER BY overall_rating DESC");
    $mp->execute(array($myTeamId));
    $myPlayers = $mp->fetchAll();

    $mb = $pdo->prepare("SELECT player_id, player_note FROM trade_block WHERE team_id = ?");
    $mb->execute(array($myTeamId));
    foreach ($mb->fetchAll() as $r) $myBlockIds[$r['player_id']] = $r['player_note'];

    $mn = $pdo->prepare("SELECT team_needs FROM trade_block_notes WHERE team_id = ?");
    $mn->execute(array($myTeamId));
    $myNeeds = (string)$mn->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>HFNHL Trade Block</title>
    <style>
        body { font-family: Arial, sans-serif; background: #0f172a; color: #f8fafc; padding: 20px; max-width: 1200px; margin: auto; }
        nav { background: #020617; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; display: flex; gap: 20px; border: 1px solid #334155; align-items: center; }
        nav a { color: #38bdf8; text-decoration: none; font-weight: bold; }
        h1, h2 { color: #38bdf8; }
        .card { background: #1e293b; padding: 20px; border-radius: 8px; border: 1px solid #334155; margin-bottom: 24px; }
        table { width: 100%; border-collapse: collapse; background: #1e293b; margin-top: 10px; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #334155; }
        th { background: #020617; color: #38bdf8; }
        input[type=text], textarea { width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #475569; background: #0f172a; color: #fff; box-sizing: border-box; }
        .alert { background: #14532d; color: #86efac; padding: 12px; border-radius: 6px; border: 1px solid #22c55e; margin-bottom: 16px; font-weight: bold; }
        .btn { background: #0284c7; color: #fff; border: none; padding: 12px 22px; border-radius: 6px; font-weight: bold; cursor: pointer; margin-top: 14px; }
        .btn:hover { background: #0369a1; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>

<h1>HFNHL League Trade Block</h1>
<?php if ($savedMsg): ?><div class="alert"><?= htmlspecialchars($savedMsg) ?></div><?php endif; ?>

<?php if ($isLoggedIn && $myTeamId > 0): ?>
    <div class="card">
        <h2 style="margin-top:0;">Manage My Trade Block (Batch Update &amp; Email Alert)</h2>
        <form method="post">
            <label><strong>Team Needs / Shopping List:</strong></label>
            <textarea name="team_needs" rows="2" placeholder="e.g., Looking for a Top-4 RHD, 2027 1st Round Picks, or Goalie depth..."><?= htmlspecialchars($myNeeds) ?></textarea>

            <table>
                <tr><th style="width:60px;">On Block</th><th>Player</th><th>Pos</th><th>Age</th><th>OVR</th><th>Status</th><th>Player Trade Note</th></tr>
                <?php foreach ($myPlayers as $p):
                    $pid = $p['player_id'];
                    $checked = array_key_exists($pid, $myBlockIds) ? 'checked' : '';
                    $pNote = $myBlockIds[$pid] ?? '';
                    ?>
                    <tr>
                        <td><input type="checkbox" name="block_pids[]" value="<?= $pid ?>" <?= $checked ?>></td>
                        <td><strong><?= htmlspecialchars($p['first_name'] . ' ' . $p['last_name']) ?></strong></td>
                        <td><?= htmlspecialchars($p['position']) ?></td>
                        <td><?= (int)$p['age'] ?></td>
                        <td><?= (int)$p['overall_rating'] ?></td>
                        <td><?= htmlspecialchars($p['roster_status']) ?></td>
                        <td><input type="text" name="pnote[<?= $pid ?>]" value="<?= htmlspecialchars($pNote) ?>" placeholder="Optional asking price..."></td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <button type="submit" class="btn">Save Trade Block &amp; Send Batch Alert</button>
        </form>
    </div>
<?php endif; ?>

<h2>Available Players Around the League</h2>
<?php if (empty($blockRows)): ?>
    <p style="color:#94a3b8;">No players are currently listed on the Trade Block.</p>
<?php else: ?>
    <table>
        <tr><th>Team</th><th>Player</th><th>Pos</th><th>Age</th><th>OVR</th><th>Salary</th><th>Note &amp; Team Needs</th><th>Action</th></tr>
        <?php foreach ($blockRows as $b): ?>
            <tr>
                <td><strong><?= htmlspecialchars($b['team_name']) ?></strong></td>
                <td><?= htmlspecialchars($b['first_name'] . ' ' . $b['last_name']) ?></td>
                <td><?= htmlspecialchars($b['position']) ?></td>
                <td><?= (int)$b['age'] ?></td>
                <td><?= (int)$b['overall_rating'] ?></td>
                <td>$<?= number_format((int)$b['salary']) ?> (<?= (int)$b['contract_years'] ?>y)</td>
                <td>
                    <?= $b['player_note'] ? '<div><strong>Note:</strong> ' . htmlspecialchars($b['player_note']) . '</div>' : '' ?>
                    <?= $b['team_needs'] ? '<div style="color:#94a3b8;font-size:0.88em;"><em>Needs: ' . htmlspecialchars($b['team_needs']) . '</em></div>' : '' ?>
                </td>
                <td>
                    <?php if ($isLoggedIn && $myTeamId !== (int)$b['team_id']): ?>
                        <a href="trades.php?partner=<?= (int)$b['team_id'] ?>" style="color:#38bdf8;font-weight:bold;text-decoration:none;">Make Offer &rarr;</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>
</body>
</html>

