<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);

require 'db.php';
require_once 'rfa_comp_helper.php';
/** @var PDO $pdo */

$isCommish  = !empty($_SESSION['is_commish']) || !empty($_SESSION['is_admin']);
$isFaComm   = !empty($_SESSION['is_fa_committee']);
$userTeamId = (int)($_SESSION['team_id'] ?? 0);
$canOffer   = ($userTeamId > 0 || $isCommish || $isFaComm);
$msg        = '';
$alertType  = 'info';

// Fetch league phase and signing deadline
$currentPhase = 'REGULAR_SEASON';
$faDeadline   = '';
try {
    $sRows = $pdo->query("SELECT setting_key, setting_value FROM league_settings WHERE setting_key IN ('league_phase', 'fa_signing_deadline')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $currentPhase = $sRows['league_phase'] ?? 'REGULAR_SEASON';
    $faDeadline   = trim($sRows['fa_signing_deadline'] ?? '');
} catch (Exception $e) {}

$deadlineTs     = ($faDeadline !== '') ? strtotime($faDeadline) : false;
$isBiddingPhase = ($deadlineTs !== false && time() < $deadlineTs);

// Helper to finalize an unmatched RFA Offer Sheet (signs player to offering team + transfers own draft picks)
$finalizeUnmatchedRfa = function(array $bidRow) use ($pdo) {
    $bidId      = (int)$bidRow['bid_id'];
    $pid        = (int)$bidRow['player_id'];
    $offerTid   = (int)$bidRow['team_id'];
    $rightsTid  = (int)$bidRow['prev_team_id'];
    $years      = (int)$bidRow['offered_years'];
    $salary     = (int)$bidRow['offered_salary'];
    $destRoster = $bidRow['dest_roster'] ?: 'PRO';

    $transfer = transferRfaCompensationPicks($pdo, $offerTid, $rightsTid, $salary, $years);

    $pdo->prepare("
        UPDATE players
        SET team_id        = ?,
            roster_status  = ?,
            contract_years = ?,
            salary         = ?,
            fa_status      = NULL,
            qo_tendered    = FALSE,
            line_number    = 99
        WHERE player_id = ?
    ")->execute(array($offerTid, $destRoster, $years, $salary, $pid));

    $pdo->prepare("UPDATE fa_bids SET status = 'WON' WHERE bid_id = ?")->execute(array($bidId));
    return $transfer['label'] ?? 'None';
};

// Auto-process any expired 7-day RFA Match windows on page load
try {
    $expiredMatches = $pdo->query("
        SELECT b.*, p.prev_team_id
        FROM fa_bids b
        JOIN players p ON b.player_id = p.player_id
        WHERE b.status = 'MATCH_PENDING'
          AND b.match_deadline IS NOT NULL
          AND b.match_deadline <= NOW()
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($expiredMatches)) {
        $pdo->beginTransaction();
        foreach ($expiredMatches as $exp) {
            $finalizeUnmatchedRfa($exp);
        }
        $pdo->commit();
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
}

// Handle Rights-Holding Team Match or Decline Action
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'rfa_match_decision') {
    $bidId    = (int)($_POST['bid_id'] ?? 0);
    $decision = $_POST['decision'] ?? '';

    $mStmt = $pdo->prepare("
        SELECT b.*, p.first_name, p.last_name, p.prev_team_id
        FROM fa_bids b
        JOIN players p ON b.player_id = p.player_id
        WHERE b.bid_id = ? AND b.status = 'MATCH_PENDING'
    ");
    $mStmt->execute(array($bidId));
    $matchBid = $mStmt->fetch(PDO::FETCH_ASSOC);

    if ($matchBid) {
        $rightsTid = (int)$matchBid['prev_team_id'];
        $pName     = trim($matchBid['first_name'] . ' ' . $matchBid['last_name']);

        if ($userTeamId === $rightsTid || $isCommish) {
            $pdo->beginTransaction();
            if ($decision === 'match') {
                // Rights-holding team matches exact AAV and term
                $pdo->prepare("
                    UPDATE players
                    SET team_id        = ?,
                        roster_status  = 'PRO',
                        contract_years = ?,
                        salary         = ?,
                        fa_status      = NULL,
                        qo_tendered    = FALSE,
                        line_number    = 99
                    WHERE player_id = ?
                ")->execute(array($rightsTid, (int)$matchBid['offered_years'], (int)$matchBid['offered_salary'], (int)$matchBid['player_id']));

                $pdo->prepare("UPDATE fa_bids SET status = 'MATCHED' WHERE bid_id = ?")->execute(array($bidId));
                $pdo->commit();

                $msg       = "OFFER SHEET MATCHED! {$pName} stays with his original team ({$matchBid['offered_years']}y @ $" . number_format((int)$matchBid['offered_salary']) . ").";
                $alertType = 'success';
            } elseif ($decision === 'decline') {
                // Rights-holding team declines -> Player signs with offering team & picks transfer
                $compLabel = $finalizeUnmatchedRfa($matchBid);
                $pdo->commit();

                $msg       = "OFFER SHEET DECLINED! {$pName} signs with the offering team. Draft pick compensation transferred: {$compLabel}.";
                $alertType = 'success';
            } else {
                $pdo->rollBack();
            }
        }
    }
}

// Handle Placing / Updating a Bid on a Free Agent
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'place_fa_bid' && $canOffer) {
    $pid        = (int)($_POST['player_id'] ?? 0);
    $years      = max(1, min(7, (int)($_POST['contract_years'] ?? 1)));
    $salary     = max(750000, (int)($_POST['salary'] ?? 750000));
    $destRoster = (($_POST['dest_roster'] ?? 'PRO') === 'AHL') ? 'AHL' : 'PRO';
    $offerNote  = trim($_POST['offer_reason'] ?? '');

    $targetTeamId = ($isCommish || $isFaComm) && !empty($_POST['target_team_id'])
            ? (int)$_POST['target_team_id']
            : $userTeamId;

    if ($pid > 0 && $targetTeamId > 0) {
        $pChk = $pdo->prepare("SELECT first_name, last_name, COALESCE(fa_status, 'UFA') AS fa_status, prev_team_id FROM players WHERE player_id = ? AND UPPER(COALESCE(roster_status, '')) = 'FA'");
        $pChk->execute(array($pid));
        $targetP = $pChk->fetch(PDO::FETCH_ASSOC);

        if ($targetP) {
            $faType  = strtoupper(trim($targetP['fa_status'])) ?: 'UFA';
            $prevTid = (int)($targetP['prev_team_id'] ?? 0);
            $pName   = trim($targetP['first_name'] . ' ' . $targetP['last_name']);

            // If external RFA Offer Sheet, verify offering team owns required original draft picks
            $compCheck = array('ok' => true, 'missing' => '');
            $compInfo  = getRfaCompensation($salary, $years);
            if ($faType === 'RFA' && $prevTid > 0 && $targetTeamId !== $prevTid) {
                $compCheck = verifyTeamOwnPicksForRfa($pdo, $targetTeamId, $compInfo['rounds']);
            }

            if (!$compCheck['ok']) {
                $msg       = "Cannot submit RFA Offer Sheet for {$pName} ({$compInfo['label']}): " . $compCheck['missing'];
                $alertType = 'error';
            } else {
                $upsert = $pdo->prepare("
                    INSERT INTO fa_bids (player_id, team_id, offered_years, offered_salary, dest_roster, offer_reason, status, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, 'ACTIVE', NOW())
                    ON CONFLICT (player_id, team_id)
                    DO UPDATE SET
                        offered_years  = EXCLUDED.offered_years,
                        offered_salary = EXCLUDED.offered_salary,
                        dest_roster    = EXCLUDED.dest_roster,
                        offer_reason   = EXCLUDED.offer_reason,
                        status         = 'ACTIVE',
                        updated_at     = NOW()
                ");
                $upsert->execute(array($pid, $targetTeamId, $years, $salary, $destRoster, $offerNote));

                $compNote  = ($faType === 'RFA' && $targetTeamId !== $prevTid) ? " | RFA Comp Tier: {$compInfo['label']}" : "";
                $msg       = "Sealed bid saved for {$pName}: {$years}y @ $" . number_format($salary) . "/yr{$compNote}. Highest bidder wins when signings unlock!";
                $alertType = 'success';
            }
        }
    }
}

// Fetch active MATCH_PENDING RFA Offer Sheets
$pendingMatches = $pdo->query("
    SELECT b.*, p.first_name, p.last_name, p.position, p.overall_rating, p.prev_team_id,
           offer_t.abbreviation AS offer_abbr, offer_t.name AS offer_team_name,
           rights_t.abbreviation AS rights_abbr, rights_t.name AS rights_team_name
    FROM fa_bids b
    JOIN players p ON b.player_id = p.player_id
    JOIN teams offer_t ON b.team_id = offer_t.team_id
    LEFT JOIN teams rights_t ON p.prev_team_id = rights_t.team_id
    WHERE b.status = 'MATCH_PENDING'
    ORDER BY b.match_deadline ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Filters & Pagination
$posFilter    = strtoupper(trim($_GET['pos'] ?? 'ALL'));
$typeFilter   = strtoupper(trim($_GET['type'] ?? 'ALL'));
$searchFilter = trim($_GET['q'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 50;
$offset       = ($page - 1) * $perPage;

$where  = array("UPPER(COALESCE(p.roster_status, '')) = 'FA'", "COALESCE(p.is_retired, FALSE) = FALSE");
$params = array();

if ($posFilter !== '' && $posFilter !== 'ALL') {
    if ($posFilter === 'F') $where[] = "UPPER(p.position) IN ('LW', 'C', 'RW', 'F')";
    else { $where[] = "UPPER(p.position) = ?"; $params[] = $posFilter; }
}
if ($typeFilter === 'UFA' || $typeFilter === 'RFA') {
    $where[]  = "UPPER(COALESCE(p.fa_status, 'UFA')) = ?";
    $params[] = $typeFilter;
}
if ($searchFilter !== '') {
    $where[]  = "(LOWER(p.first_name || ' ' || p.last_name) LIKE LOWER(?))";
    $params[] = '%' . $searchFilter . '%';
}

$whereSql  = implode(' AND ', $where);
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM players p WHERE {$whereSql}");
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

$sql = "
    SELECT p.*, t.abbreviation AS prev_abbr,
           (SELECT COUNT(*) FROM fa_bids fb WHERE fb.player_id = p.player_id AND fb.status = 'ACTIVE') AS total_bids
    FROM players p
    LEFT JOIN teams t ON p.prev_team_id = t.team_id
    WHERE {$whereSql}
    ORDER BY p.overall_rating DESC, p.last_name ASC
    LIMIT {$perPage} OFFSET {$offset}
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$freeAgents = $stmt->fetchAll(PDO::FETCH_ASSOC);

$myBidsByPlayer = array();
if ($userTeamId > 0) {
    $myBStmt = $pdo->prepare("SELECT * FROM fa_bids WHERE team_id = ? AND status = 'ACTIVE'");
    $myBStmt->execute(array($userTeamId));
    foreach ($myBStmt->fetchAll(PDO::FETCH_ASSOC) as $mb) {
        $myBidsByPlayer[(int)$mb['player_id']] = $mb;
    }
}

$allTeams = ($isCommish || $isFaComm)
        ? $pdo->query("SELECT team_id, abbreviation, name FROM teams ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC)
        : array();

function buildFaUrl($overrides = array()) {
    return '?' . http_build_query(array_merge(array(
                    'pos'  => $_GET['pos'] ?? 'ALL',
                    'type' => $_GET['type'] ?? 'ALL',
                    'q'    => $_GET['q'] ?? '',
                    'page' => $_GET['page'] ?? 1,
            ), $overrides));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Free Agent Market - HFNHL</title>
    <style>
        body { font-family: Arial, sans-serif; background: #0f172a; color: #f8fafc; padding: 20px; max-width: 1380px; margin: auto; }
        h1, h2 { color: #38bdf8; }
        .header-bar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; }
        .phase-pill { padding: 6px 12px; border-radius: 6px; font-weight: bold; font-size: 0.85em; background: #0284c7; color: #fff; }
        .match-card { background: #1e1b4b; border: 1px solid #6366f1; border-radius: 8px; padding: 16px; margin-bottom: 20px; }
        .filter-card { background: #1e293b; padding: 14px 18px; border-radius: 8px; border: 1px solid #334155; margin-bottom: 18px; display: flex; gap: 14px; flex-wrap: wrap; align-items: center; }
        .filter-group { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        .filter-btn { background: #0f172a; color: #cbd5e1; border: 1px solid #475569; padding: 6px 11px; border-radius: 5px; text-decoration: none; font-weight: bold; font-size: 0.86em; }
        .filter-btn.active { background: #0284c7; color: #fff; border-color: #38bdf8; }
        input[type=text], input[type=number], select { padding: 6px 8px; border-radius: 5px; border: 1px solid #475569; background: #0f172a; color: #fff; font-size: 0.85em; }
        table { width: 100%; border-collapse: collapse; background: #1e293b; border-radius: 8px; overflow: hidden; }
        th, td { padding: 9px 10px; text-align: left; border-bottom: 1px solid #334155; font-size: 0.91em; }
        th { background: #020617; color: #38bdf8; font-size: 0.84em; }
        .badge-ufa { background: #7c2d12; color: #fdba74; border: 1px solid #f97316; padding: 2px 6px; border-radius: 4px; font-size: 0.78em; font-weight: bold; }
        .badge-rfa { background: #1e3a8a; color: #93c5fd; border: 1px solid #3b82f6; padding: 2px 6px; border-radius: 4px; font-size: 0.78em; font-weight: bold; }
        .badge-mybid { background: #065f46; color: #6ee7b7; border: 1px solid #10b981; padding: 2px 6px; border-radius: 4px; font-size: 0.75em; margin-left: 5px; }
        .btn-bid { background: #0284c7; color: #fff; border: none; padding: 6px 10px; border-radius: 5px; font-weight: bold; cursor: pointer; font-size: 0.83em; }
        .btn-match { background: #16a34a; color: #fff; border: none; padding: 6px 11px; border-radius: 5px; font-weight: bold; cursor: pointer; font-size: 0.83em; }
        .btn-decline { background: #dc2626; color: #fff; border: none; padding: 6px 11px; border-radius: 5px; font-weight: bold; cursor: pointer; font-size: 0.83em; }
        .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; font-weight: bold; }
        .alert.success { background: #14532d; color: #86efac; border: 1px solid #22c55e; }
        .alert.error { background: #450a0a; color: #fca5a5; border: 1px solid #ef4444; }
        .pagination { display: flex; justify-content: space-between; align-items: center; margin-top: 16px; padding: 12px 16px; background: #1e293b; border-radius: 8px; border: 1px solid #334155; flex-wrap: wrap; gap: 10px; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>

<div class="header-bar">
    <div>
        <h1 style="margin:0;">Free Agent Market (<?= number_format($totalRows) ?> Available)</h1>
        <small style="color:#94a3b8;">
            <?php if ($isBiddingPhase): ?>
                <strong>Sealed Bidding Open:</strong> Highest total contract value wins when signings unlock on <strong style="color:#fbbf24;"><?= date('M j, Y g:i A', $deadlineTs) ?></strong>.
            <?php else: ?>
                Place bids below — external RFA Offer Sheets require your team's <strong>own original draft picks</strong> and trigger a <strong>7-day Match Window</strong>.
            <?php endif; ?>
        </small>
    </div>
    <span class="phase-pill">Mode: <?= htmlspecialchars(str_replace('_', ' ', $currentPhase)) ?></span>
</div>

<?php if ($msg): ?>
    <div class="alert <?= htmlspecialchars($alertType) ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>
<?php if (!empty($pendingMatches)): ?>
    <div class="match-card">
        <h2 style="margin-top:0; color:#a5b4fc;">⏳ RFA Offer Sheets Pending Match (7-Day Window)</h2>
        <table>
            <tr>
                <th>RFA Player</th><th>Rights Held By</th><th>Offer Sheet By</th>
                <th>Contract Terms</th><th>Draft Pick Compensation</th><th>Match Deadline</th><th>Decision</th>
            </tr>
            <?php foreach ($pendingMatches as $pm):
                $compInfo  = getRfaCompensation((int)$pm['offered_salary'], (int)$pm['offered_years']);
                $canDecide = ($userTeamId === (int)$pm['prev_team_id'] || $isCommish);
                $deadStr   = !empty($pm['match_deadline']) ? date('M j, Y g:i A', strtotime($pm['match_deadline'])) : '7 Days';
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($pm['first_name'] . ' ' . $pm['last_name']) ?></strong> (<?= htmlspecialchars($pm['position']) ?> &bull; <?= (int)$pm['overall_rating'] ?> OVR)</td>
                    <td><strong><?= htmlspecialchars($pm['rights_abbr']) ?></strong></td>
                    <td><strong><?= htmlspecialchars($pm['offer_abbr']) ?></strong></td>
                    <td style="color:#4ade80; font-weight:bold;"><?= (int)$pm['offered_years'] ?>y @ $<?= number_format((int)$pm['offered_salary']) ?>/yr</td>
                    <td><span class="badge-rfa"><?= htmlspecialchars($compInfo['label']) ?></span></td>
                    <td style="color:#fbbf24;"><?= htmlspecialchars($deadStr) ?></td>
                    <td>
                        <?php if ($canDecide): ?>
                            <form method="post" style="display:flex; gap:6px; margin:0;">
                                <input type="hidden" name="action" value="rfa_match_decision">
                                <input type="hidden" name="bid_id" value="<?= (int)$pm['bid_id'] ?>">
                                <button type="submit" name="decision" value="match" class="btn-match" onclick="return confirm('Match this Offer Sheet and retain the player?');">Match Offer</button>
                                <button type="submit" name="decision" value="decline" class="btn-decline" onclick="return confirm('Decline to match? Player signs with <?= htmlspecialchars($pm['offer_abbr']) ?> and you receive: <?= htmlspecialchars($compInfo['label']) ?>.');">Decline (Take Picks)</button>
                            </form>
                        <?php else: ?>
                            <small style="color:#94a3b8;">Awaiting <?= htmlspecialchars($pm['rights_abbr']) ?> GM</small>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
<?php endif; ?>

<div class="filter-card">
    <div class="filter-group">
        <strong>Type:</strong>
        <?php foreach (array('ALL' => 'All', 'UFA' => 'UFA Only', 'RFA' => 'RFA Only') as $tCode => $tLabel): ?>
            <a href="<?= htmlspecialchars(buildFaUrl(array('type' => $tCode, 'page' => 1))) ?>" class="filter-btn <?= $typeFilter === $tCode ? 'active' : '' ?>"><?= $tLabel ?></a>
        <?php endforeach; ?>
    </div>
    <div class="filter-group">
        <strong>Pos:</strong>
        <?php foreach (array('ALL' => 'All', 'F' => 'Forwards', 'C' => 'C', 'LW' => 'LW', 'RW' => 'RW', 'D' => 'D', 'G' => 'G') as $pCode => $pLabel): ?>
            <a href="<?= htmlspecialchars(buildFaUrl(array('pos' => $pCode, 'page' => 1))) ?>" class="filter-btn <?= $posFilter === $pCode ? 'active' : '' ?>"><?= $pLabel ?></a>
        <?php endforeach; ?>
    </div>
    <form method="get" style="margin-left:auto; display:flex; gap:8px; align-items:center;">
        <input type="hidden" name="pos" value="<?= htmlspecialchars($posFilter) ?>">
        <input type="hidden" name="type" value="<?= htmlspecialchars($typeFilter) ?>">
        <input type="text" name="q" value="<?= htmlspecialchars($searchFilter) ?>" placeholder="Search player name...">
        <button type="submit" class="filter-btn active" style="cursor:pointer;">Search</button>
    </form>
</div>

<?php if (empty($freeAgents)): ?>
    <div style="background:#1e293b; padding:24px; border-radius:8px; border:1px solid #334155; color:#94a3b8; text-align:center;">No Free Agents currently available matching your filter.</div>
<?php else: ?>
    <div style="overflow-x:auto;">
        <table>
            <tr>
                <th>Player</th><th>Pos</th><th>Age</th><th>Type</th><th>Prev Team</th>
                <th>OVR</th><th>SK</th><th>SH</th><th>PA</th><th>DF</th><th>PH</th>
                <?php if ($canOffer): ?><th style="min-width:410px;">Place / Update Sealed Bid</th><?php endif; ?>
            </tr>
            <?php foreach ($freeAgents as $fa):
                $pid       = (int)$fa['player_id'];
                $fullName  = trim($fa['first_name'] . ' ' . $fa['last_name']);
                $faType    = strtoupper(trim($fa['fa_status'] ?? 'UFA')) ?: 'UFA';
                $prevAbbr  = $fa['prev_abbr'] ?: '—';
                $myBid     = $myBidsByPlayer[$pid] ?? null;
                $defSal    = $myBid ? (int)$myBid['offered_salary'] : max(750000, (int)($fa['salary'] ?? 750000));
                $defYrs    = $myBid ? (int)$myBid['offered_years'] : 1;
                $totalBids = (int)($fa['total_bids'] ?? 0);
                ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($fullName) ?></strong>
                        <?php if ($totalBids > 0): ?><small style="color:#94a3b8; margin-left:4px;">(<?= $totalBids ?> bid<?= $totalBids > 1 ? 's' : '' ?>)</small><?php endif; ?>
                        <?php if ($myBid): ?><span class="badge-mybid">Your Bid: <?= $defYrs ?>y @ $<?= number_format($defSal) ?></span><?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($fa['position']) ?></td>
                    <td><?= (int)($fa['age'] ?? 0) ?: '—' ?></td>
                    <td><span class="<?= $faType === 'RFA' ? 'badge-rfa' : 'badge-ufa' ?>"><?= htmlspecialchars($faType) ?></span></td>
                    <td><strong><?= htmlspecialchars($prevAbbr) ?></strong></td>
                    <td><strong style="color:#38bdf8;"><?= (int)$fa['overall_rating'] ?></strong></td>
                    <td><?= (int)$fa['skating'] ?></td>
                    <td><?= (int)$fa['shooting'] ?></td>
                    <td><?= (int)$fa['passing'] ?></td>
                    <td><?= (int)$fa['defense'] ?></td>
                    <td><?= (int)$fa['physical'] ?></td>
                    <?php if ($canOffer): ?>
                        <td>
                            <form method="post" style="display:flex; gap:6px; align-items:center; flex-wrap:wrap; margin:0;">
                                <input type="hidden" name="action" value="place_fa_bid">
                                <input type="hidden" name="player_id" value="<?= $pid ?>">
                                <?php if ($isCommish || $isFaComm): ?>
                                    <select name="target_team_id" required>
                                        <?php foreach ($allTeams as $tm): ?>
                                            <option value="<?= (int)$tm['team_id'] ?>" <?= (int)$tm['team_id'] === $userTeamId ? 'selected' : '' ?>><?= htmlspecialchars($tm['abbreviation']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                                <input type="number" name="salary" value="<?= $defSal ?>" min="750000" step="50000" style="width:98px;" required>
                                <select name="contract_years">
                                    <?php for ($y = 1; $y <= 7; $y++): ?>
                                        <option value="<?= $y ?>" <?= $y === $defYrs ? 'selected' : '' ?>><?= $y ?>y</option>
                                    <?php endfor; ?>
                                </select>
                                <select name="dest_roster"><option value="PRO">NHL</option><option value="AHL">AHL</option></select>
                                <button type="submit" class="btn-bid"><?= $myBid ? 'Update Bid' : ($faType === 'RFA' ? 'Offer Sheet' : 'Place Bid') ?></button>
                            </form>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <div class="pagination">
        <div style="color:#94a3b8; font-size:0.9em;">Showing <strong><?= $offset + 1 ?></strong>–<strong><?= min($offset + $perPage, $totalRows) ?></strong> of <strong><?= number_format($totalRows) ?></strong> Free Agents (Page <?= $page ?> of <?= $totalPages ?>)</div>
        <div style="display:flex; gap:8px;">
            <?php if ($page > 1): ?>
                <a href="<?= htmlspecialchars(buildFaUrl(array('page' => 1))) ?>" class="filter-btn">&laquo; First</a>
                <a href="<?= htmlspecialchars(buildFaUrl(array('page' => $page - 1))) ?>" class="filter-btn">&lsaquo; Prev</a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
                <a href="<?= htmlspecialchars(buildFaUrl(array('page' => $page + 1))) ?>" class="filter-btn">Next &rsaquo;</a>
                <a href="<?= htmlspecialchars(buildFaUrl(array('page' => $totalPages))) ?>" class="filter-btn">Last &raquo;</a>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
</body>
</html>


