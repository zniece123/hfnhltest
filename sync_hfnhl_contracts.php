<?php
ini_set('display_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED);
set_time_limit(300);

require 'db.php';
/** @var PDO $pdo */

$teamMap = array();
foreach ($pdo->query("SELECT team_id, name FROM teams")->fetchAll(PDO::FETCH_ASSOC) as $tm) {
    $teamMap[strtolower(trim($tm['name']))] = (int)$tm['team_id'];
}

$updPlayer = $pdo->prepare("
    UPDATE players
    SET team_id        = :tid,
        roster_status  = :rstatus,
        age            = :age,
        contract_years = :yrs,
        salary         = :sal,
        fa_status      = NULL,
        qo_tendered    = FALSE,
        prev_team_id   = NULL
    WHERE LOWER(TRIM(first_name)) = LOWER(TRIM(:fn))
      AND LOWER(TRIM(last_name))  = LOWER(TRIM(:ln))
      AND COALESCE(is_retired, FALSE) = FALSE
      AND (
          :is_def = -1
          OR (:is_def = 1 AND UPPER(COALESCE(position, '')) = 'D')
          OR (:is_def = 0 AND UPPER(COALESCE(position, '')) <> 'D')
      )
");

$totalUpdated = 0;

for ($sthsId = 1; $sthsId <= 32; $sthsId++) {
    $url = 'https://' . 'hfnhl.org/2027/ProTeam.php?' . http_build_query(array('Team' => $sthsId));

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
        'Referer: https://' . 'hfnhl.org/2027/index.php',
        'Cache-Control: no-cache'
    ));
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    $html = curl_exec($ch);
    usleep(250000);

    if (!$html) {
        echo "[Team={$sthsId}] Failed: " . curl_error($ch) . "\n";
        continue;
    }

    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xpath = new DOMXPath($dom);

    $localTeamId = 0;
    $teamLabel   = "Team={$sthsId}";
    $titleNodes  = $xpath->query('//title');
    $pageTitle   = ($titleNodes->length > 0) ? strtolower(trim($titleNodes->item(0)->textContent)) : '';

    foreach ($teamMap as $tName => $tid) {
        if ($pageTitle !== '' && strpos($pageTitle, $tName) !== false) {
            $localTeamId = $tid;
            $teamLabel   = ucwords($tName);
            break;
        }
    }

    if ($localTeamId === 0) {
        echo "[Team={$sthsId}] Unmatched title: {$pageTitle}\n";
        continue;
    }

    $teamUpdated = 0;
    foreach ($xpath->query('//table') as $tbl) {
        $currentStatus = 'PRO';
        foreach ($tbl->getElementsByTagName('tr') as $tr) {
            $rowText = trim(preg_replace('/\s+/', ' ', $tr->textContent));
            if (stripos($rowText, 'Farm Team') === 0) {
                $currentStatus = 'AHL';
                continue;
            } elseif (stripos($rowText, 'Scratches') === 0) {
                $currentStatus = 'PRO';
                continue;
            }

            $cells = $tr->getElementsByTagName('td');
            if ($cells->length < 15) continue;

            $vals = array();
            foreach ($cells as $td) {
                $vals[] = trim(preg_replace('/\s+/', ' ', $td->textContent));
            }

            $tail   = array_slice($vals, -3);
            $ageRaw = (string)reset($tail);
            $yrsRaw = (string)next($tail);
            $salRaw = (string)end($tail);

            if (!preg_match('/^[\d,]+\$$/', $salRaw) || !ctype_digit($yrsRaw) || !ctype_digit($ageRaw)) {
                continue;
            }

            $age    = (int)$ageRaw;
            $salary = (int)str_replace(array(',', '$'), '', $salRaw);
            $years  = (int)$yrsRaw;

            $nameSlice = array_slice($vals, 1, 1);
            $rawName   = (string)reset($nameSlice);
            $cleanName = trim(preg_replace('/\s*\([RCA]\)\s*/i', ' ', $rawName));
            $cleanName = preg_replace('/\s+/', ' ', $cleanName);

            if ($cleanName === '' || stripos($cleanName, 'TEAM AVERAGE') !== false) {
                continue;
            }

            $isDef = -1;
            if ($cells->length >= 25) {
                $posSlice = array_slice($vals, 3, 4);
                if (count($posSlice) === 4) {
                    $dVal  = (string)end($posSlice);
                    $isDef = (strtoupper($dVal) === 'X') ? 1 : 0;
                }
            }

            $parts = explode(' ', $cleanName, 2);
            if (count($parts) === 2) {
                $updPlayer->execute(array(
                    'tid'     => $localTeamId,
                    'rstatus' => $currentStatus,
                    'age'     => $age,
                    'yrs'     => $years,
                    'sal'     => $salary,
                    'fn'      => reset($parts),
                    'ln'      => end($parts),
                    'is_def'  => $isDef,
                ));
                $teamUpdated  += $updPlayer->rowCount();
                $totalUpdated += $updPlayer->rowCount();
            }
        }
    }

    echo "[{$teamLabel}] Updated {$teamUpdated} contracts & ages.\n";
}

$pdo->exec("DELETE FROM league_settings WHERE setting_key = 'initial_offseason_done'");
echo "\nSYNC COMPLETE! Total players updated: {$totalUpdated}\n";



