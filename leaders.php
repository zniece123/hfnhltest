<?php
require 'db.php';

$tab    = $_GET['tab'] ?? 'skaters';
$pos    = $_GET['pos'] ?? 'ALL';
$teamId = isset($_GET['team']) && $_GET['team'] !== '' ? (int)$_GET['team'] : 0;
$sort   = $_GET['sort'] ?? ($tab === 'goalies' ? 'sv_pct' : 'pts');
$dir    = (isset($_GET['dir']) && strtolower($_GET['dir']) === 'asc') ? 'ASC' : 'DESC';

$teams = $pdo->query("SELECT team_id, name, abbreviation FROM teams ORDER BY name ASC")->fetchAll();

function getTopLeaders($pdo, $selectExpr, $isGoalie, $orderSql, $havingSql = '') {
    $op = $isGoalie ? "=" : "<>";
    $sql = "
        SELECT p.player_id, p.first_name, p.last_name, p.position, t.abbreviation, $selectExpr AS val
        FROM player_game_stats pgs
        JOIN players p ON pgs.player_id = p.player_id
        LEFT JOIN teams t ON p.team_id = t.team_id
        WHERE pgs.toi_seconds > 0 AND p.position $op 'G'
        GROUP BY p.player_id, p.first_name, p.last_name, p.position, t.abbreviation
        $havingSql
        ORDER BY $orderSql
        LIMIT 5
    ";
    return $pdo->query($sql)->fetchAll();
}

$topPoints      = getTopLeaders($pdo, "SUM(pgs.goals + pgs.assists)", false, "val DESC, SUM(pgs.goals) DESC");
$topGoals       = getTopLeaders($pdo, "SUM(pgs.goals)", false, "val DESC, SUM(pgs.goals + pgs.assists) DESC");
$topAssists     = getTopLeaders($pdo, "SUM(pgs.assists)", false, "val DESC, SUM(pgs.goals + pgs.assists) DESC");
$topShots       = getTopLeaders($pdo, "SUM(pgs.shots)", false, "val DESC");

$topGoalieSvPct = getTopLeaders($pdo, "ROUND(SUM(pgs.saves)::numeric / NULLIF(SUM(pgs.saves + pgs.goals_against), 0), 3)", true, "val DESC, SUM(pgs.saves) DESC", "HAVING SUM(pgs.saves + pgs.goals_against) > 0");
$topGoalieGaa   = getTopLeaders($pdo, "ROUND((SUM(pgs.goals_against)::numeric * 3600) / NULLIF(SUM(pgs.toi_seconds), 0), 2)", true, "val ASC, SUM(pgs.toi_seconds) DESC");
$topGoalieSaves = getTopLeaders($pdo, "SUM(pgs.saves)", true, "val DESC");
$topGoalieSo    = getTopLeaders($pdo, "SUM(CASE WHEN pgs.goals_against = 0 AND pgs.toi_seconds >= 3000 THEN 1 ELSE 0 END)", true, "val DESC, SUM(pgs.saves) DESC");

$params = [];
$where  = ["pgs.toi_seconds > 0"];

if ($tab === 'goalies') {
    $where[] = "p.position = 'G'";
} else {
    $where[] = "p.position <> 'G'";
    if ($pos === 'F') {
        $where[] = "p.position IN ('C', 'LW', 'RW')";
    } elseif ($pos !== 'ALL') {
        $where[] = "p.position = ?";
        $params[] = $pos;
    }
}
if ($teamId > 0) {
    $where[] = "p.team_id = ?";
    $params[] = $teamId;
}
$whereSql = implode(' AND ', $where);

