<?php
require 'db.php';
/** @var PDO $pdo */

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$isAdmin  = !empty($_SESSION['is_admin']);
$myTeamId = (int)($_SESSION['team_id'] ?? 0);

if (!$isAdmin) {
    $team_id = $myTeamId;
    if ($team_id <= 0) die("Your account is not assigned to a team yet. Contact the Commissioner.");
} else {
    $team_id = isset($_GET['id']) ? (int)$_GET['id'] : ($myTeamId > 0 ? $myTeamId : 1);
}

$savedMsg = '';
$allTeams = $pdo->query("SELECT team_id, name, abbreviation FROM teams ORDER BY name")->fetchAll();

$tStmt = $pdo->prepare("SELECT * FROM teams WHERE team_id = ?");
$tStmt->execute([$team_id]);
$team = $tStmt->fetch();
if (!$team) die("Team not found.");

$pStmt = $pdo->prepare("SELECT * FROM players WHERE team_id = ? AND roster_status = 'PRO' ORDER BY overall_rating DESC");
$pStmt->execute(array($team_id));
$proRoster = $pStmt->fetchAll();

$forwards = array_values(array_filter($proRoster, fn($p) => $p['position'] !== 'D' && $p['position'] !== 'G'));
$defense  = array_values(array_filter($proRoster, fn($p) => $p['position'] === 'D'));
$skaters  = array_values(array_filter($proRoster, fn($p) => $p['position'] !== 'G'));
$goalies  = array_values(array_filter($proRoster, fn($p) => $p['position'] === 'G'));

function idAt($arr, $i) {
    return isset($arr[$i]) ? (int)$arr[$i]['player_id'] : 0;
}

