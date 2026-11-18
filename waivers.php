<?php
require 'db.php';
/** @var PDO $pdo */

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$myTeamId  = (int)($_SESSION['team_id'] ?? 0);
$isCommish = !empty($_SESSION['is_commish']) || !empty($_SESSION['is_admin']);
$msg       = '';
$err       = '';
function resolveWaivers(PDO $pdo, $forceAll = false) {
    $sql = $forceAll
        ? "SELECT * FROM waiver_wire WHERE status = 'ACTIVE' ORDER BY placed_at ASC, waiver_id ASC"
        : "SELECT * FROM waiver_wire WHERE status = 'ACTIVE' AND expires_at <= NOW() ORDER BY expires_at ASC, waiver_id ASC";

    $expiredItems = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $resolvedCount = 0;

    foreach ($expiredItems as $w) {
        $wid = (int)$w['waiver_id'];
        $pid = (int)$w['player_id'];

        $pdo->beginTransaction();

        $claimStmt = $pdo->prepare("
            SELECT wc.claiming_team_id, t.waiver_priority, t.name AS team_name
            FROM waiver_claims wc
            JOIN teams t ON wc.claiming_team_id = t.team_id
            WHERE wc.waiver_id = ?
            ORDER BY t.waiver_priority ASC, wc.claimed_at ASC
            LIMIT 1
        ");
        $claimStmt->execute(array($wid));
        $winner = $claimStmt->fetch(PDO::FETCH_ASSOC);

        if ($winner) {
            $winTeamId = (int)$winner['claiming_team_id'];
            $oldPrio   = (int)$winner['waiver_priority'];

            $pdo->prepare("
                UPDATE players
                SET team_id = ?, roster_status = 'PRO', line_number = 99
                WHERE player_id = ?
            ")->execute(array($winTeamId, $pid));

            $pdo->prepare("
                UPDATE waiver_wire
                SET status = 'CLAIMED', claimed_by_team_id = ?, resolved_at = NOW()
                WHERE waiver_id = ?
            ")->execute(array($winTeamId, $wid));

            $maxPrio = (int)$pdo->query("SELECT COALESCE(MAX(waiver_priority), 32) FROM teams")->fetchColumn();
            if ($oldPrio > 0 && $oldPrio < $maxPrio) {
                $pdo->prepare("
                    UPDATE teams
                    SET waiver_priority = waiver_priority - 1
                    WHERE waiver_priority > ?
                ")->execute(array($oldPrio));

                $pdo->prepare("
                    UPDATE teams
                    SET waiver_priority = ?
                    WHERE team_id = ?
                ")->execute(array($maxPrio, $winTeamId));
            }
        } else {

            $pdo->prepare("
                UPDATE players
                SET roster_status = 'AHL', line_number = 99
                WHERE player_id = ?
            ")->execute(array($pid));

            $pdo->prepare("
                UPDATE waiver_wire
                SET status = 'CLEARED', resolved_at = NOW()
                WHERE waiver_id = ?
            ")->execute(array($wid));
        }

        $pdo->commit();
        $resolvedCount++;
    }

    return $resolvedCount;
}

$autoResolved = resolveWaivers($pdo, false);
if ($autoResolved > 0) {
    $msg = "Processed {$autoResolved} expired waiver placement(s) and updated rolling waiver priority!";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'place_waivers') {
    $pid = (int)($_POST['player_id'] ?? 0);
    if ($myTeamId > 0 && $pid > 0) {
        $pChk = $pdo->prepare("
            SELECT player_id, first_name, last_name, salary, is_waiver_exempt
            FROM players
            WHERE player_id = ? AND team_id = ? AND UPPER(TRIM(roster_status)) = 'PRO' AND COALESCE(is_retired, FALSE) = FALSE
        ");
        $pChk->execute(array($pid, $myTeamId));
        $pl = $pChk->fetch(PDO::FETCH_ASSOC);

        $dupChk = $pdo->prepare("SELECT 1 FROM waiver_wire WHERE player_id = ? AND status = 'ACTIVE'");
        $dupChk->execute(array($pid));

        if (!$pl) {
            $err = "Player not found on your active NHL roster.";
        } elseif ($dupChk->fetchColumn()) {
            $err = "That player is already on the active waiver wire.";
        } elseif (!empty($pl['is_waiver_exempt'])) {
            $pdo->prepare("UPDATE players SET roster_status = 'AHL', line_number = 99 WHERE player_id = ?")->execute(array($pid));
            $msg = "{$pl['first_name']} {$pl['last_name']} is Waiver Exempt and was assigned directly to your AHL affiliate!";
        } else {
            $pdo->prepare("
                INSERT INTO waiver_wire (player_id, waiving_team_id, placed_at, expires_at, status)
                VALUES (?, ?, NOW(), NOW() + INTERVAL '24 hours', 'ACTIVE')
            ")->execute(array($pid, $myTeamId));

            $cType = ((int)$pl['salary'] >= 2000000) ? '1-Way (counts on cap even if cleared)' : '2-Way (cap relief if cleared)';
            $msg = "{$pl['first_name']} {$pl['last_name']} ({$cType}) placed on the Waiver Wire for 24 hours!";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_claim') {
    $wid = (int)($_POST['waiver_id'] ?? 0);
    $wChk = $pdo->prepare("SELECT * FROM waiver_wire WHERE waiver_id = ? AND status = 'ACTIVE' AND expires_at > NOW()");
    $wChk->execute(array($wid));
    $wRow = $wChk->fetch(PDO::FETCH_ASSOC);

    if ($wRow && $myTeamId > 0 && (int)$wRow['waiving_team_id'] !== $myTeamId) {
        $pdo->prepare("
            INSERT INTO waiver_claims (waiver_id, claiming_team_id)
            VALUES (?, ?)
            ON CONFLICT (waiver_id, claiming_team_id) DO NOTHING
        ")->execute(array($wid, $myTeamId));
        $msg = "Waiver claim submitted! If your team holds highest priority when the window closes, you will win the player and move to #32.";
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'withdraw_claim') {
    $wid = (int)($_POST['waiver_id'] ?? 0);
    $pdo->prepare("DELETE FROM waiver_claims WHERE waiver_id = ? AND claiming_team_id = ?")->execute(array($wid, $myTeamId));
    $msg = "Waiver claim withdrawn.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isCommish) {
    if (($_POST['action'] ?? '') === 'force_process') {
        $cnt = resolveWaivers($pdo, true);
        $msg = "Commissioner processed {$cnt} active waiver item(s) immediately!";
    } elseif (($_POST['action'] ?? '') === 'reset_priority_standings') {
        $pdo->exec("
            WITH team_standings AS (
                SELECT
                    t.team_id,
                    COALESCE(SUM(CASE
                        WHEN (g.home_team_id = t.team_id AND g.home_score > g.away_score)
                          OR (g.away_team_id = t.team_id AND g.away_score > g.home_score) THEN 2
                        ELSE 0
                    END), 0) AS pts,
                    COALESCE(SUM(CASE
                        WHEN (g.home_team_id = t.team_id AND g.home_score > g.away_score)
                          OR (g.away_team_id = t.team_id AND g.away_score > g.home_score) THEN 1
                        ELSE 0
                    END), 0) AS wins
                FROM teams t
                LEFT JOIN games g ON (g.home_team_id = t.team_id OR g.away_team_id = t.team_id) AND g.status = 'FINAL'
                GROUP BY t.team_id
            ),
            ranked AS (
                SELECT team_id, ROW_NUMBER() OVER (ORDER BY pts ASC, wins ASC, team_id ASC) AS rk
                FROM team_standings
            )
            UPDATE teams t
            SET waiver_priority = r.rk
            FROM ranked r
            WHERE t.team_id = r.team_id
        ");
        $msg = "Waiver priority order reset to current reverse standings!";
    }
}

$activeWaivers = $pdo->query("
    SELECT ww.*, p.first_name, p.last_name, p.position, p.overall_rating, p.age, p.salary, p.contract_years,
           t.name AS waiving_team, t.abbreviation AS waiving_abbr,
           (SELECT COUNT(*) FROM waiver_claims wc WHERE wc.waiver_id = ww.waiver_id) AS claim_count
    FROM waiver_wire ww
    JOIN players p ON ww.player_id = p.player_id
    JOIN teams t ON ww.waiving_team_id = t.team_id
    WHERE ww.status = 'ACTIVE'
    ORDER BY ww.expires_at ASC
")->fetchAll(PDO::FETCH_ASSOC);

$myClaimIds = array();
if ($myTeamId > 0) {
    $mcStmt = $pdo->prepare("SELECT waiver_id FROM waiver_claims WHERE claiming_team_id = ?");
    $mcStmt->execute(array($myTeamId));
    $myClaimIds = array_map('intval', $mcStmt->fetchAll(PDO::FETCH_COLUMN));
}

$myProPlayers = $myTeamId > 0 ? $pdo->query("
    SELECT player_id, first_name, last_name, position, overall_rating, salary, contract_years, is_waiver_exempt
    FROM players
    WHERE team_id = {$myTeamId}
      AND UPPER(TRIM(roster_status)) = 'PRO'
      AND COALESCE(is_retired, FALSE) = FALSE
    ORDER BY overall_rating DESC, last_name ASC
")->fetchAll(PDO::FETCH_ASSOC) : array();

$priorityList = $pdo->query("
    SELECT t.team_id, t.name, t.abbreviation, t.waiver_priority,
           COALESCE(cap.active_cap_hit, 0) AS active_cap_hit,
           COALESCE(cap.buried_one_way_cap, 0) AS buried_one_way_cap
    FROM teams t
    LEFT JOIN team_cap_summary cap ON t.team_id = cap.team_id
    ORDER BY t.waiver_priority ASC, t.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$recentWaivers = $pdo->query("
    SELECT ww.*, p.first_name, p.last_name, p.position, p.overall_rating, p.salary,
           t1.abbreviation AS waiving_abbr, t2.abbreviation AS claimed_abbr, t2.name AS claimed_team
    FROM waiver_wire ww
    JOIN players p ON ww.player_id = p.player_id
    JOIN teams t1 ON ww.waiving_team_id = t1.team_id
    LEFT JOIN teams t2 ON ww.claimed_by_team_id = t2.team_id
    WHERE ww.status IN ('CLAIMED', 'CLEARED')
    ORDER BY ww.resolved_at DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>HFNHL Waiver Wire</title>
    <style>
        body { font-family: Arial, sans-serif; background: #0f172a; color: #f8fafc; padding: 20px; max-width: 1320px; margin: auto; }
        nav { background: #020617; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; display: flex; gap: 20px; border: 1px solid #334155; align-items: center; }
        nav a { color: #38bdf8; text-decoration: none; font-weight: bold; }
        h1, h2, h3 { color: #38bdf8; }
        .layout-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 22px; align-items: start; }
        .card { background: #1e293b; padding: 20px; border-radius: 8px; border: 1px solid #334155; margin-bottom: 22px; }
        select { width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #475569; background: #0f172a; color: #fff; box-sizing: border-box; }
        table { width: 100%; border-collapse: collapse; background: #1e293b; margin-top: 10px; }
        th, td { padding: 9px 10px; text-align: left; border-bottom: 1px solid #334155; vertical-align: middle; font-size: 0.92em; }
        th { background: #020617; color: #38bdf8; }
        .btn { background: #0284c7; color: #fff; border: none; padding: 8px 14px; border-radius: 6px; font-weight: bold; cursor: pointer; font-size: 13px; }
        .btn-green { background: #16a34a; }
        .btn-red { background: #dc2626; }
        .btn-amber { background: #d97706; }
        .badge-1way { background: #b45309; color: #fef3c7; padding: 2px 7px; border-radius: 4px; font-size: 11px; font-weight: bold; }
        .badge-2way { background: #1e3a8a; color: #dbeafe; padding: 2px 7px; border-radius: 4px; font-size: 11px; font-weight: bold; }
        .badge-claimed { background: #15803d; color: #dcfce7; padding: 2px 7px; border-radius: 4px; font-size: 11px; font-weight: bold; }
        .badge-cleared { background: #334155; color: #cbd5e1; padding: 2px 7px; border-radius: 4px; font-size: 11px; font-weight: bold; }
        .alert { background: #14532d; color: #86efac; padding: 12px; border-radius: 6px; border: 1px solid #22c55e; margin-bottom: 16px; font-weight: bold; }
        .alert-err { background: #7f1d1d; color: #fecaca; padding: 12px; border-radius: 6px; border: 1px solid #ef4444; margin-bottom: 16px; font-weight: bold; }
        .my-row { background: #172554; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>

<h1>HFNHL Waiver Wire &amp; Priority Order</h1>
<p style="color:#94a3b8; margin-top:0;">
    Contracts <strong>&ge; $2,000,000</strong> are <strong>1-Way</strong> (count against NHL cap even if cleared to AHL).
    Contracts <strong>&lt; $2,000,000</strong> are <strong>2-Way</strong> (cap relief when assigned to AHL).
    Winning a waiver claim moves your team to <strong>#32 (Bottom)</strong> of the priority order.
</p>

<?php if ($msg): ?><div class="alert"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert-err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="layout-grid">
    <div>
        <?php if ($myTeamId > 0): ?>
            <div class="card">
                <h2 style="margin-top:0;">Assign NHL Player to AHL / Place on Waivers</h2>
                <p style="color:#94a3b8; margin-top:0; font-size:0.9em;">
                    <strong>Waiver Exempt</strong> players move straight to your AHL roster immediately.
                    <strong>Waiver Eligible</strong> players enter the 24-hour Waiver Wire below.
                </p>
                <form method="post" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                    <input type="hidden" name="action" value="place_waivers">
                    <select name="player_id" required style="flex:1; min-width:300px;">
                        <option value="">-- Select NHL Roster Player --</option>
                        <?php foreach ($myProPlayers as $mp):
                            $isEx   = !empty($mp['is_waiver_exempt']);
                            $cTag   = ((int)$mp['salary'] >= 2000000) ? '1-Way' : '2-Way';
                            $exText = $isEx ? 'WAIVER EXEMPT -> Direct to AHL' : 'REQUIRES WAIVERS (24h)';
                            ?>
                            <option value="<?= (int)$mp['player_id'] ?>">
                                <?= htmlspecialchars("{$mp['last_name']}, {$mp['first_name']} ({$mp['position']} - {$mp['overall_rating']} OVR | $" . number_format((int)$mp['salary']) . " {$cTag} | {$exText})") ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-amber">Assign / Waive Player</button>
                </form>
            </div>
        <?php endif; ?>

        <div class="card">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                <h2 style="margin:0;">Active Waiver Wire (<?= count($activeWaivers) ?>)</h2>
                <?php if ($isCommish && !empty($activeWaivers)): ?>
                    <form method="post">
                        <input type="hidden" name="action" value="force_process">
                        <button type="submit" class="btn btn-amber" onclick="return confirm('Process all active waivers right now and award claims?');">Commish: Process Waivers Now</button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if (empty($activeWaivers)): ?>
                <p style="color:#94a3b8; margin-top:12px;">No players are currently on the waiver wire.</p>
            <?php else: ?>
                <table>
                    <tr>
                        <th>Player</th>
                        <th>Waived By</th>
                        <th>Contract &amp; Cap Rule</th>
                        <th>Clears / Expires</th>
                        <th>Action</th>
                    </tr>
                    <?php foreach ($activeWaivers as $w):
                        $wid       = (int)$w['waiver_id'];
                        $isOneWay  = ((int)$w['salary'] >= 2000000);
                        $isMyWaive = ((int)$w['waiving_team_id'] === $myTeamId);
                        $hasClaim  = in_array($wid, $myClaimIds);
                        $secsLeft  = max(0, strtotime($w['expires_at']) - time());
                        $hrsLeft   = floor($secsLeft / 3600);
                        $minsLeft  = floor(($secsLeft % 3600) / 60);
                        ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars("{$w['first_name']} {$w['last_name']}") ?></strong><br>
                                <small style="color:#94a3b8;"><?= htmlspecialchars($w['position']) ?> &bull; <?= (int)$w['overall_rating'] ?> OVR &bull; Age <?= (int)($w['age'] ?? 0) ?></small>
                            </td>
                            <td><strong><?= htmlspecialchars($w['waiving_abbr']) ?></strong></td>
                            <td>
                                $<?= number_format((int)$w['salary']) ?> (<?= (int)$w['contract_years'] ?>y)<br>
                                <?php if ($isOneWay): ?>
                                    <span class="badge-1way">1-WAY (Counts on Cap)</span>
                                <?php else: ?>
                                    <span class="badge-2way">2-WAY (AHL Cap Relief)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= $hrsLeft ?>h <?= $minsLeft ?>m</strong> left<br>
                                <small style="color:#94a3b8;"><?= date('M j, g:i A', strtotime($w['expires_at'])) ?></small>
                            </td>
                            <td>
                                <?php if ($isMyWaive): ?>
                                    <span style="color:#94a3b8; font-size:0.85em;">Your Waived Player</span>
                                <?php elseif ($myTeamId > 0): ?>
                                    <form method="post">
                                        <input type="hidden" name="waiver_id" value="<?= $wid ?>">
                                        <?php if ($hasClaim): ?>
                                            <button type="submit" name="action" value="withdraw_claim" class="btn btn-red">Withdraw Claim</button>
                                        <?php else: ?>
                                            <button type="submit" name="action" value="submit_claim" class="btn btn-green">Submit Claim</button>
                                        <?php endif; ?>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2 style="margin-top:0;">Recent Waiver Transactions</h2>
            <?php if (empty($recentWaivers)): ?>
                <p style="color:#94a3b8;">No completed waiver transactions yet.</p>
            <?php else: ?>
            <table>
                <tr>
                    <th>Date</th>
                    <th>Player</th>
                    <th>Waived By</th>
                    <th>Result</th>
                </tr>
                <?php foreach ($recentWaivers as $rw): ?>
                <tr>
                <tr>
                    <td style="color:#94a3b8;"><?= date('M j', strtotime($rw['resolved_at'])) ?></td>
                    <td>
                        <strong><?= htmlspecialchars("{$rw['first_name']} {$rw['last_name']}") ?></strong>
                        (<?= htmlspecialchars($rw['position']) ?> &bull; <?= (int)$rw['overall_rating'] ?> OVR)
                    </td>
                    <td><?= htmlspecialchars($rw['waiving_abbr']) ?></td>
                    <td>
                        <?php if ($rw['status'] === 'CLAIMED'): ?>
                            <span class="badge-claimed">CLAIMED</span> by <strong><?= htmlspecialchars($rw['claimed_abbr']) ?></strong>
                        <?php else: ?>
                            <span class="badge-cleared">CLEARED</span> to <?= htmlspecialchars($rw['waiving_abbr']) ?> AHL
                            <?= ((int)$rw['salary'] >= 2000000) ? '<small style="color:#fbbf24;">(1-Way Cap Counted)</small>' : '' ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:8px; margin-bottom:8px;">
            <h2 style="margin:0; font-size:1.2em;">Waiver Priority Order</h2>
            <?php if ($isCommish): ?>
                <form method="post">
                    <input type="hidden" name="action" value="reset_priority_standings">
                    <button type="submit" class="btn btn-amber" style="padding:5px 9px; font-size:11px;" onclick="return confirm('Reset waiver priority order to current reverse standings?');">Sync Standings</button>
                </form>
            <?php endif; ?>
        </div>
        <p style="color:#94a3b8; font-size:0.84em; margin-top:0;">
            #1 holds top claim priority. Winning a claim moves a team to #32.
        </p>

        <table style="margin-top:6px;">
            <tr>
                <th style="width:42px;">#</th>
                <th>Team</th>
                <th style="text-align:right;">Active Cap Hit</th>
            </tr>
            <?php foreach ($priorityList as $pl):
                $isMine = ((int)$pl['team_id'] === $myTeamId);
                ?>
                <tr class="<?= $isMine ? 'my-row' : '' ?>">
                    <td><strong><?= (int)$pl['waiver_priority'] ?></strong></td>
                    <td>
                        <strong><?= htmlspecialchars($pl['abbreviation']) ?></strong>
                        <?php if ((int)$pl['buried_one_way_cap'] > 0): ?>
                            <br><small style="color:#fbbf24;" title="Includes 1-way >=$2M players in AHL">+$<?= number_format((int)$pl['buried_one_way_cap']) ?> AHL 1-Way</small>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right; font-size:0.88em;">
                        $<?= number_format((int)$pl['active_cap_hit']) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
</body>
</html>