if ($tab === 'goalies') {
    $sorts = ['name'=>'p.last_name','gp'=>'gp','sa'=>'sa','sv'=>'sv','ga'=>'ga','sv_pct'=>'sv_pct','gaa'=>'gaa','so'=>'so','toi'=>'total_toi'];
    $orderCol = $sorts[$sort] ?? 'sv_pct';
    $sql = "
        SELECT p.player_id, p.first_name, p.last_name, p.position, t.abbreviation,
               COUNT(pgs.game_id) AS gp, SUM(pgs.saves + pgs.goals_against) AS sa, SUM(pgs.saves) AS sv, SUM(pgs.goals_against) AS ga,
               COALESCE(ROUND(SUM(pgs.saves)::numeric / NULLIF(SUM(pgs.saves + pgs.goals_against), 0), 3), 0) AS sv_pct,
               COALESCE(ROUND((SUM(pgs.goals_against)::numeric * 3600) / NULLIF(SUM(pgs.toi_seconds), 0), 2), 0) AS gaa,
               SUM(CASE WHEN pgs.goals_against = 0 AND pgs.toi_seconds >= 3000 THEN 1 ELSE 0 END) AS so,
               SUM(pgs.toi_seconds) AS total_toi
        FROM player_game_stats pgs
        JOIN players p ON pgs.player_id = p.player_id
        LEFT JOIN teams t ON p.team_id = t.team_id
        WHERE $whereSql
        GROUP BY p.player_id, p.first_name, p.last_name, p.position, t.abbreviation
        ORDER BY $orderCol $dir, gp DESC
    ";
} else {
    $sorts = ['name'=>'p.last_name','gp'=>'gp','g'=>'g','a'=>'a','pts'=>'pts','ppg'=>'ppg','sog'=>'sog','sh_pct'=>'sh_pct','hit'=>'hit','blk'=>'blk','pim'=>'pim','toi'=>'avg_toi'];
    $orderCol = $sorts[$sort] ?? 'pts';
    $sql = "
        SELECT p.player_id, p.first_name, p.last_name, p.position, t.abbreviation,
               COUNT(pgs.game_id) AS gp, SUM(pgs.goals) AS g, SUM(pgs.assists) AS a, SUM(pgs.goals + pgs.assists) AS pts,
               ROUND(SUM(pgs.goals + pgs.assists)::numeric / NULLIF(COUNT(pgs.game_id), 0), 2) AS ppg,
               SUM(pgs.shots) AS sog,
               COALESCE(ROUND((SUM(pgs.goals)::numeric / NULLIF(SUM(pgs.shots), 0)) * 100, 1), 0) AS sh_pct,
               SUM(pgs.hits) AS hit, SUM(pgs.blocks) AS blk, SUM(pgs.pim) AS pim,
               ROUND(SUM(pgs.toi_seconds)::numeric / NULLIF(COUNT(pgs.game_id), 0)) AS avg_toi
        FROM player_game_stats pgs
        JOIN players p ON pgs.player_id = p.player_id
        LEFT JOIN teams t ON p.team_id = t.team_id
        WHERE $whereSql
        GROUP BY p.player_id, p.first_name, p.last_name, p.position, t.abbreviation
        ORDER BY $orderCol $dir, g DESC, gp ASC
    ";
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

function sortLink($col, $label, $tab, $pos, $team, $sort, $dir, $def = 'DESC') {
    $next = ($sort === $col && $dir === 'DESC') ? 'ASC' : (($sort === $col && $dir === 'ASC') ? 'DESC' : $def);
    $arrow = $sort === $col ? ($dir === 'ASC' ? ' &#9650;' : ' &#9660;') : '';
    return "<a href=\"?tab=$tab&pos=$pos&team=$team&sort=$col&dir=$next\" class=\"sort-link\">$label$arrow</a>";
}
function formatToi($sec) { return sprintf('%02d:%02d', floor((int)$sec / 60), (int)$sec % 60); }
function renderCard($title, $list, $dec = null) {
    echo "<div class=\"card\"><h3>" . htmlspecialchars($title) . "</h3>";
    if (!$list) { echo "<div class=\"muted\">No stats yet.</div></div>"; return; }
    echo "<ol>";
    foreach ($list as $i => $p) {
        $ab = strtoupper($p['abbreviation'] ?? '');
        $logo = "logos/" . strtolower($ab) . ".svg";
        $v = $dec !== null ? number_format((float)$p['val'], $dec) : $p['val'];
        if ($dec === 3 && str_starts_with($v, '0.')) $v = substr($v, 1);
        $cls = $i === 0 ? ' class="top"' : '';
        echo "<li$cls><div class=\"pinfo\"><span>" . ($i+1) . ".</span>";
        if ($ab && file_exists($logo)) echo "<img src=\"$logo\" class=\"logo\">";
        echo "<div><div class=\"pname\">" . htmlspecialchars($p['first_name'] . ' ' . $p['last_name']) . "</div><div class=\"pmeta\">$ab &bull; {$p['position']}</div></div></div><b>$v</b></li>";
    }
    echo "</ol></div>";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>League Leaders &amp; Stats - HFNHL</title>
    <style>
        body { font-family: Arial, sans-serif; background: #0f172a; color: #f8fafc; padding: 20px; max-width: 1200px; margin: auto; }
        nav { background: #020617; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; display: flex; gap: 20px; border: 1px solid #334155; }
        nav a { color: #38bdf8; text-decoration: none; font-weight: bold; }
        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; flex-wrap: wrap; gap: 12px; }
        .top-bar h1 { margin: 0; color: #38bdf8; font-size: 1.7em; }
        .tabs { display: flex; gap: 8px; }
        .tab { padding: 9px 18px; border-radius: 6px; text-decoration: none; font-weight: bold; background: #1e293b; color: #94a3b8; border: 1px solid #334155; }
        .tab.active { background: #0284c7; color: #fff; border-color: #38bdf8; }
        .grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 25px; }
        @media (max-width: 900px) { .grid { grid-template-columns: repeat(2, 1fr); } }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 8px; padding: 14px; }
        .card h3 { margin: 0 0 10px 0; color: #38bdf8; font-size: 0.9em; text-transform: uppercase; border-bottom: 1px solid #334155; padding-bottom: 8px; }
        .card ol { list-style: none; padding: 0; margin: 0; }
        .card li { display: flex; justify-content: space-between; align-items: center; padding: 7px 4px; border-bottom: 1px solid #293548; }
        .card li:last-child { border-bottom: none; }
        .card li.top { background: rgba(56, 189, 248, 0.08); border-radius: 6px; padding: 9px 6px; }
        .pinfo { display: flex; align-items: center; gap: 8px; }
        .pinfo span { color: #94a3b8; font-weight: bold; width: 18px; font-size: 0.85em; }
        .logo { width: 22px; height: 22px; object-fit: contain; }
        .pname { font-weight: bold; font-size: 0.9em; }
        .top .pname, .top b { color: #38bdf8; }
        .pmeta { font-size: 0.75em; color: #94a3b8; }
        .filters { background: #1e293b; padding: 12px 16px; border-radius: 8px; border: 1px solid #334155; margin-bottom: 14px; display: flex; gap: 15px; align-items: center; flex-wrap: wrap; }
        .filters label { font-size: 0.85em; color: #94a3b8; font-weight: bold; }
        .filters select { background: #0f172a; color: #f8fafc; border: 1px solid #334155; padding: 6px 10px; border-radius: 5px; }
        .wrap { overflow-x: auto; border-radius: 8px; border: 1px solid #334155; }
        table { width: 100%; border-collapse: collapse; background: #1e293b; }
        th, td { padding: 9px 10px; text-align: center; border-bottom: 1px solid #334155; font-size: 0.88em; white-space: nowrap; }
        th { background: #020617; color: #38bdf8; }
        th.left, td.left { text-align: left; }
        tr:hover { background: #263348; }
        .sort-link { color: #38bdf8; text-decoration: none; }
        .tcell { display: flex; align-items: center; gap: 6px; }
        .muted { color: #94a3b8; font-size: 0.85em; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>

<div class="top-bar">
    <h1>HFNHL Stats &amp; League Leaders</h1>
    <div class="tabs">
        <a href="?tab=skaters" class="tab <?= $tab !== 'goalies' ? 'active' : '' ?>">Skaters</a>
        <a href="?tab=goalies" class="tab <?= $tab === 'goalies' ? 'active' : '' ?>">Goalies</a>
    </div>
</div>

<div class="grid">
    <?php if ($tab === 'goalies'): ?>
        <?php renderCard('Save Percentage', $topGoalieSvPct, 3); ?>
        <?php renderCard('Goals Against Avg', $topGoalieGaa, 2); ?>
        <?php renderCard('Total Saves', $topGoalieSaves); ?>
        <?php renderCard('Shutouts', $topGoalieSo); ?>
    <?php else: ?>
        <?php renderCard('Points', $topPoints); ?>
        <?php renderCard('Goals', $topGoals); ?>
        <?php renderCard('Assists', $topAssists); ?>
        <?php renderCard('Shots on Goal', $topShots); ?>
    <?php endif; ?>
</div>

<form method="GET" class="filters">
    <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
    <?php if ($tab !== 'goalies'): ?>
        <div>
            <label for="pos">Position:</label>
            <select name="pos" id="pos" onchange="this.form.submit()">
                <option value="ALL" <?= $pos === 'ALL' ? 'selected' : '' ?>>All Skaters</option>
                <option value="F" <?= $pos === 'F' ? 'selected' : '' ?>>Forwards (C/LW/RW)</option>
                <option value="C" <?= $pos === 'C' ? 'selected' : '' ?>>Center (C)</option>
                <option value="LW" <?= $pos === 'LW' ? 'selected' : '' ?>>Left Wing (LW)</option>
                <option value="RW" <?= $pos === 'RW' ? 'selected' : '' ?>>Right Wing (RW)</option>
                <option value="D" <?= $pos === 'D' ? 'selected' : '' ?>>Defense (D)</option>
            </select>
        </div>
    <?php endif; ?>
    <div>
        <label for="team">Team:</label>
        <select name="team" id="team" onchange="this.form.submit()">
            <option value="">All Teams</option>
            <?php foreach ($teams as $t): ?>
                <option value="<?= $t['team_id'] ?>" <?= $teamId === (int)$t['team_id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</form>

<div class="wrap">
    <?php if ($tab === 'goalies'): ?>
        <table>
            <tr>
                <th>#</th>
                <th class="left"><?= sortLink('name', 'Goalie', $tab, $pos, $teamId, $sort, $dir, 'ASC') ?></th>
                <th class="left">Team</th>
                <th><?= sortLink('gp', 'GP', $tab, $pos, $teamId, $sort, $dir) ?></th>
                <th><?= sortLink('sa', 'SA', $tab, $pos, $teamId, $sort, $dir) ?></th>
                <th><?= sortLink('sv', 'SV', $tab, $pos, $teamId, $sort, $dir) ?></th>
                <th><?= sortLink('ga', 'GA', $tab, $pos, $teamId, $sort, $dir, 'ASC') ?></th>
                <th><?= sortLink('sv_pct', 'SV%', $tab, $pos, $teamId, $sort, $dir) ?></th>
                <th><?= sortLink('gaa', 'GAA', $tab, $pos, $teamId, $sort, $dir, 'ASC') ?></th>
                <th><?= sortLink('so', 'SO', $tab, $pos, $teamId, $sort, $dir) ?></th>
                <th><?= sortLink('toi', 'TOI', $tab, $pos, $teamId, $sort, $dir) ?></th>
            </tr>
            <?php if (!$rows): ?>
                <tr><td colspan="11">No goalie stats recorded yet.</td></tr>
            <?php else: foreach ($rows as $i => $r):
                $ab = strtoupper($r['abbreviation'] ?? '');
                $logo = "logos/" . strtolower($ab) . ".svg";
                $sv = number_format((float)$r['sv_pct'], 3);
                if (str_starts_with($sv, '0.')) $sv = substr($sv, 1);
                ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td class="left"><strong><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></strong></td>
                    <td class="left"><div class="tcell"><?php if ($ab && file_exists($logo)): ?><img src="<?= $logo ?>" class="logo"><?php endif; ?><?= $ab ?></div></td>
                    <td><?= $r['gp'] ?></td><td><?= $r['sa'] ?></td><td><?= $r['sv'] ?></td><td><?= $r['ga'] ?></td>
                    <td><strong><?= $sv ?></strong></td><td><?= number_format((float)$r['gaa'], 2) ?></td><td><?= $r['so'] ?></td><td><?= formatToi($r['total_toi']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
        </table>
    <?php else: ?>
    <table>
        <tr>
            <th>#</th>
            <th class="left"><?= sortLink('name', 'Player', $tab, $pos, $teamId, $sort, $dir, 'ASC') ?></th>
            <th class="left">Team</th>
            <th>Pos</th>
            <th><?= sortLink('gp', 'GP', $tab, $pos, $teamId, $sort, $dir) ?></th>
            <th><?= sortLink('g', 'G', $tab, $pos, $teamId, $sort, $dir) ?></th>
            <th><?= sortLink('a', 'A', $tab, $pos, $teamId, $sort, $dir) ?></th>
            <th><?= sortLink('pts', 'PTS', $tab, $pos, $teamId, $sort, $dir) ?></th>
            <th><?= sortLink('ppg', 'P/GP', $tab, $pos, $teamId, $sort, $dir) ?></th>
            <th><?= sortLink('sog', 'SOG', $tab, $pos, $teamId, $sort, $dir) ?></th>
            <th><?= sortLink('sh_pct', 'S%', $tab, $pos, $teamId, $sort, $dir) ?></th>
            <th><?= sortLink('hit', 'HIT', $tab, $pos, $teamId, $sort, $dir) ?></th>
            <th><?= sortLink('blk', 'BLK', $tab, $pos, $teamId, $sort, $dir) ?></th>
            <th><?= sortLink('pim', 'PIM', $tab, $pos, $teamId, $sort, $dir) ?></th>
            <th><?= sortLink('toi', 'TOI/GP', $tab, $pos, $teamId, $sort, $dir) ?></th>
        </tr>
        <?php if (!$rows): ?>
            <tr><td colspan="15">No skater stats recorded yet.</td></tr>
        <?php else: foreach ($rows as $i => $r):
        $ab = strtoupper($r['abbreviation'] ?? '');
        $logo = "logos/" . strtolower($ab) . ".svg";
        ?>
        <tr>
            <td><?= $i + 1 ?></td>
            <td class="left"><strong><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></strong></td>
            <td class="left"><div class="tcell"><?php if ($ab && file_exists($logo)): ?><img src="<?= $logo ?>" class="logo"><?php endif; ?><?= $ab ?></div></td>
            <td><?= htmlspecialchars($r['position']) ?></td>
            <td><?= $r['gp'] ?></td><td><?= $r['g'] ?></td><td><?= $r['a'] ?></td><td><strong><?= $r['pts'] ?></strong></td>
            <td><?= number_format((float)$r['ppg'], 2) ?></td><td><?= $r['sog'] ?></td><td><?= number_format((float)$r['sh_pct'], 1) ?>%</td>
            <td><?= $r['hit'] ?></td><td><?= $r['blk'] ?></td><td><?= $r['pim'] ?></td><td><?= formatToi($r['avg_toi']) ?></td>
        </tr>
        <?php endforeach; endif; ?>
    </table>
    <?php endif; ?>
</div>
</body>
</html>



