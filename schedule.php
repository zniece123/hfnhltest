<?php
require 'db.php';

$finals = $pdo->query("
    SELECT g.*, h.name AS home_name, h.abbreviation AS home_abbr, a.name AS away_name, a.abbreviation AS away_abbr
    FROM games g
    JOIN teams h ON g.home_team_id = h.team_id
    JOIN teams a ON g.away_team_id = a.team_id
    WHERE g.status = 'FINAL'
    ORDER BY g.game_date DESC, g.game_id DESC
    LIMIT 30
")->fetchAll();

$upcoming = $pdo->query("
    SELECT g.*, h.name AS home_name, h.abbreviation AS home_abbr, a.name AS away_name, a.abbreviation AS away_abbr
    FROM games g
    JOIN teams h ON g.home_team_id = h.team_id
    JOIN teams a ON g.away_team_id = a.team_id
    WHERE g.status = 'SCHEDULED'
    ORDER BY g.game_date ASC, g.game_id ASC
    LIMIT 30
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Scores &amp; Schedule - HFNHL</title>
    <style>
        body { font-family: Arial, sans-serif; background: #0f172a; color: #f8fafc; padding: 20px; max-width: 1100px; margin: auto; }
        nav { background: #020617; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; display: flex; gap: 20px; border: 1px solid #334155; }
        nav a { color: #38bdf8; text-decoration: none; font-weight: bold; }
        h1, h2 { color: #38bdf8; }
        table { width: 100%; border-collapse: collapse; background: #1e293b; border-radius: 8px; overflow: hidden; margin-bottom: 30px; }
        th, td { padding: 11px 14px; text-align: left; border-bottom: 1px solid #334155; }
        th { background: #020617; color: #38bdf8; }
        tr:hover { background: #273549; }
        a.btn { background: #0284c7; color: #fff; padding: 5px 10px; border-radius: 5px; text-decoration: none; font-size: 0.85em; font-weight: bold; }
        a.btn:hover { background: #0369a1; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>

<h1>Recent Results (<?= count($finals) ?>)</h1>
<table>
    <tr><th>Date</th><th>Away</th><th>Score</th><th>Home</th><th>Result</th><th>Box Score</th></tr>
    <?php foreach ($finals as $g): ?>
        <tr>
            <td><?= htmlspecialchars($g['game_date']) ?></td>
            <td><?= htmlspecialchars($g['away_name']) ?> (<?= $g['away_abbr'] ?>)</td>
            <td><strong><?= $g['away_score'] ?> - <?= $g['home_score'] ?></strong></td>
            <td><?= htmlspecialchars($g['home_name']) ?> (<?= $g['home_abbr'] ?>)</td>
            <td><?= $g['is_shootout'] ? 'Final (SO)' : ($g['is_overtime'] ? 'Final (OT)' : 'Final') ?></td>
            <td><a class="btn" href="boxscore.php?id=<?= $g['game_id'] ?>">Box Score &amp; Log</a></td>
        </tr>
    <?php endforeach; ?>
</table>

<h2>Upcoming Schedule (Next 30 Games)</h2>
<table>
    <tr><th>Date</th><th>Away</th><th>@</th><th>Home</th><th>Status</th></tr>
    <?php foreach ($upcoming as $g): ?>
        <tr>
            <td><?= htmlspecialchars($g['game_date']) ?></td>
            <td><?= htmlspecialchars($g['away_name']) ?> (<?= $g['away_abbr'] ?>)</td>
            <td>@</td>
            <td><?= htmlspecialchars($g['home_name']) ?> (<?= $g['home_abbr'] ?>)</td>
            <td>Scheduled</td>
        </tr>
    <?php endforeach; ?>
</table>
</body>
</html>

