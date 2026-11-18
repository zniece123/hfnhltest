<?php
require 'db.php';
/** @var PDO $pdo */

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

$lotteryOdds = array(
    1  => '18.5%', 2  => '13.5%', 3  => '11.5%', 4  => '9.5%',
    5  => '8.5%',  6  => '7.5%',  7  => '6.5%',  8  => '6.0%',
    9  => '5.0%',  10 => '3.5%',  11 => '3.0%',  12 => '2.5%',
    13 => '2.0%',  14 => '1.5%',  15 => '0.5%',  16 => '0.5%'
);

$teams = $pdo->query("SELECT team_id, name, abbreviation FROM teams")->fetchAll(PDO::FETCH_ASSOC);
$games = $pdo->query("SELECT home_team_id, away_team_id, home_score, away_score FROM games WHERE status = 'FINAL'")->fetchAll(PDO::FETCH_ASSOC);

$stats = array();
foreach ($teams as $t) {
    $tid  = (int)$t['team_id'];
    $abbr = strtoupper(trim($t['abbreviation']));
    $pair = $nhlAlign[$abbr] ?? array('Eastern', 'Atlantic');
    $stats[$tid] = array(
        'team_id' => $tid, 'name' => $t['name'], 'abbr' => $abbr,
        'conf' => $pair[0], 'div' => $pair[1],
        'gp' => 0, 'w' => 0, 'l' => 0, 'otl' => 0, 'pts' => 0, 'gf' => 0, 'ga' => 0, 'diff' => 0,
        'is_playoff' => false, 'seed_label' => ''
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
    $s['diff']   = $s['gf'] - $s['ga'];
    $s['ptspct'] = $s['gp'] > 0 ? ($s['pts'] / ($s['gp'] * 2)) : 0;
}
unset($s);

$cmpBestFirst = function($a, $b) {
    if ($b['pts'] !== $a['pts']) return $b['pts'] <=> $a['pts'];
    if (abs($b['ptspct'] - $a['ptspct']) > 0.0001) return ($b['ptspct'] > $a['ptspct']) ? 1 : -1;
    if ($b['w'] !== $a['w']) return $b['w'] <=> $a['w'];
    if ($b['diff'] !== $a['diff']) return $b['diff'] <=> $a['diff'];
    if ($b['gf'] !== $a['gf']) return $b['gf'] <=> $a['gf'];
    return strcmp($a['name'], $b['name']);
};

$cmpWorstFirst = function($a, $b) use ($cmpBestFirst) {
    return -$cmpBestFirst($a, $b);
};

foreach (array('Eastern' => array('Atlantic', 'Metropolitan'), 'Western' => array('Central', 'Pacific')) as $confName => $divs) {
    $wcPool = array();
    foreach ($divs as $dName) {
        $dRows = array_values(array_filter($stats, fn($s) => $s['div'] === $dName));
        usort($dRows, $cmpBestFirst);
        foreach ($dRows as $idx => $r) {
            if ($idx < 3) {
                $stats[$r['team_id']]['is_playoff'] = true;
                $stats[$r['team_id']]['seed_label'] = substr($dName, 0, 3) . ' #' . ($idx + 1);
            } else {
                $wcPool[] = $r;
            }
        }
    }
    usort($wcPool, $cmpBestFirst);
    foreach ($wcPool as $idx => $r) {
        if ($idx < 2) {
            $stats[$r['team_id']]['is_playoff'] = true;
            $stats[$r['team_id']]['seed_label'] = 'WC #' . ($idx + 1);
        }
    }
}

$lotteryTeams = array_values(array_filter($stats, fn($s) => !$s['is_playoff']));
$playoffTeams = array_values(array_filter($stats, fn($s) => $s['is_playoff']));

usort($lotteryTeams, $cmpWorstFirst);
usort($playoffTeams, $cmpWorstFirst);

$draftOrder = array_merge($lotteryTeams, $playoffTeams);

$pickOwners = array();
try {
    $chk = $pdo->query("SELECT to_regclass('public.draft_picks')")->fetchColumn();
    if ($chk) {
        $dpRows = $pdo->query("
            SELECT dp.original_team_id, dp.current_team_id, t.abbreviation AS owner_abbr, t.name AS owner_name
            FROM draft_picks dp
            JOIN teams t ON dp.current_team_id = t.team_id
            WHERE dp.round = 1
            ORDER BY dp.draft_year ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($dpRows as $dp) {
            $origId = (int)$dp['original_team_id'];
            if (!isset($pickOwners[$origId])) {
                $pickOwners[$origId] = $dp;
            }
        }
    }
} catch (Exception $e) {

}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>HFNHL Draft Order &amp; Lottery Odds</title>
    <style>
        body { font-family: Arial, sans-serif; background: #0f172a; color: #f8fafc; padding: 14px 20px; max-width: 1150px; margin: auto; }
        h1 { color: #38bdf8; margin-bottom: 6px; }
        .subtitle { color: #94a3b8; font-size: 0.92em; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; background: #1e293b; border-radius: 8px; overflow: hidden; margin-bottom: 24px; }
        th, td { padding: 10px 12px; border-bottom: 1px solid #334155; font-size: 0.92em; }
        th { background: #020617; color: #38bdf8; text-align: left; }
        th.num, td.num { text-align: center; }
        tr:hover { background: #263348; }
        tr.cutoff-row td { border-bottom: 3px dashed #fbbf24; }
        .pick-num { font-weight: bold; color: #38bdf8; font-size: 1.05em; }
        .team-cell { display: inline-flex; align-items: center; gap: 9px; color: #f8fafc; text-decoration: none; }
        .team-cell:hover { color: #38bdf8; }
        .team-cell img { width: 26px; height: 26px; object-fit: contain; }
        .odds-pill { background: rgba(56, 189, 248, 0.14); color: #38bdf8; padding: 2px 8px; border-radius: 12px; font-weight: bold; font-size: 0.85em; }
        .po-pill { background: #14532d; color: #86efac; padding: 2px 8px; border-radius: 4px; font-size: 0.8em; font-weight: bold; }
        .traded-pill { background: rgba(251, 191, 36, 0.15); color: #fbbf24; border: 1px solid rgba(251, 191, 36, 0.4); padding: 2px 8px; border-radius: 4px; font-size: 0.82em; font-weight: bold; display: inline-flex; align-items: center; gap: 5px; }
        .traded-pill img { width: 16px; height: 16px; object-fit: contain; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>

<h1>HFNHL Live Draft Order</h1>
<div class="subtitle">
    Picks 1&ndash;16 are non-playoff teams ordered by lowest points / points percentage with NHL Draft Lottery #1 pick odds. Picks 17&ndash;32 are current playoff teams.
</div>

<table>
    <tr>
        <th class="num" style="width:60px;">Pick</th>
        <th>Team</th>
        <th>Pick Ownership</th>
        <th class="num">Status / Odds</th>
        <th class="num">GP</th>
        <th class="num">W-L-OTL</th>
        <th class="num">PTS</th>
        <th class="num">P%</th>
        <th class="num">DIFF</th>
    </tr>
    <?php foreach ($draftOrder as $idx => $r):
        $pickNum = $idx + 1;
        $tid     = $r['team_id'];
        $pctStr  = $r['gp'] > 0 ? number_format($r['ptspct'], 3) : '.000';
        $diffStr = ($r['diff'] > 0 ? '+' : '') . $r['diff'];
        $rowCls  = ($pickNum === 16) ? ' class="cutoff-row"' : '';

        $ownerInfo = $pickOwners[$tid] ?? null;
        $isTraded  = ($ownerInfo && (int)$ownerInfo['current_team_id'] !== $tid);
        ?>
        <tr<?= $rowCls ?>>
            <td class="num pick-num">#<?= $pickNum ?></td>
            <td>
                <a class="team-cell" href="team.php?id=<?= $tid ?>">
                    <img src="logos/<?= htmlspecialchars($r['abbr']) ?>.svg" alt="">
                    <strong><?= htmlspecialchars($r['name']) ?> (<?= htmlspecialchars($r['abbr']) ?>)</strong>
                </a>
            </td>
            <td>
                <?php if ($isTraded):
                    $ownAbbr = strtoupper(trim($ownerInfo['owner_abbr']));
                    ?>
                    <span class="traded-pill">
                        <img src="logos/<?= htmlspecialchars($ownAbbr) ?>.svg" alt="">
                        Via <?= htmlspecialchars($r['abbr']) ?> &rarr; Owned by <?= htmlspecialchars($ownAbbr) ?>
                    </span>
                <?php else: ?>
                    <span style="color:#94a3b8;font-size:0.88em;">Own Pick</span>
                <?php endif; ?>
            </td>
            <td class="num">
                <?php if ($pickNum <= 16): ?>
                    <span class="odds-pill"><?= $lotteryOdds[$pickNum] ?></span>
                <?php else: ?>
                    <span class="po-pill">Playoffs (<?= htmlspecialchars($r['seed_label']) ?>)</span>
                <?php endif; ?>
            </td>
            <td class="num"><?= $r['gp'] ?></td>
            <td class="num"><?= $r['w'] ?>-<?= $r['l'] ?>-<?= $r['otl'] ?></td>
            <td class="num" style="color:#38bdf8;font-weight:bold;"><?= $r['pts'] ?></td>
            <td class="num"><?= $pctStr ?></td>
            <td class="num"><?= $diffStr ?></td>
        </tr>
    <?php endforeach; ?>
</table>

</body>
</html>

