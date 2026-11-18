<?php
require 'db.php';

$game_id = isset($_GET['id']) ? (int)$_GET['id'] : 1;

$stmt = $pdo->prepare("
    SELECT g.*, h.name AS home_name, h.abbreviation AS home_abbr, a.name AS away_name, a.abbreviation AS away_abbr
    FROM games g
    JOIN teams h ON g.home_team_id = h.team_id
    JOIN teams a ON g.away_team_id = a.team_id
    WHERE g.game_id = ?
");
$stmt->execute([$game_id]);
$game = $stmt->fetch();
if (!$game) die("Game not found.");

$sStmt = $pdo->prepare("
    SELECT pgs.*, p.first_name, p.last_name, p.position, p.team_id
    FROM player_game_stats pgs
    JOIN players p ON pgs.player_id = p.player_id
    WHERE pgs.game_id = ? AND pgs.toi_seconds > 0
    ORDER BY p.position = 'G', (pgs.goals + pgs.assists) DESC, pgs.goals DESC, pgs.shots DESC
");
$sStmt->execute([$game_id]);
$stats = $sStmt->fetchAll();

$awaySkaters = array_filter($stats, fn($s) => $s['team_id'] == $game['away_team_id'] && $s['position'] !== 'G');
$homeSkaters = array_filter($stats, fn($s) => $s['team_id'] == $game['home_team_id'] && $s['position'] !== 'G');
$goalies     = array_filter($stats, fn($s) => $s['position'] === 'G');

$events = $game['play_by_play'] ? json_decode($game['play_by_play'], true) : [];

function formatToi($sec) {
    return sprintf('%02d:%02d', floor($sec / 60), $sec % 60);
}

$awayTotalShots = $game['away_p1_shots'] + $game['away_p2_shots'] + $game['away_p3_shots'] + $game['away_ot_shots'];
$homeTotalShots = $game['home_p1_shots'] + $game['home_p2_shots'] + $game['home_p3_shots'] + $game['home_ot_shots'];

$awayPpPct = $game['away_pp_opps'] > 0 ? number_format(($game['away_pp_goals'] / $game['away_pp_opps']) * 100, 1) . '%' : '0.0%';
$homePpPct = $game['home_pp_opps'] > 0 ? number_format(($game['home_pp_goals'] / $game['home_pp_opps']) * 100, 1) . '%' : '0.0%';

$awayPkKills = max(0, $game['home_pp_opps'] - $game['home_pp_goals']);
$homePkKills = max(0, $game['away_pp_opps'] - $game['away_pp_goals']);
$awayPkPct   = $game['home_pp_opps'] > 0 ? number_format(($awayPkKills / $game['home_pp_opps']) * 100, 1) . '%' : '100.0%';
$homePkPct   = $game['away_pp_opps'] > 0 ? number_format(($homePkKills / $game['away_pp_opps']) * 100, 1) . '%' : '100.0%';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $game['away_abbr'] ?> vs <?= $game['home_abbr'] ?> Box Score - HFNHL</title>
    <style>
        body { font-family: Arial, sans-serif; background: #0f172a; color: #f8fafc; padding: 20px; max-width: 1100px; margin: auto; }
        nav { background: #020617; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; display: flex; gap: 20px; border: 1px solid #334155; }
        nav a { color: #38bdf8; text-decoration: none; font-weight: bold; }
        .scoreboard { background: #1e293b; padding: 20px; border-radius: 8px; border: 1px solid #334155; text-align: center; margin-bottom: 20px; }
        .scoreboard h1 { margin: 5px 0 0 0; color: #38bdf8; font-size: 1.8em; }
        .summary-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-bottom: 25px; }
        .box-card { background: #1e293b; padding: 14px; border-radius: 8px; border: 1px solid #334155; }
        .box-card h3 { margin: 0 0 10px 0; color: #38bdf8; font-size: 0.95em; text-transform: uppercase; }
        h2 { color: #e2e8f0; border-bottom: 2px solid #334155; padding-bottom: 5px; margin-top: 28px; }
        table { width: 100%; border-collapse: collapse; background: #1e293b; border-radius: 8px; overflow: hidden; margin-top: 8px; }
        th, td { padding: 8px 10px; text-align: center; border-bottom: 1px solid #334155; font-size: 0.88em; }
        th { background: #020617; color: #38bdf8; }
        td.name { text-align: left; }
        .log { background: #020617; padding: 15px; border-radius: 8px; border: 1px solid #334155; max-height: 400px; overflow-y: auto; font-family: monospace; line-height: 1.6; }
        .goal-line { color: #4ade80; font-weight: bold; }
        .inj-line { color: #f87171; font-weight: bold; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>

<div class="scoreboard">
    <div><?= htmlspecialchars($game['game_date']) ?> &bull; <?= $game['is_shootout'] ? 'Final (SO)' : ($game['is_overtime'] ? 'Final (OT)' : 'Final') ?></div>
    <h1><?= htmlspecialchars($game['away_name']) ?> <?= $game['away_score'] ?> &mdash; <?= $game['home_score'] ?> <?= htmlspecialchars($game['home_name']) ?></h1>
</div>

<div class="summary-grid">
    <div class="box-card">
        <h3>Goals by Period</h3>
        <table>
            <tr><th style="text-align:left;">Team</th><th>1</th><th>2</th><th>3</th><?php if ($game['is_overtime'] || $game['is_shootout']): ?><th>OT</th><?php endif; ?><th>T</th></tr>
            <tr>
                <td class="name"><strong><?= $game['away_abbr'] ?></strong></td>
                <td><?= $game['away_p1_goals'] ?></td><td><?= $game['away_p2_goals'] ?></td><td><?= $game['away_p3_goals'] ?></td>
                <?php if ($game['is_overtime'] || $game['is_shootout']): ?><td><?= $game['away_ot_goals'] ?></td><?php endif; ?>
                <td><strong><?= $game['away_score'] ?></strong></td>
            </tr>
            <tr>
                <td class="name"><strong><?= $game['home_abbr'] ?></strong></td>
                <td><?= $game['home_p1_goals'] ?></td><td><?= $game['home_p2_goals'] ?></td><td><?= $game['home_p3_goals'] ?></td>
                <?php if ($game['is_overtime'] || $game['is_shootout']): ?><td><?= $game['home_ot_goals'] ?></td><?php endif; ?>
                <td><strong><?= $game['home_score'] ?></strong></td>
            </tr>
        </table>
    </div>

    <div class="box-card">
        <h3>Shots on Goal</h3>
        <table>
            <tr><th style="text-align:left;">Team</th><th>1</th><th>2</th><th>3</th><?php if ($game['is_overtime'] || $game['is_shootout']): ?><th>OT</th><?php endif; ?><th>T</th></tr>
            <tr>
                <td class="name"><strong><?= $game['away_abbr'] ?></strong></td>
                <td><?= $game['away_p1_shots'] ?></td><td><?= $game['away_p2_shots'] ?></td><td><?= $game['away_p3_shots'] ?></td>
                <?php if ($game['is_overtime'] || $game['is_shootout']): ?><td><?= $game['away_ot_shots'] ?></td><?php endif; ?>
                <td><strong><?= $awayTotalShots ?></strong></td>
            </tr>
            <tr>
                <td class="name"><strong><?= $game['home_abbr'] ?></strong></td>
                <td><?= $game['home_p1_shots'] ?></td><td><?= $game['home_p2_shots'] ?></td><td><?= $game['home_p3_shots'] ?></td>
                <?php if ($game['is_overtime'] || $game['is_shootout']): ?><td><?= $game['home_ot_shots'] ?></td><?php endif; ?>
                <td><strong><?= $homeTotalShots ?></strong></td>
            </tr>
        </table>
    </div>

    <div class="box-card">
        <h3>Special Teams</h3>
        <table>
            <tr><th style="text-align:left;">Team</th><th>Power Play</th><th>Penalty Kill</th></tr>
            <tr>
                <td class="name"><strong><?= $game['away_abbr'] ?></strong></td>
                <td><?= $game['away_pp_goals'] ?>/<?= $game['away_pp_opps'] ?> (<?= $awayPpPct ?>)</td>
                <td><?= $awayPkKills ?>/<?= $game['home_pp_opps'] ?> (<?= $awayPkPct ?>)</td>
            </tr>
            <tr>
                <td class="name"><strong><?= $game['home_abbr'] ?></strong></td>
                <td><?= $game['home_pp_goals'] ?>/<?= $game['home_pp_opps'] ?> (<?= $homePpPct ?>)</td>
                <td><?= $homePkKills ?>/<?= $game['away_pp_opps'] ?> (<?= $homePkPct ?>)</td>
            </tr>
        </table>
    </div>
</div>

<h2><?= htmlspecialchars($game['away_name']) ?> Skaters (<?= count($awaySkaters) ?>)</h2>
<table>
    <tr><th style="text-align:left;">Player</th><th>Pos</th><th>G</th><th>A</th><th>PTS</th><th>SOG</th><th>HIT</th><th>BLK</th><th>PIM</th><th>PP TOI</th><th>PK TOI</th><th>TOI</th></tr>
    <?php foreach ($awaySkaters as $s): ?>
        <tr>
            <td class="name"><?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?></td>
            <td><?= $s['position'] ?></td>
            <td><?= $s['goals'] ?></td>
            <td><?= $s['assists'] ?></td>
            <td><strong><?= $s['goals'] + $s['assists'] ?></strong></td>
            <td><?= $s['shots'] ?></td>
            <td><?= $s['hits'] ?></td>
            <td><?= $s['blocks'] ?></td>
            <td><?= $s['pim'] ?></td>
            <td><?= formatToi($s['pp_toi_seconds']) ?></td>
            <td><?= formatToi($s['pk_toi_seconds']) ?></td>
            <td><?= formatToi($s['toi_seconds']) ?></td>
        </tr>
    <?php endforeach; ?>
</table>

<h2><?= htmlspecialchars($game['home_name']) ?> Skaters (<?= count($homeSkaters) ?>)</h2>
<table>
    <tr><th style="text-align:left;">Player</th><th>Pos</th><th>G</th><th>A</th><th>PTS</th><th>SOG</th><th>HIT</th><th>BLK</th><th>PIM</th><th>PP TOI</th><th>PK TOI</th><th>TOI</th></tr>
    <?php foreach ($homeSkaters as $s): ?>
        <tr>
            <td class="name"><?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?></td>
            <td><?= $s['position'] ?></td>
            <td><?= $s['goals'] ?></td>
            <td><?= $s['assists'] ?></td>
            <td><strong><?= $s['goals'] + $s['assists'] ?></strong></td>
            <td><?= $s['shots'] ?></td>
            <td><?= $s['hits'] ?></td>
            <td><?= $s['blocks'] ?></td>
            <td><?= $s['pim'] ?></td>
            <td><?= formatToi($s['pp_toi_seconds']) ?></td>
            <td><?= formatToi($s['pk_toi_seconds']) ?></td>
            <td><?= formatToi($s['toi_seconds']) ?></td>
        </tr>
    <?php endforeach; ?>
</table>

<h2>Goaltending</h2>
<table>
    <tr><th style="text-align:left;">Goalie</th><th>Team</th><th>SA</th><th>SV</th><th>GA</th><th>SV%</th><th>TOI</th></tr>
    <?php foreach ($goalies as $g):
        $sa = $g['saves'] + $g['goals_against'];
        $svPct = $sa > 0 ? number_format($g['saves'] / $sa, 3) : '.000';
        ?>
        <tr>
            <td class="name"><?= htmlspecialchars($g['first_name'] . ' ' . $g['last_name']) ?></td>
            <td><?= $g['team_id'] == $game['home_team_id'] ? $game['home_abbr'] : $game['away_abbr'] ?></td>
            <td><?= $sa ?></td>
            <td><?= $g['saves'] ?></td>
            <td><?= $g['goals_against'] ?></td>
            <td><?= $svPct ?></td>
            <td><?= formatToi($g['toi_seconds']) ?></td>
        </tr>
    <?php endforeach; ?>
</table>

<h2>Play-by-Play Log</h2>
<div class="log">
    <?php
    foreach ($events as $ev) {
        $cls = '';
        if (str_contains($ev, 'GOAL')) {
            $cls = 'goal-line';
        } elseif (str_contains($ev, 'INJURY')) {
            $cls = 'inj-line';
        }
        echo '<div class="' . $cls . '">' . htmlspecialchars($ev) . '</div>';
    }
    ?>
</div>
</body>
</html>
