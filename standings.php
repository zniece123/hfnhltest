<?php
require 'db.php';
/** @var PDO $pdo */

$view = $_GET['view'] ?? 'wildcard';
if (!in_array($view, array('wildcard', 'division', 'conference', 'league'))) {
    $view = 'wildcard';
}

$nhlAlign = array(
        'BOS' => array('Eastern', 'Atlantic'),     'BUF' => array('Eastern', 'Atlantic'),
        'DET' => array('Eastern', 'Atlantic'),     'FLA' => array('Eastern', 'Atlantic'),
        'MTL' => array('Eastern', 'Atlantic'),     'OTT' => array('Eastern', 'Atlantic'),
        'TBL' => array('Eastern', 'Atlantic'),     'TOR' => array('Eastern', 'Atlantic'),
        'CAR' => array('Eastern', 'Metropolitan'), 'CBJ' => array('Eastern', 'Metropolitan'),
        'NJD' => array('Eastern', 'Metropolitan'), 'NYI' => array('Eastern', 'Metropolitan'),
        'NYR' => array('Eastern', 'Metropolitan'), 'PHI' => array('Eastern', 'Metropolitan'),
        'PIT' => array('Eastern', 'Metropolitan'), 'WSH' => array('Eastern', 'Metropolitan'),
        'CHI' => array('Western', 'Central'),      'COL' => array('Western', 'Central'),
        'DAL' => array('Western', 'Central'),      'MIN' => array('Western', 'Central'),
        'NSH' => array('Western', 'Central'),      'STL' => array('Western', 'Central'),
        'UTA' => array('Western', 'Central'),      'WPG' => array('Western', 'Central'),
        'ANA' => array('Western', 'Pacific'),      'CGY' => array('Western', 'Pacific'),
        'EDM' => array('Western', 'Pacific'),      'LAK' => array('Western', 'Pacific'),
        'SEA' => array('Western', 'Pacific'),      'SJS' => array('Western', 'Pacific'),
        'VAN' => array('Western', 'Pacific'),      'VGK' => array('Western', 'Pacific'),
);

$teams = $pdo->query("SELECT team_id, name, abbreviation FROM teams")->fetchAll(PDO::FETCH_ASSOC);
$games = $pdo->query("SELECT home_team_id, away_team_id, home_score, away_score FROM games WHERE status = 'FINAL'")->fetchAll(PDO::FETCH_ASSOC);

$stats = array();
foreach ($teams as $t) {
    $tid   = (int)$t['team_id'];
    $abbr  = strtoupper(trim($t['abbreviation']));
    $pair  = $nhlAlign[$abbr] ?? array('Eastern', 'Atlantic');
    $conf  = reset($pair);
    $div   = end($pair);
    $stats[$tid] = array(
            'team_id' => $tid, 'name' => $t['name'], 'abbr' => $abbr,
            'conf' => $conf, 'div' => $div,
            'gp' => 0, 'w' => 0, 'l' => 0, 'otl' => 0, 'pts' => 0, 'gf' => 0, 'ga' => 0, 'diff' => 0
    );
}

foreach ($games as $g) {
    $h  = (int)$g['home_team_id'];
    $a  = (int)$g['away_team_id'];
    $hs = (int)$g['home_score'];
    $as = (int)$g['away_score'];
    if (!isset($stats[$h]) || !isset($stats[$a])) continue;

    $stats[$h]['gp']++; $stats[$a]['gp']++;
    $stats[$h]['gf'] += $hs; $stats[$h]['ga'] += $as;
    $stats[$a]['gf'] += $as; $stats[$a]['ga'] += $hs;

    $isOt = (abs($hs - $as) === 1 && ($hs + $as) >= 3);
    if ($hs > $as) {
        $stats[$h]['w']++; $stats[$h]['pts'] += 2;
        if ($isOt) { $stats[$a]['otl']++; $stats[$a]['pts'] += 1; } else { $stats[$a]['l']++; }
    } elseif ($as > $hs) {
        $stats[$a]['w']++; $stats[$a]['pts'] += 2;
        if ($isOt) { $stats[$h]['otl']++; $stats[$h]['pts'] += 1; } else { $stats[$h]['l']++; }
    }
}

foreach ($stats as &$s) {
    $s['diff'] = $s['gf'] - $s['ga'];
    $s['ptspct'] = $s['gp'] > 0 ? ($s['pts'] / ($s['gp'] * 2)) : 0;
}
unset($s);

$sortStandings = function(&$list) {
    usort($list, function($a, $b) {
        if ($b['pts'] !== $a['pts']) return $b['pts'] <=> $a['pts'];
        if (abs($b['ptspct'] - $a['ptspct']) > 0.0001) return ($b['ptspct'] > $a['ptspct']) ? 1 : -1;
        if ($b['w'] !== $a['w']) return $b['w'] <=> $a['w'];
        if ($b['diff'] !== $a['diff']) return $b['diff'] <=> $a['diff'];
        if ($b['gf'] !== $a['gf']) return $b['gf'] <=> $a['gf'];
        return strcmp($a['name'], $b['name']);
    });
};

