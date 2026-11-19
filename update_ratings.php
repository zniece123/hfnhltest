<?php
require 'db.php';
/** @var PDO $pdo */

if (empty($_SESSION['is_commish']) && empty($_SESSION['is_admin'])) {
    die("Commissioner access only.");
}

$pdo->exec("ALTER TABLE players ADD COLUMN IF NOT EXISTS ahl_ovr INT NULL");
$pdo->exec("ALTER TABLE players ADD COLUMN IF NOT EXISTS ahl_gp INT NOT NULL DEFAULT 0");
$pdo->exec("ALTER TABLE players ADD COLUMN IF NOT EXISTS nhl_ovr INT NULL");
$pdo->exec("ALTER TABLE players ADD COLUMN IF NOT EXISTS nhl_season_gp INT NOT NULL DEFAULT 0");

$msg     = '';
$msgType = 'info';

function cleanMpName(string $name): string {
    $map = [
            'À'=>'A','Á'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','Å'=>'A','Ç'=>'C','È'=>'E','É'=>'E','Ê'=>'E','Ë'=>'E',
            'Ì'=>'I','Í'=>'I','Î'=>'I','Ï'=>'I','Ñ'=>'N','Ò'=>'O','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','Ù'=>'U',
            'Ú'=>'U','Û'=>'U','Ü'=>'U','Ý'=>'Y','à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a','ç'=>'c',
            'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','ñ'=>'n','ò'=>'o','ó'=>'o',
            'ô'=>'o','õ'=>'o','ö'=>'o','ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ý'=>'y','ÿ'=>'y','Č'=>'C','č'=>'c',
            'Ć'=>'C','ć'=>'c','Ě'=>'E','ě'=>'e','Ł'=>'L','ł'=>'l','Ń'=>'N','ń'=>'n','Ň'=>'N','ň'=>'n','Ř'=>'R',
            'ř'=>'r','Ś'=>'S','ś'=>'s','Š'=>'S','š'=>'s','Ť'=>'T','ť'=>'t','Ů'=>'U','ů'=>'u','Ź'=>'Z','ź'=>'z',
            'Ż'=>'Z','ż'=>'z','Ž'=>'Z','ž'=>'z'
    ];
    $name = preg_replace('/\s*\(total\)\s*$/i', '', trim($name));
    $n    = strtolower(strtr($name, $map));
    return preg_replace('/[^a-z0-9]/', '', $n);
}

function clampRating(float $val, int $min = 52, int $max = 98): int {
    return max($min, min($max, (int)round($val)));
}

