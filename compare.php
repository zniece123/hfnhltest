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

$playerLabelsById = array();
foreach ($allPlayers as $ap) {
    $ab = strtoupper(trim($ap['abbreviation'] ?? 'FA'));
    $playerLabelsById[(int)$ap['player_id']] = "{$ap['last_name']}, {$ap['first_name']} ({$ap['position']} - {$ap['overall_rating']} OVR | {$ab})";
}

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
        $gp = (int)$row['gp'];
        $row['sv_pct'] = $sa > 0 ? round((int)$row['sv'] / $sa, 3) : 0;
        $row['gaa']    = (int)$row['toi_sec'] > 0 ? round(((int)$row['ga'] * 3600) / (int)$row['toi_sec'], 2) : 0;
        $row['ppg']    = $gp > 0 ? round((int)$row['pts'] / $gp, 2) : 0;
        $byId[(int)$row['player_id']] = $row;
    }
    foreach ($selectedIds as $sid) {
        if (isset($byId[$sid])) $compared[] = $byId[$sid];
    }
}

function renderCompareRow($label, $players, $key, $mode = 'max', $format = 'int', $roleFilter = 'all') {
    $vals       = array();
    $applicable = array();

    foreach ($players as $idx => $pl) {
        $isGoalie = (strtoupper(trim($pl['position'] ?? '')) === 'G');
        $ok = ($roleFilter === 'all')
                || ($roleFilter === 'goalie' && $isGoalie)
                || ($roleFilter === 'skater' && !$isGoalie);

        $applicable[$idx] = $ok;
        $vals[$idx]       = $ok ? (float)($pl[$key] ?? 0) : null;
    }

    $validVals = array_filter($vals, fn($v) => $v !== null);
    if ($mode === 'min' && ($format === 'money' || $format === 'dec2')) {
        $validVals = array_filter($validVals, fn($v) => $v > 0);
    }

    $best = null;
    if (count($validVals) > 1 && $mode !== 'none') {
        $best = ($mode === 'min') ? min($validVals) : max($validVals);
        if ($best == 0 && $mode === 'max') $best = null;
    }

    echo "<tr><td class=\"attr-label\">" . htmlspecialchars($label) . "</td>";
    foreach ($players as $idx => $pl) {
        if (!$applicable[$idx]) {
            echo "<td style=\"color:#64748b;\">—</td>";
            continue;
        }

        $raw    = $vals[$idx];
        $isBest = ($best !== null && abs($raw - $best) < 0.0001 && !($mode === 'min' && ($format === 'money' || $format === 'dec2') && $raw <= 0));
        $cls    = $isBest ? ' class="best-val"' : '';

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
        body { font-family: Arial, sans-serif; background: #0f172a; color: #f8fafc; padding: 14px 20px; max-width: 1380px; margin: auto; }
        h1 { color: #38bdf8; margin-bottom: 6px; }
        .subtitle { color: #94a3b8; font-size: 0.92em; margin-bottom: 18px; }
        .picker-bar { background: #1e293b; padding: 14px 18px; border-radius: 8px; border: 1px solid #334155; margin-bottom: 22px; display: flex; gap: 14px; align-items: end; flex-wrap: wrap; }
        .picker-col { flex: 1; min-width: 240px; position: relative; }
        .picker-col label { display: block; color: #94a3b8; font-weight: bold; font-size: 0.84em; margin-bottom: 5px; }
        .search-wrap { position: relative; display: flex; align-items: center; }
        .search-input { width: 100%; background: #0f172a; color: #f8fafc; border: 1px solid #475569; padding: 8px 30px 8px 10px; border-radius: 6px; font-size: 0.91em; box-sizing: border-box; }
        .search-input:focus { outline: none; border-color: #38bdf8; }
        .clear-x { position: absolute; right: 8px; background: none; border: none; color: #94a3b8; font-weight: bold; cursor: pointer; font-size: 1em; display: none; padding: 0 4px; }
        .clear-x:hover { color: #f87171; }
        .ac-list { position: absolute; top: 100%; left: 0; right: 0; background: #0f172a; border: 1px solid #38bdf8; border-top: none; border-radius: 0 0 6px 6px; max-height: 240px; overflow-y: auto; z-index: 50; display: none; box-shadow: 0 8px 20px rgba(0,0,0,0.5); }
        .ac-item { padding: 8px 10px; font-size: 0.88em; cursor: pointer; border-bottom: 1px solid #1e293b; color: #e2e8f0; }
        .ac-item:hover, .ac-item.active { background: #0284c7; color: #fff; }
        .cmp-btn { background: #0284c7; color: #fff; border: none; padding: 9px 20px; border-radius: 6px; font-weight: bold; cursor: pointer; font-size: 0.95em; }
        .cmp-btn:hover { background: #0369a1; }
        .reset-btn { background: #334155; color: #cbd5e1; text-decoration: none; padding: 9px 14px; border-radius: 6px; font-weight: bold; font-size: 0.9em; }
        .reset-btn:hover { background: #475569; color: #fff; }
        table { width: 100%; border-collapse: collapse; background: #1e293b; border-radius: 8px; overflow: hidden; table-layout: fixed; }
        th, td { padding: 11px 14px; border-bottom: 1px solid #334155; text-align: center; font-size: 0.94em; }
        th { background: #020617; color: #38bdf8; vertical-align: middle; }
        td.attr-label { text-align: left; color: #cbd5e1; font-weight: bold; background: #162032; width: 220px; }
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
<div class="subtitle">Type to search and select up to 3 skaters or goalies to compare ratings, contracts, and current season stats side-by-side.</div>

<form method="GET" class="picker-bar" id="compareForm">
    <?php foreach (array(1 => $p1, 2 => $p2, 3 => $p3) as $slot => $curVal):
        $curText = $playerLabelsById[$curVal] ?? '';
        ?>
        <div class="picker-col" data-slot="<?= $slot ?>">
            <label for="search_p<?= $slot ?>">Player <?= $slot ?><?= $slot === 3 ? ' (Optional)' : '' ?>:</label>
            <input type="hidden" name="p<?= $slot ?>" id="p<?= $slot ?>" value="<?= $curVal ?>">
            <div class="search-wrap">
                <input type="text"
                       id="search_p<?= $slot ?>"
                       class="search-input"
                       value="<?= htmlspecialchars($curText) ?>"
                       placeholder="Type player name or team..."
                       autocomplete="off">
                <button type="button" class="clear-x" title="Clear selection">&times;</button>
            </div>
            <div class="ac-list"></div>
        </div>
    <?php endforeach; ?>
    <button type="submit" class="cmp-btn">Compare</button>
    <?php if (!empty($selectedIds)): ?>
        <a href="compare_players.php" class="reset-btn">Reset</a>
    <?php endif; ?>
</form>

<?php if (empty($compared)): ?>
    <div style="background:#1e293b;padding:24px;border-radius:8px;border:1px solid #334155;text-align:center;color:#94a3b8;">
        Type a player's name in two or three boxes above, pick them from the list, and click <strong>Compare</strong>.
    </div>
<?php else:
    $hasGoalie = false;
    $hasSkater = false;
    foreach ($compared as $cp) {
        if (strtoupper(trim($cp['position'] ?? '')) === 'G') $hasGoalie = true;
        else $hasSkater = true;
    }
    ?>
    <table>
        <tr>
            <th style="text-align:left;width:220px;">Attribute</th>
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
        <?php if ($hasGoalie) renderCompareRow('Goaltending (GO)', $compared, 'goaltending', 'max', 'int', 'goalie'); ?>

        <tr class="sec-hdr"><td colspan="<?= count($compared) + 1 ?>">Current Season Stats</td></tr>
        <?php renderCompareRow('Games Played (GP)', $compared, 'gp', 'max'); ?>
        <?php if ($hasSkater): ?>
            <?php renderCompareRow('Goals (G)', $compared, 'g', 'max', 'int', 'skater'); ?>
            <?php renderCompareRow('Assists (A)', $compared, 'a', 'max', 'int', 'skater'); ?>
            <?php renderCompareRow('Points (PTS)', $compared, 'pts', 'max', 'int', 'skater'); ?>
            <?php renderCompareRow('Points / Game (P/GP)', $compared, 'ppg', 'max', 'dec2', 'skater'); ?>
            <?php renderCompareRow('Shots on Goal (SOG)', $compared, 'sog', 'max', 'int', 'skater'); ?>
            <?php renderCompareRow('Hits (HIT)', $compared, 'hit', 'max', 'int', 'skater'); ?>
            <?php renderCompareRow('Blocked Shots (BLK)', $compared, 'blk', 'max', 'int', 'skater'); ?>
            <?php renderCompareRow('Penalty Minutes (PIM)', $compared, 'pim', 'none', 'int', 'skater'); ?>
        <?php endif; ?>
        <?php if ($hasGoalie): ?>
            <?php renderCompareRow('Saves (SV)', $compared, 'sv', 'max', 'int', 'goalie'); ?>
            <?php renderCompareRow('Goals Against (GA)', $compared, 'ga', 'min', 'int', 'goalie'); ?>
            <?php renderCompareRow('Save Percentage (SV%)', $compared, 'sv_pct', 'max', 'sv', 'goalie'); ?>
            <?php renderCompareRow('Goals Against Avg (GAA)', $compared, 'gaa', 'min', 'dec2', 'goalie'); ?>
        <?php endif; ?>
    </table>
<?php endif; ?>

<script>
    const ALL_PLAYERS = <?= json_encode(array_map(function($ap) use ($playerLabelsById) {
        $id = (int)$ap['player_id'];
        return array(
                'id'    => $id,
                'label' => $playerLabelsById[$id],
                'q'     => strtolower($ap['first_name'] . ' ' . $ap['last_name'] . ' ' . $ap['last_name'] . ', ' . $ap['first_name'] . ' ' . ($ap['abbreviation'] ?? 'FA') . ' ' . $ap['position'])
        );
    }, $allPlayers), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

    document.querySelectorAll('.picker-col').forEach(col => {
        const hidden = col.querySelector('input[type="hidden"]');
        const input  = col.querySelector('.search-input');
        const clear  = col.querySelector('.clear-x');
        const list   = col.querySelector('.ac-list');

        function syncClear() {
            clear.style.display = input.value.trim() !== '' ? 'block' : 'none';
        }

        function renderMatches(term) {
            const q = term.trim().toLowerCase();
            const matches = q === ''
                ? ALL_PLAYERS.slice(0, 30)
                : ALL_PLAYERS.filter(p => p.q.includes(q)).slice(0, 40);

            list.innerHTML = '';
            if (matches.length === 0) {
                list.innerHTML = '<div class="ac-item" style="color:#94a3b8;cursor:default;">No matching players</div>';
            } else {
                if (matches.length === 0) {
                    list.innerHTML = '<div class="ac-item" style="color:#94a3b8;cursor:default;">No matching players</div>';
                } else {
                    matches.forEach(m => {
                        const div = document.createElement('div');
                        div.className = 'ac-item';
                        div.textContent = m.label;
                        div.addEventListener('mousedown', (e) => {
                            e.preventDefault();
                            hidden.value = m.id;
                            input.value  = m.label;
                            list.style.display = 'none';
                            syncClear();
                        });
                        list.appendChild(div);
                    });
                }
                list.style.display = 'block';
            }

            input.addEventListener('focus', () => renderMatches(input.value));
            input.addEventListener('input', () => {
                hidden.value = 0;
                syncClear();
                renderMatches(input.value);
            });
            input.addEventListener('blur', () => {
                setTimeout(() => { list.style.display = 'none'; }, 150);
            });

            clear.addEventListener('click', () => {
                hidden.value = 0;
                input.value  = '';
                syncClear();
                input.focus();
            });

            syncClear();
        });
</script>
</body>
</html>
