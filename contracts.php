<?php
require 'db.php';
require 'mailer.php';
/** @var PDO $pdo */

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
function getNhlSeasonStartYear($refTimestamp = null) {
    $ts   = $refTimestamp ?? time();
    $year = (int)date('Y', $ts);

    $openingNightTs = strtotime("first wednesday of october {$year}");

    return ($ts >= $openingNightTs) ? $year : ($year - 1);
}

$realNhlSeasonYear = getNhlSeasonStartYear(time());

$simDateStr = $pdo->query("
    SELECT COALESCE(
        (SELECT MIN(game_date)::text FROM games WHERE status = 'SCHEDULED'),
        (SELECT MAX(game_date)::text FROM games)
    )
")->fetchColumn();

$simSeasonYear = $simDateStr ? getNhlSeasonStartYear(strtotime($simDateStr)) : $realNhlSeasonYear;

$currentSimYear = max($realNhlSeasonYear, $simSeasonYear);
$nextOpeningStr = date('M j, Y', strtotime('first wednesday of october ' . ($currentSimYear + 1)));

$cbaStmt = $pdo->prepare("SELECT * FROM league_cba_rules WHERE season_year <= ? ORDER BY season_year DESC LIMIT 1");
$cbaStmt->execute(array($currentSimYear));
$cbaRow = $cbaStmt->fetch(PDO::FETCH_ASSOC);

$yearsBeyond = max(0, $currentSimYear - (int)($cbaRow['season_year'] ?? 2026));
$dynCap      = (int)(($cbaRow['salary_cap'] ?? 104000000) * pow(1.045, $yearsBeyond));
$dynMin      = (int)($cbaRow['min_salary'] ?? 850000) + ($yearsBeyond * 50000);
$dynMax      = (int)round($dynCap * 0.20);

define('ACTIVE_SEASON_LABEL', $currentSimYear . '-' . substr((string)($currentSimYear + 1), -2));
define('NEXT_SEASON_ROLLOVER', $nextOpeningStr);
define('MAX_CONTRACT_YEARS', (int)($cbaRow['max_contract_years'] ?? 4));
define('NHL_SALARY_CAP', $dynCap);
define('NHL_MIN_SALARY', $dynMin);
define('NHL_MAX_SALARY', $dynMax);


$myTeamId      = (int)($_SESSION['team_id'] ?? 0);
$isAdmin       = !empty($_SESSION['is_admin']);
$isCommish     = !empty($_SESSION['is_commish']);
$isFaCommittee = !empty($_SESSION['is_fa_committee']) || $isCommish || $isAdmin;
$faCommEmail   = 'facommittee@yourleague.com'; // <-- Replace with your Free Agent Committee email address
$msg           = '';
$err           = '';

$nhlFaCaseSql = "
    COALESCE(
        NULLIF(p.fa_status, ''),
        CASE
            WHEN COALESCE(p.age, 24) >= 27 THEN 'UFA'
            WHEN p.position = 'G' AND COALESCE(p.nhl_games_played, 0) >= 80 THEN 'UFA'
            WHEN p.position <> 'G' AND COALESCE(p.nhl_games_played, 0) >= 280 THEN 'UFA'
            ELSE 'RFA'
        END
    )
";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_offer') {
    $offerCategory = $_POST['offer_category'] ?? '';
    $offersInput   = $_POST['offers'] ?? array();
    $validBatch    = array();
    $rowErrors     = array();

    if ($myTeamId > 0 && is_array($offersInput)) {
        $pCheckStmt = $pdo->prepare("
            SELECT p.player_id, p.first_name, p.last_name, p.position, p.overall_rating,
                   p.team_id, p.salary AS prev_salary, COALESCE(p.contract_years, 0) AS prev_years,
                   {$nhlFaCaseSql} AS calc_fa
            FROM players p
            WHERE p.player_id = ? AND COALESCE(p.contract_years, 0) <= 1
        ");

        foreach ($offersInput as $pidRaw => $row) {
            $pid    = (int)$pidRaw;
            $years  = (int)($row['years'] ?? 0);
            $salary = (int)preg_replace('/[^0-9]/', '', $row['salary'] ?? '');
            $reason = trim($row['reason'] ?? '');

            if ($salary <= 0) {
                continue;
            }

            if ($years < 1 || $years > MAX_CONTRACT_YEARS) {
                $rowErrors[] = "Player #{$pid}: Term must be 1–" . MAX_CONTRACT_YEARS . " years.";
                continue;
            }
            if ($salary < NHL_MIN_SALARY || $salary > NHL_MAX_SALARY) {
                $rowErrors[] = "Player #{$pid}: Salary must be between $" . number_format(NHL_MIN_SALARY) . " and $" . number_format(NHL_MAX_SALARY) . ".";
                continue;
            }

            $pCheckStmt->execute(array($pid));
            $pl = $pCheckStmt->fetch(PDO::FETCH_ASSOC);
            if (!$pl) {
                continue;
            }

            $isMine     = ((int)$pl['team_id'] === $myTeamId);
            $isUnsigned = empty($pl['team_id']);
            $faType     = $pl['calc_fa'];

            $eligible = false;
            if ($offerCategory === 'RFA' && $isMine && $faType === 'RFA') {
                $eligible = true;
            } elseif ($offerCategory === 'TEAM_UFA' && $isMine && $faType === 'UFA') {
                $eligible = true;
            } elseif ($offerCategory === 'OPEN_FA' && $isUnsigned) {
                $eligible = true;
            }

            if (!$eligible) {
                $rowErrors[] = "{$pl['first_name']} {$pl['last_name']} is not eligible under this offer type.";
                continue;
            }

            $pl['offer_years']  = $years;
            $pl['offer_salary'] = $salary;
            $pl['offer_reason'] = $reason;
            $validBatch[]       = $pl;
        }
    }

    if (!empty($rowErrors)) {
        $err = implode(' ', $rowErrors);
    } elseif (empty($validBatch)) {
        $err = "Enter a valid salary on at least one player in that section before submitting.";
    } else {
        $pdo->beginTransaction();

        $insNeg = $pdo->prepare("
            INSERT INTO contract_negotiations (
                player_id, team_id, fa_status, proposed_years, proposed_salary,
                last_actor, last_reason, status
            ) VALUES (?, ?, ?, ?, ?, 'GM', ?, 'PENDING_COMMITTEE')
            RETURNING neg_id
        ");

        $insHist = $pdo->prepare("
            INSERT INTO contract_offer_history (neg_id, actor_role, offered_years, offered_salary, reason)
            VALUES (?, 'GM', ?, ?, ?)
        ");

        $emailLines = array();
        foreach ($validBatch as $item) {
            $reasonForLog = $item['offer_reason'];
            if ($item['calc_fa'] === 'RFA') {
                $prevInfo     = "Prev Contract: $" . number_format((int)$item['prev_salary']) . " (" . (int)$item['prev_years'] . " yr)";
                $reasonForLog = $reasonForLog !== '' ? "{$prevInfo} | {$reasonForLog}" : $prevInfo;
            }

            $insNeg->execute(array(
                    (int)$item['player_id'],
                    $myTeamId,
                    $item['calc_fa'],
                    (int)$item['offer_years'],
                    (int)$item['offer_salary'],
                    $reasonForLog
            ));
            $negId = (int)$insNeg->fetchColumn();

            $insHist->execute(array(
                    $negId,
                    (int)$item['offer_years'],
                    (int)$item['offer_salary'],
                    $reasonForLog
            ));

            $fmtOffer = '$' . number_format((int)$item['offer_salary']);
            $line     = "• [Neg #{$negId}] {$item['first_name']} {$item['last_name']} ({$item['position']}, {$item['overall_rating']} OVR - {$item['calc_fa']})\n";
            $line    .= "   Offer: {$item['offer_years']} yr(s) @ {$fmtOffer} AAV\n";

            if ($item['calc_fa'] === 'RFA') {
                $fmtPrev = '$' . number_format((int)$item['prev_salary']);
                $line   .= "   Previous Contract: {$fmtPrev} / yr ({$item['prev_years']} yr term)\n";

                $pdo->prepare("
                    UPDATE players
                    SET qo_tendered = TRUE,
                        fa_status   = 'RFA'
                    WHERE player_id = ?
                ")->execute(array((int)$item['player_id']));
            }

            if ($item['offer_reason'] !== '') {
                $line .= "   GM Notes: {$item['offer_reason']}\n";
            }
            $emailLines[] = $line;
        }

        $pdo->commit();

        $teamName = $pdo->query("SELECT name FROM teams WHERE team_id = {$myTeamId}")->fetchColumn();
        $catLabels = array(
                'RFA'      => 'RFA Re-Signings',
                'TEAM_UFA' => 'Pending Team UFAs',
                'OPEN_FA'  => 'Unrestricted Free Agents'
        );
        $catTitle = $catLabels[$offerCategory] ?? 'Contract Offers';
        $count    = count($validBatch);

        $emails = $pdo->query("SELECT email FROM users WHERE (is_fa_committee = TRUE OR is_commish = TRUE OR is_admin = TRUE) AND email <> ''")->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($faCommEmail)) {
            $emails[] = $faCommEmail;
        }

        $emailBody  = "{$teamName} has submitted {$count} bulk contract offer(s) under [{$catTitle}]:\n\n";
        $emailBody .= implode("\n", $emailLines);
        $emailBody .= "\nLog in to the HFNHL Contract Center to Accept, Decline, or Send Counter-Offers with reasoning.";

        sendLeagueEmail(
                array_unique($emails),
                "HFNHL Bulk {$catTitle}: {$teamName} ({$count} Offer" . ($count > 1 ? 's' : '') . ")",
                $emailBody
        );

        $msg = "Submitted {$count} {$catTitle} offer(s) and emailed the Free Agent Committee!";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'counter_offer') {
    $negId  = (int)($_POST['neg_id'] ?? 0);
    $years  = (int)($_POST['years'] ?? 0);
    $salary = (int)preg_replace('/[^0-9]/', '', $_POST['salary'] ?? '0');
    $reason = trim($_POST['reason'] ?? '');

    if ($years < 1 || $years > MAX_CONTRACT_YEARS) {
        $err = "Counter-offer term must be between 1 and " . MAX_CONTRACT_YEARS . " years.";
    } elseif ($salary < NHL_MIN_SALARY || $salary > NHL_MAX_SALARY) {
        $err = "Counter-offer salary must be between $" . number_format(NHL_MIN_SALARY) . " and $" . number_format(NHL_MAX_SALARY) . ".";
    } else {
        $nStmt = $pdo->prepare("
            SELECT cn.*, p.first_name, p.last_name, p.salary AS prev_salary, p.contract_years AS prev_years, t.name AS team_name
            FROM contract_negotiations cn
            JOIN players p ON cn.player_id = p.player_id
            JOIN teams t ON cn.team_id = t.team_id
            WHERE cn.neg_id = ?
        ");
        $nStmt->execute(array($negId));
        $neg = $nStmt->fetch();

        if ($neg) {
            $isMyNeg        = ((int)$neg['team_id'] === $myTeamId);
            $canCommCounter = ($neg['status'] === 'PENDING_COMMITTEE' && $isFaCommittee && !$isMyNeg);
            $canGmCounter   = ($neg['status'] === 'PENDING_GM' && $isMyNeg);

            if ($canCommCounter && $reason === '') {
                $err = "The Free Agent Committee must provide a reason explaining why the player is worth this contract.";
            } elseif ($canCommCounter || $canGmCounter) {
                $actorRole  = $canCommCounter ? 'COMMITTEE' : 'GM';
                $nextStatus = $canCommCounter ? 'PENDING_GM' : 'PENDING_COMMITTEE';

                $pdo->beginTransaction();
                $pdo->prepare("
                    UPDATE contract_negotiations
                    SET proposed_years = ?, proposed_salary = ?, last_actor = ?, last_reason = ?, status = ?, updated_at = NOW()
                    WHERE neg_id = ?
                ")->execute(array($years, $salary, $actorRole, $reason, $nextStatus, $negId));

                $pdo->prepare("
                    INSERT INTO contract_offer_history (neg_id, actor_role, offered_years, offered_salary, reason)
                    VALUES (?, ?, ?, ?, ?)
                ")->execute(array($negId, $actorRole, $years, $salary, $reason));
                $pdo->commit();

                $pName  = "{$neg['first_name']} {$neg['last_name']} ({$neg['fa_status']})";
                $fmtSal = '$' . number_format($salary);

                if ($canCommCounter) {
                    $gmEmails = $pdo->query("SELECT email FROM users WHERE team_id = " . (int)$neg['team_id'] . " AND email <> ''")->fetchAll(PDO::FETCH_COLUMN);
                    sendLeagueEmail(
                            $gmEmails,
                            "HFNHL Counter-Offer on {$pName} (Negotiation #{$negId})",
                            "The Free Agent Committee has countered your offer for {$pName}:\n" .
                            "Counter Terms: {$years} yr(s) @ {$fmtSal} AAV\n" .
                            "Why He's Worth It: {$reason}\n\n" .
                            "Log in to the HFNHL Contract Center to Accept or Counter Back."
                    );
                    $msg = "Counter-offer and reasoning sent to {$neg['team_name']}!";
                } else {
                    $emails = $pdo->query("SELECT email FROM users WHERE (is_fa_committee = TRUE OR is_commish = TRUE OR is_admin = TRUE) AND email <> ''")->fetchAll(PDO::FETCH_COLUMN);
                    if (!empty($faCommEmail)) $emails[] = $faCommEmail;
                    $prevLine = ($neg['fa_status'] === 'RFA')
                            ? "Previous Contract: $" . number_format((int)$neg['prev_salary']) . " ({$neg['prev_years']} yr)\n"
                            : "";
                    sendLeagueEmail(
                            array_unique($emails),
                            "HFNHL GM Counter-Offer on {$pName} (Negotiation #{$negId})",
                            "{$neg['team_name']} has countered back for {$pName}:\n" .
                            "New Offer: {$years} yr(s) @ {$fmtSal} AAV\n" .
                            $prevLine .
                            ($reason ? "GM Note: {$reason}\n\n" : "\n") .
                            "Log in to the HFNHL Contract Center to review."
                    );
                    $msg = "Your counter-offer for {$pName} has been sent back to the Free Agent Committee!";
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', array('accept_offer', 'decline_offer', 'withdraw_offer'))) {
    $negId = (int)($_POST['neg_id'] ?? 0);
    $act   = $_POST['action'];

    $nStmt = $pdo->prepare("
        SELECT cn.*, p.first_name, p.last_name, t.name AS team_name
        FROM contract_negotiations cn
        JOIN players p ON cn.player_id = p.player_id
        JOIN teams t ON cn.team_id = t.team_id
        WHERE cn.neg_id = ?
    ");
    $nStmt->execute(array($negId));
    $neg = $nStmt->fetch();

    if ($neg) {
        $isMyNeg       = ((int)$neg['team_id'] === $myTeamId);
        $canCommAct    = ($neg['status'] === 'PENDING_COMMITTEE' && $isFaCommittee && !$isMyNeg);
        $canGmAccept   = ($neg['status'] === 'PENDING_GM' && $isMyNeg);
        $canGmWithdraw = (in_array($neg['status'], array('PENDING_COMMITTEE', 'PENDING_GM')) && $isMyNeg);

        if ($act === 'accept_offer' && ($canCommAct || $canGmAccept)) {
            $pdo->beginTransaction();
            $pdo->prepare("
                UPDATE players
                SET team_id = ?, contract_years = ?, salary = ?
                WHERE player_id = ?
            ")->execute(array((int)$neg['team_id'], (int)$neg['proposed_years'], (int)$neg['proposed_salary'], (int)$neg['player_id']));

            $pdo->prepare("UPDATE contract_negotiations SET status = 'AGREED', updated_at = NOW() WHERE neg_id = ?")
                    ->execute(array($negId));
            $pdo->commit();

            $pName  = "{$neg['first_name']} {$neg['last_name']}";
            $fmtSal = '$' . number_format((int)$neg['proposed_salary']);
            $msg    = "Agreement reached! {$pName} signed with {$neg['team_name']} for {$neg['proposed_years']} yr(s) @ {$fmtSal} AAV.";
        } elseif ($act === 'decline_offer' && $canCommAct) {
            $pdo->prepare("UPDATE contract_negotiations SET status = 'DECLINED', updated_at = NOW() WHERE neg_id = ?")
                    ->execute(array($negId));
            $msg = "Offer #{$negId} declined by Free Agent Committee.";
        } elseif ($act === 'withdraw_offer' && $canGmWithdraw) {
            $pdo->prepare("UPDATE contract_negotiations SET status = 'WITHDRAWN', updated_at = NOW() WHERE neg_id = ?")
                    ->execute(array($negId));
            $msg = "Offer #{$negId} withdrawn.";
        }
    }
}

$pendingFas = $pdo->query("
    SELECT p.player_id, p.first_name, p.last_name, p.position, p.overall_rating, p.age,
           p.nhl_games_played, p.salary, COALESCE(p.contract_years, 0) AS contract_years, p.team_id,
           {$nhlFaCaseSql} AS fa_type,
           t.name AS team_name
    FROM players p
    LEFT JOIN teams t ON p.team_id = t.team_id
    WHERE COALESCE(p.contract_years, 0) <= 1
      AND COALESCE(p.is_retired, FALSE) = FALSE
    ORDER BY
        CASE WHEN ({$nhlFaCaseSql}) = 'RFA' THEN 1 ELSE 2 END ASC,
        p.overall_rating DESC,
        p.last_name ASC
")->fetchAll(PDO::FETCH_ASSOC);


$myRfas  = array();
$myUfas  = array();
$openFas = array();

foreach ($pendingFas as $p) {
    $tid = (int)($p['team_id'] ?? 0);
    if ($tid === $myTeamId && $myTeamId > 0) {
        if ($p['fa_type'] === 'RFA') {
            $myRfas[] = $p;
        } else {
            $myUfas[] = $p;
        }
    } elseif ($tid === 0) {
        $openFas[] = $p;
    }
}

$negStmt = $pdo->prepare("
    SELECT cn.*, p.first_name, p.last_name, p.position, p.overall_rating, p.salary AS prev_salary, p.contract_years AS prev_years, t.name AS team_name
    FROM contract_negotiations cn
    JOIN players p ON cn.player_id = p.player_id
    JOIN teams t ON cn.team_id = t.team_id
    WHERE cn.status = 'AGREED'
       OR :is_fc = TRUE
       OR cn.team_id = :my_team
    ORDER BY
        CASE WHEN cn.status IN ('PENDING_COMMITTEE', 'PENDING_GM') THEN 1 ELSE 2 END ASC,
        cn.updated_at DESC
    LIMIT 60
");
$negStmt->bindValue(':is_fc', $isFaCommittee, PDO::PARAM_BOOL);
$negStmt->bindValue(':my_team', $myTeamId, PDO::PARAM_INT);
$negStmt->execute();
$negotiations = $negStmt->fetchAll(PDO::FETCH_ASSOC);

$historyMap = array();
if (!empty($negotiations)) {
    $negIds = array_map(function ($n) { return (int)$n['neg_id']; }, $negotiations);
    $inList = implode(',', $negIds);
    foreach ($pdo->query("SELECT * FROM contract_offer_history WHERE neg_id IN ({$inList}) ORDER BY created_at ASC, history_id ASC") as $h) {
        $historyMap[(int)$h['neg_id']][] = $h;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>HFNHL Contract &amp; Free Agency Center</title>
    <style>
        body { font-family: Arial, sans-serif; background: #0f172a; color: #f8fafc; padding: 20px; max-width: 1300px; margin: auto; }
        nav { background: #020617; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; display: flex; gap: 20px; border: 1px solid #334155; align-items: center; }
        nav a { color: #38bdf8; text-decoration: none; font-weight: bold; }
        h1, h2, h3 { color: #38bdf8; }
        .card { background: #1e293b; padding: 20px; border-radius: 8px; border: 1px solid #334155; margin-bottom: 24px; }
        select, input[type="number"], input[type="text"], textarea { width: 100%; padding: 7px 8px; border-radius: 5px; border: 1px solid #475569; background: #0f172a; color: #fff; box-sizing: border-box; font-size: 13px; }
        table { width: 100%; border-collapse: collapse; background: #1e293b; margin-top: 10px; }
        th, td { padding: 9px 10px; text-align: left; border-bottom: 1px solid #334155; vertical-align: top; }
        th { background: #020617; color: #38bdf8; font-size: 13px; }
        .btn { background: #0284c7; color: #fff; border: none; padding: 9px 16px; border-radius: 6px; font-weight: bold; cursor: pointer; font-size: 13px; }
        .btn:hover { background: #0369a1; }
        .btn-green { background: #16a34a; }
        .btn-red { background: #dc2626; }
        .btn-amber { background: #d97706; }
        .tab-bar { display: flex; gap: 10px; margin-bottom: 16px; flex-wrap: wrap; }
        .tab-btn { background: #0f172a; color: #cbd5e1; border: 1px solid #475569; padding: 10px 18px; border-radius: 6px; font-weight: bold; cursor: pointer; font-size: 14px; }
        .tab-btn.active { background: #0284c7; color: #fff; border-color: #38bdf8; }
        .tab-panel { display: none; }
        .tab-panel.active { display: block; }
        .badge-rfa { background: #1d4ed8; color: #dbeafe; padding: 3px 8px; border-radius: 4px; font-weight: bold; font-size: 12px; }
        .badge-ufa { background: #7e22ce; color: #f3e8ff; padding: 3px 8px; border-radius: 4px; font-weight: bold; font-size: 12px; }
        .alert { background: #14532d; color: #86efac; padding: 12px; border-radius: 6px; border: 1px solid #22c55e; margin-bottom: 16px; font-weight: bold; }
        .alert-err { background: #7f1d1d; color: #fecaca; padding: 12px; border-radius: 6px; border: 1px solid #ef4444; margin-bottom: 16px; font-weight: bold; }
        .history-box { background: #0f172a; padding: 8px; border-radius: 5px; border: 1px solid #334155; font-size: 12px; margin-top: 4px; }
        .counter-box { background: #0f172a; padding: 10px; border-radius: 6px; border: 1px solid #475569; margin-top: 8px; }
        .scroll-table { max-height: 480px; overflow-y: auto; border: 1px solid #334155; border-radius: 6px; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>

<h1>HFNHL Contract &amp; Free Agency Center</h1>
<p style="color:#94a3b8; margin-top:0;">
    Active CBA Season: <strong><?= ACTIVE_SEASON_LABEL ?></strong> (Next Rollover: <strong><?= NEXT_SEASON_ROLLOVER ?></strong>) |
    Max Term: <strong><?= MAX_CONTRACT_YEARS ?> Yrs</strong> |
    Min Salary: <strong>$<?= number_format(NHL_MIN_SALARY) ?></strong> |
    Max Salary: <strong>$<?= number_format(NHL_MAX_SALARY) ?></strong>
</p>

<?php if ($msg): ?><div class="alert"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<?php if ($myTeamId > 0): ?>
<div class="card">
    <h2 style="margin-top:0;">Bulk Contract Offer Builder (Grouped &amp; Sent by Type)</h2>
    <p style="color:#94a3b8; margin-top:0;">
        Select a player category below, enter salary and term on every player you want to offer in that group, and submit all offers in one batch to the Free Agent Committee.
        <strong>RFA emails automatically include each player's previous contract.</strong>
    </p>

    <div class="tab-bar">
        <button type="button" class="tab-btn active" onclick="switchOfferTab('tab-rfa', this)">
            1. Your Pending RFAs (<?= count($myRfas) ?>)
        </button>
        <button type="button" class="tab-btn" onclick="switchOfferTab('tab-team-ufa', this)">
            2. Your Pending UFAs (<?= count($myUfas) ?>)
        </button>
        <button type="button" class="tab-btn" onclick="switchOfferTab('tab-open-fa', this)">
            3. Unrestricted Free Agents (<?= count($openFas) ?>)
        </button>
    </div>

    <div id="tab-rfa" class="tab-panel active">
        <form method="post">
            <input type="hidden" name="action" value="bulk_offer">
            <input type="hidden" name="offer_category" value="RFA">
            <div class="scroll-table">
                <table style="margin-top:0;">
                    <tr>
                        <th>Player</th>
                        <th>Pos / OVR</th>
                        <th>Age / NHL GP</th>
                        <th>Previous Contract (Sent to Committee)</th>
                        <th style="width:115px;">Offer Years</th>
                        <th style="width:165px;">Offer Salary ($)</th>
                        <th>GM Notes / Pitch</th>
                    </tr>
                    <?php if (empty($myRfas)): ?>
                        <tr><td colspan="7" style="color:#94a3b8;">You have no pending RFAs with 1 year or less remaining.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($myRfas as $p): $pid = (int)$p['player_id']; ?>
                        <tr>
                            <td>
                                <span class="badge-rfa">RFA</span>
                                <strong><?= htmlspecialchars("{$p['first_name']} {$p['last_name']}") ?></strong>
                            </td>
                            <td><?= htmlspecialchars($p['position']) ?> / <strong><?= (int)$p['overall_rating'] ?></strong></td>
                            <td><?= $p['age'] ? (int)$p['age'] . ' yrs' : 'N/A' ?> / <?= (int)$p['nhl_games_played'] ?> GP</td>
                            <td style="color:#fde047;">
                                <strong>$<?= number_format((int)$p['salary']) ?></strong> / yr
                                (<?= (int)$p['contract_years'] ?> yr left)
                            </td>
                            <td>
                                <select name="offers[<?= $pid ?>][years]">
                                    <?php for ($y = 1; $y <= MAX_CONTRACT_YEARS; $y++): ?>
                                        <option value="<?= $y ?>"><?= $y ?> Yr<?= $y > 1 ? 's' : '' ?></option>
                                    <?php endfor; ?>
                                </select>
                            </td>
                            <td>
                                <input type="number" name="offers[<?= $pid ?>][salary]"
                                       min="<?= NHL_MIN_SALARY ?>" max="<?= NHL_MAX_SALARY ?>" step="25000"
                                       placeholder="Leave blank to skip">
                            </td>
                            <td>
                                <input type="text" name="offers[<?= $pid ?>][reason]" placeholder="Optional pitch/comparables...">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php if (!empty($myRfas)): ?>
                <button type="submit" class="btn" style="margin-top:14px;">Submit All RFA Offers to FA Committee</button>
            <?php endif; ?>
        </form>
    </div>

    <div id="tab-team-ufa" class="tab-panel">
        <form method="post">
            <input type="hidden" name="action" value="bulk_offer">
            <input type="hidden" name="offer_category" value="TEAM_UFA">
            <div class="scroll-table">
                <table style="margin-top:0;">
                    <tr>
                        <th>Player</th>
                        <th>Pos / OVR</th>
                        <th>Age / NHL GP</th>
                        <th>Current Contract</th>
                        <th style="width:115px;">Offer Years</th>
                        <th style="width:165px;">Offer Salary ($)</th>
                        <th>GM Notes / Pitch</th>
                    </tr>
                    <?php if (empty($myUfas)): ?>
                        <tr><td colspan="7" style="color:#94a3b8;">You have no pending UFAs with 1 year or less remaining.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($myUfas as $p): $pid = (int)$p['player_id']; ?>
                    <tr>
                        <td>
                            <span class="badge-ufa">UFA</span>
                            <strong><?= htmlspecialchars("{$p['first_name']} {$p['last_name']}") ?></strong>
                        </td>
                        <td><?= htmlspecialchars($p['position']) ?> / <strong><?= (int)$p['overall_rating'] ?></strong></td>
                        <td><?= $p['age'] ? (int)$p['age'] . ' yrs' : 'N/A' ?> / <?= (int)$p['nhl_games_played'] ?> GP</td>
                        <td>$<?= number_format((int)$p['salary']) ?> (<?= (int)$p['contract_years'] ?> yr left)</td>
                        <td>
                        <td>
                            <input type="number" name="offers[<?= $pid ?>][salary]"
                                   min="<?= NHL_MIN_SALARY ?>" max="<?= NHL_MAX_SALARY ?>" step="25000"
                                   placeholder="Leave blank to skip">
                        </td>
                        <td>
                            <input type="text" name="offers[<?= $pid ?>][reason]" placeholder="Optional pitch/comparables...">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php if (!empty($myUfas)): ?>
                <button type="submit" class="btn" style="margin-top:14px;">Submit All Pending UFA Offers to FA Committee</button>
            <?php endif; ?>
        </form>
    </div>

    <div id="tab-open-fa" class="tab-panel">
        <form method="post">
            <input type="hidden" name="action" value="bulk_offer">
            <input type="hidden" name="offer_category" value="OPEN_FA">
            <div style="margin-bottom:10px; max-width:340px;">
                <input type="text" id="openFaSearch" onkeyup="filterOpenFaTable()" placeholder="Search unrestricted free agents by name or pos...">
            </div>
            <div class="scroll-table">
                <table id="openFaTable" style="margin-top:0;">
                    <tr>
                        <th>Player</th>
                        <th>Pos / OVR</th>
                        <th>Age / NHL GP</th>
                        <th>Prior Salary</th>
                        <th style="width:115px;">Offer Years</th>
                        <th style="width:165px;">Offer Salary ($)</th>
                        <th>GM Notes / Pitch</th>
                    </tr>
                    <?php if (empty($openFas)): ?>
                        <tr><td colspan="7" style="color:#94a3b8;">There are currently no unsigned free agents available.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($openFas as $p): $pid = (int)$p['player_id']; ?>
                        <tr class="open-fa-row">
                            <td>
                                <span class="badge-ufa"><?= htmlspecialchars($p['fa_type']) ?></span>
                                <strong><?= htmlspecialchars("{$p['first_name']} {$p['last_name']}") ?></strong>
                            </td>
                            <td><?= htmlspecialchars($p['position']) ?> / <strong><?= (int)$p['overall_rating'] ?></strong></td>
                            <td><?= $p['age'] ? (int)$p['age'] . ' yrs' : 'N/A' ?> / <?= (int)$p['nhl_games_played'] ?> GP</td>
                            <td>$<?= number_format((int)$p['salary']) ?></td>
                            <td>
                                <select name="offers[<?= $pid ?>][years]">
                                    <?php for ($y = 1; $y <= MAX_CONTRACT_YEARS; $y++): ?>
                                        <option value="<?= $y ?>"><?= $y ?> Yr<?= $y > 1 ? 's' : '' ?></option>
                                    <?php endfor; ?>
                                </select>
                            </td>
                            <td>
                                <input type="number" name="offers[<?= $pid ?>][salary]"
                                       min="<?= NHL_MIN_SALARY ?>" max="<?= NHL_MAX_SALARY ?>" step="25000"
                                       placeholder="Leave blank to skip">
                            </td>
                            <td>
                                <input type="text" name="offers[<?= $pid ?>][reason]" placeholder="Optional pitch/comparables...">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php if (!empty($openFas)): ?>
                <button type="submit" class="btn" style="margin-top:14px;">Submit All Free Agent Offers to FA Committee</button>
            <?php endif; ?>
        </form>
    </div>
</div>
<?php endif; ?>

<h2>Active Negotiations &amp; Signed Contracts</h2>
<table>
    <tr>
        <th>ID &amp; Status</th>
        <th>Player</th>
        <th>Team</th>
        <th>Current Offer</th>
        <th>Negotiation History &amp; Reasoning</th>
        <th>Actions &amp; Counter-Offer</th>
    </tr>
    <?php foreach ($negotiations as $n):
        $nid           = (int)$n['neg_id'];
        $isMyNeg       = ((int)$n['team_id'] === $myTeamId);
        $canCommAct    = ($n['status'] === 'PENDING_COMMITTEE' && $isFaCommittee && !$isMyNeg);
        $canGmAct      = ($n['status'] === 'PENDING_GM' && $isMyNeg);
        $canGmWithdraw = (in_array($n['status'], array('PENDING_COMMITTEE', 'PENDING_GM')) && $isMyNeg);
        ?>
        <tr>
            <td>
                <strong>#<?= $nid ?></strong><br>
                <span class="<?= $n['fa_status'] === 'RFA' ? 'badge-rfa' : 'badge-ufa' ?>"><?= htmlspecialchars($n['fa_status']) ?></span><br><br>
                <strong><?= htmlspecialchars($n['status']) ?></strong>
            </td>
            <td>
                <strong><?= htmlspecialchars("{$n['first_name']} {$n['last_name']}") ?></strong><br>
                <small style="color:#94a3b8;"><?= htmlspecialchars("{$n['position']} - {$n['overall_rating']} OVR") ?></small>
                <?php if ($n['fa_status'] === 'RFA'): ?>
                    <br><small style="color:#fde047;">Prev: $<?= number_format((int)$n['prev_salary']) ?> (<?= (int)$n['prev_years'] ?> yr)</small>
                <?php endif; ?>
            </td>
            <td><strong><?= htmlspecialchars($n['team_name']) ?></strong></td>
            <td>
                <strong><?= (int)$n['proposed_years'] ?> yr(s)</strong> @<br>
                <strong style="color:#4ade80;">$<?= number_format((int)$n['proposed_salary']) ?></strong> / yr
            </td>
            <td>
                <div class="history-box">
                    <?php foreach ($historyMap[$nid] ?? array() as $h): ?>
                        <div style="margin-bottom:6px; border-bottom:1px solid #1e293b; padding-bottom:4px;">
                            <strong><?= $h['actor_role'] === 'COMMITTEE' ? 'FA Committee' : 'Team GM' ?>:</strong>
                            <?= (int)$h['offered_years'] ?> yr(s) @ $<?= number_format((int)$h['offered_salary']) ?>
                            <?php if (!empty($h['reason'])): ?>
                                <br><em style="color:#cbd5e1;">"<?= htmlspecialchars($h['reason']) ?>"</em>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </td>
            <td style="min-width:270px;">
                <?php if ($canCommAct || $canGmAct): ?>
                    <form method="post" style="margin-bottom:8px;">
                        <input type="hidden" name="neg_id" value="<?= $nid ?>">
                        <button class="btn btn-green" name="action" value="accept_offer">Accept &amp; Sign</button>
                        <?php if ($canCommAct): ?>
                            <button class="btn btn-red" name="action" value="decline_offer">Decline</button>
                        <?php endif; ?>
                        <?php if ($canGmWithdraw): ?>
                            <button class="btn btn-red" name="action" value="withdraw_offer">Withdraw</button>
                        <?php endif; ?>
                    </form>

                    <details class="counter-box">
                        <summary style="cursor:pointer; color:#fbbf24; font-weight:bold;">Send Counter-Offer</summary>
                        <form method="post" style="margin-top:8px;">
                            <input type="hidden" name="action" value="counter_offer">
                            <input type="hidden" name="neg_id" value="<?= $nid ?>">
                            <label>Years (1–<?= MAX_CONTRACT_YEARS ?>):</label>
                            <select name="years" required style="margin-bottom:6px;">
                                <?php for ($y = 1; $y <= MAX_CONTRACT_YEARS; $y++): ?>
                                    <option value="<?= $y ?>" <?= $y === (int)$n['proposed_years'] ? 'selected' : '' ?>><?= $y ?> Year<?= $y > 1 ? 's' : '' ?></option>
                                <?php endfor; ?>
                            </select>
                            <label>Salary ($<?= number_format(NHL_MIN_SALARY) ?> – $<?= number_format(NHL_MAX_SALARY) ?>):</label>
                            <input type="number" name="salary" min="<?= NHL_MIN_SALARY ?>" max="<?= NHL_MAX_SALARY ?>" step="25000" value="<?= (int)$n['proposed_salary'] ?>" required style="margin-bottom:6px;">
                            <label><?= $canCommAct ? 'Reason Why Player Is Worth This (Required):' : 'GM Counter Reasoning:' ?></label>
                            <textarea name="reason" rows="2" <?= $canCommAct ? 'required' : '' ?> placeholder="Explain valuation, stats, or comparables..."></textarea>
                            <button type="submit" class="btn btn-amber" style="margin-top:6px; width:100%;">Submit Counter</button>
                        </form>
                    </details>
                <?php elseif ($canGmWithdraw): ?>
                    <form method="post">
                        <input type="hidden" name="neg_id" value="<?= $nid ?>">
                        <button class="btn btn-red" name="action" value="withdraw_offer">Withdraw Offer</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
</table>

<script>
    function switchOfferTab(panelId, btn) {
        document.querySelectorAll('.tab-panel').forEach(function(p) { p.classList.remove('active'); });
        document.querySelectorAll('.tab-btn').forEach(function(b) { b.classList.remove('active'); });
        document.getElementById(panelId).classList.add('active');
        btn.classList.add('active');
    }

    function filterOpenFaTable() {
        var q = document.getElementById('openFaSearch').value.toLowerCase();
        document.querySelectorAll('#openFaTable .open-fa-row').forEach(function(row) {
            row.style.display = row.innerText.toLowerCase().includes(q) ? '' : 'none';
        });
    }
</script>
</body>
</html>







