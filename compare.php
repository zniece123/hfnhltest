<?php
require 'db.php';
/** @var PDO $pdo */

$p1 = isset($_GET['p1']) ? (int)$_GET['p1'] : 0;
$p2 = isset($_GET['p2']) ? (int)$_GET['p2'] : 0;
$p3 = isset($_GET['p3']) ? (int)$_GET['p3'] : 0;

$allPlayers = $pdo->query("
    SELECT p.player_id, p.first_name, p.last_name, p.position, p.overall_rating, t.abbreviation
    FROM players p
    LEFT JOIN teams t ON p.team_id = t.team_id
    WHERE COALESCE(p.is_retired, FALSE) = FALSE
    ORDER BY p.overall_rating DESC, p.last_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$selectedIds = array_values(array_filter(array($p1, $p2, $p3), fn($id) => $id > 0));
$compared    = array();

if (!empty($selectedIds)) {
    $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
    $stmt = $pdo->prepare("
        SELECT p.*, t.name AS team_name, t.abbreviation,
               COALESCE(st.gp, 0) AS gp,
               COALESCE(st.g, 0) AS g,
               COALESCE(st.a, 0) AS a,
               COALESCE(st.pts, 0) AS pts,
               COALESCE(st.sog, 0) AS sog,
               COALESCE(st.hit, 0) AS hit,
               COALESCE(st.blk, 0) AS blk,
               COALESCE(st.pim, 0) AS pim,
               COALESCE(st.sv, 0) AS sv,
               COALESCE(st.ga, 0) AS ga,
               COALESCE(st.toi_sec, 0) AS toi_sec
        FROM players p
        LEFT JOIN teams t ON p.team_id = t.team_id
        LEFT JOIN (
            SELECT player_id,
                   COUNT(game_id) AS gp,
                   SUM(goals) AS g,
                   SUM(assists) AS a,
                   SUM(goals + assists) AS pts,
                   SUM(shots) AS sog,
                   SUM(hits) AS hit,
                   SUM(blocks) AS blk,
                   SUM(pim) AS pim,
                   SUM(saves) AS sv,
                   SUM(goals_against) AS ga,
                   SUM(toi_seconds) AS toi_sec
            FROM player_game_stats
            WHERE toi_seconds > 0
            GROUP BY player_id
        ) st ON st.player_id = p.player_id
        WHERE p.player_id IN ($placeholders)
    ");
    $stmt->execute($selectedIds);
    $byId = array();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sa = (int)$row['sv'] + (int)$row['ga'];
        $row['sv_pct'] = $sa > 0 ? round((int)$row['sv'] / $sa, 3) : 0;
        $row['gaa']    = (int)$row['toi_sec'] > 0 ? round(((int)$row['ga'] * 3600) / (int)$row['toi_sec'], 2) : 0;
        $byId[(int)$row['player_id']] = $row;
    }
    foreach ($selectedIds as $sid) {
        if (isset($byId[$sid])) $compared[] = $byId[$sid];
    }
}

function renderCompareRow($label, $players, $key, $mode = 'max', $format = 'int') {
    $vals = array();
    foreach ($players as $idx => $pl) {
        $vals[$idx] = (float)($pl[$key] ?? 0);
    }
    $best = null;
    if (count($players) > 1 && $mode !== 'none') {
        $best = ($mode === 'min') ? min($vals) : max($vals);
        if ($best == 0 && $mode === 'max') $best = null;
    }

    echo "<tr><td class=\"attr-label\">" . htmlspecialchars($label) . "</td>";
    foreach ($players as $idx => $pl) {
        $raw = $vals[$idx];
        $isBest = ($best !== null && abs($raw - $best) < 0.0001);
        $cls = $isBest ? ' class="best-val"' : '';

        if ($format === 'money') {
            $disp = '$' . number_format((int)$raw);
        } elseif ($format === 'sv') {
            $disp = $raw > 0 ? ltrim(number_format($raw, 3), '0') : '.000';
        } elseif ($format === 'dec2') {
            $disp = number_format($raw, 2);
        } else {
            $disp = (int)$raw;
        }
        echo "<td$cls>$disp</td>";
    }
    echo "</tr>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Compare Players - HFNHL</title>
    <style>
        body { font-family: Arial, sans-serif; background: #0f172a; color: #f8fafc; padding: 14px 20px; }
        h1 { color: #38bdf8; margin-bottom: 6px; }
        .subtitle { color: #94a3b8; font-size: 0.92em; margin-bottom: 18px; }
        .picker-bar { background: #1e293b; padding: 14px 18px; border-radius: 8px; border: 1px solid #334155; margin-bottom: 22px; display: flex; gap: 14px; align-items: end; flex-wrap: wrap; }
        .picker-col { flex: 1; min-width: 220px; }
        .picker-col label { display: block; color: #94a3b8; font-weight: bold; font-size: 0.84em; margin-bottom: 5px; }
        .picker-col select { width: 100%; background: #0f172a; color: #f8fafc; border: 1px solid #475569; padding: 8px 10px; border-radius: 6px; font-size: 0.92em; }
        .cmp-btn { background: #0284c7; color: #fff; border: none; padding: 9px 20px; border-radius: 6px; font-weight: bold; cursor: pointer; font-size: 0.95em; }
        .cmp-btn:hover { background: #0369a1; }
        table { width: 100%; border-collapse: collapse; background: #1e293b; border-radius: 8px; overflow: hidden; table-layout: fixed; }
        th, td { padding: 11px 14px; border-bottom: 1px solid #334155; text-align: center; font-size: 0.94em; }
        th { background: #020617; color: #38bdf8; vertical-align: middle; }
        td.attr-label { text-align: left; color: #cbd5e1; font-weight: bold; background: #162032; width: 210px; }
        tr.sec-hdr td { background: #020617; color: #fbbf24; text-align: left; font-weight: bold; font-size: 0.85em; text-transform: uppercase; letter-spacing: 0.6px; padding: 8px 14px; }
        td.best-val { color: #4ade80; font-weight: bold; background: rgba(34, 197, 94, 0.1); }
        .p-head { display: flex; flex-direction: column; align-items: center; gap: 5px; }
        .p-head img { width: 34px; height: 34px; object-fit: contain; }
        .p-head .pname { font-size: 1.08em; color: #f8fafc; }
        .p-head .pmeta { font-size: 0.8em; color: #94a3b8; font-weight: normal; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>

<h1>Compare Players</h1>
<div class="subtitle">Select up to 3 skaters or goalies to compare ratings, contracts, and current season stats side-by-side.</div>

<form method="GET" class="picker-bar">
    <?php foreach (array(1 => $p1, 2 => $p2, 3 => $p3) as $slot => $curVal): ?>
        <div class="picker-col">
            <label for="p<?= $slot ?>">Player <?= $slot ?><?= $slot === 3 ? ' (Optional)' : '' ?>:</label>
            <select name="p<?= $slot ?>" id="p<?= $slot ?>">
                <option value="0">-- Select Player --</option>
                <?php foreach ($allPlayers as $ap):
                    $ab = strtoupper(trim($ap['abbreviation'] ?? 'FA'));
                    $lbl = "{$ap['last_name']}, {$ap['first_name']} ({$ap['position']} - {$ap['overall_rating']} OVR | {$ab})";
                    ?>
                    <option value="<?= (int)$ap['player_id'] ?>" <?= (int)$ap['player_id'] === $curVal ? 'selected' : '' ?>>
                        <?= htmlspecialchars($lbl) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endforeach; ?>
    <button type="submit" class="cmp-btn">Compare</button>
</form>

<?php if (empty($compared)): ?>
    <div style="background:#1e293b;padding:24px;border-radius:8px;border:1px solid #334155;text-align:center;color:#94a3b8;">
        Pick two or three players above and click <strong>Compare</strong> to view a full side-by-side breakdown.
    </div>
<?php else:
$hasGoalie = false;
foreach ($compared as $cp) {
    if (strtoupper($cp['position']) === 'G') $hasGoalie = true;
}
?>
<table>
    <tr>
        <th style="text-align:left;width:210px;">Attribute</th>
        <?php foreach ($compared as $cp):
            $ab = strtoupper(trim($cp['abbreviation'] ?? ''));
            ?>
            <th>
                <div class="p-head">
                    <?php if ($ab): ?><img src="logos/<?= htmlspecialchars($ab) ?>.svg" alt=""><?php endif; ?>
                    <span class="pname"><?= htmlspecialchars(trim($cp['first_name'] . ' ' . $cp['last_name'])) ?></span>
                    <span class="pmeta"><?= htmlspecialchars($cp['position']) ?> &bull; <?= htmlspecialchars($ab ?: 'Free Agent') ?></span>
                </div>
            </th>
        <?php endforeach; ?>
    </tr>

    <tr class="sec-hdr"><td colspan="<?= count($compared) + 1 ?>">Bio &amp; Contract</td></tr>
    <?php renderCompareRow('Age', $compared, 'age', 'min'); ?>
    <?php renderCompareRow('Career NHL GP', $compared, 'nhl_games_played', 'max'); ?>
    <?php renderCompareRow('Salary (Cap Hit)', $compared, 'salary', 'min', 'money'); ?>
    <?php renderCompareRow('Contract Years Left', $compared, 'contract_years', 'none'); ?>

    <tr class="sec-hdr"><td colspan="<?= count($compared) + 1 ?>">Sim Ratings</td></tr>
    <?php renderCompareRow('Overall Rating (OVR)', $compared, 'overall_rating', 'max'); ?>
    <?php renderCompareRow('Skating (SK)', $compared, 'skating', 'max'); ?>
    <?php renderCompareRow('Shooting (SH)', $compared, 'shooting', 'max'); ?>
    <?php renderCompareRow('Passing (PA)', $compared, 'passing', 'max'); ?>
    <?php renderCompareRow('Defense (DF)', $compared, 'defense', 'max'); ?>
    <?php renderCompareRow('Physical (PH)', $compared, 'physical', 'max'); ?>
    <?php if ($hasGoalie) renderCompareRow('Goaltending (GO)', $compared, 'goaltending', 'max'); ?>

    <tr class="sec-hdr"><td colspan="<?= count($compared) + 1 ?>">Current Season Stats</td></tr>
    <?php renderCompareRow('Games Played (GP)', $compared, 'gp', 'max'); ?>
    <?php if ($hasGoalie): ?>
        <?php renderCompareRow('Saves (SV)', $compared, 'sv', 'max'); ?>
        <?php renderCompareRow('Goals Against (GA)', $compared, 'ga', 'min'); ?>
        <?php renderCompareRow('Save Percentage (SV%)', $compared, 'sv_pct', 'max', 'sv'); ?>
        <?php renderCompareRow('Goals Against Avg (GAA)', $compared, 'gaa', 'min', 'dec2'); ?>
    <?php else: ?>
        <?php renderCompareRow('Goals (G)', $compared, 'g', 'max'); ?>
        <?php renderCompareRow('Assists (A)', $compared, 'a', 'max'); ?>
        <?php renderCompareRow('Points (PTS)', $compared, 'pts', 'max'); ?>
        <?php renderCompareRow('Shots on Goal (SOG)', $compared, 'sog', 'max'); ?>
        <?php renderCompareRow('Hits (HIT)', $compared, 'hit', 'max'); ?>
        <?php renderCompareRow('Blocked Shots (BLK)', $compared, 'blk', 'max'); ?>
        <?php renderCompareRow('Penalty Minutes (PIM)', $compared, 'pim', 'none'); ?>
    <?php endif; ?>
</table>
<?php endif; ?>

</body>
</html>