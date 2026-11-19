<?php
require 'db.php';
require 'mailer.php';
require_once 'cap_helper.php';
/** @var PDO $pdo */

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$myTeamId         = (int)($_SESSION['team_id'] ?? 0);
$isAdmin          = !empty($_SESSION['is_admin']);
$isCommish        = !empty($_SESSION['is_commish']);
$isTradeCommittee = !empty($_SESSION['is_trade_committee']) || $isCommish || $isAdmin;
$partnerId        = isset($_GET['partner']) ? (int)$_GET['partner'] : 0;
$counterId        = isset($_GET['counter']) ? (int)$_GET['counter'] : 0;
$msg              = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'propose') {
    $recTeamId        = (int)($_POST['receiving_team_id'] ?? 0);
    $counterTradeId   = (int)($_POST['counter_trade_id'] ?? 0);
    $offerIds         = array_map('intval', $_POST['offer_pids'] ?? array());
    $reqIds           = array_map('intval', $_POST['req_pids'] ?? array());
    $offerPickIds     = array_map('intval', $_POST['offer_pick_ids'] ?? array());
    $reqPickIds       = array_map('intval', $_POST['req_pick_ids'] ?? array());
    $offerProspectIds = array_map('intval', $_POST['offer_prospect_ids'] ?? array());
    $reqProspectIds   = array_map('intval', $_POST['req_prospect_ids'] ?? array());
    $notes            = trim($_POST['trade_notes'] ?? '');

    $rawOfferRet = $_POST['offer_ret'] ?? array();
    $rawReqRet   = $_POST['req_ret'] ?? array();
    $offerRetMap = array();
    $reqRetMap   = array();
    $retError    = '';

    $chkSalStmt = $pdo->prepare("SELECT salary, UPPER(COALESCE(roster_status, 'PRO')) AS st FROM players WHERE player_id = ?");

    foreach ($offerIds as $pid) {
        $pct = isset($rawOfferRet[$pid]) ? (float)$rawOfferRet[$pid] : 0;
        if ($pct > 0) {
            $chkSalStmt->execute(array($pid));
            $pRow = $chkSalStmt->fetch(PDO::FETCH_ASSOC);
            $sal  = (int)($pRow['salary'] ?? 0);
            if ($sal <= 0) {
                $retError = "Cannot retain salary on a $0 contract.";
                break;
            }
            $retAmt = (int)round($sal * ($pct / 100));
            $err = validateSalaryRetention($pdo, $myTeamId, $pid, $pct, $retAmt);
            if ($err !== '') { $retError = $err; break; }
            $offerRetMap[$pid] = $pct;
        }
    }

    if ($retError === '') {
        foreach ($reqIds as $pid) {
            $pct = isset($rawReqRet[$pid]) ? (float)$rawReqRet[$pid] : 0;
            if ($pct > 0) {
                $chkSalStmt->execute(array($pid));
                $pRow = $chkSalStmt->fetch(PDO::FETCH_ASSOC);
                $sal  = (int)($pRow['salary'] ?? 0);
                if ($sal <= 0) {
                    $retError = "Cannot retain salary on a $0 contract.";
                    break;
                }
                $retAmt = (int)round($sal * ($pct / 100));
                $err = validateSalaryRetention($pdo, $recTeamId, $pid, $pct, $retAmt);
                if ($err !== '') { $retError = $err; break; }
                $reqRetMap[$pid] = $pct;
            }
        }
    }

    $hasOffer = !empty($offerIds) || !empty($offerPickIds) || !empty($offerProspectIds);
    $hasReq   = !empty($reqIds) || !empty($reqPickIds) || !empty($reqProspectIds);

    if ($retError !== '') {
        $msg = "Retention Error: " . $retError;
    } elseif ($recTeamId > 0 && $recTeamId !== $myTeamId && ($hasOffer || $hasReq)) {
        $pdo->beginTransaction();

        if ($counterTradeId > 0) {
            $pdo->prepare("
                UPDATE trades SET status = 'COUNTERED', updated_at = NOW()
                WHERE trade_id = ? AND receiving_team_id = ? AND status = 'PENDING_GM'
            ")->execute(array($counterTradeId, $myTeamId));
        }

        $pgOffer          = '{' . implode(',', $offerIds) . '}';
        $pgReq            = '{' . implode(',', $reqIds) . '}';
        $pgOfferPicks     = '{' . implode(',', $offerPickIds) . '}';
        $pgReqPicks       = '{' . implode(',', $reqPickIds) . '}';
        $pgOfferProspects = '{' . implode(',', $offerProspectIds) . '}';
        $pgReqProspects   = '{' . implode(',', $reqProspectIds) . '}';

        $stmt = $pdo->prepare("
            INSERT INTO trades (
                proposing_team_id, receiving_team_id,
                offered_player_ids, requested_player_ids,
                offered_pick_ids, requested_pick_ids,
                offered_prospect_ids, requested_prospect_ids,
                offered_retentions, requested_retentions,
                trade_notes, status, parent_trade_id
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING_GM', ?)
        ");
        $stmt->execute(array(
            $myTeamId, $recTeamId,
            $pgOffer, $pgReq,
            $pgOfferPicks, $pgReqPicks,
            $pgOfferProspects, $pgReqProspects,
            json_encode((object)$offerRetMap),
            json_encode((object)$reqRetMap),
            $notes, $counterTradeId > 0 ? $counterTradeId : null
        ));

        $pdo->commit();

        $fromName  = $pdo->query("SELECT name FROM teams WHERE team_id = {$myTeamId}")->fetchColumn();
        $recEmails = $pdo->query("SELECT email FROM users WHERE team_id = {$recTeamId} AND email <> ''")->fetchAll(PDO::FETCH_COLUMN);
        $subject   = $counterTradeId > 0
            ? "HFNHL Counter-Offer from {$fromName} (Re: Trade #{$counterTradeId})"
            : "New HFNHL Trade Offer from {$fromName}";

        sendLeagueEmail($recEmails, $subject, "{$fromName} has sent you a trade proposal!\n\nLog in to the HFNHL Trade Center to review and respond.");
        $msg = $counterTradeId > 0 ? "Counter-offer sent!" : "Trade offer sent!";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', array('accept_gm', 'decline_gm', 'cancel_gm'))) {
    $tid = (int)$_POST['trade_id'];
    $act = $_POST['action'];

    if ($act === 'accept_gm') {
        $pdo->prepare("UPDATE trades SET status = 'PENDING_COMMISH', updated_at = NOW() WHERE trade_id = ? AND receiving_team_id = ? AND status = 'PENDING_GM'")
            ->execute(array($tid, $myTeamId));

        $tcEmails   = $pdo->query("SELECT email FROM users WHERE (is_trade_committee = TRUE OR is_commish = TRUE OR is_admin = TRUE) AND email <> ''")->fetchAll(PDO::FETCH_COLUMN);
        $tcEmails[] = 'yourleaguetrade@email.com';

        sendLeagueEmail(
            $tcEmails,
            "HFNHL Trade #{$tid} Accepted — Trade Committee Review Required",
            "Trade #{$tid} has been officially accepted by both GMs and is awaiting Trade Committee approval.\n\nLog in to the HFNHL Trade Center to review and click 'Approve & Execute'."
        );
        $msg = "Trade accepted! Sent to the Trade Committee for final league approval.";
    } elseif ($act === 'decline_gm') {
        $pdo->prepare("UPDATE trades SET status = 'DECLINED', updated_at = NOW() WHERE trade_id = ? AND receiving_team_id = ? AND status = 'PENDING_GM'")
            ->execute(array($tid, $myTeamId));
        $msg = "Trade declined.";
    } elseif ($act === 'cancel_gm') {
        $pdo->prepare("UPDATE trades SET status = 'CANCELLED', updated_at = NOW() WHERE trade_id = ? AND proposing_team_id = ? AND status = 'PENDING_GM'")
            ->execute(array($tid, $myTeamId));
        $msg = "Trade offer withdrawn.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isTradeCommittee && ($_POST['action'] ?? '') === 'approve_commish') {
    $tid = (int)$_POST['trade_id'];
    $chk = $pdo->prepare("
        SELECT * FROM trades 
        WHERE trade_id = ? 
          AND status = 'PENDING_COMMISH' 
          AND proposing_team_id <> ? 
          AND receiving_team_id <> ?
    ");
    $chk->execute(array($tid, $myTeamId, $myTeamId));
    $tRow = $chk->fetch();

    if ($tRow) {
        $pdo->beginTransaction();
        try {
            $propId       = (int)$tRow['proposing_team_id'];
            $recId        = (int)$tRow['receiving_team_id'];
            $offArr       = array_filter(explode(',', trim($tRow['offered_player_ids'], '{}')));
            $reqArr       = array_filter(explode(',', trim($tRow['requested_player_ids'], '{}')));
            $offPickArr   = array_filter(explode(',', trim($tRow['offered_pick_ids'] ?? '{}', '{}')));
            $reqPickArr   = array_filter(explode(',', trim($tRow['requested_pick_ids'] ?? '{}', '{}')));
            $offProspArr  = array_filter(explode(',', trim($tRow['offered_prospect_ids'] ?? '{}', '{}')));
            $reqProspArr  = array_filter(explode(',', trim($tRow['requested_prospect_ids'] ?? '{}', '{}')));
            $offRetMap    = json_decode($tRow['offered_retentions'] ?? '{}', true) ?: array();
            $reqRetMap    = json_decode($tRow['requested_retentions'] ?? '{}', true) ?: array();

            $moveCmd      = $pdo->prepare("UPDATE players SET team_id = ?, line_number = 99 WHERE player_id = ?");
            $delBlock     = $pdo->prepare("DELETE FROM trade_block WHERE player_id = ?");
            $movePickCmd  = $pdo->prepare("UPDATE draft_picks SET current_team_id = ? WHERE pick_id = ?");
            $moveProspCmd = $pdo->prepare("UPDATE prospects SET team_id = ? WHERE prospect_id = ?");
            $getPlayer    = $pdo->prepare("SELECT salary, COALESCE(contract_years, 1) AS yrs FROM players WHERE player_id = ?");
            $updSalary    = $pdo->prepare("UPDATE players SET salary = ? WHERE player_id = ?");
            $insRet       = $pdo->prepare("
                INSERT INTO retained_salaries (player_id, retaining_team_id, retained_percent, retained_cap_hit, years_remaining)
                VALUES (?, ?, ?, ?, ?)
            ");

            foreach ($offArr as $pidRaw) {
                $pid = (int)$pidRaw;
                $pct = isset($offRetMap[$pid]) ? (float)$offRetMap[$pid] : 0;
                if ($pct > 0) {
                    $getPlayer->execute(array($pid));
                    $pInfo  = $getPlayer->fetch(PDO::FETCH_ASSOC);
                    $retAmt = (int)round($pInfo['salary'] * ($pct / 100));
                    $err    = validateSalaryRetention($pdo, $propId, $pid, $pct, $retAmt);
                    if ($err !== '') throw new Exception($err);
                    $insRet->execute(array($pid, $propId, $pct, $retAmt, max(1, (int)$pInfo['yrs'])));
                    $updSalary->execute(array($pInfo['salary'] - $retAmt, $pid));
                }
                $moveCmd->execute(array($recId, $pid));
                $delBlock->execute(array($pid));
            }

            foreach ($reqArr as $pidRaw) {
                $pid = (int)$pidRaw;
                $pct = isset($reqRetMap[$pid]) ? (float)$reqRetMap[$pid] : 0;
                if ($pct > 0) {
                    $getPlayer->execute(array($pid));
                    $pInfo  = $getPlayer->fetch(PDO::FETCH_ASSOC);
                    $retAmt = (int)round($pInfo['salary'] * ($pct / 100));
                    $err    = validateSalaryRetention($pdo, $recId, $pid, $pct, $retAmt);
                    if ($err !== '') throw new Exception($err);
                    $insRet->execute(array($pid, $recId, $pct, $retAmt, max(1, (int)$pInfo['yrs'])));
                    $updSalary->execute(array($pInfo['salary'] - $retAmt, $pid));
                }
                $moveCmd->execute(array($propId, $pid));
                $delBlock->execute(array($pid));
            }

            foreach ($offPickArr as $pickId) {
                $movePickCmd->execute(array($recId, (int)$pickId));
            }
            foreach ($reqPickArr as $pickId) {
                $movePickCmd->execute(array($propId, (int)$pickId));
            }
            foreach ($offProspArr as $prId) {
                $moveProspCmd->execute(array($recId, (int)$prId));
            }
            foreach ($reqProspArr as $prId) {
                $moveProspCmd->execute(array($propId, (int)$prId));
            }

            $pdo->prepare("UPDATE trades SET status = 'APPROVED', updated_at = NOW() WHERE trade_id = ?")->execute(array($tid));
            $pdo->commit();
            $msg = "Trade #{$tid} approved and rosters/prospects/picks/retentions updated automatically!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $msg = "Cannot approve trade: " . $e->getMessage();
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $isTradeCommittee && ($_POST['action'] ?? '') === 'veto_commish') {
    $tid = (int)$_POST['trade_id'];
    $pdo->prepare("
        UPDATE trades SET status = 'VETOED', updated_at = NOW() 
        WHERE trade_id = ? 
          AND status = 'PENDING_COMMISH'
          AND proposing_team_id <> ? 
          AND receiving_team_id <> ?
    ")->execute(array($tid, $myTeamId, $myTeamId));
    $msg = "Trade #{$tid} declined by Trade Committee.";
}

$playerMap = array();
foreach ($pdo->query("SELECT player_id, first_name, last_name, position, overall_rating, salary, UPPER(COALESCE(roster_status, 'PRO')) AS st FROM players") as $p) {
    $sal  = (int)$p['salary'];
    $salM = number_format($sal / 1000000, 2);
    $st   = $p['st'];
    if (in_array($st, array('RIGHTS', 'EURO', 'EUROPE', 'LTIR', 'INJURED', 'INACTIVE'))) {
        $playerMap[$p['player_id']] = "{$p['first_name']} {$p['last_name']} ({$p['position']} {$p['overall_rating']} - [{$st} Rights] \${$salM}M)";
    } else {
        $playerMap[$p['player_id']] = "{$p['first_name']} {$p['last_name']} ({$p['position']} {$p['overall_rating']} - \${$salM}M)";
    }
}

$prospectMap = array();
foreach ($pdo->query("SELECT prospect_id, first_name, last_name, position, draft_year FROM prospects") as $pr) {
    $yrStr  = !empty($pr['draft_year']) ? " {$pr['draft_year']}" : "";
    $posStr = !empty($pr['position']) ? " {$pr['position']}" : "";
    $prospectMap[$pr['prospect_id']] = trim("{$pr['first_name']} {$pr['last_name']}") . " ({$posStr}{$yrStr})";
}

$pickMap = array();
$pickSql = "
    SELECT dp.pick_id, dp.draft_year, dp.round_num, dp.conditions, t.name AS orig_team
    FROM draft_picks dp
    JOIN teams t ON dp.original_team_id = t.team_id
";
foreach ($pdo->query($pickSql) as $pk) {
    $cond = !empty($pk['conditions']) ? " [{$pk['conditions']}]" : "";
    $pickMap[$pk['pick_id']] = "{$pk['draft_year']} Rd {$pk['round_num']} ({$pk['orig_team']}){$cond}";
}

function formatAssetList($pgPlayerArr, $pgPickArr, $pgProspArr, $playerMap, $pickMap, $prospectMap, $retJson = '{}') {
    $out    = array();
    $retMap = json_decode($retJson ?? '{}', true) ?: array();

    foreach (array_filter(explode(',', trim($pgPlayerArr ?? '{}', '{}'))) as $id) {
        $pid   = (int)$id;
        $label = htmlspecialchars($playerMap[$pid] ?? "Player #{$pid}");
        if (!empty($retMap[$pid]) && (float)$retMap[$pid] > 0) {
            $pct    = rtrim(rtrim(number_format((float)$retMap[$pid], 2), '0'), '.');
            $label .= ' <span style="color:#fbbf24;font-weight:bold;">[Retained ' . $pct . '%]</span>';
        }
        $out[] = $label;
    }
    foreach (array_filter(explode(',', trim($pgProspArr ?? '{}', '{}'))) as $id) {
        $out[] = '<strong style="color:#a78bfa;">[Prospect]</strong> ' . htmlspecialchars($prospectMap[(int)$id] ?? "Prospect #{$id}");
    }
    foreach (array_filter(explode(',', trim($pgPickArr ?? '{}', '{}'))) as $id) {
        $out[] = '<strong>[Pick]</strong> ' . htmlspecialchars($pickMap[(int)$id] ?? "Pick #{$id}");
    }
    return empty($out) ? '<em>None</em>' : implode('<br>', $out);
}

$preOfferPlayers   = array();
$preReqPlayers     = array();
$preOfferPicks     = array();
$preReqPicks       = array();
$preOfferProspects = array();
$preReqProspects   = array();
$preOfferRet       = array();
$preReqRet         = array();
$preNotes          = '';

if ($counterId > 0 && $myTeamId > 0) {
    $cStmt = $pdo->prepare("SELECT * FROM trades WHERE trade_id = ? AND receiving_team_id = ? AND status = 'PENDING_GM'");
    $cStmt->execute(array($counterId, $myTeamId));
    $cTrade = $cStmt->fetch();

    if ($cTrade) {
        $partnerId         = (int)$cTrade['proposing_team_id'];
        $preOfferPlayers   = array_map('intval', array_filter(explode(',', trim($cTrade['requested_player_ids'], '{}'))));
        $preReqPlayers     = array_map('intval', array_filter(explode(',', trim($cTrade['offered_player_ids'], '{}'))));
        $preOfferPicks     = array_map('intval', array_filter(explode(',', trim($cTrade['requested_pick_ids'] ?? '{}', '{}'))));
        $preReqPicks       = array_map('intval', array_filter(explode(',', trim($cTrade['offered_pick_ids'] ?? '{}', '{}'))));
        $preOfferProspects = array_map('intval', array_filter(explode(',', trim($cTrade['requested_prospect_ids'] ?? '{}', '{}'))));
        $preReqProspects   = array_map('intval', array_filter(explode(',', trim($cTrade['offered_prospect_ids'] ?? '{}', '{}'))));
        $preOfferRet       = json_decode($cTrade['requested_retentions'] ?? '{}', true) ?: array();
        $preReqRet         = json_decode($cTrade['offered_retentions'] ?? '{}', true) ?: array();
        $preNotes          = "Counter to Trade #{$counterId}: " . $cTrade['trade_notes'];
    } else {
        $counterId = 0;
    }
}

$allTeams      = $pdo->query("SELECT team_id, name FROM teams ORDER BY name")->fetchAll();
$myRoster      = $myTeamId > 0 ? $pdo->query("SELECT player_id, first_name, last_name, position, overall_rating, salary, UPPER(COALESCE(roster_status, 'PRO')) AS st FROM players WHERE team_id = {$myTeamId} AND COALESCE(is_retired, FALSE) = FALSE ORDER BY overall_rating DESC")->fetchAll() : array();
$partnerRoster = $partnerId > 0 ? $pdo->query("SELECT player_id, first_name, last_name, position, overall_rating, salary, UPPER(COALESCE(roster_status, 'PRO')) AS st FROM players WHERE team_id = {$partnerId} AND COALESCE(is_retired, FALSE) = FALSE ORDER BY overall_rating DESC")->fetchAll() : array();

$myProspects      = $myTeamId > 0 ? $pdo->query("SELECT prospect_id, first_name, last_name, position, draft_year FROM prospects WHERE team_id = {$myTeamId} ORDER BY draft_year DESC, last_name ASC")->fetchAll() : array();
$partnerProspects = $partnerId > 0 ? $pdo->query("SELECT prospect_id, first_name, last_name, position, draft_year FROM prospects WHERE team_id = {$partnerId} ORDER BY draft_year DESC, last_name ASC")->fetchAll() : array();

$myPicks = $myTeamId > 0 ? $pdo->query("
    SELECT dp.pick_id, dp.draft_year, dp.round_num, dp.conditions, t.name AS orig_team
    FROM draft_picks dp
    JOIN teams t ON dp.original_team_id = t.team_id
    WHERE dp.current_team_id = {$myTeamId} AND dp.is_used = FALSE
    ORDER BY dp.draft_year ASC, dp.round_num ASC
")->fetchAll() : array();

$partnerPicks = $partnerId > 0 ? $pdo->query("
    SELECT dp.pick_id, dp.draft_year, dp.round_num, dp.conditions, t.name AS orig_team
    FROM draft_picks dp
    JOIN teams t ON dp.original_team_id = t.team_id
    WHERE dp.current_team_id = {$partnerId} AND dp.is_used = FALSE
    ORDER BY dp.draft_year ASC, dp.round_num ASC
")->fetchAll() : array();

$tradeStmt = $pdo->prepare("
    SELECT tr.*, t1.name AS prop_name, t2.name AS rec_name
    FROM trades tr
    JOIN teams t1 ON tr.proposing_team_id = t1.team_id
    JOIN teams t2 ON tr.receiving_team_id = t2.team_id
    WHERE tr.status NOT IN ('PENDING_GM', 'PENDING_COMMISH', 'DECLINED', 'CANCELLED', 'VETOED', 'COUNTERED')
       OR :is_tc = TRUE
       OR tr.proposing_team_id = :my_team
       OR tr.receiving_team_id = :my_team
    ORDER BY tr.created_at DESC
    LIMIT 40
");
$tradeStmt->bindValue(':is_tc', $isTradeCommittee, PDO::PARAM_BOOL);
$tradeStmt->bindValue(':my_team', $myTeamId, PDO::PARAM_INT);
$tradeStmt->execute();
$trades = $tradeStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>HFNHL Trade Center</title>
    <style>
        body { font-family: Arial, sans-serif; background: #0f172a; color: #f8fafc; padding: 20px; max-width: 1200px; margin: auto; }
        nav { background: #020617; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; display: flex; gap: 20px; border: 1px solid #334155; align-items: center; }
        nav a { color: #38bdf8; text-decoration: none; font-weight: bold; }
        h1, h2, h3, h4 { color: #38bdf8; }
        .card { background: #1e293b; padding: 20px; border-radius: 8px; border: 1px solid #334155; margin-bottom: 24px; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        select, textarea { width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #475569; background: #0f172a; color: #fff; box-sizing: border-box; }
        .roster-box { max-height: 240px; overflow-y: auto; background: #0f172a; padding: 10px; border-radius: 6px; border: 1px solid #334155; }
        .roster-row { display: flex; justify-content: space-between; align-items: center; padding: 6px 4px; border-bottom: 1px solid #1e293b; gap: 8px; }
        .roster-row label { cursor: pointer; flex: 1; font-size: 13px; }
        .ret-select { width: 105px; padding: 4px; font-size: 12px; }
        .badge-rights { color: #c084fc; font-weight: bold; font-size: 11px; }
        table { width: 100%; border-collapse: collapse; background: #1e293b; margin-top: 10px; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #334155; vertical-align: top; }
        th { background: #020617; color: #38bdf8; }
        .btn { background: #0284c7; color: #fff; border: none; padding: 10px 16px; border-radius: 6px; font-weight: bold; cursor: pointer; text-decoration: none; display: inline-block; font-size: 13px; }
        .btn-green { background: #16a34a; }
        .btn-red { background: #dc2626; }
        .btn-amber { background: #d97706; }
        .alert { background: #14532d; color: #86efac; padding: 12px; border-radius: 6px; border: 1px solid #22c55e; margin-bottom: 16px; font-weight: bold; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>

<h1>HFNHL Trade Center</h1>
<?php if ($msg): ?><div class="alert"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<?php if ($myTeamId > 0): ?>
    <div class="card">
        <h2 style="margin-top:0;"><?= $counterId > 0 ? "Counter-Offer for Trade #{$counterId}" : "Propose a Trade" ?></h2>
        <form method="get" style="margin-bottom:16px;">
            <label><strong>Select Trade Partner:</strong></label>
            <select name="partner" onchange="this.form.submit()" style="max-width:350px;margin-top:6px;">
                <option value="0">-- Choose Team --</option>
                <?php foreach ($allTeams as $t): if ((int)$t['team_id'] === $myTeamId) continue; ?>
                    <option value="<?= $t['team_id'] ?>" <?= $partnerId == $t['team_id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>

        <?php if ($partnerId > 0): ?>
            <form method="post">
                <input type="hidden" name="action" value="propose">
                <input type="hidden" name="receiving_team_id" value="<?= $partnerId ?>">
                <input type="hidden" name="counter_trade_id" value="<?= $counterId ?>">

                <div class="grid-2">
                    <div>
                        <h3>You Send (Players &amp; Rights Owned):</h3>
                        <div class="roster-box">
                            <?php foreach ($myRoster as $p):
                                $pid      = (int)$p['player_id'];
                                $sal      = (int)$p['salary'];
                                $st       = $p['st'];
                                $isRights = in_array($st, array('RIGHTS', 'EURO', 'EUROPE', 'LTIR', 'INJURED', 'INACTIVE'));
                                $salM     = number_format($sal / 1000000, 2);
                                $selRet   = (float)($preOfferRet[$pid] ?? 0);
                            ?>
                                <div class="roster-row">
                                    <label>
                                        <input type="checkbox" name="offer_pids[]" value="<?= $pid ?>"
                                            <?= in_array($pid, $preOfferPlayers) ? 'checked' : '' ?>>
                                        <?= htmlspecialchars("{$p['first_name']} {$p['last_name']} ({$p['position']} {$p['overall_rating']})") ?>
                                        <?php if ($isRights): ?>
                                            <span class="badge-rights">[<?= htmlspecialchars($st) ?> Rights]</span>
                                        <?php endif; ?>
                                        <span style="color:#94a3b8;">- $<?= $salM ?>M</span>
                                    </label>
                                    <?php if ($sal > 0): ?>
                                        <select name="offer_ret[<?= $pid ?>]" class="ret-select" title="Salary Retained %">
                                            <option value="0">0% Ret.</option>
                                            <?php foreach (array(10, 15, 20, 25, 30, 35, 40, 45, 50) as $pct): ?>
                                                <option value="<?= $pct ?>" <?= $selRet == $pct ? 'selected' : '' ?>><?= $pct ?>% Ret.</option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <h4>You Send (Prospects):</h4>
                        <div class="roster-box" style="max-height:150px;">
                            <?php if (empty($myProspects)): ?>
                                <em style="color:#94a3b8;">No prospects on list.</em>
                            <?php endif; ?>
                            <?php foreach ($myProspects as $pr): $prid = (int)$pr['prospect_id']; ?>
                                <div class="roster-row">
                                    <label>
                                        <input type="checkbox" name="offer_prospect_ids[]" value="<?= $prid ?>"
                                            <?= in_array($prid, $preOfferProspects) ? 'checked' : '' ?>>
                                        <?= htmlspecialchars(trim("{$pr['first_name']} {$pr['last_name']}") . " ({$pr['position']}" . (!empty($pr['draft_year']) ? " - {$pr['draft_year']}" : "") . ")") ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <h4>You Send (Draft Picks):</h4>
                        <div class="roster-box" style="max-height:150px;">
                            <?php if (empty($myPicks)): ?>
                                <em style="color:#94a3b8;">No available draft picks.</em>
                            <?php endif; ?>
                            <?php foreach ($myPicks as $pk): ?>
                                <div class="roster-row">
                                    <label>
                                        <input type="checkbox" name="offer_pick_ids[]" value="<?= $pk['pick_id'] ?>"
                                            <?= in_array((int)$pk['pick_id'], $preOfferPicks) ? 'checked' : '' ?>>
                                        <?= htmlspecialchars("{$pk['draft_year']} Rd {$pk['round_num']} ({$pk['orig_team']})" . (!empty($pk['conditions']) ? " [{$pk['conditions']}]" : "")) ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div>
                        <h3>You Receive (Players &amp; Rights Owned):</h3>
                        <div class="roster-box">
                            <?php foreach ($partnerRoster as $p):
                                $pid      = (int)$p['player_id'];
                                $sal      = (int)$p['salary'];
                                $st       = $p['st'];
                                $isRights = in_array($st, array('RIGHTS', 'EURO', 'EUROPE', 'LTIR', 'INJURED', 'INACTIVE'));
                                $salM     = number_format($sal / 1000000, 2);
                                $selRet   = (float)($preReqRet[$pid] ?? 0);
                            ?>
                                <div class="roster-row">
                                    <label>
                                        <input type="checkbox" name="req_pids[]" value="<?= $pid ?>"
                                            <?= in_array($pid, $preReqPlayers) ? 'checked' : '' ?>>
                                        <?= htmlspecialchars("{$p['first_name']} {$p['last_name']} ({$p['position']} {$p['overall_rating']})") ?>
                                        <?php if ($isRights): ?>
                                            <span class="badge-rights">[<?= htmlspecialchars($st) ?> Rights]</span>
                                        <?php endif; ?>
                                        <span style="color:#94a3b8;">- $<?= $salM ?>M</span>
                                    </label>
                                    <?php if ($sal > 0): ?>
                                        <select name="req_ret[<?= $pid ?>]" class="ret-select" title="Salary Retained %">
                                            <option value="0">0% Ret.</option>
                                            <?php foreach (array(10, 15, 20, 25, 30, 35, 40, 45, 50) as $pct): ?>
                                                <option value="<?= $pct ?>" <?= $selRet == $pct ? 'selected' : '' ?>><?= $pct ?>% Ret.</option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <h4>You Receive (Prospects):</h4>
                        <div class="roster-box" style="max-height:150px;">
                            <?php if (empty($partnerProspects)): ?>
                                <em style="color:#94a3b8;">No prospects on list.</em>
                            <?php endif; ?>
                            <?php foreach ($partnerProspects as $pr): $prid = (int)$pr['prospect_id']; ?>
                                <div class="roster-row">
                                    <label>
                                        <input type="checkbox" name="req_prospect_ids[]" value="<?= $prid ?>"
                                            <?= in_array($prid, $preReqProspects) ? 'checked' : '' ?>>
                                        <?= htmlspecialchars(trim("{$pr['first_name']} {$pr['last_name']}") . " ({$pr['position']}" . (!empty($pr['draft_year']) ? " - {$pr['draft_year']}" : "") . ")") ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <h4>You Receive (Draft Picks):</h4>
                        <div class="roster-box" style="max-height:150px;">
                            <?php if (empty($partnerPicks)): ?>
                                <em style="color:#94a3b8;">No available draft picks.</em>
                            <?php endif; ?>
                            <?php foreach ($partnerPicks as $pk): ?>
                                <div class="roster-row">
                                    <label>
                                        <input type="checkbox" name="req_pick_ids[]" value="<?= $pk['pick_id'] ?>"
                                            <?= in_array((int)$pk['pick_id'], $preReqPicks) ? 'checked' : '' ?>>
                                        <?= htmlspecialchars("{$pk['draft_year']} Rd {$pk['round_num']} ({$pk['orig_team']})" . (!empty($pk['conditions']) ? " [{$pk['conditions']}]" : "")) ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div style="margin-top:14px;">
                    <label><strong>Trade Notes / Conditions:</strong></label>
                    <textarea name="trade_notes" rows="2" placeholder="Optional message or pick conditions..."><?= htmlspecialchars($preNotes) ?></textarea>
                </div>
                <button type="submit" class="btn" style="margin-top:14px;">
                    <?= $counterId > 0 ? "Send Counter-Offer" : "Submit Trade Offer" ?>
                </button>
                <?php if ($counterId > 0): ?>
                    <a href="trades.php" class="btn btn-red" style="margin-top:14px;margin-left:8px;">Cancel Counter</a>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </div>
<?php endif; ?>

<h2>Active &amp; Completed League Trades</h2>
<table>
    <tr>
        <th>ID</th>
        <th>Proposing Team</th>
        <th>Sends</th>
        <th>Receiving Team</th>
        <th>Sends</th>
        <th>Notes &amp; Status</th>
        <th>Actions</th>
    </tr>
    <?php foreach ($trades as $tr): ?>
        <tr>
            <td>
                #<?= (int)$tr['trade_id'] ?>
                <?= !empty($tr['parent_trade_id']) ? '<br><small style="color:#94a3b8;">(Counter to #' . (int)$tr['parent_trade_id'] . ')</small>' : '' ?>
            </td>
            <td><strong><?= htmlspecialchars($tr['prop_name']) ?></strong></td>
            <td><?= formatAssetList($tr['offered_player_ids'], $tr['offered_pick_ids'] ?? '{}', $tr['offered_prospect_ids'] ?? '{}', $playerMap, $pickMap, $prospectMap, $tr['offered_retentions'] ?? '{}') ?></td>
            <td><strong><?= htmlspecialchars($tr['rec_name']) ?></strong></td>
            <td><?= formatAssetList($tr['requested_player_ids'], $tr['requested_pick_ids'] ?? '{}', $tr['requested_prospect_ids'] ?? '{}', $playerMap, $pickMap, $prospectMap, $tr['requested_retentions'] ?? '{}') ?></td>
            <td>
                <strong>Status:</strong> <?= htmlspecialchars($tr['status']) ?><br>
                <?= $tr['trade_notes'] ? '<em style="color:#94a3b8;">' . htmlspecialchars($tr['trade_notes']) . '</em>' : '' ?>
            </td>
            <td>
                <?php if ($tr['status'] === 'PENDING_GM' && (int)$tr['receiving_team_id'] === $myTeamId): ?>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="trade_id" value="<?= $tr['trade_id'] ?>">
                        <button class="btn btn-green" name="action" value="accept_gm">Accept</button>
                        <button class="btn btn-red" name="action" value="decline_gm">Decline</button>
                    </form>
                    <a href="?counter=<?= (int)$tr['trade_id'] ?>" class="btn btn-amber" style="margin-top:4px;">Counter</a>
                <?php elseif ($tr['status'] === 'PENDING_GM' && (int)$tr['proposing_team_id'] === $myTeamId): ?>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="trade_id" value="<?= $tr['trade_id'] ?>">
                        <button class="btn btn-red" name="action" value="cancel_gm">Withdraw</button>
                    </form>
                <?php elseif ($tr['status'] === 'PENDING_COMMISH' && $isTradeCommittee && (int)$tr['proposing_team_id'] !== $myTeamId && (int)$tr['receiving_team_id'] !== $myTeamId): ?>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="trade_id" value="<?= $tr['trade_id'] ?>">
                        <button class="btn btn-green" name="action" value="approve_commish">Approve &amp; Execute</button>
                        <button class="btn btn-red" name="action" value="veto_commish">Decline</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
</table>
</body>
</html>
