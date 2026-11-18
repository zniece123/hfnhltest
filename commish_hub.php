<?php
require 'db.php';
require_once 'cap_helper.php';


if (empty($_SESSION['is_commish'])) {
    die("Access denied. Only the active League Commissioner can access this page.");
}

$myUid   = (int)$_SESSION['user_id'];
$msg     = '';
$simLogs = '';

$pdo->exec("
    CREATE TABLE IF NOT EXISTS league_settings (
        setting_key VARCHAR(50) PRIMARY KEY,
        setting_value VARCHAR(100) NOT NULL
    )
");
$pdo->exec("
    INSERT INTO league_settings (setting_key, setting_value)
    VALUES ('league_phase', 'REGULAR_SEASON')
    ON CONFLICT (setting_key) DO NOTHING
");
$currentPhase = $pdo->query("SELECT setting_value FROM league_settings WHERE setting_key = 'league_phase'")->fetchColumn() ?: 'REGULAR_SEASON';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'manual_sim') {
    $simDate = trim($_POST['sim_date'] ?? '');

    $exePath = 'C:\\HFNHLSimEngine\\bin\\Debug\\net8.0\\HFNHLSimEngine.exe';
    if (!file_exists($exePath)) {
        $exePath = 'C:\\HFNHLSimEngine\\bin\\Release\\net8.0\\HFNHLSimEngine.exe';
    }

    if (file_exists($exePath)) {
        if ($simDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $simDate)) {
            $pdo->prepare("UPDATE games SET game_date = CURRENT_DATE WHERE game_date = ? AND status = 'SCHEDULED'")
                    ->execute(array($simDate));
        }

        $cmd     = '"' . $exePath . '" 2>&1';
        $simLogs = shell_exec($cmd);
        $msg     = "Manual simulation executed! Check the output below.";
    } else {
        $msg = "Could not find HFNHLSimEngine.exe at {$exePath}. Update \$exePath inside commish_hub.php to match your Visual Studio bin folder.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'offseason_control') {
    $offAct = $_POST['offseason_step'] ?? '';

    if ($offAct === 'enable_offseason') {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE league_settings SET setting_value = 'OFFSEASON' WHERE setting_key = 'league_phase'")->execute();

        $initCheck = $pdo->query("SELECT setting_value FROM league_settings WHERE setting_key = 'initial_offseason_done'")->fetchColumn();

        $pdo->exec("UPDATE players SET qo_tendered = FALSE");

        if ($initCheck === '1') {

            $pdo->exec("
                UPDATE players
                SET contract_years = GREATEST(0, COALESCE(contract_years, 0) - 1),
                    age            = COALESCE(age, 25) + 1
                WHERE COALESCE(is_retired, FALSE) = FALSE
                  AND UPPER(COALESCE(roster_status, '')) NOT IN ('RETIRED', 'EURO', 'FA')
            ");
            $rollNote = "Contracts rolled over (-1 yr) and player ages advanced (+1 yr).";
        } else {
            $pdo->exec("
                INSERT INTO league_settings (setting_key, setting_value)
                VALUES ('initial_offseason_done', '1')
                ON CONFLICT (setting_key) DO UPDATE SET setting_value = '1'
            ");
            $rollNote = "Initial 2026 Offseason activated using imported 0-year contracts. Future offseasons will automatically roll contracts forward.";
        }

        $pdo->exec("
            UPDATE players
            SET fa_status = CASE WHEN COALESCE(age, 27) < 27 THEN 'RFA' ELSE 'UFA' END
            WHERE COALESCE(contract_years, 0) = 0
              AND team_id IS NOT NULL
              AND COALESCE(is_retired, FALSE) = FALSE
              AND UPPER(COALESCE(roster_status, '')) NOT IN ('RETIRED', 'EURO', 'FA')
        ");
        $newCapInfo = advanceLeagueCapForOffseason($pdo, ($initCheck === '1'));
        $capNote    = "Salary Cap updated for {$newCapInfo['season_label']}: $" . number_format($newCapInfo['min']) . " Floor / $" . number_format($newCapInfo['max']) . " Ceiling.";

        $pdo->commit();
        $currentPhase = 'OFFSEASON';
        $msg = "OFFSEASON MODE ENABLED! {$rollNote} {$capNote}";
    }


    elseif ($offAct === 'generate_fa_pool') {
        $pdo->beginTransaction();

        $nonTenderedStmt = $pdo->prepare("
    UPDATE players p
    SET fa_status = 'UFA'
    WHERE COALESCE(p.contract_years, 0) <= 0
      AND UPPER(COALESCE(p.fa_status, '')) = 'RFA'
      AND COALESCE(p.qo_tendered, FALSE) = FALSE
      AND NOT EXISTS (
          SELECT 1 FROM contract_negotiations cn
          WHERE cn.player_id = p.player_id
            AND UPPER(COALESCE(cn.fa_status, '')) = 'RFA'
            AND UPPER(COALESCE(cn.status, '')) NOT IN ('WITHDRAWN', 'REJECTED', 'EXPIRED')
      )
      AND p.team_id IS NOT NULL
      AND COALESCE(p.is_retired, FALSE) = FALSE
      AND UPPER(COALESCE(p.roster_status, '')) NOT IN ('RETIRED', 'EURO', 'FA')
");
        $nonTenderedStmt->execute();
        $convertedToUfa = $nonTenderedStmt->rowCount();

        $departingIds = $pdo->query("
            SELECT player_id FROM players
            WHERE COALESCE(contract_years, 0) <= 0
              AND UPPER(COALESCE(fa_status, '')) IN ('UFA', 'RFA')
              AND team_id IS NOT NULL
              AND COALESCE(is_retired, FALSE) = FALSE
              AND UPPER(COALESCE(roster_status, '')) NOT IN ('RETIRED', 'EURO', 'FA')
        ")->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($departingIds)) {
            $allLineRows = $pdo->query("SELECT * FROM team_lines")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($allLineRows as $lr) {
                $tid = (int)$lr['team_id'];
                $nullCols = array();
                foreach ($lr as $col => $val) {
                    if ($col !== 'team_id' && $col !== 'id' && in_array((int)$val, $departingIds)) {
                        $nullCols[] = "\"{$col}\" = NULL";
                    }
                }
                if (!empty($nullCols)) {
                    $pdo->prepare("UPDATE team_lines SET " . implode(', ', $nullCols) . " WHERE team_id = ?")
                            ->execute(array($tid));
                }
            }
        }

        $stmt = $pdo->prepare("
            UPDATE players
            SET prev_team_id  = team_id,
                team_id       = NULL,
                roster_status = 'FA',
                line_number   = 99
            WHERE COALESCE(contract_years, 0) <= 0
              AND UPPER(COALESCE(fa_status, '')) IN ('UFA', 'RFA')
              AND team_id IS NOT NULL
              AND COALESCE(is_retired, FALSE) = FALSE
              AND UPPER(COALESCE(roster_status, '')) NOT IN ('RETIRED', 'EURO', 'FA')
        ");
        $stmt->execute();
        $releasedCount = $stmt->rowCount();

        $pdo->commit();
        $msg = "FREE AGENCY OPENED! {$releasedCount} unsigned players (UFAs & QO'd RFAs) entered Free Agency ({$convertedToUfa} non-tendered RFAs became UFAs).";
    }

    elseif ($offAct === 'start_regular_season') {
        $pdo->prepare("UPDATE league_settings SET setting_value = 'REGULAR_SEASON' WHERE setting_key = 'league_phase'")->execute();
        $currentPhase = 'REGULAR_SEASON';
        $msg = "League switched back to Regular Season Mode.";
    }
        elseif ($offAct === 'start_regular_season') {
        $pdo->prepare("UPDATE league_settings SET setting_value = 'REGULAR_SEASON' WHERE setting_key = 'league_phase'")->execute();
        $currentPhase = 'REGULAR_SEASON';
        $msg = "League switched back to Regular Season Mode.";
    }

    elseif ($offAct === 'set_fa_deadline') {
        $deadlineInput = trim($_POST['fa_signing_deadline'] ?? '');
        $pdo->prepare("
            INSERT INTO league_settings (setting_key, setting_value)
            VALUES ('fa_signing_deadline', ?)
            ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value
        ")->execute(array($deadlineInput));

        $msg = ($deadlineInput !== '')
            ? "Free Agency Signing Deadline set to: " . date('M j, Y g:i A', strtotime($deadlineInput))
            : "Free Agency Signing Deadline cleared (open signings enabled).";
    }

    elseif ($offAct === 'award_highest_bids') {
        $pdo->beginTransaction();

        $biddedPlayerIds = $pdo->query("
            SELECT DISTINCT b.player_id
            FROM fa_bids b
            JOIN players p ON b.player_id = p.player_id
            WHERE b.status = 'ACTIVE'
              AND UPPER(COALESCE(p.roster_status, '')) = 'FA'
        ")->fetchAll(PDO::FETCH_COLUMN);

        $awardedCount  = 0;
        $rfaMatchCount = 0;

        $topBidStmt = $pdo->prepare("
            SELECT * FROM fa_bids
            WHERE player_id = ? AND status = 'ACTIVE'
            ORDER BY (offered_salary * offered_years) DESC, offered_salary DESC, updated_at ASC
            LIMIT 1
        ");

        $playerInfoStmt = $pdo->prepare("SELECT fa_status, prev_team_id FROM players WHERE player_id = ?");

        $signPlayerStmt = $pdo->prepare("
            UPDATE players
            SET team_id        = ?,
                roster_status  = ?,
                contract_years = ?,
                salary         = ?,
                fa_status      = NULL,
                qo_tendered    = FALSE,
                line_number    = 99
            WHERE player_id = ?
        ");

        foreach ($biddedPlayerIds as $pid) {
            $pid = (int)$pid;
            $topBidStmt->execute(array($pid));
            $win = $topBidStmt->fetch(PDO::FETCH_ASSOC);

            if ($win) {
                $playerInfoStmt->execute(array($pid));
                $pInfo     = $playerInfoStmt->fetch(PDO::FETCH_ASSOC);
                $faStatus  = strtoupper(trim($pInfo['fa_status'] ?? 'UFA'));
                $prevTid   = (int)($pInfo['prev_team_id'] ?? 0);
                $bidderTid = (int)$win['team_id'];

                $pdo->prepare("UPDATE fa_bids SET status = 'OUTBID' WHERE player_id = ? AND bid_id <> ? AND status = 'ACTIVE'")
                        ->execute(array($pid, (int)$win['bid_id']));

                if ($faStatus === 'RFA' && $prevTid > 0 && $bidderTid !== $prevTid) {
                    $pdo->prepare("
                        UPDATE fa_bids
                        SET status = 'MATCH_PENDING',
                            match_deadline = NOW() + INTERVAL '7 days'
                        WHERE bid_id = ?
                    ")->execute(array((int)$win['bid_id']));
                    $rfaMatchCount++;
                } else {
                    $signPlayerStmt->execute(array(
                            $bidderTid,
                            $win['dest_roster'],
                            (int)$win['offered_years'],
                            (int)$win['offered_salary'],
                            $pid
                    ));
                    $pdo->prepare("UPDATE fa_bids SET status = 'WON' WHERE bid_id = ?")
                            ->execute(array((int)$win['bid_id']));
                    $awardedCount++;
                }
            }
        }

        $pdo->commit();
        $msg = "BIDS PROCESSED! {$awardedCount} players signed immediately, and {$rfaMatchCount} RFA Offer Sheets entered the 7-day Match Window!";
    }
}
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


$pendingUfaCount = (int)$pdo->query("
    SELECT COUNT(*) FROM players
    WHERE COALESCE(contract_years, 0) <= 0
      AND UPPER(COALESCE(fa_status, '')) IN ('UFA', 'RFA')
      AND team_id IS NOT NULL
      AND COALESCE(is_retired, FALSE) = FALSE
      AND UPPER(COALESCE(roster_status, '')) NOT IN ('RETIRED', 'EURO', 'FA')
")->fetchColumn();

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

$pendingUfaCount = (int)$pdo->query("
    SELECT COUNT(*) FROM players
    WHERE COALESCE(contract_years, 0) <= 0
      AND UPPER(COALESCE(fa_status, '')) = 'UFA'
      AND team_id IS NOT NULL
      AND COALESCE(is_retired, FALSE) = FALSE
      AND UPPER(COALESCE(roster_status, '')) NOT IN ('RETIRED', 'EURO', 'FA')
")->fetchColumn();

$activeFaCount = (int)$pdo->query("
    SELECT COUNT(*) FROM players
    WHERE UPPER(COALESCE(roster_status, '')) = 'FA'
      AND COALESCE(is_retired, FALSE) = FALSE
")->fetchColumn();

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Commissioner Control Hub - HFNHL</title>
    <style>
        body { font-family: Arial, sans-serif; background: #0f172a; color: #f8fafc; padding: 20px; max-width: 1200px; margin: auto; }
        nav { background: #020617; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; display: flex; gap: 20px; border: 1px solid #334155; align-items: center; }
        nav a { color: #38bdf8; text-decoration: none; font-weight: bold; }
        h1, h2 { color: #38bdf8; }
        .badge-commish { background: #fbbf24; color: #020617; padding: 3px 8px; border-radius: 4px; font-weight: bold; font-size: 0.8em; margin-left: 8px; vertical-align: middle; }
        .card { background: #1e293b; padding: 20px; border-radius: 8px; border: 1px solid #334155; margin-bottom: 24px; }
        table { width: 100%; border-collapse: collapse; background: #1e293b; margin-top: 12px; }
        th, td { padding: 9px 10px; text-align: left; border-bottom: 1px solid #334155; }
        th { background: #020617; color: #38bdf8; }
        .center { text-align: center; }
        input[type=checkbox] { width: 18px; height: 18px; accent-color: #38bdf8; cursor: pointer; }
        input[type=date], input[type=text], select { padding: 8px 10px; border-radius: 5px; border: 1px solid #475569; background: #0f172a; color: #fff; }
        .btn { background: #0284c7; color: #fff; border: none; padding: 11px 20px; border-radius: 6px; font-weight: bold; cursor: pointer; }
        .btn:hover { background: #0369a1; }
        .btn-warn { background: #d97706; }
        .btn-warn:hover { background: #b45309; }
        .alert { background: #14532d; color: #86efac; padding: 12px 16px; border-radius: 6px; border: 1px solid #22c55e; margin-bottom: 16px; font-weight: bold; }
        pre { background: #020617; color: #4ade80; padding: 14px; border-radius: 6px; border: 1px solid #334155; max-height: 300px; overflow-y: auto; font-size: 0.88em; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>

<h1>Commissioner Control Hub <span class="badge-commish">COMMISH</span></h1>
<?php if ($msg): ?><div class="alert"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="card">
    <h2 style="margin-top:0;">Manual Sim Override</h2>
    <p style="color:#94a3b8;margin-top:0;">Run the simulation engine manually for today's scheduled games, or pick a specific schedule date to advance the sim immediately.</p>
    <form method="post" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
        <input type="hidden" name="action" value="manual_sim">
        <label><strong>Target Date (optional):</strong></label>
        <input type="date" name="sim_date">
        <button type="submit" class="btn btn-warn" onclick="return confirm('Run the simulation engine now?');">Run Manual Sim Now</button>
    </form>
    <?php if ($simLogs !== ''): ?>
        <h3 style="margin-bottom:6px;">Simulation Output:</h3>
        <pre><?= htmlspecialchars($simLogs) ?></pre>
    <?php endif; ?>
</div>

<div class="card" style="border-color: <?= $currentPhase === 'OFFSEASON' ? '#38bdf8' : '#334155' ?>;">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <h2 style="margin:0;">Offseason Mode &amp; Free Agency Generator</h2>
        <span style="padding:5px 12px; border-radius:6px; font-weight:bold; font-size:0.85em;
                background: <?= $currentPhase === 'OFFSEASON' ? '#0284c7' : '#334155' ?>; color: #fff;">
            Current Mode: <?= htmlspecialchars($currentPhase) ?>
        </span>
    </div>

    <p style="color:#94a3b8; margin-top:8px;">
        <strong>Step 1:</strong> Enable Offseason Mode to roll over contracts by 1 year and flag expiring players as pending UFAs/RFAs so teams can re-sign their own players.<br>
        <strong>Step 2:</strong> Once the re-signing window closes, click <strong>Release Unsigned UFAs to Free Agency</strong>. Anyone not re-signed (excluding Retired or Europe players) automatically leaves their team and enters the Free Agent pool.
    </p>

    <div style="display:flex; gap:20px; margin-bottom:14px; font-size:0.92em; color:#cbd5e1;">
        <span><strong>Pending Unsigned UFAs on Rosters:</strong> <span style="color:#fbbf24; font-weight:bold;"><?= $pendingUfaCount ?></span></span>
        <span><strong>Current Free Agents in Pool:</strong> <span style="color:#4ade80; font-weight:bold;"><?= $activeFaCount ?></span></span>
        <span><a href="free_agents.php" style="color:#38bdf8; font-weight:bold;">View Public Free Agent List &rarr;</a></span>
    </div>

    <form method="post" style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
        <input type="hidden" name="action" value="offseason_control">

        <?php if ($currentPhase !== 'OFFSEASON'): ?>
            <button type="submit" name="offseason_step" value="enable_offseason" class="btn"
                    onclick="return confirm('Enter Offseason Mode? This will subtract 1 year from all active contracts and tag expiring players as pending UFAs/RFAs.');">
                1. Turn ON Offseason Mode (Roll Contracts)
            </button>
        <?php else: ?>
            <button type="submit" name="offseason_step" value="generate_fa_pool" class="btn btn-warn"
                    onclick="return confirm('Release all <?= $pendingUfaCount ?> unsigned UFAs from team rosters into Free Agency? (Retired & Europe players will remain untouched.)');">
                2. Release Unsigned UFAs &amp; Generate FA List (<?= $pendingUfaCount ?>)
            </button>

            <button type="submit" name="offseason_step" value="start_regular_season" class="btn" style="background:#16a34a;"
                    onclick="return confirm('Switch league back to Regular Season Mode?');">
                3. Return to Regular Season Mode
            </button>
        <?php endif; ?>
    </form>

    <?php
    $faDeadline     = $pdo->query("SELECT setting_value FROM league_settings WHERE setting_key = 'fa_signing_deadline'")->fetchColumn() ?: '';
    $activeBidCount = (int)$pdo->query("SELECT COUNT(*) FROM fa_bids WHERE status = 'ACTIVE'")->fetchColumn();
    ?>
    <hr style="border-color:#334155; margin:16px 0;">
    <form method="post" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <input type="hidden" name="action" value="offseason_control">
        <label><strong>Signing Unlock Date/Time:</strong></label>
        <input type="datetime-local" name="fa_signing_deadline" value="<?= htmlspecialchars($faDeadline) ?>"
               style="padding:8px 10px; border-radius:5px; border:1px solid #475569; background:#0f172a; color:#fff;">
        <button type="submit" name="offseason_step" value="set_fa_deadline" class="btn">Save Deadline</button>

        <?php if ($activeBidCount > 0): ?>
            <button type="submit" name="offseason_step" value="award_highest_bids" class="btn btn-warn"
                    onclick="return confirm('Award all <?= $activeBidCount ?> active Free Agent bids to the highest bidders now?');">
                Award Highest Bidders Now (<?= $activeBidCount ?> Active Bids)
            </button>
        <?php endif; ?>
    </form>
</div>
<?php
$capLimits   = getLeagueCapLimits($pdo);
$capSchedule = $pdo->query("SELECT * FROM nhl_cap_schedule ORDER BY season_year ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="card">
    <h2>NHL Salary Cap Schedule (Active: <?= htmlspecialchars($capLimits['season_label']) ?>)</h2>
    <p style="color:#94a3b8; font-size:0.9em; margin-top:0;">
        Active Limits: <strong style="color:#fbbf24;">$<?= number_format($capLimits['min']) ?></strong> Floor &ndash;
        <strong style="color:#4ade80;">$<?= number_format($capLimits['max']) ?></strong> Ceiling.
        Future seasons automatically activate when you enter Offseason Mode.
    </p>

    <form method="post" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; margin-bottom:16px;">
        <input type="hidden" name="action" value="save_cap_schedule">
        <div>
            <label style="display:block; font-size:0.82em; color:#94a3b8; margin-bottom:4px;">Start Year (e.g. 2027 = 2027-28)</label>
            <input type="number" name="season_year" value="<?= (int)$capLimits['season_year'] + 1 ?>" min="2025" max="2050"
                   style="padding:7px 10px; border-radius:5px; border:1px solid #475569; background:#0f172a; color:#fff; width:110px;" required>
        </div>
        <div>
            <label style="display:block; font-size:0.82em; color:#94a3b8; margin-bottom:4px;">Cap Floor ($)</label>
            <input type="number" name="cap_min" value="83900000" min="30000000" step="100000"
                   style="padding:7px 10px; border-radius:5px; border:1px solid #475569; background:#0f172a; color:#fff; width:145px;" required>
        </div>
        <div>
            <label style="display:block; font-size:0.82em; color:#94a3b8; margin-bottom:4px;">Cap Ceiling ($)</label>
            <input type="number" name="cap_max" value="113500000" min="50000000" step="100000"
                   style="padding:7px 10px; border-radius:5px; border:1px solid #475569; background:#0f172a; color:#fff; width:145px;" required>
        </div>
        <button type="submit" class="btn">Save / Update Season Cap</button>
    </form>

    <?php if (!empty($capSchedule)): ?>
        <table style="width:100%; border-collapse:collapse; font-size:0.9em;">
            <tr style="background:#020617; color:#38bdf8;">
                <th style="padding:8px; text-align:left;">Season</th>
                <th style="padding:8px; text-align:left;">Cap Floor</th>
                <th style="padding:8px; text-align:left;">Cap Ceiling</th>
                <th style="padding:8px; text-align:left;">Status</th>
            </tr>
            <?php foreach ($capSchedule as $cs):
                $isActive = ((int)$cs['season_year'] === (int)$capLimits['season_year']);
                ?>
                <tr style="border-bottom:1px solid #334155;">
                    <td style="padding:8px;"><strong><?= htmlspecialchars($cs['season_label']) ?></strong></td>
                    <td style="padding:8px; color:#fbbf24;">$<?= number_format((int)$cs['cap_min']) ?></td>
                    <td style="padding:8px; color:#4ade80;">$<?= number_format((int)$cs['cap_max']) ?></td>
                    <td style="padding:8px;">
                        <?= $isActive ? '<span style="color:#38bdf8; font-weight:bold;">Active Season</span>' : '<span style="color:#94a3b8;">Scheduled</span>' ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</div>
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
    <p style="color:#94a3b8;margin-top:0;">Choose which GMs sit on the Trade Committee, Free Agent Committee, or can upload post-draft prospect CSVs.</p>
    <form method="post">
        <input type="hidden" name="action" value="save_roles">
        <table>
            <tr>
                <th>Franchise</th>
                <th>GM Username</th>
                <th>Email</th>
                <th class="center">Trade Committee<br><small style="color:#94a3b8;">(Approve/Decline Trades)</small></th>
                <th class="center">FA Committee<br><small style="color:#94a3b8;">(Negotiate/Sign FAs)</small></th>
                <th class="center">Draft Uploader<br><small style="color:#94a3b8;">(Import Draft CSV)</small></th>
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




