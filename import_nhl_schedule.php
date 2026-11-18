<?php
require 'db.php';
require_once 'sim_engine.php';
/** @var PDO $pdo */

if (empty($_SESSION['is_commish']) && empty($_SESSION['is_admin'])) {
    die("Commissioner access only.");
}

ensureSimSchema($pdo);

$msg     = '';
$msgType = 'info';

function fetchNhlJson(string $url): ?array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERAGENT      => 'Mozilla/5.0'
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        if ($res) return json_decode($res, true);
    }
    $ctx = stream_context_create(['http' => ['timeout' => 15], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $res = @file_get_contents($url, false, $ctx);
    return $res ? json_decode($res, true) : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['start_date']) && !empty($_POST['end_date'])) {
    set_time_limit(0);
    $startDate = $_POST['start_date'];
    $endDate   = $_POST['end_date'];
    $clearOld  = !empty($_POST['clear_existing']);

    if ($clearOld) {
        $pdo->exec("DELETE FROM player_game_stats WHERE game_id IN (SELECT game_id FROM games WHERE COALESCE(game_type, 'REGULAR') = 'REGULAR')");
        $pdo->exec("DELETE FROM games WHERE COALESCE(game_type, 'REGULAR') = 'REGULAR'");
    }

    $teamMap = [];
    foreach ($pdo->query("SELECT team_id, abbreviation FROM teams")->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $teamMap[strtoupper(trim($t['abbreviation']))] = (int)$t['team_id'];
    }
    if (isset($teamMap['UTA'])) $teamMap['ARI'] = $teamMap['UTA'];
    if (isset($teamMap['LAK'])) $teamMap['LA']  = $teamMap['LAK'];
    if (isset($teamMap['NJD'])) $teamMap['NJ']  = $teamMap['NJD'];
    if (isset($teamMap['SJS'])) $teamMap['SJ']  = $teamMap['SJS'];
    if (isset($teamMap['TBL'])) $teamMap['TB']  = $teamMap['TBL'];

    $insGame = $pdo->prepare("
        INSERT INTO games (game_date, home_team_id, away_team_id, status, game_type)
        VALUES (?, ?, ?, 'SCHEDULED', 'REGULAR')
    ");

    $seenNhlGameIds = [];
    $imported       = 0;
    $curDate        = $startDate;
    $apiHost        = 'https://api-web' . '.nhle.com';

    while ($curDate <= $endDate) {
        $data = fetchNhlJson("{$apiHost}/v1/schedule/{$curDate}");
        if (!empty($data['gameWeek'])) {
            foreach ($data['gameWeek'] as $day) {
                $gDate = $day['date'] ?? '';
                if ($gDate < $startDate || $gDate > $endDate) continue;

                foreach (($day['games'] ?? []) as $g) {
                    $nhlGid = (int)($g['id'] ?? 0);
                    if ((int)($g['gameType'] ?? 0) !== 2 || isset($seenNhlGameIds[$nhlGid])) continue;
                    $seenNhlGameIds[$nhlGid] = true;

                    $hAbbr = strtoupper(trim($g['homeTeam']['abbrev'] ?? ''));
                    $aAbbr = strtoupper(trim($g['awayTeam']['abbrev'] ?? ''));

                    if (isset($teamMap[$hAbbr], $teamMap[$aAbbr])) {
                        $insGame->execute([$gDate, $teamMap[$hAbbr], $teamMap[$aAbbr]]);
                        $imported++;
                    }
                }
            }
        }
        $curDate = date('Y-m-d', strtotime($curDate . ' +7 days'));
        usleep(50000);
    }

    $msgType = 'info';
    $msg     = "Imported {$imported} official NHL regular-season games ({$startDate} to {$endDate})!";
}

$regCount = (int)$pdo->query("SELECT COUNT(*) FROM games WHERE COALESCE(game_type, 'REGULAR') = 'REGULAR'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Import 84-Game NHL Schedule - HFNHL</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #0f172a; color: #f8fafc; padding: 16px 24px; max-width: 820px; margin: auto; }
        h1 { color: #38bdf8; }
        .card { background: #1e293b; border: 1px solid #334155; border-top: 4px solid #0284c7; border-radius: 10px; padding: 20px; }
        .row { margin-bottom: 14px; }
        label { display: block; color: #cbd5e1; font-weight: bold; margin-bottom: 5px; font-size: 0.9em; }
        input[type="date"] { background: #0f172a; color: #f8fafc; border: 1px solid #475569; padding: 8px 12px; border-radius: 6px; }
        .btn { background: #0284c7; color: #fff; border: none; padding: 10px 18px; border-radius: 6px; font-weight: bold; cursor: pointer; font-size: 0.95em; }
        .btn:hover { background: #0369a1; }
        .alert { background: rgba(2, 132, 199, 0.2); border: 1px solid #38bdf8; color: #7dd3fc; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-weight: bold; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>

<h1>Official 84-Game NHL Schedule Importer</h1>
<p style="color:#94a3b8;">Fetches the real NHL regular-season schedule directly from NHL.com (currently <strong><?= $regCount ?></strong> regular-season games in database).</p>

<?php if ($msg): ?>
    <div class="alert"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="card">
    <form method="POST">
        <div class="row">
            <label>NHL Regular Season Start Date:</label>
            <input type="date" name="start_date" value="2026-10-01" required>
        </div>
        <div class="row">
            <label>NHL Regular Season End Date:</label>
            <input type="date" name="end_date" value="2027-04-20" required>
        </div>
        <div class="row">
            <label style="font-weight:normal;">
                <input type="checkbox" name="clear_existing" value="1">
                Clear existing regular-season games &amp; stats before importing (recommended when starting a brand-new season)
            </label>
        </div>
        <button type="submit" class="btn">Import Official NHL Schedule</button>
    </form>
</div>

</body>
</html>

