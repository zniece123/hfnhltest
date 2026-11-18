<?php
require_once 'cap_helper.php';

function ensureSimSchema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS league_settings (
            setting_key VARCHAR(64) PRIMARY KEY,
            setting_value TEXT NOT NULL
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS playoff_series (
            series_id SERIAL PRIMARY KEY,
            season_year INT NOT NULL DEFAULT 2027,
            round_num INT NOT NULL,
            conference VARCHAR(32) NOT NULL,
            bracket_slot VARCHAR(32) NOT NULL,
            higher_seed_id INT NOT NULL REFERENCES teams(team_id),
            lower_seed_id INT NOT NULL REFERENCES teams(team_id),
            higher_wins INT NOT NULL DEFAULT 0,
            lower_wins INT NOT NULL DEFAULT 0,
            winner_team_id INT NULL REFERENCES teams(team_id),
            status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("ALTER TABLE games ADD COLUMN IF NOT EXISTS game_type VARCHAR(20) NOT NULL DEFAULT 'REGULAR'");
    $pdo->exec("ALTER TABLE games ADD COLUMN IF NOT EXISTS series_id INT NULL");
}

function getLeagueSetting(PDO $pdo, string $key, string $default = ''): string {
    $stmt = $pdo->prepare("SELECT setting_value FROM league_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val !== false ? (string)$val : $default;
}

function setLeagueSetting(PDO $pdo, string $key, string $value): void {
    $stmt = $pdo->prepare("
        INSERT INTO league_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value
    ");
    $stmt->execute([$key, $value]);
}

function validateAllTeamsCompliance(PDO $pdo): array {
    $teams  = $pdo->query("SELECT team_id, name, abbreviation FROM teams ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $issues = [];

    $pStmt = $pdo->prepare("
        SELECT player_id, first_name, last_name, position, line_number, is_injured, salary
        FROM players
        WHERE team_id = ?
          AND UPPER(TRIM(roster_status)) = 'PRO'
          AND COALESCE(is_retired, FALSE) = FALSE
    ");

    foreach ($teams as $t) {
        $tid  = (int)$t['team_id'];
        $abbr = strtoupper(trim($t['abbreviation']));
        $pStmt->execute([$tid]);
        $roster = $pStmt->fetchAll(PDO::FETCH_ASSOC);

        $activeCount = count($roster);
        if ($activeCount > 23) {
            $issues[] = "{$abbr} ({$t['name']}): Active NHL roster has {$activeCount}/23 players (over 23-man limit).";
        }
        if ($activeCount < 20) {
            $issues[] = "{$abbr} ({$t['name']}): Active NHL roster only has {$activeCount}/20 players.";
        }

        $dressedF = 0;
        $dressedD = 0;
        $dressedG = 0;

        foreach ($roster as $p) {
            $pos = strtoupper(trim($p['position']));
            $ln  = (int)$p['line_number'];
            $inj = !empty($p['is_injured']);

            if ($pos === 'G') {
                if ($ln >= 1 && $ln <= 2) {
                    if ($inj) $issues[] = "{$abbr}: Injured goalie {$p['first_name']} {$p['last_name']} is dressed on Line {$ln}.";
                    else $dressedG++;
                }
            } elseif ($pos === 'D') {
                if ($ln >= 1 && $ln <= 3) {
                    if ($inj) $issues[] = "{$abbr}: Injured defenseman {$p['first_name']} {$p['last_name']} is dressed on Pair {$ln}.";
                    else $dressedD++;
                }
            } else {
                if ($ln >= 1 && $ln <= 4) {
                    if ($inj) $issues[] = "{$abbr}: Injured forward {$p['first_name']} {$p['last_name']} is dressed on Line {$ln}.";
                    else $dressedF++;
                }
            }
        }

        if ($dressedF < 12) $issues[] = "{$abbr}: Only {$dressedF}/12 healthy forwards dressed on Lines 1-4.";
        if ($dressedD < 6)  $issues[] = "{$abbr}: Only {$dressedD}/6 healthy defensemen dressed on Pairs 1-3.";
        if ($dressedG < 2)  $issues[] = "{$abbr}: Only {$dressedG}/2 healthy goalies dressed (Starter/Backup).";

        if (function_exists('getTeamCapSummary')) {
            $cap = getTeamCapSummary($pdo, $tid);
            if ($cap['cap_hit'] > $cap['cap_max']) {
                $over = number_format($cap['cap_hit'] - $cap['cap_max']);
                $issues[] = "{$abbr}: Over Salary Cap by \${$over}.";
            }
            if (!empty($cap['cap_min']) && $cap['cap_hit'] < $cap['cap_min']) {
                $under = number_format($cap['cap_min'] - $cap['cap_hit']);
                $issues[] = "{$abbr}: Under Salary Floor by \${$under}.";
            }
        }
    }

    return $issues;
}

function autoFixNonCompliantLines(PDO $pdo): int {
    $teams = $pdo->query("SELECT team_id FROM teams")->fetchAll(PDO::FETCH_COLUMN);
    $fixed = 0;

    foreach ($teams as $tid) {
        $tid = (int)$tid;
        $injUpd = $pdo->prepare("
            UPDATE players SET line_number = 99
            WHERE team_id = ? AND UPPER(TRIM(roster_status)) = 'PRO'
              AND COALESCE(is_injured, FALSE) = TRUE AND line_number < 99
        ");
        $injUpd->execute([$tid]);
        if ($injUpd->rowCount() > 0) $fixed++;

        $pStmt = $pdo->prepare("
            SELECT player_id, position, line_number, overall_rating
            FROM players
            WHERE team_id = ? AND UPPER(TRIM(roster_status)) = 'PRO'
              AND COALESCE(is_injured, FALSE) = FALSE AND COALESCE(is_retired, FALSE) = FALSE
            ORDER BY line_number ASC, overall_rating DESC
        ");
        $pStmt->execute([$tid]);
        $healthy = $pStmt->fetchAll(PDO::FETCH_ASSOC);

        $fList = array_values(array_filter($healthy, fn($p) => !in_array(strtoupper($p['position']), ['D', 'G'])));
        $dList = array_values(array_filter($healthy, fn($p) => strtoupper($p['position']) === 'D'));
        $gList = array_values(array_filter($healthy, fn($p) => strtoupper($p['position']) === 'G'));

        $updLn = $pdo->prepare("UPDATE players SET line_number = ? WHERE player_id = ?");

        foreach ($fList as $idx => $p) {
            $target = $idx < 12 ? (int)floor($idx / 3) + 1 : 99;
            if ((int)$p['line_number'] !== $target) {
                $updLn->execute([$target, $p['player_id']]);
                $fixed++;
            }
        }
        foreach ($dList as $idx => $p) {
            $target = $idx < 6 ? (int)floor($idx / 2) + 1 : 99;
            if ((int)$p['line_number'] !== $target) {
                $updLn->execute([$target, $p['player_id']]);
                $fixed++;
            }
        }
        foreach ($gList as $idx => $p) {
            $target = $idx < 2 ? ($idx + 1) : 99;
            if ((int)$p['line_number'] !== $target) {
                $updLn->execute([$target, $p['player_id']]);
                $fixed++;
            }
        }
    }

    return $fixed;
}
function getStandingsSeeded(PDO $pdo): array {
    $nhlAlign = [
        'BOS' => ['Eastern', 'Atlantic'],     'BUF' => ['Eastern', 'Atlantic'],
        'DET' => ['Eastern', 'Atlantic'],     'FLA' => ['Eastern', 'Atlantic'],
        'MTL' => ['Eastern', 'Atlantic'],     'OTT' => ['Eastern', 'Atlantic'],
        'TBL' => ['Eastern', 'Atlantic'],     'TOR' => ['Eastern', 'Atlantic'],
        'CAR' => ['Eastern', 'Metropolitan'], 'CBJ' => ['Eastern', 'Metropolitan'],
        'NJD' => ['Eastern', 'Metropolitan'], 'NYI' => ['Eastern', 'Metropolitan'],
        'NYR' => ['Eastern', 'Metropolitan'], 'PHI' => ['Eastern', 'Metropolitan'],
        'PIT' => ['Eastern', 'Metropolitan'], 'WSH' => ['Eastern', 'Metropolitan'],
        'CHI' => ['Western', 'Central'],      'COL' => ['Western', 'Central'],
        'DAL' => ['Western', 'Central'],      'MIN' => ['Western', 'Central'],
        'NSH' => ['Western', 'Central'],      'STL' => ['Western', 'Central'],
        'UTA' => ['Western', 'Central'],      'WPG' => ['Western', 'Central'],
        'ANA' => ['Western', 'Pacific'],      'CGY' => ['Western', 'Pacific'],
        'EDM' => ['Western', 'Pacific'],      'LAK' => ['Western', 'Pacific'],
        'SEA' => ['Western', 'Pacific'],      'SJS' => ['Western', 'Pacific'],
        'VAN' => ['Western', 'Pacific'],      'VGK' => ['Western', 'Pacific'],
    ];

    $teams = $pdo->query("SELECT team_id, name, abbreviation FROM teams")->fetchAll(PDO::FETCH_ASSOC);
    $games = $pdo->query("
        SELECT home_team_id, away_team_id, home_score, away_score
        FROM games
        WHERE status = 'FINAL' AND COALESCE(game_type, 'REGULAR') = 'REGULAR'
    ")->fetchAll(PDO::FETCH_ASSOC);

    $stats = [];
    foreach ($teams as $t) {
        $tid  = (int)$t['team_id'];
        $abbr = strtoupper(trim($t['abbreviation']));
        $pair = $nhlAlign[$abbr] ?? ['Eastern', 'Atlantic'];
        $stats[$tid] = [
            'team_id' => $tid, 'name' => $t['name'], 'abbr' => $abbr,
            'conf' => reset($pair), 'div' => end($pair),
            'gp' => 0, 'w' => 0, 'l' => 0, 'otl' => 0, 'pts' => 0, 'gf' => 0, 'ga' => 0, 'diff' => 0, 'ptspct' => 0
        ];
    }

    foreach ($games as $g) {
        $h = (int)$g['home_team_id']; $a = (int)$g['away_team_id'];
        $hs = (int)$g['home_score'];  $as = (int)$g['away_score'];
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

    $sortFn = function(&$list) {
        usort($list, function($a, $b) {
            if ($b['pts'] !== $a['pts']) return $b['pts'] <=> $a['pts'];
            if (abs($b['ptspct'] - $a['ptspct']) > 0.0001) return ($b['ptspct'] > $a['ptspct']) ? 1 : -1;
            if ($b['w'] !== $a['w']) return $b['w'] <=> $a['w'];
            if ($b['diff'] !== $a['diff']) return $b['diff'] <=> $a['diff'];
            return $b['gf'] <=> $a['gf'];
        });
    };

    $bracket = ['Eastern' => [], 'Western' => []];
    foreach (['Eastern' => ['Atlantic', 'Metropolitan'], 'Western' => ['Central', 'Pacific']] as $conf => $divs) {
        $divTop = [];
        $wcPool = [];
        foreach ($divs as $d) {
            $rows = array_values(array_filter($stats, fn($x) => $x['div'] === $d));
            $sortFn($rows);
            $divTop[$d] = array_slice($rows, 0, 3);
            foreach (array_slice($rows, 3) as $r) $wcPool[] = $r;
        }
        $sortFn($wcPool);
        $wc1 = reset($wcPool);
        $wc2 = next($wcPool);

        $d1 = reset($divs);
        $d2 = next($divs);

        $d1Leader = reset($divTop[$d1]);
        $d1Second = next($divTop[$d1]);
        $d1Third  = next($divTop[$d1]);

        $d2Leader = reset($divTop[$d2]);
        $d2Second = next($divTop[$d2]);
        $d2Third  = next($divTop[$d2]);

        $d1First = ($d1Leader['pts'] > $d2Leader['pts'] || ($d1Leader['pts'] === $d2Leader['pts'] && $d1Leader['w'] >= $d2Leader['w']));
        $bracket[$conf] = [
            [$d1 . '_1', $d1Leader, $d1First ? $wc2 : $wc1],
            [$d1 . '_2', $d1Second, $d1Third],
            [$d2 . '_1', $d2Leader, $d1First ? $wc1 : $wc2],
            [$d2 . '_2', $d2Second, $d2Third],
        ];
    }

    return $bracket;
}

function scheduleSeriesGames(PDO $pdo, int $seriesId, int $highId, int $lowId, string $startDate): void {
    $homePattern = [1 => $highId, 2 => $highId, 3 => $lowId, 4 => $lowId, 5 => $highId, 6 => $lowId, 7 => $highId];
    $insGame = $pdo->prepare("
        INSERT INTO games (game_date, home_team_id, away_team_id, status, game_type, series_id)
        VALUES (?, ?, ?, 'SCHEDULED', 'PLAYOFF', ?)
    ");
    for ($g = 1; $g <= 7; $g++) {
        $gDate = date('Y-m-d', strtotime($startDate . ' +' . (($g - 1) * 2) . ' days'));
        $home  = $homePattern[$g];
        $away  = ($home === $highId) ? $lowId : $highId;
        $insGame->execute([$gDate, $home, $away, $seriesId]);
    }
}

function generatePlayoffBracket(PDO $pdo, int $seasonYear = 2027): int {
    ensureSimSchema($pdo);
    $active = (int)$pdo->query("SELECT COUNT(*) FROM playoff_series WHERE season_year = {$seasonYear}")->fetchColumn();
    if ($active > 0) return 0;

    $seeded = getStandingsSeeded($pdo);
    $insSer = $pdo->prepare("
        INSERT INTO playoff_series (season_year, round_num, conference, bracket_slot, higher_seed_id, lower_seed_id)
        VALUES (?, 1, ?, ?, ?, ?)
        RETURNING series_id
    ");

    $created = 0;
    $startDate = date('Y-m-d');
    foreach ($seeded as $conf => $matchups) {
        foreach ($matchups as $m) {
            [$slot, $high, $low] = $m;
            $insSer->execute([$seasonYear, $conf, $slot, $high['team_id'], $low['team_id']]);
            $sid = (int)$insSer->fetchColumn();
            scheduleSeriesGames($pdo, $sid, (int)$high['team_id'], (int)$low['team_id'], $startDate);
            $created++;
        }
    }
    return $created;
}
function simulateSingleGame(PDO $pdo, int $gameId): array {
    $gStmt = $pdo->prepare("
        SELECT g.*, h.abbreviation AS home_abbr, a.abbreviation AS away_abbr
        FROM games g
        JOIN teams h ON g.home_team_id = h.team_id
        JOIN teams a ON g.away_team_id = a.team_id
        WHERE g.game_id = ? AND g.status = 'SCHEDULED'
    ");
    $gStmt->execute([$gameId]);
    $game = $gStmt->fetch(PDO::FETCH_ASSOC);
    if (!$game) return ['ok' => false, 'msg' => 'Game not found or already final.'];

    $isPlayoff = (strtoupper($game['game_type'] ?? 'REGULAR') === 'PLAYOFF');
    $homeId    = (int)$game['home_team_id'];
    $awayId    = (int)$game['away_team_id'];

    $loadLineup = function(int $tid) use ($pdo) {
        $st = $pdo->prepare("
            SELECT * FROM players
            WHERE team_id = ? AND UPPER(TRIM(roster_status)) = 'PRO'
              AND COALESCE(is_injured, FALSE) = FALSE AND COALESCE(is_retired, FALSE) = FALSE
              AND line_number < 99
            ORDER BY line_number ASC, overall_rating DESC
        ");
        $st->execute([$tid]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    };

    $homeRoster = $loadLineup($homeId);
    $awayRoster = $loadLineup($awayId);

    $homeGoalies = array_values(array_filter($homeRoster, fn($p) => strtoupper($p['position']) === 'G'));
    $awayGoalies = array_values(array_filter($awayRoster, fn($p) => strtoupper($p['position']) === 'G'));
    $homeSkaters = array_values(array_filter($homeRoster, fn($p) => strtoupper($p['position']) !== 'G'));
    $awaySkaters = array_values(array_filter($awayRoster, fn($p) => strtoupper($p['position']) !== 'G'));

    if (empty($homeGoalies) || empty($awayGoalies) || count($homeSkaters) < 6 || count($awaySkaters) < 6) {
        return ['ok' => false, 'msg' => "Incomplete dressed lineup for {$game['away_abbr']} @ {$game['home_abbr']}."];
    }

    $homeG = reset($homeGoalies);
    $awayG = reset($awayGoalies);

    $calcRating = function(array $skaters, string $col): float {
        $sum = 0;
        foreach ($skaters as $s) $sum += (float)($s[$col] ?? $s['overall_rating'] ?? 70);
        return count($skaters) > 0 ? ($sum / count($skaters)) : 70.0;
    };

    $hOff = ($calcRating($homeSkaters, 'shooting') + $calcRating($homeSkaters, 'passing')) / 2.0 + 1.5;
    $aOff = ($calcRating($awaySkaters, 'shooting') + $calcRating($awaySkaters, 'passing')) / 2.0;
    $hDef = $calcRating($homeSkaters, 'defense');
    $aDef = $calcRating($awaySkaters, 'defense');
    $hGk  = (float)($homeG['goaltending'] ?? $homeG['overall_rating'] ?? 75);
    $aGk  = (float)($awayG['goaltending'] ?? $awayG['overall_rating'] ?? 75);

    $pStats = [];
    foreach (array_merge($homeSkaters, $awaySkaters, [$homeG, $awayG]) as $pl) {
        $pStats[(int)$pl['player_id']] = [
            'g' => 0, 'a' => 0, 'sog' => 0, 'hit' => 0, 'blk' => 0, 'pim' => 0,
            'pp_toi' => 0, 'pk_toi' => 0, 'toi' => 0, 'sv' => 0, 'ga' => 0
        ];
    }

    $events  = [];
    $hPGoals = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
    $aPGoals = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
    $hPShots = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
    $aPShots = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
    $hPpOpps = mt_rand(2, 5);
    $aPpOpps = mt_rand(2, 5);

    $pickWeighted = function(array $skaters, string $biasCol) {
        $pool = [];
        foreach ($skaters as $s) {
            $lnW = max(1, 5 - (int)$s['line_number']);
            $w   = max(1, (int)round(((float)$s[$biasCol] / 25.0) * $lnW));
            for ($i = 0; $i < $w; $i++) $pool[] = $s;
        }
        return $pool[array_rand($pool)];
    };

    $simPeriod = function(int $per, bool $suddenDeath = false) use (
        &$hPGoals, &$aPGoals, &$hPShots, &$aPShots, &$pStats, &$events,
        $homeSkaters, $awaySkaters, $homeG, $awayG, $hOff, $aOff, $hDef, $aDef, $hGk, $aGk, $game, $pickWeighted
    ) {
        $hShots = $suddenDeath ? mt_rand(3, 10) : max(6, (int)round(mt_rand(7, 13) + ($hOff - $aDef) * 0.15));
        $aShots = $suddenDeath ? mt_rand(3, 10) : max(6, (int)round(mt_rand(7, 13) + ($aOff - $hDef) * 0.15));
        $pLabel = $per === 4 ? 'OT' : "P{$per}";

        for ($i = 0; $i < $hShots; $i++) {
            $shooter = $pickWeighted($homeSkaters, 'shooting');
            $sid = (int)$shooter['player_id'];
            $pStats[$sid]['sog']++;
            $hPShots[$per]++;

            $goalProb = max(0.04, min(0.18, 0.088 + ((float)$shooter['shooting'] - $aGk) * 0.0022));
            if ((mt_rand(1, 10000) / 10000.0) <= $goalProb) {
                $pStats[$sid]['g']++;
                $pStats[(int)$awayG['player_id']]['ga']++;
                $hPGoals[$per]++;

                $a1 = $pickWeighted($homeSkaters, 'passing');
                $aStr = '';
                if ((int)$a1['player_id'] !== $sid) {
                    $pStats[(int)$a1['player_id']]['a']++;
                    $aStr = " (Assists: {$a1['first_name']} {$a1['last_name']}";
                    $a2 = $pickWeighted($homeSkaters, 'passing');
                    if ((int)$a2['player_id'] !== $sid && (int)$a2['player_id'] !== (int)$a1['player_id'] && mt_rand(1, 100) <= 65) {
                        $pStats[(int)$a2['player_id']]['a']++;
                        $aStr .= ", {$a2['first_name']} {$a2['last_name']}";
                    }
                    $aStr .= ")";
                }
                $min = str_pad((string)mt_rand(0, 19), 2, '0', STR_PAD_LEFT);
                $sec = str_pad((string)mt_rand(0, 59), 2, '0', STR_PAD_LEFT);
                $events[] = "[{$pLabel} {$min}:{$sec}] GOAL {$game['home_abbr']}: {$shooter['first_name']} {$shooter['last_name']}{$aStr}";
                if ($suddenDeath) return;
            } else {
                $pStats[(int)$awayG['player_id']]['sv']++;
            }
        }

        for ($i = 0; $i < $aShots; $i++) {
            $shooter = $pickWeighted($awaySkaters, 'shooting');
            $sid = (int)$shooter['player_id'];
            $pStats[$sid]['sog']++;
            $aPShots[$per]++;

            $goalProb = max(0.04, min(0.18, 0.088 + ((float)$shooter['shooting'] - $hGk) * 0.0022));
            if ((mt_rand(1, 10000) / 10000.0) <= $goalProb) {
                $pStats[$sid]['g']++;
                $pStats[(int)$homeG['player_id']]['ga']++;
                $aPGoals[$per]++;

                $a1 = $pickWeighted($awaySkaters, 'passing');
                $aStr = '';
                if ((int)$a1['player_id'] !== $sid) {
                    $pStats[(int)$a1['player_id']]['a']++;
                    $aStr = " (Assists: {$a1['first_name']} {$a1['last_name']}";
                    $a2 = $pickWeighted($awaySkaters, 'passing');
                    if ((int)$a2['player_id'] !== $sid && (int)$a2['player_id'] !== (int)$a1['player_id'] && mt_rand(1, 100) <= 65) {
                        $pStats[(int)$a2['player_id']]['a']++;
                        $aStr .= ", {$a2['first_name']} {$a2['last_name']}";
                    }
                    $aStr .= ")";
                }
                $min = str_pad((string)mt_rand(0, 19), 2, '0', STR_PAD_LEFT);
                $sec = str_pad((string)mt_rand(0, 59), 2, '0', STR_PAD_LEFT);
                $events[] = "[{$pLabel} {$min}:{$sec}] GOAL {$game['away_abbr']}: {$shooter['first_name']} {$shooter['last_name']}{$aStr}";
                if ($suddenDeath) return;
            } else {
                $pStats[(int)$homeG['player_id']]['sv']++;
            }
        }
    };
    
    for ($p = 1; $p <= 3; $p++) {
        $simPeriod($p, false);
    }

    $homeScore = $hPGoals[1] + $hPGoals[2] + $hPGoals[3];
    $awayScore = $aPGoals[1] + $aPGoals[2] + $aPGoals[3];
    $isOt = false;
    $isSo = false;

    if ($homeScore === $awayScore) {
        $isOt = true;
        $simPeriod(4, true);
        $homeScore += $hPGoals[4];
        $awayScore += $aPGoals[4];

        while ($homeScore === $awayScore) {
            if ($isPlayoff) {
                $hPGoals[4] = 0;
                $aPGoals[4] = 0;
                $simPeriod(4, true);
                $homeScore += $hPGoals[4];
                $awayScore += $aPGoals[4];
            } else {
                $isSo = true;
                if (mt_rand(0, 1) === 1) {
                    $homeScore++;
                    $events[] = "[SO] {$game['home_abbr']} wins the shootout!";
                } else {
                    $awayScore++;
                    $events[] = "[SO] {$game['away_abbr']} wins the shootout!";
                }
            }
        }
    }

    $hPpGoals = min($homeScore, mt_rand(0, min(2, $hPpOpps)));
    $aPpGoals = min($awayScore, mt_rand(0, min(2, $aPpOpps)));

    $totalToi = $isOt ? 3900 : 3600;
    $pStats[(int)$homeG['player_id']]['toi'] = $totalToi;
    $pStats[(int)$awayG['player_id']]['toi'] = $totalToi;

    foreach (array_merge($homeSkaters, $awaySkaters) as $sk) {
        $sid = (int)$sk['player_id'];
        $ln  = max(1, min(4, (int)$sk['line_number']));
        $baseToi = (strtoupper($sk['position']) === 'D') ? (1550 - $ln * 180) : (1320 - $ln * 170);
        $pStats[$sid]['toi']    = max(480, $baseToi + mt_rand(-90, 90));
        $pStats[$sid]['pp_toi'] = $ln <= 2 ? mt_rand(90, 260) : mt_rand(0, 45);
        $pStats[$sid]['pk_toi'] = $ln >= 2 ? mt_rand(60, 220) : mt_rand(0, 50);
        $pStats[$sid]['hit']    = mt_rand(0, (int)round(((float)$sk['physical']) / 24));
        $pStats[$sid]['blk']    = (strtoupper($sk['position']) === 'D') ? mt_rand(0, 4) : mt_rand(0, 2);
        $pStats[$sid]['pim']    = (mt_rand(1, 100) <= 14) ? 2 : 0;
    }

    $allSkaters = array_merge($homeSkaters, $awaySkaters);
    if (!empty($allSkaters) && mt_rand(1, 100) <= 3) {
        $victim = $allSkaters[array_rand($allSkaters)];
        $days   = mt_rand(2, 10);
        $pdo->prepare("UPDATE players SET is_injured = TRUE, injury_days_remaining = ?, line_number = 99 WHERE player_id = ?")
            ->execute([$days, $victim['player_id']]);
        $events[] = "[INJURY] {$victim['first_name']} {$victim['last_name']} suffered an injury ({$days} days).";
    }

    $updGame = $pdo->prepare("
        UPDATE games SET
            status = 'FINAL',
            home_score = ?, away_score = ?,
            home_p1_goals = ?, home_p2_goals = ?, home_p3_goals = ?, home_ot_goals = ?,
            away_p1_goals = ?, away_p2_goals = ?, away_p3_goals = ?, away_ot_goals = ?,
            home_p1_shots = ?, home_p2_shots = ?, home_p3_shots = ?, home_ot_shots = ?,
            away_p1_shots = ?, away_p2_shots = ?, away_p3_shots = ?, away_ot_shots = ?,
            home_pp_goals = ?, home_pp_opps = ?, away_pp_goals = ?, away_pp_opps = ?,
            is_overtime = ?, is_shootout = ?, play_by_play = ?
        WHERE game_id = ?
    ");
    $updGame->execute([
        $homeScore, $awayScore,
        $hPGoals[1], $hPGoals[2], $hPGoals[3], $hPGoals[4],
        $aPGoals[1], $aPGoals[2], $aPGoals[3], $aPGoals[4],
        $hPShots[1], $hPShots[2], $hPShots[3], $hPShots[4],
        $aPShots[1], $aPShots[2], $aPShots[3], $aPShots[4],
        $hPpGoals, $hPpOpps, $aPpGoals, $aPpOpps,
        $isOt ? 'true' : 'false', $isSo ? 'true' : 'false', json_encode($events),
        $gameId
    ]);

    $pdo->prepare("DELETE FROM player_game_stats WHERE game_id = ?")->execute([$gameId]);
    $insStat = $pdo->prepare("
        INSERT INTO player_game_stats (
            game_id, player_id, goals, assists, shots, hits, blocks, pim,
            pp_toi_seconds, pk_toi_seconds, toi_seconds, saves, goals_against
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($pStats as $pid => $st) {
        $insStat->execute([
            $gameId, $pid, $st['g'], $st['a'], $st['sog'], $st['hit'], $st['blk'], $st['pim'],
            $st['pp_toi'], $st['pk_toi'], $st['toi'], $st['sv'], $st['ga']
        ]);
    }

    if ($isPlayoff && !empty($game['series_id'])) {
        $winnerId = ($homeScore > $awayScore) ? $homeId : $awayId;
        updatePlayoffSeriesProgress($pdo, (int)$game['series_id'], $winnerId);
    }

    return [
        'ok'  => true,
        'msg' => "{$game['away_abbr']} {$awayScore} @ {$game['home_abbr']} {$homeScore}" . ($isSo ? ' (SO)' : ($isOt ? ' (OT)' : ''))
    ];
}

function updatePlayoffSeriesProgress(PDO $pdo, int $seriesId, int $winnerId): void {
    $sStmt = $pdo->prepare("SELECT * FROM playoff_series WHERE series_id = ?");
    $sStmt->execute([$seriesId]);
    $ser = $sStmt->fetch(PDO::FETCH_ASSOC);
    if (!$ser || $ser['status'] === 'COMPLETE') return;

    $highWins = (int)$ser['higher_wins'];
    $lowWins  = (int)$ser['lower_wins'];

    if ($winnerId === (int)$ser['higher_seed_id']) $highWins++;
    else $lowWins++;

    if ($highWins >= 4 || $lowWins >= 4) {
        $champId = ($highWins >= 4) ? (int)$ser['higher_seed_id'] : (int)$ser['lower_seed_id'];
        $pdo->prepare("
            UPDATE playoff_series
            SET higher_wins = ?, lower_wins = ?, winner_team_id = ?, status = 'COMPLETE'
            WHERE series_id = ?
        ")->execute([$highWins, $lowWins, $champId, $seriesId]);

        $pdo->prepare("DELETE FROM games WHERE series_id = ? AND status = 'SCHEDULED'")->execute([$seriesId]);
        advancePlayoffRoundIfReady($pdo, (int)$ser['season_year'], (int)$ser['round_num']);
    } else {
        $pdo->prepare("UPDATE playoff_series SET higher_wins = ?, lower_wins = ? WHERE series_id = ?")
            ->execute([$highWins, $lowWins, $seriesId]);
    }
}

function advancePlayoffRoundIfReady(PDO $pdo, int $seasonYear, int $completedRound): void {
    $rem = $pdo->prepare("SELECT COUNT(*) FROM playoff_series WHERE season_year = ? AND round_num = ? AND status <> 'COMPLETE'");
    $rem->execute([$seasonYear, $completedRound]);
    if ((int)$rem->fetchColumn() > 0) return;

    $nextRound = $completedRound + 1;
    if ($nextRound > 4) return;

    $exists = $pdo->prepare("SELECT COUNT(*) FROM playoff_series WHERE season_year = ? AND round_num = ?");
    $exists->execute([$seasonYear, $nextRound]);
    if ((int)$exists->fetchColumn() > 0) return;

    $prev = $pdo->prepare("SELECT * FROM playoff_series WHERE season_year = ? AND round_num = ? ORDER BY series_id ASC");
    $prev->execute([$seasonYear, $completedRound]);
    $rows = $prev->fetchAll(PDO::FETCH_ASSOC);

    $insSer = $pdo->prepare("
        INSERT INTO playoff_series (season_year, round_num, conference, bracket_slot, higher_seed_id, lower_seed_id)
        VALUES (?, ?, ?, ?, ?, ?)
        RETURNING series_id
    ");
    $startDate = date('Y-m-d', strtotime('+2 days'));

    if ($nextRound === 2 || $nextRound === 3) {
        foreach (['Eastern', 'Western'] as $conf) {
            $cWinners = array_values(array_map(fn($r) => (int)$r['winner_team_id'], array_filter($rows, fn($r) => $r['conference'] === $conf)));
            for ($i = 0; $i + 1 < count($cWinners); $i += 2) {
                $slot = "{$conf}_R{$nextRound}_" . (($i / 2) + 1);
                $insSer->execute([$seasonYear, $nextRound, $conf, $slot, $cWinners[$i], $cWinners[$i + 1]]);
                $sid = (int)$insSer->fetchColumn();
                scheduleSeriesGames($pdo, $sid, $cWinners[$i], $cWinners[$i + 1], $startDate);
            }
        }
    } elseif ($nextRound === 4 && count($rows) === 2) {
        $firstFin  = reset($rows);
        $secondFin = next($rows);
        $t1 = (int)$firstFin['winner_team_id'];
        $t2 = (int)$secondFin['winner_team_id'];
        $insSer->execute([$seasonYear, 4, 'Stanley Cup Final', 'SCF', $t1, $t2]);
        $sid = (int)$insSer->fetchColumn();
        scheduleSeriesGames($pdo, $sid, $t1, $t2, $startDate);
    }
}

function advanceSimDay(PDO $pdo, bool $enforceCompliance = true): array {
    ensureSimSchema($pdo);

    if ($enforceCompliance) {
        $issues = validateAllTeamsCompliance($pdo);
        if (!empty($issues)) {
            return ['ok' => false, 'msg' => 'Compliance check failed.', 'issues' => $issues];
        }
    }

    $nextDate = $pdo->query("SELECT MIN(game_date) FROM games WHERE status = 'SCHEDULED'")->fetchColumn();
    if (!$nextDate) {
        return ['ok' => false, 'msg' => 'No scheduled games remaining to simulate.'];
    }

    $gStmt = $pdo->prepare("SELECT game_id FROM games WHERE status = 'SCHEDULED' AND game_date = ? ORDER BY game_id ASC");
    $gStmt->execute([$nextDate]);
    $gameIds = $gStmt->fetchAll(PDO::FETCH_COLUMN);

    $results = [];
    foreach ($gameIds as $gid) {
        $res = simulateSingleGame($pdo, (int)$gid);
        if ($res['ok']) $results[] = $res['msg'];
    }

    $pdo->exec("
        UPDATE players
        SET injury_days_remaining = GREATEST(0, injury_days_remaining - 1),
            is_injured = CASE WHEN (injury_days_remaining - 1) <= 0 THEN FALSE ELSE TRUE END
        WHERE COALESCE(is_injured, FALSE) = TRUE
    ");

    return [
        'ok'      => true,
        'date'    => $nextDate,
        'count'   => count($results),
        'results' => $results,
        'msg'     => "Simulated " . count($results) . " game(s) for {$nextDate}."
    ];
}




