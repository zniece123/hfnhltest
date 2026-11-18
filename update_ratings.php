<?php
require 'db.php';
/** @var PDO $pdo */

if (empty($_SESSION['is_commish']) && empty($_SESSION['is_admin'])) {
    die("Commissioner access only.");
}

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
    $n = strtolower(strtr(trim($name), $map));
    return preg_replace('/[^a-z0-9]/', '', $n);
}

function clampRating(float $val, int $min = 52, int $max = 98): int {
    return max($min, min($max, (int)round($val)));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['mp_csv']['tmp_name'])) {
    $fileType = $_POST['mp_type'] ?? 'skaters';
    $fh       = fopen($_FILES['mp_csv']['tmp_name'], 'r');

    if (!$fh) {
        $msgType = 'error';
        $msg     = 'Could not read uploaded CSV file.';
    } else {
        $headers = fgetcsv($fh);
        $colIdx  = [];
        foreach ($headers as $i => $h) {
            $colIdx[strtolower(trim((string)$h))] = $i;
        }

        $playerMap = [];
        $allP = $pdo->query("SELECT player_id, first_name, last_name, position FROM players WHERE COALESCE(is_retired, FALSE) = FALSE")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($allP as $p) {
            $k = cleanMpName($p['first_name'] . $p['last_name']);
            $playerMap[$k][] = $p;
        }

        $updSkater = $pdo->prepare("
            UPDATE players
            SET overall_rating = ?, skating = ?, shooting = ?, passing = ?, defense = ?, physical = ?,
                nhl_games_played = GREATEST(COALESCE(nhl_games_played, 0), ?)
            WHERE player_id = ?
        ");
        $updGoalie = $pdo->prepare("
            UPDATE players
            SET overall_rating = ?, goaltending = ?,
                nhl_games_played = GREATEST(COALESCE(nhl_games_played, 0), ?)
            WHERE player_id = ?
        ");

        $updated = 0;
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
                $svPct = $shots > 0 ? (($shots - $ga) / $shots) : 0.900;

                $base = 76 + ($svPct - 0.905) * 420 + ($gsax * 0.35);
                if ($gp < 10) $base = min($base, 74);
                $ovr = clampRating($base, 55, 97);

                foreach ($playerMap[$k] as $match) {
                    if (strtoupper($match['position']) === 'G') {
                        $updGoalie->execute([$ovr, $ovr, $gp, $match['player_id']]);
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

                $g60   = $hrs > 0.5 ? ($goals / $hrs) : 0;
                $a60   = $hrs > 0.5 ? (($a1 + $a2 * 0.7) / $hrs) : 0;
                $sog60 = $hrs > 0.5 ? ($sog / $hrs) : 0;
                $hit60 = $hrs > 0.5 ? ($hits / $hrs) : 0;
                $blk60 = $hrs > 0.5 ? ($blocks / $hrs) : 0;
                $xga60 = $hrs > 0.5 ? ($onXga / $hrs) : 2.6;
                $xgPct = ($onXgf + $onXga) > 0 ? ($onXgf / ($onXgf + $onXga)) : 0.50;

                $sh = clampRating(62 + ($g60 * 16.5) + ($sog60 * 1.1));
                $pa = clampRating(62 + ($a60 * 14.0) + (($xgPct - 0.50) * 45));
                $sk = clampRating(66 + (($xgPct - 0.50) * 65) + ($sog60 * 0.8));
                $df = clampRating(64 + ((2.75 - $xga60) * 14.0) + ($blk60 * 1.6));
                $ph = clampRating(60 + ($hit60 * 2.1) + ($blk60 * 1.4));

                $rawOvr = ($sk * 0.22) + ($sh * 0.22) + ($pa * 0.22) + ($df * 0.22) + ($ph * 0.12);
                if ($gp < 12) $rawOvr = min($rawOvr, 73);
                $ovr = clampRating($rawOvr, 55, 98);

                foreach ($playerMap[$k] as $match) {
                    if (strtoupper($match['position']) !== 'G') {
                        $updSkater->execute([$ovr, $sk, $sh, $pa, $df, $ph, $gp, $match['player_id']]);
                        $updated++;
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
<p style="color:#94a3b8;">Upload Ratings <strong>skaters.csv</strong> or <strong>goalies.csv</strong> each offseason to recalculate player ratings (OVR, SK, SH, PA, DF, PH, GO) automatically.</p>

<?php if ($msg): ?>
    <div class="alert <?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="card">
    <form method="POST" enctype="multipart/form-data">
        <div class="row">
            <label>CSV Type:</label>
            <select name="mp_type">
                <option value="skaters">Skaters CSV (skaters.csv)</option>
                <option value="goalies">Goalies CSV (goalies.csv)</option>
            </select>
        </div>
        <div class="row">
            <label>Select CSV File:</label>
            <input type="file" name="mp_csv" accept=".csv" required>
        </div>
        <button type="submit" class="btn">Upload &amp; Recalculate Player Ratings</button>
    </form>
</div>

</body>
</html>

