<?php
ini_set('display_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED);
set_time_limit(300);

require 'db.php';
/** @var PDO $pdo */

if (empty($_SESSION['is_commish']) && empty($_SESSION['is_admin'])) {
    die("Commissioner access only.");
}

header('Content-Type: text/plain; charset=UTF-8');

$players = $pdo->query("
    SELECT player_id, first_name, last_name, position, age, nhl_games_played
    FROM players
    WHERE COALESCE(is_retired, FALSE) = FALSE
    ORDER BY overall_rating DESC
")->fetchAll(PDO::FETCH_ASSOC);

$updBio = $pdo->prepare("
    UPDATE players
    SET age = COALESCE(:age, age),
        nhl_games_played = COALESCE(:gp, nhl_games_played)
    WHERE player_id = :pid
");

$updated = 0;
$today   = new DateTime('now', new DateTimeZone('America/Toronto'));

foreach ($players as $p) {
    $fullName  = trim($p['first_name'] . ' ' . $p['last_name']);
    $searchUrl = 'https://nhle.com' . urlencode($fullName);

    $ch = curl_init($searchUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_USERAGENT      => 'Mozilla/5.0'
    ]);
    $res = curl_exec($ch);
    curl_close($ch);

    if (!$res) continue;
    $matches = json_decode($res, true);
    if (!is_array($matches) || empty($matches)) continue;

    $nhlId = null;
    foreach ($matches as $m) {
        if (strcasecmp(trim($m['name'] ?? ''), $fullName) === 0) {
            $nhlId = $m['playerId'] ?? null;
            break;
        }
    }
    if (!$nhlId && isset($matches[0]['playerId'])) {
        $nhlId = $matches[0]['playerId'];
    }
    if (!$nhlId) continue;

    $bioUrl = "https://nhle.com{$nhlId}/landing";
    $ch2 = curl_init($bioUrl);
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_USERAGENT      => 'Mozilla/5.0'
    ]);
    $bioRes = curl_exec($ch2);
    curl_close($ch2);

    if (!$bioRes) continue;
    $bio = json_decode($bioRes, true);
    if (!is_array($bio)) continue;

    $newAge = null;
    if (!empty($bio['birthDate'])) {
        $dob    = new DateTime($bio['birthDate']);
        $newAge = (int)$dob->diff($today)->y;
    }

    $careerGp = $bio['careerTotals']['regularSeason']['gamesPlayed'] ?? null;

    if ($newAge !== null || $careerGp !== null) {
        $updBio->execute([
            'age' => $newAge,
            'gp'  => $careerGp !== null ? (int)$careerGp : null,
            'pid' => $p['player_id']
        ]);
        $updated++;
        echo "{$fullName}: Age={$newAge}, NHL GP={$careerGp}\n";
        @ob_flush();
        flush();
    }
    usleep(80000);
}

echo "\nDONE! Updated bio & NHL GP for {$updated} players.\n";