function generateOptimalLineup($proRoster, $existingLineup = array()) {
    $availF = array_merge(
            array_values(array_filter($proRoster, fn($p) => !in_array($p['position'], ['D', 'G']) && empty($p['is_injured']))),
            array_values(array_filter($proRoster, fn($p) => !in_array($p['position'], ['D', 'G']) && !empty($p['is_injured'])))
    );
    $availD = array_merge(
            array_values(array_filter($proRoster, fn($p) => $p['position'] === 'D' && empty($p['is_injured']))),
            array_values(array_filter($proRoster, fn($p) => $p['position'] === 'D' && !empty($p['is_injured'])))
    );
    $availG = array_merge(
            array_values(array_filter($proRoster, fn($p) => $p['position'] === 'G' && empty($p['is_injured']))),
            array_values(array_filter($proRoster, fn($p) => $p['position'] === 'G' && !empty($p['is_injured'])))
    );

    $usedF = array();
    $pickF = function($preferredPos) use (&$availF, &$usedF) {
        foreach ($availF as $f) {
            if (!isset($usedF[$f['player_id']]) && strtoupper($f['position']) === $preferredPos) {
                $usedF[$f['player_id']] = true;
                return (int)$f['player_id'];
            }
        }
        foreach ($availF as $f) {
            if (!isset($usedF[$f['player_id']])) {
                $usedF[$f['player_id']] = true;
                return (int)$f['player_id'];
            }
        }
        return 0;
    };

    $lineup = $existingLineup;
    for ($l = 1; $l <= 4; $l++) {
        $lineup["f{$l}_lw"] = $pickF('LW');
        $lineup["f{$l}_c"]  = $pickF('C');
        $lineup["f{$l}_rw"] = $pickF('RW');
    }
    for ($d = 1; $d <= 3; $d++) {
        $base = ($d - 1) * 2;
        $lineup["d{$d}_ld"] = idAt($availD, $base);
        $lineup["d{$d}_rd"] = idAt($availD, $base + 1);
    }

    $lineup['pp1_f1'] = idAt($availF, 0); $lineup['pp1_f2'] = idAt($availF, 1); $lineup['pp1_f3'] = idAt($availF, 2);
    $lineup['pp1_d1'] = idAt($availD, 0); $lineup['pp1_d2'] = idAt($availD, 1) ?: idAt($availF, 3);
    $lineup['pp2_f1'] = idAt($availF, 3); $lineup['pp2_f2'] = idAt($availF, 4); $lineup['pp2_f3'] = idAt($availF, 5);
    $lineup['pp2_d1'] = idAt($availD, 2); $lineup['pp2_d2'] = idAt($availD, 3);

    $lineup['pk1_f1'] = idAt($availF, 0); $lineup['pk1_f2'] = idAt($availF, 1);
    $lineup['pk1_d1'] = idAt($availD, 0); $lineup['pk1_d2'] = idAt($availD, 1);
    $lineup['pk2_f1'] = idAt($availF, 2); $lineup['pk2_f2'] = idAt($availF, 3);
    $lineup['pk2_d1'] = idAt($availD, 2); $lineup['pk2_d2'] = idAt($availD, 3);

    $lineup['4v4_1_f1'] = idAt($availF, 0); $lineup['4v4_1_f2'] = idAt($availF, 1);
    $lineup['4v4_1_d1'] = idAt($availD, 0); $lineup['4v4_1_d2'] = idAt($availD, 1);
    $lineup['4v4_2_f1'] = idAt($availF, 2); $lineup['4v4_2_f2'] = idAt($availF, 3);
    $lineup['4v4_2_d1'] = idAt($availD, 2); $lineup['4v4_2_d2'] = idAt($availD, 3);

    $lineup['3v3_1_f1'] = idAt($availF, 0); $lineup['3v3_1_f2'] = idAt($availF, 1); $lineup['3v3_1_d1'] = idAt($availD, 0);
    $lineup['3v3_2_f1'] = idAt($availF, 2); $lineup['3v3_2_f2'] = idAt($availF, 3); $lineup['3v3_2_d1'] = idAt($availD, 1);

    $lineup['g_starter'] = idAt($availG, 0);
    $lineup['g_backup']  = idAt($availG, 1);

    $lineup['pp1_toi'] = $lineup['pp1_toi'] ?? 60; $lineup['pp2_toi'] = $lineup['pp2_toi'] ?? 40;
    $lineup['pk1_toi'] = $lineup['pk1_toi'] ?? 55; $lineup['pk2_toi'] = $lineup['pk2_toi'] ?? 45;
    $lineup['4v4_1_toi'] = $lineup['4v4_1_toi'] ?? 55; $lineup['4v4_2_toi'] = $lineup['4v4_2_toi'] ?? 45;
    $lineup['3v3_1_toi'] = $lineup['3v3_1_toi'] ?? 60; $lineup['3v3_2_toi'] = $lineup['3v3_2_toi'] ?? 40;
    return $lineup;
}
function saveTeamLineup($pdo, $team_id, $lineup, $fwdToi, $defToi, $fwdStrat, $defStrat) {
    $upStmt = $pdo->prepare("
        INSERT INTO team_lines (team_id, lineup_json, fwd_toi_pct, def_toi_pct, fwd_strategy, def_strategy, updated_at)
        VALUES (:tid, :json::jsonb, :ftoi, :dtoi, :fstrat, :dstrat, NOW())
        ON CONFLICT (team_id) DO UPDATE SET
            lineup_json  = EXCLUDED.lineup_json,
            fwd_toi_pct  = EXCLUDED.fwd_toi_pct,
            def_toi_pct  = EXCLUDED.def_toi_pct,
            fwd_strategy = EXCLUDED.fwd_strategy,
            def_strategy = EXCLUDED.def_strategy,
            updated_at   = NOW()
    ");
    $upStmt->execute(array(
            'tid'    => $team_id,
            'json'   => json_encode($lineup),
            'ftoi'   => '{' . implode(',', $fwdToi) . '}',
            'dtoi'   => '{' . implode(',', $defToi) . '}',
            'fstrat' => '{' . implode(',', $fwdStrat) . '}',
            'dstrat' => '{' . implode(',', $defStrat) . '}',
    ));

    $pdo->prepare("UPDATE players SET line_number = 99 WHERE team_id = ? AND roster_status = 'PRO'")->execute(array($team_id));
    $syncCmd = $pdo->prepare("UPDATE players SET line_number = ? WHERE player_id = ? AND team_id = ?");

    for ($l = 1; $l <= 4; $l++) {
        foreach (array('lw', 'c', 'rw') as $posKey) {
            $pid = (int)($lineup["f{$l}_{$posKey}"] ?? 0);
            if ($pid > 0) $syncCmd->execute(array($l, $pid, $team_id));
        }
    }
    for ($d = 1; $d <= 3; $d++) {
        foreach (array('ld', 'rd') as $posKey) {
            $pid = (int)($lineup["d{$d}_{$posKey}"] ?? 0);
            if ($pid > 0) $syncCmd->execute(array($d, $pid, $team_id));
        }
    }
    foreach (array('g_starter' => 1, 'g_backup' => 2) as $gKey => $gLine) {
        $pid = (int)($lineup[$gKey] ?? 0);
        if ($pid > 0) $syncCmd->execute(array($gLine, $pid, $team_id));
    }
}

$lStmt = $pdo->prepare("SELECT * FROM team_lines WHERE team_id = ?");
$lStmt->execute(array($team_id));
$savedRow = $lStmt->fetch();
$lineup   = $savedRow ? json_decode($savedRow['lineup_json'], true) : array();

$fwdToi   = $savedRow ? explode(',', trim($savedRow['fwd_toi_pct'], '{}')) : array(35, 30, 20, 15);
$defToi   = $savedRow ? explode(',', trim($savedRow['def_toi_pct'], '{}')) : array(42, 35, 23);
$fwdStrat = $savedRow ? explode(',', trim($savedRow['fwd_strategy'], '{}')) : array('OFFENSIVE', 'BALANCED', 'BALANCED', 'DEFENSIVE');
$defStrat = $savedRow ? explode(',', trim($savedRow['def_strategy'], '{}')) : array('OFFENSIVE', 'BALANCED', 'DEFENSIVE');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';
    if ($action === 'auto_generate') {
        $lineup   = generateOptimalLineup($proRoster, $lineup);
        $fwdToi   = array(35, 30, 20, 15);
        $defToi   = array(42, 35, 23);
        $fwdStrat = array('OFFENSIVE', 'BALANCED', 'BALANCED', 'DEFENSIVE');
        $defStrat = array('OFFENSIVE', 'BALANCED', 'DEFENSIVE');
        saveTeamLineup($pdo, $team_id, $lineup, $fwdToi, $defToi, $fwdStrat, $defStrat);
        $savedMsg = "Optimal healthy lineup auto-generated and saved!";
    } else {
        $lineup   = $_POST['lineup'] ?? array();
        $fwdToi   = array_map('intval', $_POST['fwd_toi'] ?? array(35, 30, 20, 15));
        $defToi   = array_map('intval', $_POST['def_toi'] ?? array(42, 35, 23));
        $fwdStrat = $_POST['fwd_strat'] ?? array('OFFENSIVE', 'BALANCED', 'BALANCED', 'DEFENSIVE');
        $defStrat = $_POST['def_strat'] ?? array('OFFENSIVE', 'BALANCED', 'DEFENSIVE');
        saveTeamLineup($pdo, $team_id, $lineup, $fwdToi, $defToi, $fwdStrat, $defStrat);
        $savedMsg = "Lineup, Ice Time % targets, and Strategy toggles saved successfully!";
    }
} elseif (empty($lineup)) {
    $lineup = generateOptimalLineup($proRoster);
}

