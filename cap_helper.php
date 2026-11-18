<?php
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

    $capSpace   = $limits['max'] - $proCapHit;
    $underFloor = max(0, $limits['min'] - $proCapHit);

    return array(
        'season_label'    => $limits['season_label'],
        'cap_max'         => $limits['max'],
        'cap_min'         => $limits['min'],
        'cap_hit'         => $proCapHit,
        'cap_space'       => $capSpace,
        'available_space' => $capSpace,
        'under_floor'     => $underFloor,
        'is_over_cap'     => ($proCapHit > $limits['max']),
        'is_below_floor'  => ($proCapHit < $limits['min']),
    );
}

function getLeagueCapViolations(PDO $pdo): array {
    return array();
    $limits     = getLeagueCapLimits($pdo);
    $violations = array();

    $sql = "
        SELECT t.team_id,
               t.abbreviation,
               t.name AS team_name,
               COALESCE(SUM(CASE WHEN UPPER(COALESCE(p.roster_status, 'PRO')) = 'PRO' AND COALESCE(p.is_retired, FALSE) = FALSE THEN p.salary ELSE 0 END), 0) AS cap_hit,
               u.email AS gm_email,
               u.username AS gm_name
        FROM teams t
        LEFT JOIN players p ON p.team_id = t.team_id
        LEFT JOIN users u ON u.team_id = t.team_id
        GROUP BY t.team_id, t.abbreviation, t.name, u.email, u.username
        ORDER BY t.name ASC
    ";

    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $capHit = (int)$row['cap_hit'];
        if ($capHit > $limits['max']) {
            $violations[] = array(
                'team_id'      => (int)$row['team_id'],
                'abbreviation' => $row['abbreviation'],
                'team_name'    => $row['team_name'],
                'gm_name'      => $row['gm_name'] ?: 'GM',
                'gm_email'     => trim($row['gm_email'] ?? ''),
                'cap_hit'      => $capHit,
                'type'         => 'OVER_CEILING',
                'diff'         => $capHit - $limits['max'],
                'limit'        => $limits['max'],
                'season_label' => $limits['season_label'],
            );
        } elseif ($capHit < $limits['min']) {
            $violations[] = array(
                'team_id'      => (int)$row['team_id'],
                'abbreviation' => $row['abbreviation'],
                'team_name'    => $row['team_name'],
                'gm_name'      => $row['gm_name'] ?: 'GM',
                'gm_email'     => trim($row['gm_email'] ?? ''),
                'cap_hit'      => $capHit,
                'type'         => 'UNDER_FLOOR',
                'diff'         => $limits['min'] - $capHit,
                'limit'        => $limits['min'],
                'season_label' => $limits['season_label'],
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
        $teamStr = "{$v['team_name']} ({$v['abbreviation']})";
        $hitFmt  = '$' . number_format($v['cap_hit']);
        $diffFmt = '$' . number_format($v['diff']);
        $limFmt  = '$' . number_format($v['limit']);

        if ($v['type'] === 'OVER_CEILING') {
            $statusText = "OVER the {$v['season_label']} Cap Ceiling ({$limFmt}) by {$diffFmt} (Current Cap Hit: {$hitFmt})";
        } else {
            $statusText = "UNDER the {$v['season_label']} Cap Floor ({$limFmt}) by {$diffFmt} (Current Cap Hit: {$hitFmt})";
        }

        $summaryLines[] = "• {$teamStr}: {$statusText}";

        if ($sendEmails && !empty($v['gm_email']) && filter_var($v['gm_email'], FILTER_VALIDATE_EMAIL)) {
            $subject = "[HFNHL Action Required] {$v['team_name']} Salary Cap Violation";
            $body    = "Hello {$v['gm_name']},\n\n"
                . "The HFNHL simulation cannot run because {$teamStr} is currently {$statusText}.\n\n"
                . "Please log into the HFNHL portal immediately to adjust your NHL (PRO) roster via waivers, AHL demotions, trades, or free agent signings so your team is cap-compliant before the next sim.\n\n"
                . "— HFNHL Commissioner Office";

            $headers = "From: noreply@hfnhl.org\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n";

            if (@mail($v['gm_email'], $subject, $body, $headers)) {
                $emailedCount++;
            }
        }
    }

    $headerMsg = "SIM HALTED — " . count($violations) . " team(s) are not salary cap compliant"
        . ($emailedCount > 0 ? " (Alert emails sent to {$emailedCount} GM(s)):" : ":");

    return $headerMsg . "\n" . implode("\n", $summaryLines);
}




