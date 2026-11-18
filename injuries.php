<?php
require 'db.php';
/** @var PDO $pdo */

$teamFilter = isset($_GET['team']) && $_GET['team'] !== '' ? (int)$_GET['team'] : 0;
$posFilter  = $_GET['pos'] ?? 'ALL';

$teams = $pdo->query("SELECT team_id, name, abbreviation FROM teams ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$where  = ["COALESCE(p.is_injured, FALSE) = TRUE", "COALESCE(p.is_retired, FALSE) = FALSE"];
$params = [];

if ($teamFilter > 0) {
    $where[]  = "p.team_id = ?";
    $params[] = $teamFilter;
}

if ($posFilter === 'G') {
    $where[] = "p.position = 'G'";
} elseif ($posFilter === 'D') {
    $where[] = "p.position = 'D'";
} elseif ($posFilter === 'F') {
    $where[] = "p.position IN ('C', 'LW', 'RW')";
}

$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT p.player_id, p.first_name, p.last_name, p.position, p.overall_rating,
           p.roster_status, p.injury_days_remaining, p.salary, p.contract_years,
           t.team_id, t.name AS team_name, t.abbreviation
    FROM players p
    LEFT JOIN teams t ON p.team_id = t.team_id
    WHERE $whereSql
    ORDER BY p.injury_days_remaining DESC, p.overall_rating DESC, p.last_name ASC
");
$stmt->execute($params);
$injured = $stmt->fetchAll(PDO::FETCH_ASSOC);

$teamCounts = $pdo->query("
    SELECT t.team_id, t.abbreviation, t.name, COUNT(p.player_id) AS inj_count
    FROM teams t
    JOIN players p ON p.team_id = t.team_id
    WHERE COALESCE(p.is_injured, FALSE) = TRUE AND COALESCE(p.is_retired, FALSE) = FALSE
    GROUP BY t.team_id, t.abbreviation, t.name
    ORDER BY inj_count DESC, t.abbreviation ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>League Injury Report - HFNHL</title>
    <style>
        body { font-family: Arial, sans-serif; background: #0f172a; color: #f8fafc; padding: 14px 20px; }
        h1 { color: #38bdf8; margin-bottom: 6px; }
        .subtitle { color: #94a3b8; font-size: 0.92em; margin-bottom: 18px; }
        .summary-strip { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 18px; }
        .team-chip { background: #1e293b; border: 1px solid #334155; border-radius: 6px; padding: 6px 10px; display: inline-flex; align-items: center; gap: 7px; text-decoration: none; color: #f8fafc; font-size: 0.85em; font-weight: bold; }
        .team-chip:hover, .team-chip.active { border-color: #38bdf8; background: #0284c7; color: #fff; }
        .team-chip img { width: 20px; height: 20px; object-fit: contain; }
        .count-badge { background: #7f1d1d; color: #fecaca; padding: 1px 6px; border-radius: 10px; font-size: 0.82em; }
        .filter-bar { background: #1e293b; padding: 12px 16px; border-radius: 8px; border: 1px solid #334155; margin-bottom: 16px; display: flex; gap: 16px; align-items: center; flex-wrap: wrap; }
        .filter-bar label { color: #94a3b8; font-weight: bold; font-size: 0.88em; }
        .filter-bar select { background: #0f172a; color: #f8fafc; border: 1px solid #334155; padding: 6px 10px; border-radius: 5px; }
        table { width: 100%; border-collapse: collapse; background: #1e293b; border-radius: 8px; overflow: hidden; }
        th, td { padding: 10px 12px; border-bottom: 1px solid #334155; font-size: 0.92em; text-align: left; }
        th { background: #020617; color: #38bdf8; }
        th.num, td.num { text-align: center; }
        tr:hover { background: #263348; }
        .team-link { display: inline-flex; align-items: center; gap: 8px; color: #f8fafc; text-decoration: none; font-weight: bold; }
        .team-link:hover { color: #38bdf8; }
        .team-link img { width: 24px; height: 24px; object-fit: contain; }
        .inj-pill { background: #7f1d1d; color: #fecaca; border: 1px solid #ef4444; padding: 3px 9px; border-radius: 5px; font-weight: bold; font-size: 0.84em; }
        .status-tag { background: #0f172a; color: #94a3b8; border: 1px solid #334155; padding: 2px 7px; border-radius: 4px; font-size: 0.8em; font-weight: bold; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>

<h1>HFNHL Injury Report (<?= count($injured) ?>)</h1>
<div class="subtitle">All active injured players across the league, sorted by remaining recovery days.</div>

<?php if (!empty($teamCounts)): ?>
    <div class="summary-strip">
        <a href="injuries.php" class="team-chip <?= $teamFilter === 0 ? 'active' : '' ?>">All Teams</a>
        <?php foreach ($teamCounts as $tc):
            $ab = strtoupper(trim($tc['abbreviation']));
            ?>
            <a href="injuries.php?team=<?= (int)$tc['team_id'] ?>" class="team-chip <?= $teamFilter === (int)$tc['team_id'] ? 'active' : '' ?>">
                <img src="logos/<?= htmlspecialchars($ab) ?>.svg" alt="">
                <?= htmlspecialchars($ab) ?>
                <span class="count-badge"><?= (int)$tc['inj_count'] ?></span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<form method="GET" class="filter-bar">
    <div>
        <label for="team">Team:</label>
        <select name="team" id="team" onchange="this.form.submit()">
            <option value="">All Teams</option>
            <?php foreach ($teams as $t): ?>
                <option value="<?= $t['team_id'] ?>" <?= $teamFilter === (int)$t['team_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($t['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="pos">Position:</label>
        <select name="pos" id="pos" onchange="this.form.submit()">
            <option value="ALL" <?= $posFilter === 'ALL' ? 'selected' : '' ?>>All Positions</option>
            <option value="F" <?= $posFilter === 'F' ? 'selected' : '' ?>>Forwards</option>
            <option value="D" <?= $posFilter === 'D' ? 'selected' : '' ?>>Defense</option>
            <option value="G" <?= $posFilter === 'G' ? 'selected' : '' ?>>Goalies</option>
        </select>
    </div>
</form>

<table>
    <tr>
        <th>Team</th>
        <th>Player</th>
        <th class="num">Pos</th>
        <th class="num">OVR</th>
        <th class="num">Roster</th>
        <th>Contract</th>
        <th class="num">Days Remaining</th>
    </tr>
    <?php if (empty($injured)): ?>
        <tr><td colspan="7" style="text-align:center;color:#94a3b8;padding:18px;">No injured players matching your filter.</td></tr>
    <?php else: foreach ($injured as $p):
        $ab   = strtoupper(trim($p['abbreviation'] ?? ''));
        $days = (int)($p['injury_days_remaining'] ?? 0);
        ?>
        <tr>
            <td>
                <?php if ($ab): ?>
                    <a class="team-link" href="team.php?id=<?= (int)$p['team_id'] ?>">
                        <img src="logos/<?= htmlspecialchars($ab) ?>.svg" alt="">
                        <?= htmlspecialchars($p['team_name']) ?>
                    </a>
                <?php else: ?>
                    <span style="color:#94a3b8;">Free Agent</span>
                <?php endif; ?>
            </td>
            <td><strong><?= htmlspecialchars(trim($p['first_name'] . ' ' . $p['last_name'])) ?></strong></td>
            <td class="num"><?= htmlspecialchars($p['position']) ?></td>
            <td class="num"><strong style="color:#38bdf8;"><?= (int)$p['overall_rating'] ?></strong></td>
            <td class="num"><span class="status-tag"><?= htmlspecialchars(strtoupper($p['roster_status'] ?? 'PRO')) ?></span></td>
            <td>$<?= number_format((int)$p['salary']) ?> (<?= (int)$p['contract_years'] ?>y)</td>
            <td class="num"><span class="inj-pill"><?= $days ?> <?= $days === 1 ? 'Day' : 'Days' ?></span></td>
        </tr>
    <?php endforeach; endif; ?>
</table>

</body>
</html>