function blendTwoWayRating(?int $nhlOvr, int $nhlGp, ?int $ahlOvr, int $ahlGp): int {
    if ($nhlOvr !== null && $nhlGp >= 25) {
        return $nhlOvr;
    }
    if ($nhlOvr !== null && $nhlGp > 0 && $ahlOvr !== null && $ahlGp > 0) {
        $nhlWeight = min(1.0, $nhlGp / 25.0);
        return clampRating(($nhlOvr * $nhlWeight) + ($ahlOvr * (1.0 - $nhlWeight)), 58, 96);
    }
    if ($nhlOvr !== null && $nhlGp > 0) {
        return $nhlOvr;
    }
    return $ahlOvr ?? 63;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['mp_csv']['tmp_name'])) {
    $fileType = $_POST['mp_type'] ?? 'skaters';
    $fh       = fopen($_FILES['mp_csv']['tmp_name'], 'r');

    if (!$fh) {
        $msgType = 'error';
        $msg     = 'Could not read uploaded CSV file.';
    } else {
        $playerMap = [];
        $allP = $pdo->query("
            SELECT player_id, first_name, last_name, position, ahl_ovr, ahl_gp, nhl_ovr, nhl_season_gp
            FROM players
            WHERE COALESCE(is_retired, FALSE) = FALSE
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($allP as $p) {
            $k = cleanMpName($p['first_name'] . $p['last_name']);
            $playerMap[$k][] = $p;
        }

        $updSkater = $pdo->prepare("
            UPDATE players
            SET overall_rating = ?, skating = ?, shooting = ?, passing = ?, defense = ?, physical = ?,
                nhl_ovr = ?, nhl_season_gp = ?,
                nhl_games_played = GREATEST(COALESCE(nhl_games_played, 0), ?)
            WHERE player_id = ?
        ");
        $updGoalie = $pdo->prepare("
            UPDATE players
            SET overall_rating = ?, goaltending = ?,
                nhl_ovr = ?, nhl_season_gp = ?,
                nhl_games_played = GREATEST(COALESCE(nhl_games_played, 0), ?)
            WHERE player_id = ?
        ");
        $updAhlSkater = $pdo->prepare("
            UPDATE players
            SET overall_rating = ?, skating = ?, shooting = ?, passing = ?, defense = ?, physical = ?,
                ahl_ovr = ?, ahl_gp = ?
            WHERE player_id = ?
        ");
        $updAhlGoalie = $pdo->prepare("
            UPDATE players
            SET overall_rating = ?, goaltending = ?,
                ahl_ovr = ?, ahl_gp = ?
            WHERE player_id = ?
        ");

        $updated = 0;

        if ($fileType === 'ahl_by_team') {
            $mode   = '';
            $colIdx = [];

            while (($row = fgetcsv($fh)) !== false) {
                if (count($row) < 5) continue;

                $c2 = strtolower(trim(strip_tags((string)($row ?? ''))));
                if ($c2 === 'player') {
                    $mode   = 'skaters';
                    $colIdx = [];
                    foreach ($row as $i => $h) {
                        $cleanH = strtolower(trim(strip_tags((string)$h)));
                        if ($cleanH !== '') $colIdx[$cleanH] = $i;
                    }
                    continue;
                } elseif ($c2 === 'goalie') {
                    $mode   = 'goalies';
                    $colIdx = [];
                    foreach ($row as $i => $h) {
                        $cleanH = strtolower(trim(strip_tags((string)$h)));
                        if ($cleanH !== '') $colIdx[$cleanH] = $i;
                    }
                    continue;
                }

                $jersey  = trim((string)($row ?? ''));
                $rawName = trim((string)($row ?? ''));
                if ($jersey === '' || $rawName === '' || stripos($rawName, 'TOTALS') !== false || stripos($rawName, 'BENCH') !== false || stripos($rawName, 'Empty Net') !== false) {
                    continue;
                }

                $k = cleanMpName($rawName);
                if (!isset($playerMap[$k])) continue;

                if ($mode === 'goalies') {
                    $gp    = (int)($row[$colIdx['gp'] ?? 3] ?? 0);
                    $gaa   = (float)($row[$colIdx['gaa'] ?? 11] ?? 3.00);
                    $sa    = (float)($row[$colIdx['sa'] ?? 18] ?? 0);
                    $svs   = (float)($row[$colIdx['svs'] ?? 19] ?? 0);

                    $regSv  = ($sa + 400.0) > 0 ? (($svs + (400.0 * 0.900)) / ($sa + 400.0)) : 0.900;
                    $base   = 65.5 + (($regSv - 0.900) * 220) + ((2.85 - $gaa) * 1.1);
                    if ($gp < 10) $base = min($base, 65);
                    $ahlOvr = clampRating($base, 58, 70);

                    foreach ($playerMap[$k] as $match) {
                        if (strtoupper($match['position']) === 'G') {
                            $finalOvr = blendTwoWayRating(
                                    $match['nhl_ovr'] !== null ? (int)$match['nhl_ovr'] : null,
                                    (int)$match['nhl_season_gp'],
                                    $ahlOvr,
                                    $gp
                            );
                            $updAhlGoalie->execute([$finalOvr, $finalOvr, $ahlOvr, $gp, $match['player_id']]);
                            $updated++;
                        }
                    }
                } elseif ($mode === 'skaters') {
                    $pos = strtoupper(trim((string)($row[$colIdx['pos'] ?? 3] ?? 'F')));
                    if ($pos === 'G') continue;

                    $gp    = (int)($row[$colIdx['gp'] ?? 4] ?? 0);
                    $g     = (float)($row[$colIdx['g'] ?? 5] ?? 0);
                    $a     = (float)($row[$colIdx['a'] ?? 6] ?? 0);
                    $pm    = (float)($row[$colIdx['+/-'] ?? 8] ?? 0);
                    $pim   = (float)($row[$colIdx['pim'] ?? 9] ?? 0);
                    $shots = (float)($row[$colIdx['shots'] ?? 26] ?? 0);

                    $effGp    = $gp + 15.0;
                    $gpg      = ($g + (15.0 * 0.15)) / $effGp;
                    $apg      = ($a + (15.0 * 0.25)) / $effGp;
                    $sogPg    = ($shots + (15.0 * 1.5)) / $effGp;
                    $pimPg    = ($pim + (15.0 * 0.5)) / $effGp;
                    $pmPg     = $pm / $effGp;
                    $posBonus = ($pos === 'D') ? 2.5 : 0.0;

                    $sh = clampRating(58 + ($gpg * 24.0) + ($sogPg * 1.8) + $posBonus, 56, 72);
                    $pa = clampRating(58 + ($apg * 17.0) + ($pmPg * 4.0) + $posBonus, 56, 72);
                    $sk = clampRating(60 + (($gpg + $apg) * 8.5) + ($sogPg * 1.2) + $posBonus, 56, 72);
                    $df = clampRating(59 + ($pmPg * 11.0) + ($pos === 'D' ? 4.5 : 0.0), 56, 72);
                    $ph = clampRating(58 + ($pimPg * 6.5) + ($pos === 'D' ? 2.5 : 0.0), 56, 72);

                    $rawOvr = ($sk * 0.22) + ($sh * 0.22) + ($pa * 0.22) + ($df * 0.22) + ($ph * 0.12);
                    if ($gp < 12) $rawOvr = min($rawOvr, 64);
                    $ahlOvr = clampRating($rawOvr, 58, 70);

                    foreach ($playerMap[$k] as $match) {
                        if (strtoupper($match['position']) !== 'G') {
                            $finalOvr = blendTwoWayRating(
                                    $match['nhl_ovr'] !== null ? (int)$match['nhl_ovr'] : null,
                                    (int)$match['nhl_season_gp'],
                                    $ahlOvr,
                                    $gp
                            );
                            $diff = $finalOvr - $ahlOvr;
                            $updAhlSkater->execute([
                                    $finalOvr,
                                    clampRating($sk + $diff),
                                    clampRating($sh + $diff),
                                    clampRating($pa + $diff),
                                    clampRating($df + $diff),
                                    clampRating($ph + $diff),
                                    $ahlOvr,
                                    $gp,
                                    $match['player_id']
                            ]);
                            $updated++;
                        }
                    }
                }
            }
        } else {
            $headers = fgetcsv($fh);
            $colIdx  = [];
            if ($headers) {
                foreach ($headers as $i => $h) {
                    $colIdx[strtolower(trim((string)$h))] = $i;
                }
            }

            while (($row = fgetcsv($fh)) !== false) {
                if (isset($colIdx['situation'])) {
                    $sit = strtolower(trim($row[$colIdx['situation']] ?? ''));
                    if ($sit !== '' && $sit !== 'all') continue;
                }

                $rawName = trim($row[$colIdx['name'] ?? -1] ?? '');
                if ($rawName === '') continue;

                $k = cleanMpName($rawName);
                if (!isset($playerMap[$k])) continue;

                $gp  = (int)($row[$colIdx['games_played'] ?? -1] ?? 0);
                $ice = (float)($row[$colIdx['icetime'] ?? -1] ?? 0);
                $hrs = $ice > 0 ? ($ice / 3600.0) : 0;

                if ($fileType === 'goalies') {
                    $xga   = (float)($row[$colIdx['xgoals'] ?? -1] ?? 0);
                    $ga    = (float)($row[$colIdx['goals'] ?? -1] ?? 0);
                    $shots = (float)($row[$colIdx['ongoal'] ?? -1] ?? 0);
                    $gsax  = $xga - $ga;

                    $priorShots     = 750.0;
                    $leagueAvgSv    = 0.902;
                    $regressedSvPct = ($shots + $priorShots) > 0
                            ? ((($shots - $ga) + ($priorShots * $leagueAvgSv)) / ($shots + $priorShots))
                            : $leagueAvgSv;

                    $workloadFactor = min(1.0, $gp / 45.0);
                    $weightedGsax   = $gsax * $workloadFactor;

                    $base = 78.5 + (($regressedSvPct - $leagueAvgSv) * 450) + ($weightedGsax * 0.25);

                    if ($gp < 10)      $base = min($base, 74);
                    elseif ($gp < 20)  $base = min($base, 78);
                    elseif ($gp < 30)  $base = min($base, 82);
                    elseif ($gp < 42)  $base = min($base, 86);

                    if ($gp >= 50)     $base = max($base, 80);
                    elseif ($gp >= 35) $base = max($base, 77);
                    elseif ($gp >= 20) $base = max($base, 74);
                    elseif ($gp >= 5)  $base = max($base, 71);

                    $nhlOvr = clampRating($base, 68, 96);

                    foreach ($playerMap[$k] as $match) {
                        if (strtoupper($match['position']) === 'G') {
                            $finalOvr = blendTwoWayRating(
                                    $nhlOvr,
                                    $gp,
                                    $match['ahl_ovr'] !== null ? (int)$match['ahl_ovr'] : null,
                                    (int)$match['ahl_gp']
                            );
                            $updGoalie->execute([$finalOvr, $finalOvr, $nhlOvr, $gp, $gp, $match['player_id']]);
                            $updated++;
                        }
                    }
                } else {
                    $goals  = (float)($row[$colIdx['i_f_goals'] ?? -1] ?? 0);
                    $a1     = (float)($row[$colIdx['i_f_primaryassists'] ?? -1] ?? 0);
                    $a2     = (float)($row[$colIdx['i_f_secondaryassists'] ?? -1] ?? 0);
                    $sog    = (float)($row[$colIdx['i_f_shotsongoal'] ?? -1] ?? 0);
                    $hits   = (float)($row[$colIdx['i_f_hits'] ?? -1] ?? 0);
                    $blocks = (float)($row[$colIdx['shotsblockedbyplayer'] ?? -1] ?? 0);
                    $onXga  = (float)($row[$colIdx['onice_a_xgoals'] ?? -1] ?? 0);
                    $onXgf  = (float)($row[$colIdx['onice_f_xgoals'] ?? -1] ?? 0);

                    $priorHrs = 6.0;
                    $effHrs   = $hrs + $priorHrs;

                    $g60   = ($goals + ($priorHrs * 0.45)) / $effHrs;
                    $a60   = (($a1 + $a2 * 0.7) + ($priorHrs * 0.75)) / $effHrs;
                    $sog60 = ($sog + ($priorHrs * 6.5)) / $effHrs;
                    $hit60 = ($hits + ($priorHrs * 4.5)) / $effHrs;
                    $blk60 = ($blocks + ($priorHrs * 2.2)) / $effHrs;
                    $xga60 = ($onXga + ($priorHrs * 2.65)) / $effHrs;
                    $xgPct = ($onXgf + $onXga + 20.0) > 0 ? (($onXgf + 10.0) / ($onXgf + $onXga + 20.0)) : 0.50;

                    $toiPerGame = $gp > 0 ? ($ice / 60.0) / $gp : 0;
                    $usageBonus = max(-1.5, min(4.0, ($toiPerGame - 13.5) * 0.48));

                    $sh = clampRating(63 + ($g60 * 16.5) + ($sog60 * 1.1) + $usageBonus);
                    $pa = clampRating(63 + ($a60 * 14.0) + (($xgPct - 0.50) * 45) + $usageBonus);
                    $sk = clampRating(67 + (($xgPct - 0.50) * 65) + ($sog60 * 0.8) + ($usageBonus * 0.8));
                    $df = clampRating(65 + ((2.75 - $xga60) * 14.0) + ($blk60 * 1.6) + ($usageBonus * 0.8));
                    $ph = clampRating(61 + ($hit60 * 2.1) + ($blk60 * 1.4));

                    $rawOvr = ($sk * 0.22) + ($sh * 0.22) + ($pa * 0.22) + ($df * 0.22) + ($ph * 0.12);

                    if ($gp < 12)     $rawOvr = min($rawOvr, 74);
                    elseif ($gp < 25) $rawOvr = min($rawOvr, 78);

                    if ($gp >= 65)     $rawOvr = max($rawOvr, 76);
                    elseif ($gp >= 40) $rawOvr = max($rawOvr, 74);
                    elseif ($gp >= 15) $rawOvr = max($rawOvr, 71);

                    $nhlOvr = clampRating($rawOvr, 68, 96);

                    foreach ($playerMap[$k] as $match) {
                        if (strtoupper($match['position']) !== 'G') {
                            $finalOvr = blendTwoWayRating(
                                    $nhlOvr,
                                    $gp,
                                    $match['ahl_ovr'] !== null ? (int)$match['ahl_ovr'] : null,
                                    (int)$match['ahl_gp']
                            );
                            $diff = $finalOvr - $nhlOvr;
                            $updSkater->execute([
                                    $finalOvr,
                                    clampRating($sk + $diff),
                                    clampRating($sh + $diff),
                                    clampRating($pa + $diff),
                                    clampRating($df + $diff),
                                    clampRating($ph + $diff),
                                    $nhlOvr,
                                    $gp,
                                    $gp,
                                    $match['player_id']
                            ]);
                            $updated++;
                        }
                    }
                }
            }
        }
        fclose($fh);
        $msgType = 'info';
        $msg     = "Ratings Update Complete! Updated {$updated} player rating profiles from CSV.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Annual Ratings Updater - HFNHL</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #0f172a; color: #f8fafc; padding: 16px 24px; max-width: 820px; margin: auto; }
        h1 { color: #38bdf8; }
        .card { background: #1e293b; border: 1px solid #334155; border-top: 4px solid #0284c7; border-bottom: 3px solid #dc2626; border-radius: 10px; padding: 22px; }
        .row { margin-bottom: 16px; }
        label { display: block; color: #cbd5e1; font-weight: bold; margin-bottom: 6px; font-size: 0.9em; }
        select, input[type="file"] { width: 100%; background: #0f172a; color: #f8fafc; border: 1px solid #475569; padding: 10px; border-radius: 6px; box-sizing: border-box; }
        .btn { background: #0284c7; color: #fff; border: none; padding: 11px 20px; border-radius: 6px; font-weight: bold; cursor: pointer; font-size: 0.95em; }
        .btn:hover { background: #0369a1; }
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-weight: bold; }
        .alert.info { background: rgba(2, 132, 199, 0.2); border: 1px solid #38bdf8; color: #7dd3fc; }
        .alert.error { background: rgba(220, 38, 38, 0.2); border: 1px solid #ef4444; color: #fca5a5; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>

<h1>Annual Ratings Updater</h1>
<p style="color:#94a3b8;">Upload your <strong>AHL Full League</strong> CSV and <strong>NHL (MoneyPuck)</strong> CSVs in any order—two-way players automatically blend based on NHL vs. AHL games played.</p>

<?php if ($msg): ?>
    <div class="alert <?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="card">
    <form method="POST" enctype="multipart/form-data">
        <div class="row">
            <label>CSV Type:</label>
            <select name="mp_type">
                <option value="ahl_by_team">AHL Full League (Player Stats by Team CSV - All Skaters & Goalies)</option>
                <option value="skaters">NHL Skaters (MoneyPuck skaters.csv)</option>
                <option value="goalies">NHL Goalies (MoneyPuck goalies.csv)</option>
            </select>
        </div>
        <div class="row">
            <label>Select CSV File:</label>
            <input type="file" name="mp_csv" accept=".csv" required>
        </div>
        <button type="submit" class="btn">Upload & Recalculate Ratings</button>
    </form>
</div>

</body>
</html>




