<?php
require 'db.php';
require_once 'sim_engine.php';
/** @var PDO $pdo */

ensureSimSchema($pdo);

$isCommish  = !empty($_SESSION['is_commish']) || !empty($_SESSION['is_admin']);
$alertMsg   = '';
$alertType  = 'info';
$compIssues = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isCommish && !empty($_POST['sim_action'])) {
    $act = $_POST['sim_action'];

    if ($act === 'check_compliance') {
        $compIssues = validateAllTeamsCompliance($pdo);
        if (empty($compIssues)) {
            $alertType = 'info';
            $alertMsg  = 'ALL 32 TEAMS COMPLIANT: Every team passes the 23-man limit, active line minimums, and NHL Salary Cap.';
        } else {
            $alertType = 'error';
            $alertMsg  = 'COMPLIANCE ALERT: Found ' . count($compIssues) . ' roster/cap issue(s) across the league.';
        }
    } elseif ($act === 'autofix_lines') {
        $fixed = autoFixNonCompliantLines($pdo);
        $alertType = 'info';
        $alertMsg  = "Auto-fixed {$fixed} lineup/injury slot(s) across all 32 teams.";
    } elseif ($act === 'save_auto_sim') {
        $enabled = !empty($_POST['auto_sim_enabled']) ? '1' : '0';
        $simTime = preg_match('/^\d{2}:\d{2}$/', $_POST['sim_time_est'] ?? '') ? $_POST['sim_time_est'] : '20:00';
        $enforce = !empty($_POST['enforce_compliance']) ? '1' : '0';
        setLeagueSetting($pdo, 'auto_sim_enabled', $enabled);
        setLeagueSetting($pdo, 'sim_time_est', $simTime);
        setLeagueSetting($pdo, 'enforce_compliance', $enforce);
        $alertType = 'info';
        $alertMsg  = "Saved Auto-Sim settings: " . ($enabled === '1' ? "ENABLED at {$simTime} EST daily." : "DISABLED.");
    } elseif ($act === 'sim_next_day') {
        $enforce = (getLeagueSetting($pdo, 'enforce_compliance', '1') === '1');
        $res = advanceSimDay($pdo, $enforce);
        if (!$res['ok']) {
            $alertType  = 'error';
            $alertMsg   = $res['msg'];
            $compIssues = $res['issues'] ?? [];
        } else {
            $alertType = 'info';
            $alertMsg  = $res['msg'] . (!empty($res['results']) ? ' (' . implode(' | ', $res['results']) . ')' : '');
        }
    } elseif ($act === 'launch_playoffs') {
        $created = generatePlayoffBracket($pdo, 2027);
        if ($created > 0) {
            $alertType = 'info';
            $alertMsg  = "Stanley Cup Playoffs launched! Generated {$created} Round 1 Best-of-7 series from regular-season standings.";
        } else {
            $alertType = 'warning';
            $alertMsg  = "Playoff bracket is already active for this season.";
        }
    } elseif ($act === 'sim_playoff_round') {
        $gStmt = $pdo->query("
            SELECT game_id FROM games
            WHERE status = 'SCHEDULED' AND COALESCE(game_type, 'REGULAR') = 'PLAYOFF'
            ORDER BY game_date ASC, game_id ASC
        ");
        $gids = $gStmt->fetchAll(PDO::FETCH_COLUMN);
        if (empty($gids)) {
            $alertType = 'warning';
            $alertMsg  = "No scheduled playoff games remaining in the current round.";
        } else {
            $simmed = 0;
            foreach ($gids as $gid) {
                $r = simulateSingleGame($pdo, (int)$gid);
                if ($r['ok']) $simmed++;
            }
            $alertType = 'info';
            $alertMsg  = "Simulated {$simmed} playoff game(s)!";
        }
    } elseif ($act === 'reset_playoffs') {
        $pdo->exec("DELETE FROM player_game_stats WHERE game_id IN (SELECT game_id FROM games WHERE COALESCE(game_type, 'REGULAR') = 'PLAYOFF')");
        $pdo->exec("DELETE FROM games WHERE COALESCE(game_type, 'REGULAR') = 'PLAYOFF'");
        $pdo->exec("DELETE FROM playoff_series WHERE season_year = 2027");
        $alertType = 'warning';
        $alertMsg  = "Playoff bracket and playoff games have been reset.";
    }
}

$autoEnabled = (getLeagueSetting($pdo, 'auto_sim_enabled', '0') === '1');
$simTimeEst  = getLeagueSetting($pdo, 'sim_time_est', '20:00');
$enforceComp = (getLeagueSetting($pdo, 'enforce_compliance', '1') === '1');
$lastAutoRun = getLeagueSetting($pdo, 'last_auto_sim_date', 'Never');

$schedGamesCnt = (int)$pdo->query("SELECT COUNT(*) FROM games WHERE status = 'SCHEDULED'")->fetchColumn();

$seriesRows = $pdo->query("
    SELECT ps.*,
           th.abbreviation AS high_abbr, th.name AS high_name,
           tl.abbreviation AS low_abbr,  tl.name AS low_name
    FROM playoff_series ps
    JOIN teams th ON ps.higher_seed_id = th.team_id
    JOIN teams tl ON ps.lower_seed_id  = tl.team_id
    WHERE ps.season_year = 2027
    ORDER BY ps.round_num ASC, ps.series_id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$byRound = [1 => [], 2 => [], 3 => [], 4 => []];
foreach ($seriesRows as $sr) {
    $byRound[(int)$sr['round_num']][] = $sr;
}
?>

<?php
function renderSeriesBox(array $s): void {
    $hWin = ((int)$s['higher_wins'] >= 4);
    $lWin = ((int)$s['lower_wins'] >= 4);
    $hCls = $hWin ? 'team-row winner' : ($lWin ? 'team-row elim' : 'team-row');
    $lCls = $lWin ? 'team-row winner' : ($hWin ? 'team-row elim' : 'team-row');
    echo '<div class="series-card">
        <div class="series-tag">' . htmlspecialchars($s['conference']) . '</div>
        <div class="' . $hCls . '">
            <span><img src="logos/' . htmlspecialchars(strtoupper($s['high_abbr'])) . '.svg" alt=""> ' . htmlspecialchars($s['high_abbr']) . '</span>
            <strong>' . (int)$s['higher_wins'] . '</strong>
        </div>
        <div class="' . $lCls . '">
            <span><img src="logos/' . htmlspecialchars(strtoupper($s['low_abbr'])) . '.svg" alt=""> ' . htmlspecialchars($s['low_abbr']) . '</span>
            <strong>' . (int)$s['lower_wins'] . '</strong>
        </div>
    </div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Stanley Cup Playoffs &amp; Sim Center - HFNHL</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #0f172a; color: #f8fafc; padding: 14px 22px 40px 22px; max-width: 1480px; margin: auto; }
        h1 { color: #38bdf8; margin-bottom: 4px; }
        .sub { color: #94a3b8; font-size: 0.92em; margin-bottom: 18px; }
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 18px; font-weight: bold; }
        .alert.info { background: rgba(2, 132, 199, 0.18); border: 1px solid #38bdf8; color: #7dd3fc; }
        .alert.warning { background: rgba(217, 119, 6, 0.2); border: 1px solid #fbbf24; color: #fde047; }
        .alert.error { background: rgba(220, 38, 38, 0.2); border: 1px solid #ef4444; color: #fca5a5; }
        .commish-box { background: #1e293b; border: 1px solid #334155; border-top: 4px solid #0284c7; border-bottom: 3px solid #dc2626; border-radius: 10px; padding: 18px 20px; margin-bottom: 24px; }
        .commish-box h2 { margin: 0 0 12px 0; color: #fbbf24; font-size: 1.1em; }
        .btn-row { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-bottom: 14px; }
        .sim-btn { background: #0284c7; color: #fff; border: none; padding: 9px 15px; border-radius: 6px; font-weight: bold; cursor: pointer; font-size: 0.88em; }
        .sim-btn:hover { background: #0369a1; }
        .sim-btn.green { background: #15803d; }
        .sim-btn.green:hover { background: #166534; }
        .sim-btn.red { background: #b91c1c; }
        .sim-btn.red:hover { background: #991b1b; }
        .sim-btn.slate { background: #334155; }
        .sched-form { display: flex; gap: 14px; align-items: center; flex-wrap: wrap; background: #0f172a; padding: 10px 14px; border-radius: 8px; border: 1px solid #334155; font-size: 0.88em; }
        .sched-form input[type="time"] { background: #1e293b; color: #f8fafc; border: 1px solid #475569; padding: 5px 8px; border-radius: 4px; }
        .comp-panel { background: #1a1016; border: 1px solid #ef4444; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; }
        .comp-panel h3 { margin: 0 0 8px 0; color: #f87171; font-size: 1em; }
        .rounds-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; align-items: start; }
        @media (max-width: 1100px) { .rounds-grid { grid-template-columns: 1fr; } }
        .round-col { background: rgba(30, 41, 59, 0.7); border: 1px solid #334155; border-top: 3px solid #0284c7; border-radius: 10px; padding: 14px; display: flex; flex-direction: column; gap: 12px; }
        .round-hdr { text-align: center; color: #38bdf8; font-weight: bold; font-size: 0.92em; text-transform: uppercase; border-bottom: 2px solid #dc2626; padding-bottom: 8px; }
        .series-card { background: #0f172a; border: 1px solid #334155; border-radius: 8px; padding: 10px 12px; }
        .series-tag { font-size: 0.72em; color: #94a3b8; text-transform: uppercase; margin-bottom: 5px; font-weight: bold; }
        .team-row { display: flex; justify-content: space-between; align-items: center; padding: 5px 2px; font-size: 0.95em; border-bottom: 1px solid #1e293b; }
        .team-row:last-child { border-bottom: none; }
        .team-row span { display: flex; align-items: center; gap: 7px; font-weight: bold; }
        .team-row img { width: 22px; height: 22px; object-fit: contain; }
        .team-row.winner { color: #4ade80; }
        .team-row.elim { color: #64748b; text-decoration: line-through; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>

<h1>Stanley Cup Playoffs &amp; League Sim Center</h1>
<div class="sub">Full Regular Season &rarr; 16-Team NHL Wild Card Playoffs &rarr; Best-of-7 Sudden-Death OT</div>

<?php if ($alertMsg): ?>
    <div class="alert <?= $alertType ?>"><?= htmlspecialchars($alertMsg) ?></div>
<?php endif; ?>

<?php if (!empty($compIssues)): ?>
    <div class="comp-panel">
        <h3>NHL Roster &amp; Salary Cap Compliance Issues (<?= count($compIssues) ?>)</h3>
        <ul style="margin:0;padding-left:18px;font-size:0.9em;line-height:1.5;">
            <?php foreach ($compIssues as $err): ?>
                <li><?= htmlspecialchars($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($isCommish): ?>
    <div class="commish-box">
        <h2>Commissioner Simulation &amp; Playoff Controls (<?= $schedGamesCnt ?> Scheduled Games Queued)</h2>
        <div class="btn-row">
            <form method="POST" style="margin:0;"><input type="hidden" name="sim_action" value="check_compliance"><button class="sim-btn slate" type="submit">Run NHL Compliance Check</button></form>
            <form method="POST" style="margin:0;"><input type="hidden" name="sim_action" value="autofix_lines"><button class="sim-btn slate" type="submit">Auto-Dress Healthy Lines (All 32)</button></form>
            <form method="POST" style="margin:0;"><input type="hidden" name="sim_action" value="sim_next_day"><button class="sim-btn green" type="submit">Manual Sim: Next Game Day</button></form>

            <?php if (empty($seriesRows)): ?>
                <form method="POST" style="margin:0;" onsubmit="return confirm('Generate the 16-Team NHL Stanley Cup Playoff Bracket from regular-season standings?');">
                    <input type="hidden" name="sim_action" value="launch_playoffs">
                    <button class="sim-btn" type="submit">Launch Stanley Cup Playoffs</button>
                </form>
            <?php else: ?>
                <form method="POST" style="margin:0;"><input type="hidden" name="sim_action" value="sim_playoff_round"><button class="sim-btn" type="submit">Sim Active Playoff Round</button></form>
                <form method="POST" style="margin:0;" onsubmit="return confirm('Reset all playoff series and playoff games?');">
                    <input type="hidden" name="sim_action" value="reset_playoffs">
                    <button class="sim-btn red" type="submit">Reset Playoffs</button>
                </form>
            <?php endif; ?>
        </div>

        <form method="POST" class="sched-form">
            <input type="hidden" name="sim_action" value="save_auto_sim">
            <label><input type="checkbox" name="auto_sim_enabled" value="1" <?= $autoEnabled ? 'checked' : '' ?>> <strong>Enable Daily Auto-Sim</strong></label>
            <label>Daily Sim Time (EST): <input type="time" name="sim_time_est" value="<?= htmlspecialchars($simTimeEst) ?>"></label>
            <label><input type="checkbox" name="enforce_compliance" value="1" <?= $enforceComp ? 'checked' : '' ?>> Block Sim if Non-Compliant</label>
            <button type="submit" class="sim-btn">Save Auto-Sim Schedule</button>
            <span style="color:#94a3b8;">Last Auto-Sim: <?= htmlspecialchars($lastAutoRun) ?></span>
        </form>
    </div>
<?php endif; ?>

<?php if (empty($seriesRows)): ?>
    <div style="background:#1e293b;padding:26px;border-radius:10px;border:1px solid #334155;text-align:center;color:#94a3b8;">
        Regular Season in progress. Once all regular-season games are complete, the Commissioner can click <strong>Launch Stanley Cup Playoffs</strong> above to seed the top 16 NHL teams.
    </div>
<?php else: ?>
    <div class="rounds-grid">
        <?php
        $roundNames = [1 => 'Round 1 (Conference QF)', 2 => 'Round 2 (Division Finals)', 3 => 'Round 3 (Conference Finals)', 4 => 'Stanley Cup Final'];
        foreach ($roundNames as $rNum => $rTitle): ?>
            <div class="round-col">
                <div class="round-hdr"><?= $rTitle ?></div>
                <?php if (empty($byRound[$rNum])): ?>
                    <div style="text-align:center;color:#64748b;font-size:0.88em;padding:18px 0;">Awaiting prior round winners</div>
                <?php else: foreach ($byRound[$rNum] as $sr): ?>
                    <?php renderSeriesBox($sr); ?>
                <?php endforeach; endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

</body>
</html>

