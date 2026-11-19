<?php
require 'db.php';
require_once 'cap_helper.php';
/** @var PDO $pdo */

if (empty($_SESSION['is_commish'])) {
    die("Access denied. Only the active League Commissioner can access this page.");
}

$myUid = (int)$_SESSION['user_id'];
$msg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_cap_schedule') {
    $yr     = max(2025, min(2050, (int)($_POST['season_year'] ?? 2026)));
    $capMax = max(50000000, (int)($_POST['cap_max'] ?? 104000000));
    $capMin = max(30000000, (int)($_POST['cap_min'] ?? 76900000));
    $label  = $yr . '-' . substr((string)($yr + 1), -2);

    $pdo->prepare("
        INSERT INTO nhl_cap_schedule (season_year, season_label, cap_max, cap_min)
        VALUES (?, ?, ?, ?)
        ON CONFLICT (season_year) DO UPDATE
        SET season_label = EXCLUDED.season_label,
            cap_max      = EXCLUDED.cap_max,
            cap_min      = EXCLUDED.cap_min
    ")->execute(array($yr, $label, $capMax, $capMin));

    $activeYr = (int)($pdo->query("SELECT setting_value FROM league_settings WHERE setting_key = 'current_season'")->fetchColumn() ?: 2026);
    if ($yr === $activeYr) {
        $updSet = $pdo->prepare("
            INSERT INTO league_settings (setting_key, setting_value)
            VALUES (?, ?)
            ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value
        ");
        $updSet->execute(array('salary_cap_max', (string)$capMax));
        $updSet->execute(array('salary_cap_min', (string)$capMin));
    }

    $msg = "NHL Salary Cap saved for {$label}: $" . number_format($capMin) . " Floor / $" . number_format($capMax) . " Ceiling.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_retention') {
    $retPlayerId = (int)($_POST['ret_player_id'] ?? 0);
    $retTeamId   = (int)($_POST['ret_team_id'] ?? 0);
    $retPct      = (float)($_POST['ret_percent'] ?? 0);
    $retAmount   = (int)($_POST['ret_amount'] ?? 0);
    $retYears    = max(1, (int)($_POST['ret_years'] ?? 1));
    $deductSal   = !empty($_POST['deduct_from_player']);
    $bypassRules = !empty($_POST['bypass_rules']);

    if ($retPlayerId > 0 && $retTeamId > 0 && $retPct > 0 && $retPct <= 50) {
        $pStmt = $pdo->prepare("SELECT salary, first_name, last_name FROM players WHERE player_id = ?");
        $pStmt->execute(array($retPlayerId));
        $pInfo = $pStmt->fetch(PDO::FETCH_ASSOC);

        if ($pInfo) {
            if ($retAmount <= 0) {
                $retAmount = (int)round((int)$pInfo['salary'] * ($retPct / 100));
            }

            $err = $bypassRules ? '' : validateSalaryRetention($pdo, $retTeamId, $retPlayerId, $retPct, $retAmount);
            if ($err !== '') {
                $msg = "Retention Blocked: " . $err;
            } else {
                $pdo->beginTransaction();
                $pdo->prepare("
                    INSERT INTO retained_salaries (player_id, retaining_team_id, retained_percent, retained_cap_hit, years_remaining)
                    VALUES (?, ?, ?, ?, ?)
                ")->execute(array($retPlayerId, $retTeamId, $retPct, $retAmount, $retYears));

                if ($deductSal) {
                    $newSal = max(0, (int)$pInfo['salary'] - $retAmount);
                    $pdo->prepare("UPDATE players SET salary = ? WHERE player_id = ?")->execute(array($newSal, $retPlayerId));
                }
                $pdo->commit();
                $msg = "Added {$retPct}% retention ($" . number_format($retAmount) . "/yr for {$retYears} yr) on {$pInfo['first_name']} {$pInfo['last_name']}.";
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_retention') {
    $retId      = (int)($_POST['retention_id'] ?? 0);
    $restoreSal = !empty($_POST['restore_salary']);

    if ($retId > 0) {
        $rStmt = $pdo->prepare("SELECT player_id, retained_cap_hit FROM retained_salaries WHERE id = ?");
        $rStmt->execute(array($retId));
        $rRow = $rStmt->fetch(PDO::FETCH_ASSOC);

        if ($rRow) {
            $pdo->beginTransaction();
            if ($restoreSal) {
                $pdo->prepare("UPDATE players SET salary = salary + ? WHERE player_id = ?")
                        ->execute(array((int)$rRow['retained_cap_hit'], (int)$rRow['player_id']));
            }
            $pdo->prepare("DELETE FROM retained_salaries WHERE id = ?")->execute(array($retId));
            $pdo->commit();
            $msg = "Retained salary entry #{$retId} removed.";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_roles') {
    $tcIds    = array_map('intval', $_POST['tc'] ?? array());
    $faIds    = array_map('intval', $_POST['fa'] ?? array());
    $draftIds = array_map('intval', $_POST['draft'] ?? array());

    $allUsers = $pdo->query("SELECT user_id FROM users")->fetchAll(PDO::FETCH_COLUMN);
    $upd = $pdo->prepare("
        UPDATE users
        SET is_trade_committee = :tc::boolean,
            is_fa_committee    = :fa::boolean,
            can_manage_draft   = :dr::boolean
        WHERE user_id = :uid
    ");

    foreach ($allUsers as $uid) {
        $uid = (int)$uid;
        $upd->execute(array(
                'tc'  => in_array($uid, $tcIds) ? 1 : 0,
                'fa'  => in_array($uid, $faIds) ? 1 : 0,
                'dr'  => in_array($uid, $draftIds) ? 1 : 0,
                'uid' => $uid
        ));
    }
    $msg = "Trade Committee, FA Committee & Draft permissions updated!";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'transfer_commish') {
    $newCommishId = (int)($_POST['new_commish_id'] ?? 0);
    if ($newCommishId > 0 && $newCommishId !== $myUid) {
        $pdo->beginTransaction();
        $pdo->exec("UPDATE users SET is_commish = FALSE");
        $pdo->prepare("UPDATE users SET is_commish = TRUE, is_admin = TRUE WHERE user_id = ?")->execute(array($newCommishId));
        $pdo->commit();

        $_SESSION['is_commish'] = false;
        header('Location: index.php');
        exit;
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_picks') {
    $pickOwners = $_POST['pick_owner'] ?? array();

    $updPick = $pdo->prepare("UPDATE draft_picks SET current_team_id = ? WHERE pick_id = ?");
    $pdo->beginTransaction();
    foreach ($pickOwners as $pickId => $newOwnerId) {
        $pkid  = (int)$pickId;
        $owner = (int)$newOwnerId;
        if ($pkid > 0 && $owner > 0) {
            $updPick->execute(array($owner, $pkid));
        }
    }
    $pdo->commit();
    $msg = "Draft pick ownership updated for " . htmlspecialchars($_POST['selected_year'] ?? '') . "!";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_pick_condition') {
    $cYear = (int)($_POST['cond_year'] ?? 0);
    $cRnd  = (int)($_POST['cond_round'] ?? 0);
    $cOrig = (int)($_POST['cond_orig_team'] ?? 0);
    $cText = trim($_POST['cond_text'] ?? '');

    if ($cYear > 0 && $cRnd >= 1 && $cRnd <= 7 && $cOrig > 0) {
        $pdo->prepare("UPDATE draft_picks SET conditions = ? WHERE draft_year = ? AND round_num = ? AND original_team_id = ?")
                ->execute(array($cText, $cYear, $cRnd, $cOrig));
        $msg = "Updated condition note on {$cYear} Round {$cRnd} pick!";
    }
}

$selectedYear = isset($_GET['pick_year']) ? (int)$_GET['pick_year'] : (isset($_POST['selected_year']) ? (int)$_POST['selected_year'] : 2027);
$allTeamsList = $pdo->query("SELECT team_id, abbreviation, name FROM teams ORDER BY abbreviation ASC")->fetchAll(PDO::FETCH_ASSOC);

$yearPicksStmt = $pdo->prepare("
    SELECT dp.pick_id, dp.round_num, dp.original_team_id, dp.current_team_id, dp.conditions, orig.abbreviation AS orig_abbr
    FROM draft_picks dp
    JOIN teams orig ON dp.original_team_id = orig.team_id
    WHERE dp.draft_year = ? AND COALESCE(dp.is_used, FALSE) = FALSE
    ORDER BY orig.abbreviation ASC, dp.round_num ASC
");
$yearPicksStmt->execute(array($selectedYear));
$pickGrid = array();
foreach ($yearPicksStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $pickGrid[(int)$row['original_team_id']][(int)$row['round_num']] = $row;
}

$users = $pdo->query("
    SELECT u.user_id, u.username, u.email, u.is_commish, u.is_trade_committee, u.is_fa_committee, u.can_manage_draft, t.name AS team_name
    FROM users u
    LEFT JOIN teams t ON u.team_id = t.team_id
    WHERE LOWER(u.username) <> 'admin'
    ORDER BY t.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$capLimits   = getLeagueCapLimits($pdo);
$capSchedule = $pdo->query("SELECT * FROM nhl_cap_schedule ORDER BY season_year ASC")->fetchAll(PDO::FETCH_ASSOC);

$allPlayersForRet = $pdo->query("
    SELECT p.player_id, p.first_name, p.last_name, p.salary, COALESCE(p.contract_years, 1) AS contract_years, t.abbreviation AS team_abbr
    FROM players p
    LEFT JOIN teams t ON p.team_id = t.team_id
    WHERE COALESCE(p.is_retired, FALSE) = FALSE AND p.salary > 0
    ORDER BY p.last_name ASC, p.first_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$activeRetentions = $pdo->query("
    SELECT rs.*, p.first_name, p.last_name, p.salary AS current_salary, t.name AS team_name, t.abbreviation AS team_abbr
    FROM retained_salaries rs
    JOIN players p ON rs.player_id = p.player_id
    JOIN teams t ON rs.retaining_team_id = t.team_id
    WHERE rs.years_remaining > 0
    ORDER BY t.abbreviation ASC, p.last_name ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Commissioner Control Hub - HFNHL</title>
    <style>
        body { font-family: Arial, sans-serif; background: #0f172a; color: #f8fafc; padding: 20px; max-width: 1200px; margin: auto; }
        h1, h2 { color: #38bdf8; }
        .badge-commish { background: #fbbf24; color: #020617; padding: 3px 8px; border-radius: 4px; font-weight: bold; font-size: 0.8em; margin-left: 8px; vertical-align: middle; }
        .card { background: #1e293b; padding: 20px; border-radius: 8px; border: 1px solid #334155; border-top: 3px solid #0284c7; margin-bottom: 24px; }
        .tool-grid { display: flex; gap: 10px; flex-wrap: wrap; }
        .tool-link { background: #0f172a; color: #7dd3fc; border: 1px solid #334155; padding: 10px 15px; border-radius: 6px; text-decoration: none; font-weight: bold; font-size: 0.9em; transition: all 0.15s; }
        .tool-link:hover { background: #0284c7; color: #fff; border-color: #38bdf8; }
        table { width: 100%; border-collapse: collapse; background: #1e293b; margin-top: 12px; }
        th, td { padding: 9px 10px; text-align: left; border-bottom: 1px solid #334155; }
        th { background: #020617; color: #38bdf8; }
        .center { text-align: center; }
        input[type=checkbox] { width: 18px; height: 18px; accent-color: #38bdf8; cursor: pointer; }
        input[type=date], input[type=text], input[type=number], select { padding: 8px 10px; border-radius: 5px; border: 1px solid #475569; background: #0f172a; color: #fff; }
        .btn { background: #0284c7; color: #fff; border: none; padding: 11px 20px; border-radius: 6px; font-weight: bold; cursor: pointer; }
        .btn:hover { background: #0369a1; }
        .btn-warn { background: #d97706; }
        .btn-warn:hover { background: #b45309; }
        .alert { background: #14532d; color: #86efac; padding: 12px 16px; border-radius: 6px; border: 1px solid #22c55e; margin-bottom: 16px; font-weight: bold; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>

<h1>Commissioner Control Hub <span class="badge-commish">COMMISH</span></h1>
<?php if ($msg): ?><div class="alert"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="card" style="border-bottom: 3px solid #dc2626;">
    <h2 style="margin-top:0;">Commissioner Quick Tools</h2>
    <p style="color:#94a3b8;margin-top:0;">Jump directly to league simulation, playoffs, offseason mode, schedule imports, rookie creation, or annual ratings updates.</p>
    <div class="tool-grid">
        <a class="tool-link" href="playoffs.php">Sim Center, Playoffs &amp; Offseason Mode</a>
        <a class="tool-link" href="import_nhl_schedule.php">Import 84-Game NHL Schedule</a>
        <a class="tool-link" href="create_player.php">Create Rookie / Custom Player</a>
        <a class="tool-link" href="update_ratings.php">Annual Ratings Updater</a>
        <a class="tool-link" href="draft_import.php">Entry Draft Excel / CSV Importer</a>
    </div>
</div>

<div class="card">
    <h2 style="margin-top:0;">NHL Salary Cap Schedule (Active: <?= htmlspecialchars($capLimits['season_label']) ?>)</h2>
    <p style="color:#94a3b8; font-size:0.9em; margin-top:0;">
        Active Limits: <strong style="color:#fbbf24;">$<?= number_format($capLimits['min']) ?></strong> Floor &ndash;
        <strong style="color:#4ade80;">$<?= number_format($capLimits['max']) ?></strong> Ceiling.
        Future seasons automatically activate when you enter Offseason Mode.
    </p>

    <form method="post" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; margin-bottom:16px;">
        <input type="hidden" name="action" value="save_cap_schedule">
        <div>
            <label style="display:block; font-size:0.82em; color:#94a3b8; margin-bottom:4px;">Start Year (e.g. 2027 = 2027-28)</label>
            <input type="number" name="season_year" value="<?= (int)$capLimits['season_year'] + 1 ?>" min="2025" max="2050" style="width:110px;" required>
        </div>
        <div>
            <label style="display:block; font-size:0.82em; color:#94a3b8; margin-bottom:4px;">Cap Floor ($)</label>
            <input type="number" name="cap_min" value="83900000" min="30000000" step="100000" style="width:145px;" required>
        </div>
        <div>
            <label style="display:block; font-size:0.82em; color:#94a3b8; margin-bottom:4px;">Cap Ceiling ($)</label>
            <input type="number" name="cap_max" value="113500000" min="50000000" step="100000" style="width:145px;" required>
        </div>
        <button type="submit" class="btn">Save / Update Season Cap</button>
    </form>

    <?php if (!empty($capSchedule)): ?>
        <table>
            <tr>
                <th>Season</th>
                <th>Cap Floor</th>
                <th>Cap Ceiling</th>
                <th>Status</th>
            </tr>
            <?php foreach ($capSchedule as $cs):
                $isActive = ((int)$cs['season_year'] === (int)$capLimits['season_year']);
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($cs['season_label']) ?></strong></td>
                    <td style="color:#fbbf24;">$<?= number_format((int)$cs['cap_min']) ?></td>
                    <td style="color:#4ade80;">$<?= number_format((int)$cs['cap_max']) ?></td>
                    <td>
                        <?= $isActive ? '<span style="color:#38bdf8; font-weight:bold;">Active Season</span>' : '<span style="color:#94a3b8;">Scheduled</span>' ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</div>
<div class="card">
    <h2 style="margin-top:0;">Retained Salary Manager (NHL Cap Retention)</h2>
    <p style="color:#94a3b8; font-size:0.9em; margin-top:0;">
        Add existing league salary retentions or view active retained cap slots. Max <strong>3 slots</strong> per team, <strong>2 retentions</strong> per player, and <strong>15%</strong> of the cap ceiling.
    </p>

    <form method="post" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; margin-bottom:16px;">
        <input type="hidden" name="action" value="add_retention">
        <div>
            <label style="display:block; font-size:0.82em; color:#94a3b8; margin-bottom:4px;">Player</label>
            <select name="ret_player_id" style="min-width:220px;" required>
                <option value="">-- Select Player --</option>
                <?php foreach ($allPlayersForRet as $pl): ?>
                    <option value="<?= (int)$pl['player_id'] ?>">
                        <?= htmlspecialchars("{$pl['last_name']}, {$pl['first_name']} ({$pl['team_abbr']} - $" . number_format((int)$pl['salary']) . ")") ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="display:block; font-size:0.82em; color:#94a3b8; margin-bottom:4px;">Retaining Team</label>
            <select name="ret_team_id" required>
                <option value="">-- Team --</option>
                <?php foreach ($allTeamsList as $tm): ?>
                    <option value="<?= (int)$tm['team_id'] ?>"><?= htmlspecialchars("{$tm['abbreviation']} - {$tm['name']}") ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="display:block; font-size:0.82em; color:#94a3b8; margin-bottom:4px;">Retain % (1-50)</label>
            <input type="number" name="ret_percent" step="0.01" min="1" max="50" value="50" style="width:95px;" required>
        </div>
        <div>
            <label style="display:block; font-size:0.82em; color:#94a3b8; margin-bottom:4px;">Cap Hit $ (0 = Auto)</label>
            <input type="number" name="ret_amount" min="0" step="1000" value="0" style="width:130px;">
        </div>
        <div>
            <label style="display:block; font-size:0.82em; color:#94a3b8; margin-bottom:4px;">Years Left</label>
            <input type="number" name="ret_years" min="1" max="8" value="1" style="width:75px;" required>
        </div>
        <div style="display:flex; flex-direction:column; gap:4px; font-size:0.82em;">
            <label style="cursor:pointer;"><input type="checkbox" name="deduct_from_player" value="1"> Deduct from player's current salary</label>
            <label style="cursor:pointer;"><input type="checkbox" name="bypass_rules" value="1"> Bypass NHL limits (Force Add)</label>
        </div>
        <button type="submit" class="btn">Add Retention</button>
    </form>

    <?php if (empty($activeRetentions)): ?>
        <p style="color:#94a3b8; font-style:italic; margin-bottom:0;">No active retained salaries in the league.</p>
    <?php else: ?>
        <table>
            <tr>
                <th>Retaining Team</th>
                <th>Player</th>
                <th>Retained %</th>
                <th>Dead Cap Hit / Yr</th>
                <th>Years Remaining</th>
                <th>Action</th>
            </tr>
            <?php foreach ($activeRetentions as $ar): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($ar['team_abbr']) ?></strong> <small style="color:#94a3b8;">(<?= htmlspecialchars($ar['team_name']) ?>)</small></td>
                    <td><?= htmlspecialchars("{$ar['first_name']} {$ar['last_name']}") ?></td>
                    <td style="color:#fbbf24; font-weight:bold;"><?= rtrim(rtrim(number_format((float)$ar['retained_percent'], 2), '0'), '.') ?>%</td>
                    <td style="color:#f87171; font-weight:bold;">$<?= number_format((int)$ar['retained_cap_hit']) ?></td>
                    <td><?= (int)$ar['years_remaining'] ?> yr(s)</td>
                    <td>
                        <form method="post" style="display:flex; gap:8px; align-items:center;" onsubmit="return confirm('Remove this retained salary record?');">
                            <input type="hidden" name="action" value="delete_retention">
                            <input type="hidden" name="retention_id" value="<?= (int)$ar['id'] ?>">
                            <label style="font-size:0.8em; color:#94a3b8; cursor:pointer;">
                                <input type="checkbox" name="restore_salary" value="1"> Add $ back to player
                            </label>
                            <button type="submit" class="btn btn-warn" style="padding:5px 10px; font-size:0.82em;">Remove</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</div>

<div class="card">
    <h2 style="margin-top:0;">Bulk Draft Pick Ownership Editor</h2>
    <p style="color:#94a3b8;margin-top:0;">Select a draft year below, change the owning team dropdown on any traded pick (highlighted in gold), and click <strong>Save All Picks for <?= $selectedYear ?></strong>.</p>

    <form method="get" style="margin-bottom:16px;">
        <label><strong>Select Draft Year:</strong></label>
        <select name="pick_year" onchange="this.form.submit()" style="margin-left:8px;">
            <?php foreach (array(2027, 2028, 2029, 2030, 2031) as $yr): ?>
                <option value="<?= $yr ?>" <?= $selectedYear === $yr ? 'selected' : '' ?>><?= $yr ?> Draft</option>
            <?php endforeach; ?>
        </select>
    </form>

    <form method="post">
        <input type="hidden" name="action" value="save_picks">
        <input type="hidden" name="selected_year" value="<?= $selectedYear ?>">
        <div style="overflow-x:auto;">
            <table>
                <tr>
                    <th>Original Team</th>
                    <th>R1</th><th>R2</th><th>R3</th><th>R4</th><th>R5</th><th>R6</th><th>R7</th>
                </tr>
                <?php foreach ($allTeamsList as $tm): $origId = (int)$tm['team_id']; ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($tm['abbreviation']) ?></strong> <small style="color:#94a3b8;">(<?= htmlspecialchars($tm['name']) ?>)</small></td>
                        <?php for ($rd = 1; $rd <= 7; $rd++):
                            $pk = $pickGrid[$origId][$rd] ?? null;
                            if (!$pk) { echo '<td>—</td>'; continue; }
                            $pkid = (int)$pk['pick_id'];
                            $currOwner = (int)$pk['current_team_id'];
                            $isTraded = ($currOwner !== $origId);
                            ?>
                            <td style="<?= $isTraded ? 'background:#422006;' : '' ?>">
                                <select name="pick_owner[<?= $pkid ?>]" style="padding:5px;font-size:0.88em;<?= $isTraded ? 'border-color:#fbbf24;color:#fde047;font-weight:bold;' : '' ?>">
                                    <?php foreach ($allTeamsList as $opt): ?>
                                        <option value="<?= $opt['team_id'] ?>" <?= (int)$opt['team_id'] === $currOwner ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($opt['abbreviation']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        <?php endfor; ?>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <button type="submit" class="btn" style="margin-top:16px;">Save All Picks for <?= $selectedYear ?></button>
    </form>

    <hr style="border-color:#334155;margin:22px 0;">
    <h3 style="margin-top:0;color:#fbbf24;">Set / Clear Conditional Pick Note</h3>
    <form method="post" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <input type="hidden" name="action" value="save_pick_condition">
        <select name="cond_year" required>
            <?php foreach (array(2027, 2028, 2029, 2030, 2031) as $yr): ?>
                <option value="<?= $yr ?>" <?= $selectedYear === $yr ? 'selected' : '' ?>><?= $yr ?></option>
            <?php endforeach; ?>
        </select>
        <select name="cond_round" required>
            <?php for ($r = 1; $r <= 7; $r++): ?>
                <option value="<?= $r ?>">Round <?= $r ?></option>
            <?php endfor; ?>
        </select>
        <select name="cond_orig_team" required>
            <option value="">-- Original Team --</option>
            <?php foreach ($allTeamsList as $tm): ?>
                <option value="<?= (int)$tm['team_id'] ?>"><?= htmlspecialchars($tm['abbreviation'] . ' - ' . $tm['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="cond_text" placeholder="e.g., Top-10 protected (slides to 2028) — leave blank to clear" style="flex:1;min-width:260px;">
        <button type="submit" class="btn btn-warn">Save Condition</button>
    </form>
</div>

<div class="card">
    <h2 style="margin-top:0;">League Committees &amp; Roles</h2>
    <p style="color:#94a3b8;margin-top:0;">Choose which GMs sit on the Trade Committee, Free Agent Committee, or can upload post-draft prospect files.</p>
    <form method="post">
        <input type="hidden" name="action" value="save_roles">
        <table>
            <tr>
                <th>Franchise</th>
                <th>GM Username</th>
                <th>Email</th>
                <th class="center">Trade Committee<br><small style="color:#94a3b8;">(Approve/Decline Trades)</small></th>
                <th class="center">FA Committee<br><small style="color:#94a3b8;">(Negotiate/Sign FAs)</small></th>
                <th class="center">Draft Uploader<br><small style="color:#94a3b8;">(Import Draft Excel/CSV)</small></th>
            </tr>
            <?php foreach ($users as $u): $uid = (int)$u['user_id']; ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($u['team_name']) ?></strong>
                        <?= !empty($u['is_commish']) ? '<span class="badge-commish">COMMISH</span>' : '' ?>
                    </td>
                    <td><?= htmlspecialchars($u['username']) ?></td>
                    <td style="color:#94a3b8;"><?= htmlspecialchars($u['email'] ?: 'Not set') ?></td>
                    <td class="center"><input type="checkbox" name="tc[]" value="<?= $uid ?>" <?= !empty($u['is_trade_committee']) ? 'checked' : '' ?>></td>
                    <td class="center"><input type="checkbox" name="fa[]" value="<?= $uid ?>" <?= !empty($u['is_fa_committee']) ? 'checked' : '' ?>></td>
                    <td class="center"><input type="checkbox" name="draft[]" value="<?= $uid ?>" <?= !empty($u['can_manage_draft']) ? 'checked' : '' ?>></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <button type="submit" class="btn" style="margin-top:16px;">Save Committee &amp; League Roles</button>
    </form>
</div>

<div class="card" style="border-color:#d97706;">
    <h2 style="margin-top:0;color:#fbbf24;">Transfer Commissioner Role</h2>
    <p style="color:#94a3b8;margin-top:0;">When stepping down as Commissioner, select your successor below to pass the official <strong>COMMISH</strong> tag and full control to their GM account.</p>
    <form method="post" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
        <input type="hidden" name="action" value="transfer_commish">
        <select name="new_commish_id" required>
            <option value="">-- Select Next Commissioner --</option>
            <?php foreach ($users as $u): if ((int)$u['user_id'] === $myUid) continue; ?>
                <option value="<?= (int)$u['user_id'] ?>"><?= htmlspecialchars($u['team_name'] . ' — ' . $u['username']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-warn" onclick="return confirm('Are you sure you want to permanently transfer Commissioner status to this GM?');">Transfer Commish Control</button>
    </form>
</div>

</body>
</html>