function renderPlayerSelect($name, $pool, $selectedId) {
    $html = '<select name="lineup[' . htmlspecialchars($name) . ']">';
    $html .= '<option value="0">-- Empty --</option>';
    foreach ($pool as $p) {
        $sel       = ($p['player_id'] == $selectedId) ? ' selected' : '';
        $inj       = $p['is_injured'] ? ' [INJ ' . $p['injury_days_remaining'] . 'd]' : '';
        $firstInit = substr($p['first_name'], 0, 1) . '. ';
        $label     = htmlspecialchars($firstInit . $p['last_name'] . ' (' . $p['position'] . ' ' . $p['overall_rating'] . ')' . $inj);
        $html .= '<option value="' . $p['player_id'] . '"' . $sel . '>' . $label . '</option>';
    }
    $html .= '</select>';
    return $html;
}

function renderStrategySelect($name, $selectedVal) {
    $opts = array('OFFENSIVE' => 'Offensive (+Shoot / -Def)', 'BALANCED' => 'Balanced', 'DEFENSIVE' => 'Defensive (+Def / -Shoot)');
    $html = '<select name="' . htmlspecialchars($name) . '">';
    foreach ($opts as $val => $label) {
        $sel = ($val === $selectedVal) ? ' selected' : '';
        $html .= '<option value="' . $val . '"' . $sel . '>' . $label . '</option>';
    }
    $html .= '</select>';
    return $html;
}

