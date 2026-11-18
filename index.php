<?php
require 'db.php';
/** @var PDO $pdo */

if (!function_exists('getNhlLogoUrl')) {
    function getNhlLogoUrl($abbr) {
        $a = strtoupper(trim($abbr));
        return "logos/{$a}.svg";
    }
}

$newsList = $pdo->query("SELECT * FROM league_news ORDER BY created_at DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);

$recentTrades = $pdo->query("
    SELECT tr.*, t1.abbreviation AS prop_abbr, t2.abbreviation AS rec_abbr
    FROM trades tr
    JOIN teams t1 ON tr.proposing_team_id = t1.team_id
    JOIN teams t2 ON tr.receiving_team_id = t2.team_id
    WHERE tr.status = 'APPROVED'
    ORDER BY tr.updated_at DESC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

$tbHeadlines = $pdo->query("
    SELECT tb.created_at AS event_date,
           p.first_name, p.last_name, p.position, p.overall_rating,
           p.trade_block_notes,
           t.abbreviation AS team_abbr, t.name AS team_name
    FROM trade_block tb
    JOIN players p ON tb.player_id = p.player_id
    JOIN teams t ON p.team_id = t.team_id
    WHERE COALESCE(p.is_retired, FALSE) = FALSE
    ORDER BY tb.created_at DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

$waiverHeadlines = array();
try {
    $waiverHeadlines = $pdo->query("
        SELECT ww.placed_at AS event_date,
               p.first_name, p.last_name, p.position, p.overall_rating, p.salary,
               t.abbreviation AS team_abbr
        FROM waiver_wire ww
        JOIN players p ON ww.player_id = p.player_id
        JOIN teams t ON ww.waiving_team_id = t.team_id
        WHERE ww.status = 'ACTIVE'
        ORDER BY ww.placed_at DESC
        LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $waiverHeadlines = array();
}

$headlines = array();
foreach ($recentTrades as $tr) {
    $headlines[] = array(
            'type' => 'TRADE',
            'date' => $tr['updated_at'],
            'data' => $tr
    );
}
foreach ($tbHeadlines as $tb) {
    $headlines[] = array(
            'type' => 'BLOCK',
            'date' => $tb['event_date'],
            'data' => $tb
    );
}
foreach ($waiverHeadlines as $wh) {
    $headlines[] = array(
            'type' => 'WAIVER',
            'date' => $wh['event_date'],
            'data' => $wh
    );
}
usort($headlines, function($a, $b) {
    return strtotime($b['date']) <=> strtotime($a['date']);
});
$headlines = array_slice($headlines, 0, 15);

$pMap = array();
foreach ($pdo->query("SELECT player_id, first_name, last_name, position FROM players") as $p) {
    $pMap[$p['player_id']] = "{$p['first_name']} {$p['last_name']} ({$p['position']})";
}
$pkMap = array();
foreach ($pdo->query("SELECT dp.pick_id, dp.draft_year, dp.round_num, orig.abbreviation AS orig_abbr FROM draft_picks dp JOIN teams orig ON dp.original_team_id = orig.team_id") as $pk) {
    $pkMap[$pk['pick_id']] = "{$pk['draft_year']} Rd {$pk['round_num']} ({$pk['orig_abbr']})";
}

function summarizeAssets($pgP, $pgPk, $pMap, $pkMap) {
    $out = array();
    foreach (array_filter(explode(',', trim($pgP ?? '{}', '{}'))) as $id) $out[] = $pMap[(int)$id] ?? "Player #{$id}";
    foreach (array_filter(explode(',', trim($pgPk ?? '{}', '{}'))) as $id) $out[] = $pkMap[(int)$id] ?? "Pick #{$id}";
    return empty($out) ? 'Future Considerations' : implode(', ', $out);
}

$freeAgents = $pdo->query("
    SELECT player_id, first_name, last_name, position, overall_rating, age
    FROM players
    WHERE team_id IS NULL
      AND COALESCE(is_retired, FALSE) = FALSE
    ORDER BY overall_rating DESC, last_name ASC
    LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);

$totalFreeAgents = (int)$pdo->query("
    SELECT COUNT(*)
    FROM players
    WHERE team_id IS NULL
      AND COALESCE(is_retired, FALSE) = FALSE
")->fetchColumn();

$tickerGames = $pdo->query("
    SELECT g.game_id, g.game_date, g.home_score, g.away_score, g.status,
           ht.abbreviation AS home_abbr, at.abbreviation AS away_abbr
    FROM games g
    JOIN teams ht ON g.home_team_id = ht.team_id
    JOIN teams at ON g.away_team_id = at.team_id
    WHERE g.status = 'FINAL'
    ORDER BY g.game_date DESC, g.game_id DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

$gamesPlayedTotal = (int)$pdo->query("SELECT COUNT(*) FROM games WHERE status = 'FINAL'")->fetchColumn();
$activeWaiverCnt  = count($waiverHeadlines);
$myTeamId         = (int)($_SESSION['team_id'] ?? 0);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>HFNHL Hockey Simulator</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: radial-gradient(circle at top center, #132238 0%, #09111e 55%, #050911 100%);
            color: #f8fafc;
            padding: 16px 24px 40px 24px;
            max-width: 1440px;
            margin: auto;
            min-height: 100vh;
        }
        .score-ticker {
            display: flex;
            gap: 10px;
            overflow-x: auto;
            padding-bottom: 6px;
            margin-bottom: 18px;
        }
        .score-card {
            background: rgba(15, 23, 42, 0.92);
            border: 1px solid #2b4570;
            border-radius: 8px;
            padding: 8px 12px;
            min-width: 148px;
            text-decoration: none;
            color: #f8fafc;
            flex-shrink: 0;
            transition: border-color 0.15s, transform 0.15s;
        }
        .score-card:hover {
            border-color: #38bdf8;
            transform: translateY(-1px);
        }
        .sc-top {
            display: flex;
            justify-content: space-between;
            font-size: 0.72em;
            color: #94a3b8;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .sc-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.88em;
            margin: 2px 0;
        }
        .sc-team {
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: bold;
        }
        .sc-team img { width: 18px; height: 18px; object-fit: contain; }
        .sc-win { color: #4ade80; font-weight: bold; }

        .portal-hero {
            background: linear-gradient(125deg, #0c2340 0%, #0f172a 55%, #1e1b4b 100%);
            border: 1px solid #2b4570;
            border-radius: 12px;
            padding: 22px 28px;
            margin-bottom: 22px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
            box-shadow: 0 10px 28px rgba(0, 0, 0, 0.45);
        }
        .hero-left h1 {
            margin: 0 0 6px 0;
            font-size: 1.75em;
            color: #ffffff;
            letter-spacing: 0.3px;
        }
        .hero-left p {
            margin: 0 0 14px 0;
            color: #94a3b8;
            font-size: 0.95em;
        }
        .quick-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .q-btn {
            background: rgba(56, 189, 248, 0.14);
            color: #7dd3fc;
            border: 1px solid rgba(56, 189, 248, 0.35);
            padding: 7px 14px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.85em;
            font-weight: bold;
            transition: all 0.15s;
        }
        .q-btn:hover { background: #0284c7; color: #ffffff; border-color: #38bdf8; }
        .q-btn.gold {
            background: rgba(251, 191, 36, 0.14);
            color: #fde047;
            border-color: rgba(251, 191, 36, 0.4);
        }
        .q-btn.gold:hover { background: #d97706; color: #ffffff; }

        .hero-stats { display: flex; gap: 14px; flex-wrap: wrap; }
        .stat-pill {
            background: rgba(2, 6, 23, 0.7);
            border: 1px solid #334155;
            border-radius: 10px;
            padding: 12px 18px;
            text-align: center;
            min-width: 115px;
        }
        .stat-pill .num { display: block; font-size: 1.45em; font-weight: bold; color: #38bdf8; }
        .stat-pill .lbl { font-size: 0.75em; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; }

        .home-grid {
            display: grid;
            grid-template-columns: 1.55fr 1fr;
            gap: 24px;
            align-items: start;
        }
        @media (max-width: 980px) {
            .home-grid { grid-template-columns: 1fr; }
        }
        .panel {
            background: rgba(30, 41, 59, 0.88);
            border: 1px solid #334155;
            border-radius: 12px;
            padding: 20px 22px;
            margin-bottom: 22px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.32);
        }
        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #334155;
            padding-bottom: 10px;
            margin-bottom: 16px;
        }
        .panel-header h2 { color: #38bdf8; margin: 0; font-size: 1.15em; letter-spacing: 0.2px; }
        .panel-tag {
            background: #0f172a;
            color: #94a3b8;
            border: 1px solid #334155;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75em;
            font-weight: bold;
        }
        .news-item {
            background: #0f172a;
            border: 1px solid #26354d;
            border-left: 4px solid #38bdf8;
            border-radius: 8px;
            padding: 15px 18px;
            margin-bottom: 14px;
        }
        .news-item:last-child { margin-bottom: 0; }
        .news-title { font-size: 1.15em; font-weight: bold; color: #f8fafc; }
        .news-meta { font-size: 0.8em; color: #64748b; margin: 4px 0 10px 0; }
        .news-body { color: #cbd5e1; line-height: 1.55; white-space: pre-line; font-size: 0.95em; }

        .headline-scroll { max-height: 440px; overflow-y: auto; padding-right: 4px; }
        .trade-box {
            background: #0f172a;
            border: 1px solid #28364f;
            border-radius: 8px;
            padding: 11px 14px;
            margin-bottom: 10px;
            font-size: 0.9em;
            transition: border-color 0.15s;
        }
        .trade-box:hover { border-color: #475569; }
        .trade-box.type-trade { border-left: 3px solid #22c55e; }
        .trade-box.type-block { border-left: 3px solid #fbbf24; }
        .trade-box.type-waiver { border-left: 3px solid #38bdf8; }
        .headline-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.78em;
            color: #94a3b8;
            margin-bottom: 6px;
        }
        .badge-hl { padding: 2px 7px; border-radius: 4px; font-weight: bold; font-size: 0.9em; }
        .badge-hl.trade { background: rgba(34, 197, 94, 0.16); color: #4ade80; }
        .badge-hl.block { background: rgba(251, 191, 36, 0.16); color: #fbbf24; }
        .badge-hl.waiver { background: rgba(56, 189, 248, 0.16); color: #38bdf8; }
        .trade-row { display: flex; align-items: center; gap: 9px; margin: 4px 0; }
        .trade-row img { width: 22px; height: 22px; object-fit: contain; flex-shrink: 0; }

        .fa-scroll { max-height: 410px; overflow-y: auto; border: 1px solid #28364f; border-radius: 8px; }
        table.fa-table { width: 100%; border-collapse: collapse; font-size: 0.88em; background: #0f172a; }
        table.fa-table th, table.fa-table td { padding: 8px 10px; border-bottom: 1px solid #1e293b; text-align: left; }
        table.fa-table th { color: #38bdf8; background: #020617; position: sticky; top: 0; }
        table.fa-table tr:hover { background: #172338; }
        .fa-link { color: #f8fafc; text-decoration: none; font-weight: bold; }
        .fa-link:hover { color: #38bdf8; }
        .pos-pill {
            background: #1e293b;
            color: #93c5fd;
            border: 1px solid #334155;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.82em;
            font-weight: bold;
        }
        .ovr-badge {
            background: #0284c7;
            color: #fff;
            padding: 2px 7px;
            border-radius: 4px;
            font-weight: bold;
            font-size: 0.88em;
        }
        .panel-footer-link {
            display: block;
            margin-top: 12px;
            text-align: right;
            color: #38bdf8;
            font-size: 0.85em;
            font-weight: bold;
            text-decoration: none;
        }
        .panel-footer-link:hover { text-decoration: underline; }
    </style>
</head>
<body>

<?php include 'header.php'; ?>

<?php if (!empty($tickerGames)): ?>
    <div class="score-ticker">
        <?php foreach ($tickerGames as $tg):
            $hWin = (int)$tg['home_score'] > (int)$tg['away_score'];
            $aWin = (int)$tg['away_score'] > (int)$tg['home_score'];
            ?>
            <a class="score-card" href="boxscore.php?id=<?= (int)$tg['game_id'] ?>">
                <div class="sc-top">
                    <span>FINAL</span>
                    <span><?= date('M j', strtotime($tg['game_date'])) ?></span>
                </div>
                <div class="sc-row">
                    <span class="sc-team"><img src="<?= getNhlLogoUrl($tg['away_abbr']) ?>" alt=""><?= htmlspecialchars($tg['away_abbr']) ?></span>
                    <span class="<?= $aWin ? 'sc-win' : '' ?>"><?= (int)$tg['away_score'] ?></span>
                </div>
                <div class="sc-row">
                    <span class="sc-team"><img src="<?= getNhlLogoUrl($tg['home_abbr']) ?>" alt=""><?= htmlspecialchars($tg['home_abbr']) ?></span>
                    <span class="<?= $hWin ? 'sc-win' : '' ?>"><?= (int)$tg['home_score'] ?></span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="portal-hero">
    <div class="hero-left">
        <h1>HockeysFuture NHL Portal</h1>
        <p>Official League Simulation Hub &bull; 2026–27 Season</p>
        <div class="quick-actions">
            <?php if ($myTeamId > 0): ?>
                <a class="q-btn" href="team.php?id=<?= $myTeamId ?>">My Franchise</a>
                <a class="q-btn" href="lines.php">Line Editor</a>
                <a class="q-btn" href="contracts.php">Contract Center</a>
                <a class="q-btn" href="standings.php">Standings</a>
                <a class="q-btn gold" href="waivers.php">Waiver Wire<?= $activeWaiverCnt > 0 ? " ({$activeWaiverCnt})" : '' ?></a>
            <?php else: ?>
                <a class="q-btn" href="standings.php">League Standings</a>
                <a class="q-btn" href="schedule.php">Scores &amp; Schedule</a>
                <a class="q-btn gold" href="waivers.php">Waiver Wire</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="hero-stats">
        <div class="stat-pill">
            <span class="num"><?= $gamesPlayedTotal ?></span>
            <span class="lbl">Games Played</span>
        </div>
        <div class="stat-pill">
            <span class="num" style="color:#fbbf24;"><?= $activeWaiverCnt ?></span>
            <span class="lbl">On Waivers</span>
        </div>
        <div class="stat-pill">
            <span class="num" style="color:#4ade80;"><?= $totalFreeAgents ?></span>
            <span class="lbl">Free Agents</span>
        </div>
    </div>
</div>

<div class="home-grid">
    <div class="panel">
        <div class="panel-header">
            <h2>HockeysFuture NHL News</h2>
            <span class="panel-tag">LEAGUE DESK</span>
        </div>
        <?php if (empty($newsList)): ?>
            <p style="color:#94a3b8;font-size:0.9em;">No news articles posted yet.</p>
        <?php else: ?>
            <?php foreach ($newsList as $n): ?>
                <div class="news-item">
                    <div class="news-title"><?= htmlspecialchars($n['title']) ?></div>
                    <div class="news-meta">By <?= htmlspecialchars($n['author']) ?> &bull; <?= date('M j, Y g:i A', strtotime($n['created_at'])) ?></div>
                    <div class="news-body"><?= htmlspecialchars($n['body']) ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div>
        <div class="panel">
            <div class="panel-header">
                <h2>Top Headlines &amp; Transactions</h2>
                <span class="panel-tag">LIVE WIRE</span>
            </div>
            <?php if (empty($headlines)): ?>
                <p style="color:#94a3b8;font-size:0.9em;">No headlines or completed trades yet this season.</p>
            <?php else: ?>
                <div class="headline-scroll">
                    <?php foreach ($headlines as $item): ?>
                        <?php if ($item['type'] === 'TRADE'): $tr = $item['data']; ?>
                            <div class="trade-box type-trade">
                                <div class="headline-meta">
                                    <span class="badge-hl trade">TRADE</span>
                                    <span><?= date('M j, Y', strtotime($tr['updated_at'])) ?></span>
                                </div>
                                <div class="trade-row">
                                    <img src="<?= getNhlLogoUrl($tr['prop_abbr']) ?>" alt="">
                                    <span><strong><?= htmlspecialchars($tr['prop_abbr']) ?></strong> acquires: <?= htmlspecialchars(summarizeAssets($tr['requested_player_ids'], $tr['requested_pick_ids'] ?? '{}', $pMap, $pkMap)) ?></span>
                                </div>
                                <div class="trade-row">
                                    <img src="<?= getNhlLogoUrl($tr['rec_abbr']) ?>" alt="">
                                    <span><strong><?= htmlspecialchars($tr['rec_abbr']) ?></strong> acquires: <?= htmlspecialchars(summarizeAssets($tr['offered_player_ids'], $tr['offered_pick_ids'] ?? '{}', $pMap, $pkMap)) ?></span>
                                </div>
                            </div>
                        <?php elseif ($item['type'] === 'WAIVER'): $wh = $item['data']; ?>
                            <div class="trade-box type-waiver">
                                <div class="headline-meta">
                                    <span class="badge-hl waiver">WAIVERS</span>
                                    <span><?= date('M j, Y', strtotime($wh['event_date'])) ?></span>
                                </div>
                                <div class="trade-row">
                                    <img src="<?= getNhlLogoUrl($wh['team_abbr']) ?>" alt="">
                                    <span>
                                        <strong><?= htmlspecialchars($wh['team_abbr']) ?></strong> placed
                                        <strong><?= htmlspecialchars("{$wh['first_name']} {$wh['last_name']}") ?></strong>
                                        (<?= htmlspecialchars($wh['position']) ?> &bull; <?= (int)$wh['overall_rating'] ?> OVR) on waivers.
                                    </span>
                                </div>
                            </div>
                        <?php else: $tb = $item['data']; ?>
                            <div class="trade-box type-block">
                                <div class="headline-meta">
                                    <span class="badge-hl block">TRADE BLOCK</span>
                                    <span><?= date('M j, Y', strtotime($tb['event_date'])) ?></span>
                                </div>
                                <div class="trade-row">
                                    <img src="<?= getNhlLogoUrl($tb['team_abbr']) ?>" alt="">
                                    <span>
                                        <strong><?= htmlspecialchars($tb['team_abbr']) ?></strong> placed
                                        <strong><?= htmlspecialchars("{$tb['first_name']} {$tb['last_name']}") ?></strong>
                                        (<?= htmlspecialchars($tb['position']) ?> &bull; <?= (int)$tb['overall_rating'] ?> OVR) on the trade block.
                                        <?php if (!empty($tb['trade_block_notes'])): ?>
                                            <br><em style="color:#94a3b8;font-size:0.9em;">"<?= htmlspecialchars($tb['trade_block_notes']) ?>"</em>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="panel">
            <div class="panel-header">
                <h2>Top 30 Free Agents</h2>
                <span class="panel-tag">UFA POOL</span>
            </div>
            <?php if (empty($freeAgents)): ?>
                <p style="color:#94a3b8;font-size:0.9em;">No unsigned free agents currently in the pool.</p>
            <?php else: ?>
                <div class="fa-scroll">
                    <table class="fa-table">
                        <tr>
                            <th>Player</th>
                            <th>Pos</th>
                            <th>Age</th>
                            <th style="text-align:right;">OVR</th>
                        </tr>
                        <?php foreach ($freeAgents as $fa): ?>
                            <tr>
                                <td>
                                    <a class="fa-link" href="free_agents.php?search=<?= urlencode($fa['last_name']) ?>">
                                        <?= htmlspecialchars($fa['first_name'] . ' ' . $fa['last_name']) ?>
                                    </a>
                                </td>
                                <td><span class="pos-pill"><?= htmlspecialchars($fa['position']) ?></span></td>
                                <td style="color:#94a3b8;"><?= !empty($fa['age']) ? (int)$fa['age'] : '—' ?></td>
                                <td style="text-align:right;"><span class="ovr-badge"><?= (int)$fa['overall_rating'] ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
                <a class="panel-footer-link" href="free_agents.php">
                    View Full Free Agent Market (<?= $totalFreeAgents ?> Available) &rarr;
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

</body>
</html>




