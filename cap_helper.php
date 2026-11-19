<?php
require_once __DIR__ . '/mailer.php';

function getLeagueCapLimits(PDO $pdo): array {
    $seasonYear  = 2026;
    $seasonLabel = '2026-27';
    $capMax      = 104000000;
    $capMin      = 76900000;

    try {
        $sRows = $pdo->query("
            SELECT setting_key, setting_value
            FROM league_settings
            WHERE setting_key IN ('current_season', 'salary_cap_max', 'salary_cap_min')
        ")->fetchAll(PDO::FETCH_KEY_PAIR);

        if (!empty($sRows['current_season'])) $seasonYear = (int)$sRows['current_season'];
        if (!empty($sRows['salary_cap_max'])) $capMax     = (int)$sRows['salary_cap_max'];
        if (!empty($sRows['salary_cap_min'])) $capMin     = (int)$sRows['salary_cap_min'];

        $nextYrShort = substr((string)($seasonYear + 1), -2);
        $seasonLabel = "{$seasonYear}-{$nextYrShort}";

        $schStmt = $pdo->prepare("SELECT season_label, cap_max, cap_min FROM nhl_cap_schedule WHERE season_year = ?");
        $schStmt->execute(array($seasonYear));
        $sch = $schStmt->fetch(PDO::FETCH_ASSOC);

        if ($sch) {
            $seasonLabel = $sch['season_label'];
            $capMax      = (int)$sch['cap_max'];
            $capMin      = (int)$sch['cap_min'];
        }
    } catch (Exception $e) {}

    return array(
        'season_year'  => $seasonYear,
        'season_label' => $seasonLabel,
        'max'          => $capMax,
        'min'          => $capMin,
    );
}

function getTeamRetainedCap(PDO $pdo, int $teamId): int {
    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(retained_cap_hit), 0)
            FROM retained_salaries
            WHERE retaining_team_id = ? AND years_remaining > 0
        ");
        $stmt->execute(array($teamId));
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

function getTeamBuyoutCap(PDO $pdo, int $teamId): int {
    try {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(buyout_cap_hit), 0)
            FROM team_buyouts
            WHERE team_id = ? AND years_remaining > 0
        ");
        $stmt->execute(array($teamId));
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

function validateSalaryRetention(PDO $pdo, int $retainingTeamId, int $playerId, float $percent, int $newRetainedAmount): string {
    if ($percent <= 0 || $percent > 50) {
        return "Retention percentage must be between 1% and 50%.";
    }

    $slotStmt = $pdo->prepare("SELECT COUNT(*) FROM retained_salaries WHERE retaining_team_id = ? AND years_remaining > 0");
    $slotStmt->execute(array($retainingTeamId));
    if ((int)$slotStmt->fetchColumn() >= 3) {
        return "Team already has the maximum of 3 active retained salary contracts.";
    }

    $playerStmt = $pdo->prepare("SELECT COUNT(*) FROM retained_salaries WHERE player_id = ? AND years_remaining > 0");
    $playerStmt->execute(array($playerId));
    if ((int)$playerStmt->fetchColumn() >= 2) {
        return "This player's contract has already been retained twice (NHL maximum).";
    }

    $limits       = getLeagueCapLimits($pdo);
    $maxRetained  = (int)round($limits['max'] * 0.15);
    $currRetained = getTeamRetainedCap($pdo, $retainingTeamId);

    if (($currRetained + $newRetainedAmount) > $maxRetained) {
        $limitFmt = '$' . number_format($maxRetained);
        return "Adding this retention exceeds the 15% league cap retention limit ({$limitFmt}).";
    }

    return '';
}

function advanceLeagueCapForOffseason(PDO $pdo, bool $isSubsequentSeason): array {
    $currYear   = (int)($pdo->query("SELECT setting_value FROM league_settings WHERE setting_key = 'current_season'")->fetchColumn() ?: 2026);
    $targetYear = $isSubsequentSeason ? ($currYear + 1) : $currYear;

    $stmt = $pdo->prepare("SELECT season_label, cap_max, cap_min FROM nhl_cap_schedule WHERE season_year = ?");
    $stmt->execute(array($targetYear));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $latest  = $pdo->query("SELECT cap_max, cap_min FROM nhl_cap_schedule ORDER BY season_year DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $prevMax = $latest ? (int)$latest['cap_max'] : 113500000;
        $prevMin = $latest ? (int)$latest['cap_min'] : 83900000;

        $newMax   = (int)(round(($prevMax * 1.05) / 100000) * 100000);
        $newMin   = (int)(round(($prevMin * 1.05) / 100000) * 100000);
        $newLabel = $targetYear . '-' . substr((string)($targetYear + 1), -2);

        $pdo->prepare("
            INSERT INTO nhl_cap_schedule (season_year, season_label, cap_max, cap_min)
            VALUES (?, ?, ?, ?)
            ON CONFLICT (season_year) DO NOTHING
        ")->execute(array($targetYear, $newLabel, $newMax, $newMin));

        $row = array('season_label' => $newLabel, 'cap_max' => $newMax, 'cap_min' => $newMin);
    }

    $upsertSetting = $pdo->prepare("
        INSERT INTO league_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value
    ");
    $upsertSetting->execute(array('current_season', (string)$targetYear));
    $upsertSetting->execute(array('salary_cap_max', (string)$row['cap_max']));
    $upsertSetting->execute(array('salary_cap_min', (string)$row['cap_min']));

    try {
        $pdo->exec("UPDATE retained_salaries SET years_remaining = years_remaining - 1");
        $pdo->exec("DELETE FROM retained_salaries WHERE years_remaining <= 0");
        $pdo->exec("UPDATE team_buyouts SET years_remaining = years_remaining - 1");
        $pdo->exec("DELETE FROM team_buyouts WHERE years_remaining <= 0");
    } catch (Exception $e) {}

    return array(
        'season_year'  => $targetYear,
        'season_label' => $row['season_label'],
        'max'          => (int)$row['cap_max'],
        'min'          => (int)$row['cap_min'],
    );
}
function getTeamCapSummary(PDO $pdo, int $teamId): array {
    $limits = getLeagueCapLimits($pdo);

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(salary), 0)
        FROM players
        WHERE team_id = ?
          AND UPPER(COALESCE(roster_status, 'PRO')) = 'PRO'
          AND COALESCE(is_retired, FALSE) = FALSE
    ");
    $stmt->execute(array($teamId));
    $proCapHit = (int)$stmt->fetchColumn();

    $retainedCap = getTeamRetainedCap($pdo, $teamId);
    $buyoutCap   = getTeamBuyoutCap($pdo, $teamId);
    $totalCapHit = $proCapHit + $retainedCap + $buyoutCap;

    $capSpace   = $limits['max'] - $totalCapHit;
    $underFloor = max(0, $limits['min'] - $totalCapHit);

    return array(
        'season_label'    => $limits['season_label'],
        'cap_max'         => $limits['max'],
        'cap_min'         => $limits['min'],
        'pro_cap_hit'     => $proCapHit,
        'retained_cap'    => $retainedCap,
        'buyout_cap'      => $buyoutCap,
        'cap_hit'         => $totalCapHit,
        'cap_space'       => $capSpace,
        'available_space' => $capSpace,
        'under_floor'     => $underFloor,
        'is_over_cap'     => ($totalCapHit > $limits['max']),
        'is_below_floor'  => ($totalCapHit < $limits['min']),
    );
}

function getLeagueCapViolations(PDO $pdo): array {
    $limits     = getLeagueCapLimits($pdo);
    $violations = array();

    $sql = "
        SELECT t.team_id,
               t.abbreviation,
               t.name AS team_name,
               COALESCE(p_cap.pro_hit, 0) + COALESCE(r_cap.ret_hit, 0) + COALESCE(b_cap.buy_hit, 0) AS cap_hit,
               COALESCE(p_cap.healthy_skaters, 0) AS healthy_skaters,
               COALESCE(p_cap.healthy_goalies, 0) AS healthy_goalies,
               u.email AS gm_email,
               u.username AS gm_name
        FROM teams t
        LEFT JOIN (
            SELECT team_id,
                   SUM(salary) AS pro_hit,
                   SUM(CASE WHEN UPPER(COALESCE(position, '')) <> 'G' AND COALESCE(is_injured, FALSE) = FALSE THEN 1 ELSE 0 END) AS healthy_skaters,
                   SUM(CASE WHEN UPPER(COALESCE(position, '')) = 'G' AND COALESCE(is_injured, FALSE) = FALSE THEN 1 ELSE 0 END) AS healthy_goalies
            FROM players
            WHERE UPPER(COALESCE(roster_status, 'PRO')) = 'PRO'
              AND COALESCE(is_retired, FALSE) = FALSE
            GROUP BY team_id
        ) p_cap ON p_cap.team_id = t.team_id
        LEFT JOIN (
            SELECT retaining_team_id, SUM(retained_cap_hit) AS ret_hit
            FROM retained_salaries
            WHERE years_remaining > 0
            GROUP BY retaining_team_id
        ) r_cap ON r_cap.retaining_team_id = t.team_id
        LEFT JOIN (
            SELECT team_id, SUM(buyout_cap_hit) AS buy_hit
            FROM team_buyouts
            WHERE years_remaining > 0
            GROUP BY team_id
        ) b_cap ON b_cap.team_id = t.team_id
        LEFT JOIN users u ON u.team_id = t.team_id
        ORDER BY t.name ASC
    ";

    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $capHit  = (int)$row['cap_hit'];
        $skaters = (int)$row['healthy_skaters'];
        $goalies = (int)$row['healthy_goalies'];
        $issues  = array();

        if ($capHit > $limits['max']) {
            $diffFmt  = '$' . number_format($capHit - $limits['max']);
            $limFmt   = '$' . number_format($limits['max']);
            $hitFmt   = '$' . number_format($capHit);
            $issues[] = "OVER the {$limits['season_label']} Cap Ceiling ({$limFmt}) by {$diffFmt} (Current Cap Hit: {$hitFmt})";
        } elseif ($capHit < $limits['min']) {
            $diffFmt  = '$' . number_format($limits['min'] - $capHit);
            $limFmt   = '$' . number_format($limits['min']);
            $hitFmt   = '$' . number_format($capHit);
            $issues[] = "UNDER the {$limits['season_label']} Cap Floor ({$limFmt}) by {$diffFmt} (Current Cap Hit: {$hitFmt})";
        }

        if ($skaters < 18) {
            $issues[] = "Short on healthy NHL skaters ({$skaters}/18 minimum required)";
        }
        if ($goalies < 2) {
            $issues[] = "Short on healthy NHL goalies ({$goalies}/2 minimum required)";
        }

        if (!empty($issues)) {
            $violations[] = array(
                'team_id'      => (int)$row['team_id'],
                'abbreviation' => $row['abbreviation'],
                'team_name'    => $row['team_name'],
                'gm_name'      => $row['gm_name'] ?: 'GM',
                'gm_email'     => trim($row['gm_email'] ?? ''),
                'cap_hit'      => $capHit,
                'issues'       => $issues,
                'status_text'  => implode('; ', $issues),
            );
        }
    }

    return $violations;
}

function enforceCapComplianceBeforeSim(PDO $pdo, bool $sendEmails = true): string {
    $violations = getLeagueCapViolations($pdo);
    if (empty($violations)) {
        return '';
    }

    $summaryLines = array();
    $emailedCount = 0;

    foreach ($violations as $v) {
        $teamStr        = "{$v['team_name']} ({$v['abbreviation']})";
        $summaryLines[] = "• {$teamStr}: {$v['status_text']}";

        if ($sendEmails && !empty($v['gm_email']) && filter_var($v['gm_email'], FILTER_VALIDATE_EMAIL)) {
            $subject = "[HFNHL Action Required] {$v['team_name']} Cap / Roster Compliance";
            $body    = "Hello {$v['gm_name']},\n\n"
                . "The HFNHL simulation cannot run because {$teamStr} is currently non-compliant:\n"
                . " - " . implode("\n - ", $v['issues']) . "\n\n"
                . "Please log into the HFNHL portal immediately to adjust your NHL (PRO) roster via call-ups, waivers, AHL demotions, trades, or free agent signings so your team is compliant before the next sim.\n\n"
                . "— HFNHL League Office";

            if (sendLeagueEmail($v['gm_email'], $subject, $body)) {
                $emailedCount++;
            }
        }
    }

    $headerMsg = "SIM HALTED — " . count($violations) . " team(s) are not cap or roster compliant"
        . ($emailedCount > 0 ? " (Alert emails sent to {$emailedCount} GM(s)):" : ":");

    return $headerMsg . "\n" . implode("\n", $summaryLines);
}
?>