function renderToiSlider($key, $val, $partnerKey) {
    $v = (int)$val;
    return '<div style="display:flex;align-items:center;gap:8px;min-width:145px;">'
            . '<input type="range" id="rng_' . $key . '" name="lineup[' . $key . ']" min="0" max="100" step="5" value="' . $v . '" '
            . 'oninput="syncUnitToi(\'' . $key . '\', \'' . $partnerKey . '\')">'
            . '<span id="lbl_' . $key . '" style="font-weight:bold;color:#38bdf8;width:38px;">' . $v . '%</span></div>';
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
    <title>GM Line Editor - <?= htmlspecialchars($team['name']) ?></title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #0f172a; color: #f8fafc; padding: 20px; max-width: 1280px; margin: 0 auto; display: block; }
        .header-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; width: 100%; flex-wrap: wrap; gap: 12px; }
        h1 { color: #38bdf8; margin: 0; font-size: 1.7em; }
        h2 { color: #e2e8f0; border-bottom: 2px solid #334155; padding-bottom: 6px; margin-top: 28px; font-size: 1.2em; }
        .alert { background: #14532d; color: #86efac; padding: 12px 16px; border-radius: 6px; border: 1px solid #22c55e; margin-bottom: 18px; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; background: #1e293b; border-radius: 8px; overflow: hidden; margin-top: 10px; table-layout: fixed; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #334155; }
        th { background: #020617; color: #38bdf8; font-size: 0.9em; }
        select { background: #0f172a; color: #f8fafc; border: 1px solid #475569; padding: 7px 8px; border-radius: 5px; width: 100%; font-size: 0.9em; }
        .toi-box { display: flex; align-items: center; gap: 8px; }
        input[type=range] { width: 95px; accent-color: #38bdf8; }
        .toi-val { font-weight: bold; color: #38bdf8; min-width: 38px; }
        .total-pill { background: #020617; padding: 4px 10px; border-radius: 12px; font-size: 0.85em; border: 1px solid #334155; float: right; }
        .save-btn { background: #0284c7; color: #fff; border: none; padding: 14px 28px; font-size: 1.05em; font-weight: bold; border-radius: 8px; cursor: pointer; margin-top: 25px; }
        .save-btn:hover { background: #0369a1; }
        .auto-btn { background: #16a34a; color: #fff; border: none; padding: 11px 20px; font-size: 0.98em; font-weight: bold; border-radius: 8px; cursor: pointer; }
        .auto-btn:hover { background: #15803d; }
    </style></head><body>
<?php include 'header.php'; ?>

<div class="header-bar">
    <h1>GM Line Editor: <?= htmlspecialchars($team['name']) ?></h1>
    <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
        <form method="post" style="margin:0;">
            <input type="hidden" name="action" value="auto_generate">
            <button type="submit" class="auto-btn">&#9889; Auto-Generate Best Lines</button>
        </form>
        <?php if ($isAdmin): ?>
            <form method="get" style="display:flex; gap:8px; align-items:center; margin:0;">
                <label for="teamSwitch" style="white-space:nowrap;">Switch Team:</label>
                <select id="teamSwitch" name="id" onchange="this.form.submit()" style="width:220px;">
                    <?php foreach ($allTeams as $at): ?>
                        <option value="<?= $at['team_id'] ?>" <?= $at['team_id'] == $team_id ? 'selected' : '' ?>><?= htmlspecialchars($at['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($savedMsg): ?><div class="alert"><?= htmlspecialchars($savedMsg) ?></div><?php endif; ?>

<form method="post">
    <input type="hidden" name="action" value="save">
    <h2>5v5 Forward Lines <span class="total-pill">Total Ice Time Share: <strong id="fwdTotal">100</strong>% (Target: 100%)</span></h2>
    <table>
        <tr><th style="width:75px;">Line</th><th>Left Wing</th><th>Center</th><th>Right Wing</th><th style="width:165px;">Ice Time %</th><th style="width:210px;">Line Strategy</th></tr>
        <?php for ($l = 1; $l <= 4; $l++): $val = (int)($fwdToi[$l - 1] ?? 25); ?>
            <tr>
                <td><strong>Line <?= $l ?></strong></td>
                <td><?= renderPlayerSelect("f{$l}_lw", $forwards, $lineup["f{$l}_lw"] ?? 0) ?></td>
                <td><?= renderPlayerSelect("f{$l}_c",  $forwards, $lineup["f{$l}_c"] ?? 0) ?></td>
                <td><?= renderPlayerSelect("f{$l}_rw", $forwards, $lineup["f{$l}_rw"] ?? 0) ?></td>
                <td><div class="toi-box"><input type="range" class="fwd-slider" name="fwd_toi[]" min="5" max="50" value="<?= $val ?>" oninput="updateTotals()"><span class="toi-val" id="fwdVal<?= $l ?>"><?= $val ?>%</span></div></td>
                <td><?= renderStrategySelect("fwd_strat[]", $fwdStrat[$l - 1] ?? 'BALANCED') ?></td>
            </tr>
        <?php endfor; ?>
    </table>

    <h2>5v5 Defense Pairs <span class="total-pill">Total Ice Time Share: <strong id="defTotal">100</strong>% (Target: 100%)</span></h2>
    <table>
        <tr><th style="width:75px;">Pair</th><th>Left Defense</th><th>Right Defense</th><th style="width:165px;">Ice Time %</th><th style="width:210px;">Pair Strategy</th></tr>
        <?php for ($d = 1; $d <= 3; $d++): $val = (int)($defToi[$d - 1] ?? 33); ?>
            <tr>
                <td><strong>Pair <?= $d ?></strong></td>
                <td><?= renderPlayerSelect("d{$d}_ld", $defense, $lineup["d{$d}_ld"] ?? 0) ?></td>
                <td><?= renderPlayerSelect("d{$d}_rd", $defense, $lineup["d{$d}_rd"] ?? 0) ?></td>
                <td><div class="toi-box"><input type="range" class="def-slider" name="def_toi[]" min="10" max="55" value="<?= $val ?>" oninput="updateTotals()"><span class="toi-val" id="defVal<?= $d ?>"><?= $val ?>%</span></div></td>
                <td><?= renderStrategySelect("def_strat[]", $defStrat[$d - 1] ?? 'BALANCED') ?></td>
            </tr>
        <?php endfor; ?>
    </table>

    <h2>Power Play Units (5v4)</h2>
    <table>
        <tr><th>Unit</th><th>Forward 1</th><th>Forward 2</th><th>Forward 3</th><th>Point 1</th><th>Point 2 (F/D)</th><th>Ice Time %</th><th>Strategy</th></tr>
        <tr>
            <td><strong>PP1</strong></td>
            <td><?= renderPlayerSelect('pp1_f1', $forwards, $lineup['pp1_f1'] ?? 0) ?></td>
            <td><?= renderPlayerSelect('pp1_f2', $forwards, $lineup['pp1_f2'] ?? 0) ?></td>
            <td><?= renderPlayerSelect('pp1_f3', $forwards, $lineup['pp1_f3'] ?? 0) ?></td>
            <td><?= renderPlayerSelect('pp1_d1', $skaters,  $lineup['pp1_d1'] ?? 0) ?></td>
            <td><?= renderPlayerSelect('pp1_d2', $skaters,  $lineup['pp1_d2'] ?? 0) ?></td>
            <td><?= renderToiSlider('pp1_toi', $lineup['pp1_toi'] ?? 60, 'pp2_toi') ?></td>
            <td><?= renderStrategySelect('lineup[pp1_strat]', $lineup['pp1_strat'] ?? 'BALANCED') ?></td>
        </tr>
        <tr>
            <td><strong>PP2</strong></td>
            <td><?= renderPlayerSelect('pp2_f1', $forwards, $lineup['pp2_f1'] ?? 0) ?></td>
            <td><?= renderPlayerSelect('pp2_f2', $forwards, $lineup['pp2_f2'] ?? 0) ?></td>
            <td><?= renderPlayerSelect('pp2_f3', $forwards, $lineup['pp2_f3'] ?? 0) ?></td>
            <td><?= renderPlayerSelect('pp2_d1', $skaters,  $lineup['pp2_d1'] ?? 0) ?></td>
            <td><?= renderPlayerSelect('pp2_d2', $skaters,  $lineup['pp2_d2'] ?? 0) ?></td>
            <td><?= renderToiSlider('pp2_toi', $lineup['pp2_toi'] ?? 40, 'pp1_toi') ?></td>
            <td><?= renderStrategySelect('lineup[pp2_strat]', $lineup['pp2_strat'] ?? 'BALANCED') ?></td>
        </tr>
    </table>

    <h2>Penalty Kill Units (4v5)</h2>
    <table>
        <tr><th>Unit</th><th>Forward 1</th><th>Forward 2</th><th>Defense 1</th><th>Defense 2</th><th>Ice Time %</th><th>Strategy</th></tr>
        <tr>
            <td><strong>PK1</strong></td>
            <td><?= renderPlayerSelect('pk1_f1', $forwards, $lineup['pk1_f1'] ?? 0) ?></td>
            <td><?= renderPlayerSelect('pk1_f2', $forwards, $lineup['pk1_f2'] ?? 0) ?></td>
            <td><?= renderPlayerSelect('pk1_d1', $defense,  $lineup['pk1_d1'] ?? 0) ?></td>
            <td><?= renderPlayerSelect('pk1_d2', $defense,  $lineup['pk1_d2'] ?? 0) ?></td>
            <td><?= renderToiSlider('pk1_toi', $lineup['pk1_toi'] ?? 55, 'pk2_toi') ?></td>
            <td><?= renderStrategySelect('lineup[pk1_strat]', $lineup['pk1_strat'] ?? 'BALANCED') ?></td>
        </tr>
        <tr>
            <td><strong>PK2</strong></td>
            <td><?= renderPlayerSelect('pk2_f1', $forwards, $lineup['pk2_f1'] ?? 0) ?></td>
            <td><?= renderPlayerSelect('pk2_f2', $forwards, $lineup['pk2_f2'] ?? 0) ?></td>
            <td><?= renderPlayerSelect('pk2_d1', $defense,  $lineup['pk2_d1'] ?? 0) ?></td>
            <td><?= renderPlayerSelect('pk2_d2', $defense,  $lineup['pk2_d2'] ?? 0) ?></td>
            <td><?= renderToiSlider('pk2_toi', $lineup['pk2_toi'] ?? 45, 'pk1_toi') ?></td>
            <td><?= renderStrategySelect('lineup[pk2_strat]', $lineup['pk2_strat'] ?? 'BALANCED') ?></td>
        </tr>
    </table>

    <h2>4-on-4 Units</h2>
    <table>
        <tr><th>Unit</th><th>Forward 1</th><th>Forward 2</th><th>Defense 1</th><th>Defense 2</th><th>Ice Time %</th><th>Strategy</th></tr>
        <tr>
            <td><strong>Unit 1</strong></td>
            <td><?= renderPlayerSelect('4v4_1_f1', $forwards, $lineup['4v4_1_f1'] ?? 0) ?></td>
            <td><?= renderPlayerSelect('4v4_1_f2', $forwards, $lineup['4v4_1_f2'] ?? 0) ?></td>
            <td><?= renderPlayerSelect('4v4_1_d1', $defense,  $lineup['4v4_1_d1'] ?? 0) ?></td>
            <td><?= renderPlayerSelect('4v4_1_d2', $defense,  $lineup['4v4_1_d2'] ?? 0) ?></td>
            <td><?= renderToiSlider('4v4_1_toi', $lineup['4v4_1_toi'] ?? 55, '4v4_2_toi') ?></td>
            <td><?= renderStrategySelect('lineup[4v4_1_strat]', $lineup['4v4_1_strat'] ?? 'BALANCED') ?></td>
        </tr>
        <tr>
            <td><strong>Unit 2</strong></td>
            <td><?= renderPlayerSelect('4v4_2_f1', $forwards, $lineup['4v4_2_f1'] ?? 0) ?></td>
            <td><?= renderPlayerSelect('4v4_2_f2', $forwards, $lineup['4v4_2_f2'] ?? 0) ?></td>
            <td><?= renderPlayerSelect('4v4_2_d1', $defense,  $lineup['4v4_2_d1'] ?? 0) ?></td>
            <td><?= renderPlayerSelect('4v4_2_d2', $defense,  $lineup['4v4_2_d2'] ?? 0) ?></td>
            <td><?= renderToiSlider('4v4_2_toi', $lineup['4v4_2_toi'] ?? 45, '4v4_1_toi') ?></td>
            <td><?= renderStrategySelect('lineup[4v4_2_strat]', $lineup['4v4_2_strat'] ?? 'BALANCED') ?></td>
        </tr>
    </table>

    <h2>3-on-3 Overtime Trios</h2>
    <table>
        <tr><th>Slot</th><th>Player 1</th><th>Player 2</th><th>Player 3</th><th>Ice Time %</th><th>Strategy</th></tr>
        <tr>
            <td><strong>3v3 OT #1</strong></td>
            <td><?= renderPlayerSelect('3v3_1_f1', $skaters, $lineup['3v3_1_f1'] ?? 0) ?></td>
            <td><?= renderPlayerSelect('3v3_1_f2', $skaters, $lineup['3v3_1_f2'] ?? 0) ?></td>
            <td><?= renderPlayerSelect('3v3_1_d1', $skaters, $lineup['3v3_1_d1'] ?? 0) ?></td>
            <td><?= renderToiSlider('3v3_1_toi', $lineup['3v3_1_toi'] ?? 60, '3v3_2_toi') ?></td>
            <td><?= renderStrategySelect('lineup[3v3_1_strat]', $lineup['3v3_1_strat'] ?? 'BALANCED') ?></td>
        </tr>
        <tr>
            <td><strong>3v3 OT #2</strong></td>
            <td><?= renderPlayerSelect('3v3_2_f1', $skaters, $lineup['3v3_2_f1'] ?? 0) ?></td>
            <td><?= renderPlayerSelect('3v3_2_f2', $skaters, $lineup['3v3_2_f2'] ?? 0) ?></td>
            <td><?= renderPlayerSelect('3v3_2_d1', $skaters, $lineup['3v3_2_d1'] ?? 0) ?></td>
            <td><?= renderToiSlider('3v3_2_toi', $lineup['3v3_2_toi'] ?? 40, '3v3_1_toi') ?></td>
            <td><?= renderStrategySelect('lineup[3v3_2_strat]', $lineup['3v3_2_strat'] ?? 'BALANCED') ?></td>
        </tr>
    </table>

    <h2>Goaltending</h2>
    <table>
        <tr><th style="width:140px;">Role</th><th>Goaltender</th></tr>
        <tr><td><strong>Starter</strong></td><td><?= renderPlayerSelect('g_starter', $goalies, $lineup['g_starter'] ?? ($lineup['g1'] ?? 0)) ?></td></tr>
        <tr><td><strong>Backup</strong></td><td><?= renderPlayerSelect('g_backup', $goalies, $lineup['g_backup'] ?? ($lineup['g2'] ?? 0)) ?></td></tr>
    </table>

    <button type="submit" class="save-btn">Save Lineup &amp; Tactics</button>
</form>

<script>
    function updateTotals() {
        let fSum = 0;
        document.querySelectorAll('.fwd-slider').forEach((el, idx) => {
            let v = parseInt(el.value) || 0;
            fSum += v;
            document.getElementById('fwdVal' + (idx + 1)).innerText = v + '%';
        });
        let fTotEl = document.getElementById('fwdTotal');
        fTotEl.innerText = fSum;
        fTotEl.style.color = (fSum === 100) ? '#4ade80' : '#f87171';

        let dSum = 0;
        document.querySelectorAll('.def-slider').forEach((el, idx) => {
            let v = parseInt(el.value) || 0;
            dSum += v;
            document.getElementById('defVal' + (idx + 1)).innerText = v + '%';
        });
        let dTotEl = document.getElementById('defTotal');
        dTotEl.innerText = dSum;
        dTotEl.style.color = (dSum === 100) ? '#4ade80' : '#f87171';
    }
    updateTotals();

    function syncUnitToi(primaryKey, partnerKey) {
        const r1 = document.getElementById('rng_' + primaryKey);
        const l1 = document.getElementById('lbl_' + primaryKey);
        const r2 = document.getElementById('rng_' + partnerKey);
        const l2 = document.getElementById('lbl_' + partnerKey);
        const val = parseInt(r1.value, 10) || 0;
        l1.textContent = val + '%';
        if (r2 && l2) {
            const rem = 100 - val;
            r2.value = rem;
            l2.textContent = rem + '%';
        }
    }
</script>
</body></html>