function renderStandingsTable($title, $rows, $cutlineAfter = 0) {
    echo '<h2>' . htmlspecialchars($title) . '</h2>';
    echo '<table>
        <tr>
            <th style="width:45px;">#</th>
            <th>Team</th>
            <th class="num">GP</th><th class="num">W</th><th class="num">L</th><th class="num">OTL</th>
            <th class="num">PTS</th><th class="num">P%</th><th class="num">GF</th><th class="num">GA</th><th class="num">DIFF</th>
        </tr>';
    foreach ($rows as $i => $r) {
        $rank = $i + 1;
        $isCutline = ($cutlineAfter > 0 && $rank === $cutlineAfter);
        $rowStyle  = $isCutline ? ' style="border-bottom: 3px dashed #fbbf24;"' : '';
        $diffStr   = ($r['diff'] > 0 ? '+' : '') . $r['diff'];
        $pctStr    = $r['gp'] > 0 ? number_format($r['ptspct'], 3) : '.000';
        $badge     = ($cutlineAfter > 0 && $rank <= $cutlineAfter) ? '<span class="po-badge">x</span>' : '';

        echo '<tr' . $rowStyle . '>
            <td>' . $rank . '</td>
            <td>
                <a class="team-link" href="team.php?id=' . $r['team_id'] . '">
                    <img src="logos/' . htmlspecialchars($r['abbr']) . '.svg" alt="">
                    <strong>' . htmlspecialchars($r['name']) . ' (' . htmlspecialchars($r['abbr']) . ')</strong>' . $badge . '
                </a>
            </td>
            <td class="num">' . $r['gp'] . '</td>
            <td class="num">' . $r['w'] . '</td>
            <td class="num">' . $r['l'] . '</td>
            <td class="num">' . $r['otl'] . '</td>
            <td class="num" style="color:#38bdf8;font-weight:bold;">' . $r['pts'] . '</td>
            <td class="num">' . $pctStr . '</td>
            <td class="num">' . $r['gf'] . '</td>
            <td class="num">' . $r['ga'] . '</td>
            <td class="num">' . $diffStr . '</td>
        </tr>';
    }
    echo '</table>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>HFNHL Standings</title>
    <style>
        body { font-family: Arial, sans-serif; background: #0f172a; color: #f8fafc; padding: 14px 20px; }
        h1, h2 { color: #38bdf8; }
        .view-tabs { display: flex; gap: 10px; margin-bottom: 22px; flex-wrap: wrap; }
        .view-tabs a { background: #1e293b; color: #cbd5e1; text-decoration: none; padding: 9px 18px; border-radius: 6px; font-weight: bold; border: 1px solid #334155; }
        .view-tabs a.active { background: #0284c7; color: #fff; border-color: #38bdf8; }
        .conf-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; align-items: start; }
        table { width: 100%; border-collapse: collapse; background: #1e293b; border-radius: 8px; overflow: hidden; margin-bottom: 24px; }
        th, td { padding: 9px 10px; border-bottom: 1px solid #334155; font-size: 0.92em; }
        th { background: #020617; color: #38bdf8; text-align: left; }
        th.num, td.num { text-align: center; }
        .team-link { display: inline-flex; align-items: center; gap: 9px; color: #f8fafc; text-decoration: none; }
        .team-link:hover { color: #38bdf8; }
        .team-link img { width: 26px; height: 26px; object-fit: contain; }
        .po-badge { background: #14532d; color: #86efac; font-size: 0.72em; padding: 1px 6px; border-radius: 4px; margin-left: 6px; font-weight: bold; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>

<h1>HFNHL Standings</h1>
<div class="view-tabs">
    <a href="standings.php?view=wildcard" class="<?= $view === 'wildcard' ? 'active' : '' ?>">Wild Card (NHL Playoff Format)</a>
    <a href="standings.php?view=division" class="<?= $view === 'division' ? 'active' : '' ?>">Division</a>
    <a href="standings.php?view=conference" class="<?= $view === 'conference' ? 'active' : '' ?>">Conference</a>
    <a href="standings.php?view=league" class="<?= $view === 'league' ? 'active' : '' ?>">League (Overall)</a>
</div>

<?php if ($view === 'wildcard'): ?>
    <div class="conf-grid">
        <?php foreach (array('Eastern' => array('Atlantic', 'Metropolitan'), 'Western' => array('Central', 'Pacific')) as $confName => $divs): ?>
            <div>
                <h2 style="color:#fbbf24;border-bottom:1px solid #334155;padding-bottom:6px;"><?= $confName ?> Conference</h2>
                <?php
                $wcPool = array();
                foreach ($divs as $dName) {
                    $dRows = array_filter($stats, fn($s) => $s['div'] === $dName);
                    $sortStandings($dRows);
                    $top3 = array_slice($dRows, 0, 3);
                    $rest = array_slice($dRows, 3);
                    foreach ($rest as $r) $wcPool[] = $r;
                    renderStandingsTable($dName . ' Division (Top 3)', $top3, 0);
                }
                $sortStandings($wcPool);
                renderStandingsTable($confName . ' Wild Card (Top 2 Qualify)', $wcPool, 2);
                ?>
            </div>
        <?php endforeach; ?>
    </div>

<?php elseif ($view === 'division'): ?>
    <div class="conf-grid">
        <?php foreach (array('Atlantic', 'Metropolitan', 'Central', 'Pacific') as $dName):
            $dRows = array_filter($stats, fn($s) => $s['div'] === $dName);
            $sortStandings($dRows);
            ?>
            <div><?php renderStandingsTable($dName . ' Division', $dRows, 3); ?></div>
        <?php endforeach; ?>
    </div>

<?php elseif ($view === 'conference'): ?>
    <div class="conf-grid">
        <?php foreach (array('Eastern', 'Western') as $cName):
            $cRows = array_filter($stats, fn($s) => $s['conf'] === $cName);
            $sortStandings($cRows);
            ?>
            <div><?php renderStandingsTable($cName . ' Conference', $cRows, 8); ?></div>
        <?php endforeach; ?>
    </div>

<?php else:
    $allRows = array_values($stats);
    $sortStandings($allRows);
    renderStandingsTable('Overall League Standings', $allRows, 16);
endif; ?>
</body>
</html>


