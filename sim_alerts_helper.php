<?php
require_once __DIR__ . '/mailer.php';

/**
 * EMAIL #3 (MANDATORY): Sends compliance failure warnings to GMs blocking the sim.
 */
function sendComplianceBlockEmails(PDO $pdo, array $issues): int {
    if (empty($issues)) return 0;

    $teams = $pdo->query("
        SELECT t.team_id, t.abbreviation, t.name, u.email, u.username
        FROM teams t
        JOIN users u ON u.team_id = t.team_id
        WHERE u.email IS NOT NULL AND TRIM(u.email) <> ''
    ")->fetchAll(PDO::FETCH_ASSOC);

    $sent = 0;
    foreach ($teams as $tm) {
        $abbr = strtoupper(trim($tm['abbreviation']));
        $teamIssues = array_values(array_filter($issues, fn($line) => strpos($line, "{$abbr}:") === 0 || strpos($line, "{$abbr} (") === 0));

        if (!empty($teamIssues) && filter_var(trim($tm['email']), FILTER_VALIDATE_EMAIL)) {
            $gmName  = $tm['username'] ?: 'GM';
            $subject = "[HFNHL Action Required] {$tm['name']} Blocking Sim — Compliance Issue";
            $body    = "Hello {$gmName},\n\n"
                . "The HFNHL simulation is currently blocked because {$tm['name']} ({$abbr}) failed pre-sim compliance:\n\n"
                . " • " . implode("\n • ", $teamIssues) . "\n\n"
                . "Please log into the HFNHL portal immediately to fix your lines, roster size, or salary cap so the sim can proceed.\n\n"
                . "— HFNHL League Office";

            if (sendLeagueEmail(trim($tm['email']), $subject, $body)) {
                $sent++;
            }
        }
    }
    return $sent;
}

/**
 * EMAILS #1 & #2 (OPTIONAL): Sends Daily Scoreboard & Daily League Digest after a sim day.
 */
function sendDailySimAlerts(PDO $pdo, string $simDate, array $gameScores, array $newInjuries, array $recoveredPlayers): void {
    // --- EMAIL #1: Daily Games Simmed & Final Scores ---
    if (!empty($gameScores)) {
        $scoreRecipients = $pdo->query("
            SELECT email FROM users
            WHERE notify_games_simmed = TRUE
              AND email IS NOT NULL AND TRIM(email) <> ''
        ")->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($scoreRecipients)) {
            $subject = "HFNHL Scoreboard — {$simDate} (" . count($gameScores) . " Games)";
            $body    = "HFNHL Final Scores for {$simDate}:\n"
                . str_repeat('-', 40) . "\n"
                . " • " . implode("\n • ", $gameScores) . "\n\n"
                . "Log in to the HFNHL portal for full box scores, play-by-play, and updated standings.\n\n"
                . "— HFNHL League Office";

            sendLeagueEmail($scoreRecipients, $subject, $body);
        }
    }

    // --- EMAIL #2: Daily Digest (Injuries, Waivers & Trade Block) ---
    $digestSections = array();

    // A. Injuries & Recoveries from today's sim
    $injLines = array();
    foreach ($newInjuries as $inj) {
        $injLines[] = "[NEW INJURY] {$inj}";
    }
    foreach ($recoveredPlayers as $rec) {
        $injLines[] = "[CLEARED TO PLAY] {$rec}";
    }
    if (!empty($injLines)) {
        $digestSections[] = "INJURY & RECOVERY REPORT:\n • " . implode("\n • ", $injLines);
    }

    // B. Active Players Currently on Waivers
    try {
        $wRows = $pdo->query("
            SELECT p.first_name, p.last_name, p.position, p.overall_rating, t.abbreviation
            FROM players p
            LEFT JOIN teams t ON p.team_id = t.team_id
            WHERE UPPER(COALESCE(p.roster_status, '')) = 'WAIVERS'
            ORDER BY p.overall_rating DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($wRows)) {
            $wLines = array();
            foreach ($wRows as $w) {
                $abbr     = $w['abbreviation'] ?: 'FA';
                $wLines[] = "{$w['first_name']} {$w['last_name']} ({$w['position']}, {$w['overall_rating']} OVR) — Placed by {$abbr}";
            }
            $digestSections[] = "PLAYERS ON WAIVERS:\n • " . implode("\n • ", $wLines);
        }
    } catch (Exception $e) {}

    // C. Trade Block Additions (Added in the last 24 hours)
    try {
        $tbRows = $pdo->query("
            SELECT p.first_name, p.last_name, p.position, p.overall_rating, t.abbreviation, tb.notes
            FROM trade_block tb
            JOIN players p ON tb.player_id = p.player_id
            LEFT JOIN teams t ON p.team_id = t.team_id
            WHERE tb.created_at >= NOW() - INTERVAL '24 hours'
            ORDER BY tb.created_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($tbRows)) {
            $tbLines = array();
            foreach ($tbRows as $tb) {
                $abbr      = $tb['abbreviation'] ?: 'NHL';
                $noteStr   = !empty($tb['notes']) ? " — \"{$tb['notes']}\"" : "";
                $tbLines[] = "{$tb['first_name']} {$tb['last_name']} ({$tb['position']}, {$tb['overall_rating']} OVR | {$abbr}){$noteStr}";
            }
            $digestSections[] = "NEW ON THE TRADE BLOCK (LAST 24H):\n • " . implode("\n • ", $tbLines);
        }
    } catch (Exception $e) {}

    if (!empty($digestSections)) {
        $digestRecipients = $pdo->query("
            SELECT email FROM users
            WHERE (notify_trade_block = TRUE OR notify_injuries = TRUE)
              AND email IS NOT NULL AND TRIM(email) <> ''
        ")->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($digestRecipients)) {
            $subject = "HFNHL Daily League Digest — Injuries, Waivers & Trade Block ({$simDate})";
            $body    = "Here is your HFNHL Daily League Activity Digest for {$simDate}:\n\n"
                . implode("\n\n" . str_repeat('-', 40) . "\n\n", $digestSections) . "\n\n"
                . "Log in to the HFNHL portal to submit waiver claims, adjust lines, or send trade offers.\n\n"
                . "— HFNHL League Office";

            sendLeagueEmail($digestRecipients, $subject, $body);
        }
    }
}
?>

