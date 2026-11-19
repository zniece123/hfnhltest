<?php
require 'db.php';
/** @var PDO $pdo */

$myTeamId     = (int)($_SESSION['team_id'] ?? 0);
$selectedType = ($_GET['type'] ?? 'player') === 'prospect' ? 'prospect' : 'player';
$selectedId   = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_GET['player_id']) ? (int)$_GET['player_id'] : 0);

// 1. Fetch all active Players & Free Agents
$allPlayers = $pdo->query("
    SELECT p.player_id, p.first_name, p.last_name, p.position, p.overall_rating,
           p.team_id, UPPER(COALESCE(p.roster_status, 'PRO')) AS roster_status,
           COALESCE(p.fa_status, 'UFA') AS fa_status,
           t.abbreviation, t.name AS team_name
    FROM players p
    LEFT JOIN teams t ON p.team_id = t.team_id
    WHERE COALESCE(p.is_retired, FALSE) = FALSE
    ORDER BY p.overall_rating DESC, p.last_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// 2. Fetch all Prospects
$allProspects = $pdo->query("
    SELECT pr.prospect_id, pr.first_name, pr.last_name, pr.position, pr.draft_year,
           pr.team_id, t.abbreviation, t.name AS team_name
    FROM prospects pr
    LEFT JOIN teams t ON pr.team_id = t.team_id
    ORDER BY pr.draft_year DESC, pr.last_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$searchItems  = array();
$labelsByKey  = array();

foreach ($allPlayers as $ap) {
    $id   = (int)$ap['player_id'];
    $key  = "player_{$id}";
    $isFa = ($ap['roster_status'] === 'FA' || (int)$ap['team_id'] === 0);
    $loc  = $isFa ? ('FA - ' . strtoupper($ap['fa_status'])) : strtoupper($ap['abbreviation'] ?? 'TEAM');
    $lbl  = "{$ap['last_name']}, {$ap['first_name']} ({$ap['position']} - {$ap['overall_rating']} OVR | {$loc})";

    $labelsByKey[$key] = $lbl;
    $searchItems[]     = array(
        'type'  => 'player',
        'id'    => $id,
        'label' => $lbl,
        'q'     => strtolower($ap['first_name'] . ' ' . $ap['last_name'] . ' ' . $ap['last_name'] . ', ' . $ap['first_name'] . ' ' . ($isFa ? 'fa free agent' : ($ap['abbreviation'] . ' ' . $ap['team_name'])) . ' ' . $ap['position'])
    );
}

foreach ($allProspects as $pr) {
    $id   = (int)$pr['prospect_id'];
    $key  = "prospect_{$id}";
    $loc  = !empty($pr['abbreviation']) ? strtoupper($pr['abbreviation']) : 'UNASSIGNED';
    $yr   = !empty($pr['draft_year']) ? " {$pr['draft_year']}" : '';
    $pos  = !empty($pr['position']) ? $pr['position'] : 'Prospect';
    $lbl  = "{$pr['last_name']}, {$pr['first_name']} ({$pos} - [Prospect{$yr}] | {$loc})";

    $labelsByKey[$key] = $lbl;
    $searchItems[]     = array(
        'type'  => 'prospect',
        'id'    => $id,
        'label' => $lbl,
        'q'     => strtolower($pr['first_name'] . ' ' . $pr['last_name'] . ' ' . $pr['last_name'] . ', ' . $pr['first_name'] . ' prospect ' . $yr . ' ' . ($pr['abbreviation'] ?? '') . ' ' . ($pr['team_name'] ?? '') . ' ' . $pos)
    );
}

$player   = null;
$prospect = null;
$prRating = null;

if ($selectedId > 0 && $selectedType === 'player') {
    $stmt = $pdo->prepare("
        SELECT p.*, t.name AS team_name, t.abbreviation,
               pt.abbreviation AS prev_abbr,
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
        LEFT JOIN teams pt ON p.prev_team_id = pt.team_id
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
        WHERE p.player_id = ?
    ");
    $stmt->execute(array($selectedId));
    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($player) {
        $sa = (int)$player['sv'] + (int)$player['ga'];
        $gp = (int)$player['gp'];
        $player['sv_pct'] = $sa > 0 ? round((int)$player['sv'] / $sa, 3) : 0;
        $player['gaa']    = (int)$player['toi_sec'] > 0 ? round(((int)$player['ga'] * 3600) / (int)$player['toi_sec'], 2) : 0;
        $player['ppg']    = $gp > 0 ? round((int)$player['pts'] / $gp, 2) : 0;
    }
} elseif ($selectedId > 0 && $selectedType === 'prospect') {
    $prStmt = $pdo->prepare("
        SELECT pr.*, t.name AS team_name, t.abbreviation
        FROM prospects pr
        LEFT JOIN teams t ON pr.team_id = t.team_id
        WHERE pr.prospect_id = ?
    ");
    $prStmt->execute(array($selectedId));
    $prospect = $prStmt->fetch(PDO::FETCH_ASSOC);

    if ($prospect) {
        try {
            $rStmt = $pdo->prepare("SELECT * FROM prospect_ratings WHERE prospect_id = ? LIMIT 1");
            $rStmt->execute(array($selectedId));
            $prRating = $rStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e) {}
    }
}

$curKey   = ($selectedId > 0) ? "{$selectedType}_{$selectedId}" : '';
$curLabel = $labelsByKey[$curKey] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Player &amp; Prospect Lookup - HFNHL</title>
    <style>
        body { font-family: Arial, sans-serif; background: #0f172a; color: #f8fafc; padding: 14px 20px; max-width: 1150px; margin: auto; }
        h1, h2, h3 { color: #38bdf8; }
        .subtitle { color: #94a3b8; font-size: 0.92em; margin-bottom: 18px; }
        .search-card { background: #1e293b; padding: 18px; border-radius: 8px; border: 1px solid #334155; margin-bottom: 22px; display: flex; gap: 12px; align-items: end; flex-wrap: wrap; }
        .search-col { flex: 1; min-width: 280px; position: relative; }
        .search-col label { display: block; color: #94a3b8; font-weight: bold; font-size: 0.85em; margin-bottom: 6px; }
        .search-wrap { position: relative; display: flex; align-items: center; }
        .search-input { width: 100%; background: #0f172a; color: #f8fafc; border: 1px solid #475569; padding: 10px 32px 10px 12px; border-radius: 6px; font-size: 0.96em; box-sizing: border-box; }
        .search-input:focus { outline: none; border-color: #38bdf8; }
        .clear-x { position: absolute; right: 10px; background: none; border: none; color: #94a3b8; font-weight: bold; cursor: pointer; font-size: 1.1em; display: none; }
        .clear-x:hover { color: #f87171; }
        .ac-list { position: absolute; top: 100%; left: 0; right: 0; background: #0f172a; border: 1px solid #38bdf8; border-top: none; border-radius: 0 0 6px 6px; max-height: 280px; overflow-y: auto; z-index: 50; display: none; box-shadow: 0 8px 20px rgba(0,0,0,0.5); }
        .ac-item { padding: 9px 12px; font-size: 0.9em; cursor: pointer; border-bottom: 1px solid #1e293b; color: #e2e8f0; }
        .ac-item:hover { background: #0284c7; color: #fff; }
        .btn-go { background: #0284c7; color: #fff; border: none; padding: 10px 20px; border-radius: 6px; font-weight: bold; cursor: pointer; font-size: 0.95em; }
        .btn-go:hover { background: #0369a1; }
        .profile-card { background: #1e293b; border: 1px solid #334155; border-radius: 10px; padding: 22px; }
        .profile-top { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; border-bottom: 1px solid #334155; padding-bottom: 18px; margin-bottom: 18px; }
        .p-identity { display: flex; align-items: center; gap: 14px; }
        .p-identity img { width: 52px; height: 52px; object-fit: contain; }
        .p-title { font-size: 1.55em; font-weight: bold; color: #f8fafc; margin: 0; }
        .p-sub { color: #94a3b8; font-size: 0.92em; margin-top: 4px; }
        .badge { padding: 3px 8px; border-radius: 4px; font-size: 0.78em; font-weight: bold; margin-left: 6px; }
        .badge-fa { background: #7c2d12; color: #fdba74; border: 1px solid #f97316; }
        .badge-rfa { background: #1e3a8a; color: #93c5fd; border: 1px solid #3b82f6; }
        .badge-team { background: #065f46; color: #6ee7b7; border: 1px solid #10b981; }
        .badge-prospect { background: #4c1d95; color: #ddd6fe; border: 1px solid #8b5cf6; }
        .action-btn { padding: 11px 18px; border-radius: 6px; font-weight: bold; text-decoration: none; font-size: 0.95em; display: inline-block; }
        .action-fa { background: #16a34a; color: #fff; }
        .action-fa:hover { background: #15803d; }
        .action-trade { background: #0284c7; color: #fff; }
        .action-trade:hover { background: #0369a1; }
        .action-own { background: #334155; color: #cbd5e1; cursor: default; }
        .grid-sections { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 18px; }
        .stat-box { background: #0f172a; border: 1px solid #334155; border-radius: 8px; padding: 14px 16px; }
        .stat-box h3 { margin: 0 0 10px 0; font-size: 0.92em; color: #fbbf24; text-transform: uppercase; letter-spacing: 0.5px; }
        .kv-row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #1e293b; font-size: 0.92em; }
        .kv-row:last-child { border-bottom: none; }
        .kv-row span { color: #94a3b8; }
        .kv-row strong { color: #f8fafc; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>

<h1>Player &amp; Prospect Lookup</h1>
<div class="subtitle">Search any NHL/AHL player, Free Agent, or Prospect to inspect their profile and jump straight to Free Agency or a Trade Offer.</div>

<form method="GET" class="search-card" id="lookupForm">
    <div class="search-col">
        <label for="playerSearch">Find Player or Prospect (Name, Team, Prospect, or FA):</label>
        <input type="hidden" name="type" id="item_type" value="<?= htmlspecialchars($selectedType) ?>">
        <input type="hidden" name="id" id="item_id" value="<?= $selectedId ?>">
        <div class="search-wrap">
            <input type="text"
                   id="playerSearch"
                   class="search-input"
                   value="<?= htmlspecialchars($curLabel) ?>"
                   placeholder="Start typing a player or prospect name..."
                   autocomplete="off">
            <button type="button" class="clear-x" id="clearSearch" title="Clear">&times;</button>
        </div>
        <div class="ac-list" id="acList"></div>
    </div>
    <button type="submit" class="btn-go">View Profile</button>
</form>

<?php if ($player):
$fullName   = trim($player['first_name'] . ' ' . $player['last_name']);
$isGoalie   = (strtoupper(trim($player['position'] ?? '')) === 'G');
$status     = strtoupper(trim($player['roster_status'] ?? 'PRO'));
$teamId     = (int)($player['team_id'] ?? 0);
$isFreeAg   = ($status === 'FA' || $teamId === 0);
$faType     = strtoupper(trim($player['fa_status'] ?? 'UFA')) ?: 'UFA';
$abbr       = strtoupper(trim($player['abbreviation'] ?? ''));
$sal        = (int)($player['salary'] ?? 0);
$yrs        = (int)($player['contract_years'] ?? 0);
?>
<div class="profile-card">
    <div class="profile-top">

        <?php if (!$isFreeAg && $abbr): ?>
            <img src="logos/<?= htmlspecialchars($abbr) ?>.svg" alt="<?= htmlspecialchars($abbr) ?>">
        <?php endif; ?>
        <div>
            <h2 class="p-title">
                <?= htmlspecialchars($fullName) ?>
                <?php if ($isFreeAg): ?>
                    <span class="badge <?= $faType === 'RFA' ? 'badge-rfa' : 'badge-fa' ?>">Free Agent (<?= htmlspecialchars($faType) ?>)</span>
                <?php else: ?>
                    <span class="badge badge-team"><?= htmlspecialchars($player['team_name'] ?: $abbr) ?> (<?= htmlspecialchars($status) ?>)</span>
                <?php endif; ?>
            </h2>
            <div class="p-sub">
                Position: <strong><?= htmlspecialchars($player['position']) ?></strong> &bull;
                Overall: <strong style="color:#38bdf8;"><?= (int)$player['overall_rating'] ?> OVR</strong> &bull;
                Age: <strong><?= (int)($player['age'] ?? 0) ?: '—' ?></strong>
                <?php if ($isFreeAg && !empty($player['prev_abbr'])): ?>
                    &bull; Prev Team: <strong><?= htmlspecialchars($player['prev_abbr']) ?></strong>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div>
        <?php if ($isFreeAg): ?>
            <a href="free_agency.php?q=<?= urlencode($fullName) ?>" class="action-btn action-fa">Place FA Bid &rarr;</a>
        <?php elseif ($myTeamId > 0 && $teamId === $myTeamId): ?>
            <span class="action-btn action-own">On Your Roster</span>
        <?php elseif ($teamId > 0): ?>
            <a href="trades.php?partner=<?= $teamId ?>&req_pid=<?= (int)$player['player_id'] ?>" class="action-btn action-trade">Make Trade Offer &rarr;</a>
        <?php endif; ?>
    </div>
</div>

<div class="grid-sections">
    <div class="stat-box">
        <h3>Contract &amp; Career</h3>
        <div class="kv-row"><span>Cap Hit / Salary</span><strong><?= $sal > 0 ? '$' . number_format($sal) : 'Unsigned' ?></strong></div>
        <div class="kv-row"><span>Contract Years Left</span><strong><?= $yrs > 0 ? $yrs . ' yr' . ($yrs > 1 ? 's' : '') : '—' ?></strong></div>
        <div class="kv-row"><span>Career NHL Games</span><strong><?= number_format((int)($player['nhl_games_played'] ?? 0)) ?></strong></div>
        <div class="kv-row"><span>Compare Tool</span><a href="compare_players.php?p1=<?= (int)$player['player_id'] ?>" style="color:#38bdf8;font-weight:bold;text-decoration:none;">Open in Compare &rarr;</a></div>
    </div>

    <div class="stat-box">
        <h3>Sim Ratings</h3>
        <div class="kv-row"><span>Overall (OVR)</span><strong style="color:#38bdf8;"><?= (int)$player['overall_rating'] ?></strong></div>
        <div class="kv-row"><span>Skating (SK)</span><strong><?= (int)$player['skating'] ?></strong></div>
        <div class="kv-row"><span>Shooting (SH)</span><strong><?= (int)$player['shooting'] ?></strong></div>
        <div class="kv-row"><span>Passing (PA)</span><strong><?= (int)$player['passing'] ?></strong></div>
        <div class="kv-row"><span>Defense (DF)</span><strong><?= (int)$player['defense'] ?></strong></div>
        <div class="kv-row"><span>Physical (PH)</span><strong><?= (int)$player['physical'] ?></strong></div>
        <?php if ($isGoalie): ?>
            <div class="kv-row"><span>Goaltending (GO)</span><strong><?= (int)$player['goaltending'] ?></strong></div>
        <?php endif; ?>
    </div>

        <div class="stat-box">
            <h3>Current Season Stats</h3>
            <div class="kv-row"><span>Games Played (GP)</span><strong><?= (int)$player['gp'] ?></strong></div>
            <?php if ($isGoalie): ?>
                <div class="kv-row"><span>Saves (SV)</span><strong><?= (int)$player['sv'] ?></strong></div>
                <div class="kv-row"><span>Goals Against (GA)</span><strong><?= (int)$player['ga'] ?></strong></div>
                <div class="kv-row"><span>Save Percentage (SV%)</span><strong><?= $player['sv_pct'] > 0 ? ltrim(number_format($player['sv_pct'], 3), '0') : '.000' ?></strong></div>
                <div class="kv-row"><span>Goals Against Avg (GAA)</span><strong><?= number_format($player['gaa'], 2) ?></strong></div>
            <?php else: ?>
                <div class="kv-row"><span>Goals (G)</span><strong><?= (int)$player['g'] ?></strong></div>
                <div class="kv-row"><span>Assists (A)</span><strong><?= (int)$player['a'] ?></strong></div>
                <div class="kv-row"><span>Points (PTS)</span><strong><?= (int)$player['pts'] ?></strong></div>
                <div class="kv-row"><span>Points / Game (P/GP)</span><strong><?= number_format($player['ppg'], 2) ?></strong></div>
                <div class="kv-row"><span>Shots on Goal (SOG)</span><strong><?= (int)$player['sog'] ?></strong></div>
                <div class="kv-row"><span>Hits / Blocks</span><strong><?= (int)$player['hit'] ?> / <?= (int)$player['blk'] ?></strong></div>
                <div class="kv-row"><span>Penalty Minutes (PIM)</span><strong><?= (int)$player['pim'] ?></strong></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php elseif ($prospect):
    $fullName = trim($prospect['first_name'] . ' ' . $prospect['last_name']);
    $teamId   = (int)($prospect['team_id'] ?? 0);
    $abbr     = strtoupper(trim($prospect['abbreviation'] ?? ''));
    ?>
    <div class="profile-card">
        <div class="profile-top">

            <?php if ($teamId > 0 && $abbr): ?>
                <img src="logos/<?= htmlspecialchars($abbr) ?>.svg" alt="<?= htmlspecialchars($abbr) ?>">
            <?php endif; ?>
            <div>
                <h2 class="p-title">
                    <?= htmlspecialchars($fullName) ?>
                    <span class="badge badge-prospect">Prospect</span>
                    <?php if ($teamId > 0): ?>
                        <span class="badge badge-team"><?= htmlspecialchars($prospect['team_name'] ?: $abbr) ?></span>
                    <?php endif; ?>
                </h2>
                <div class="p-sub">
                    Position: <strong><?= htmlspecialchars($prospect['position'] ?: '—') ?></strong>
                    <?php if (!empty($prospect['draft_year'])): ?>
                        &bull; Draft Year: <strong><?= htmlspecialchars($prospect['draft_year']) ?></strong>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div>
            <?php if ($myTeamId > 0 && $teamId === $myTeamId): ?>
                <span class="action-btn action-own">In Your System</span>
            <?php elseif ($teamId > 0): ?>
                <a href="trades.php?partner=<?= $teamId ?>&req_prid=<?= (int)$prospect['prospect_id'] ?>" class="action-btn action-trade">Make Trade Offer &rarr;</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="grid-sections">
        <div class="stat-box">
            <h3>Prospect Info</h3>
            <div class="kv-row"><span>Status</span><strong>Organizational Prospect</strong></div>
            <div class="kv-row"><span>Rights Held By</span><strong><?= htmlspecialchars($prospect['team_name'] ?: 'Unassigned') ?></strong></div>
            <div class="kv-row"><span>Draft Year</span><strong><?= htmlspecialchars($prospect['draft_year'] ?: '—') ?></strong></div>
            <?php if (!empty($prospect['draft_round']) || !empty($prospect['draft_overall'])): ?>
                <div class="kv-row"><span>Draft Slot</span><strong>Rd <?= htmlspecialchars($prospect['draft_round'] ?? '?') ?> (#<?= htmlspecialchars($prospect['draft_overall'] ?? '?') ?>)</strong></div>
            <?php endif; ?>
        </div>

        <?php if ($prRating): ?>
            <div class="stat-box">
                <h3>Scouting Report / Ratings</h3>
                <?php foreach ($prRating as $col => $val):
                    if (in_array($col, array('rating_id', 'prospect_id', 'updated_at', 'created_at')) || $val === null || $val === '') continue;
                    $niceLabel = ucwords(str_replace('_', ' ', $col));
                    ?>
                    <div class="kv-row"><span><?= htmlspecialchars($niceLabel) ?></span><strong><?= htmlspecialchars((string)$val) ?></strong></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    </div>
<?php endif; ?>

<script>
    const SEARCH_ITEMS = <?= json_encode($searchItems, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

    const form     = document.getElementById('lookupForm');
    const typeIn   = document.getElementById('item_type');
    const idIn     = document.getElementById('item_id');
    const input    = document.getElementById('playerSearch');
    const clearBtn = document.getElementById('clearSearch');
    const list     = document.getElementById('acList');

    function syncClear() {
        clearBtn.style.display = input.value.trim() !== '' ? 'block' : 'none';
    }

    function renderMatches(term) {
        const q = term.trim().toLowerCase();
        const matches = q === ''
            ? SEARCH_ITEMS.slice(0, 30)
            : SEARCH_ITEMS.filter(item => item.q.includes(q)).slice(0, 40);

        list.innerHTML = '';
        if (matches.length === 0) {
            list.innerHTML = '<div class="ac-item" style="color:#94a3b8;cursor:default;">No matching players or prospects</div>';
        } else {
            matches.forEach(m => {
                const div = document.createElement('div');
                div.className = 'ac-item';
                div.textContent = m.label;
                div.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    typeIn.value = m.type;
                    idIn.value   = m.id;
                    input.value  = m.label;
                    list.style.display = 'none';
                    syncClear();
                    form.submit();
                });
                list.appendChild(div);
            });
        }
        list.style.display = 'block';
    }

    input.addEventListener('focus', () => renderMatches(input.value));
    input.addEventListener('input', () => {
        idIn.value = 0;
        syncClear();
        renderMatches(input.value);
    });
    input.addEventListener('blur', () => {
        setTimeout(() => { list.style.display = 'none'; }, 150);
    });

    clearBtn.addEventListener('click', () => {
        idIn.value  = 0;
        input.value = '';
        syncClear();
        input.focus();
    });

    syncClear();
</script>
</body>
</html>


