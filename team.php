<?php
require 'db.php';
require_once 'cap_helper.php';

$team_id   = isset($_GET['id']) ? (int)$_GET['id'] : 1;
$isCommish = !empty($_SESSION['is_commish']) || !empty($_SESSION['is_admin']);
$isTeamGM  = (!empty($_SESSION['team_id']) && (int)$_SESSION['team_id'] === $team_id) || $isCommish;

$commMsg         = '';
$rosterMsg       = '';
$rosterAlertType = 'info';

function requiresWaivers(array $p): bool {
    if (isset($p['waiver_eligible'])) {
        return (bool)$p['waiver_eligible'];
    }
    if (isset($p['requires_waivers'])) {
        return (bool)$p['requires_waivers'];
    }
    $age   = (int)($p['age'] ?? 0);
    $games = (int)($p['nhl_games_played'] ?? $p['games_played'] ?? $p['gp'] ?? 0);
    return ($age >= 25 || $games >= 70);
}

$isAdminUser = (!empty($_SESSION['is_commish']) || !empty($_SESSION['is_admin']));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($isTeamGM || $isAdminUser) && !empty($_POST['gm_roster_action'])) {
    $moveAct = $_POST['gm_roster_action'];
    $pid     = (int)($_POST['player_id'] ?? 0);

    if ($pid > 0) {
        $chkStmt = $pdo->prepare("SELECT * FROM players WHERE player_id = ? AND team_id = ?");
        $chkStmt->execute(array($pid, $team_id));
        $targetPlayer = $chkStmt->fetch(PDO::FETCH_ASSOC);

        if ($targetPlayer) {
            $fullName = trim(($targetPlayer['first_name'] ?? '') . ' ' . ($targetPlayer['last_name'] ?? ''));

            if ($moveAct === 'send_to_ahl') {
                $needsWaivers = requiresWaivers($targetPlayer);

                $pdo->prepare("
                    UPDATE players
                    SET roster_status = 'AHL',
                        line_number   = 99
                    WHERE player_id = ? AND team_id = ?
                ")->execute(array($pid, $team_id));

                if ($needsWaivers) {
                    $rosterAlertType = 'warning';
                    $rosterMsg = "WAIVER NOTICE: {$fullName} requires waivers to be sent down to the AHL! Please ensure waivers are posted and processed per league rules.";
                } else {
                    $rosterAlertType = 'info';
                    $rosterMsg = "{$fullName} is waiver-exempt and has been sent down to the AHL.";
                }
            } elseif ($moveAct === 'call_up_pro') {
                $pdo->prepare("
                    UPDATE players
                    SET roster_status = 'PRO',
                        line_number   = 99
                    WHERE player_id = ? AND team_id = ?
                ")->execute(array($pid, $team_id));

                $rosterAlertType = 'info';
                $rosterMsg = "{$fullName} has been called up to the NHL roster (placed on Healthy Scratch / Line 99).";
            } elseif ($moveAct === 'activate_rights_player') {
                $dest   = (($_POST['dest_roster'] ?? 'PRO') === 'AHL') ? 'AHL' : 'PRO';
                $isElig = !empty($targetPlayer['is_eligible_activate']);

                if (!$isElig && !$isAdminUser) {
                    $rosterAlertType = 'error';
                    $rosterMsg = "Cannot activate {$fullName}: Player is marked ineligible (did not play prior season).";
                } else {
                    $capBlocked = false;
                    if ($dest === 'PRO' && function_exists('getTeamCapSummary')) {
                        $capCheck = getTeamCapSummary($pdo, $team_id);
                        if (($capCheck['cap_hit'] + (int)$targetPlayer['salary']) > $capCheck['cap_max']) {
                            $capBlocked      = true;
                            $rosterAlertType = 'error';
                            $rosterMsg       = "Cannot activate {$fullName} to NHL: Adding $" . number_format((int)$targetPlayer['salary']) . " exceeds the $" . number_format($capCheck['cap_max']) . " Salary Cap ceiling.";
                        }
                    }

                    if (!$capBlocked) {
                        $pdo->prepare("
                            UPDATE players
                            SET roster_status = ?,
                                line_number   = 99
                            WHERE player_id = ? AND team_id = ?
                        ")->execute(array($dest, $pid, $team_id));

                        $rosterAlertType = 'info';
                        $rosterMsg       = "{$fullName} has been reactivated to the {$dest} roster with their saved contract intact!";
                    }
                }
            } elseif ($moveAct === 'release_rights_player') {
                $addedTs  = !empty($targetPlayer['rights_added_at']) ? strtotime($targetPlayer['rights_added_at']) : time();
                $daysHeld = (int)floor((time() - $addedTs) / 86400);
                $canRel   = ($daysHeld >= 365 || $isAdminUser);

                if (!$canRel) {
                    $daysLeft        = 365 - $daysHeld;
                    $rosterAlertType = 'error';
                    $rosterMsg       = "Cannot release {$fullName} yet: European/LTIR rights players must be held for 1 full year ({$daysLeft} days remaining).";
                } else {
                    $pdo->prepare("
                        UPDATE players
                        SET team_id        = NULL,
                            prev_team_id   = ?,
                            roster_status  = 'FA',
                            fa_status      = 'UFA',
                            contract_years = 0,
                            salary         = 0,
                            qo_tendered    = FALSE,
                            line_number    = 99
                        WHERE player_id = ? AND team_id = ?
                    ")->execute(array($team_id, $pid, $team_id));

                    $rosterAlertType = 'info';
                    $rosterMsg       = "{$fullName} has been released from Rights Owned into Unrestricted Free Agency.";
                }
            }
        }
    }
    $prospectId = (int)($_POST['prospect_id'] ?? 0);
    if ($prospectId > 0 && ($moveAct === 'sign_prospect_elc' || $moveAct === 'release_prospect')) {
        $prStmt = $pdo->prepare("SELECT * FROM prospects WHERE prospect_id = ? AND team_id = ?");
        $prStmt->execute(array($prospectId, $team_id));
        $pr = $prStmt->fetch(PDO::FETCH_ASSOC);

        if ($pr) {
            $prName = trim(($pr['first_name'] ?? '') . ' ' . ($pr['last_name'] ?? ''));

            if ($moveAct === 'release_prospect') {
                $pdo->prepare("DELETE FROM prospects WHERE prospect_id = ? AND team_id = ?")
                        ->execute(array($prospectId, $team_id));
                $rosterAlertType = 'info';
                $rosterMsg       = "Draft rights to {$prName} have been relinquished.";
            } elseif ($moveAct === 'sign_prospect_elc') {
                $draftYear = (int)($pr['draft_year'] ?? 2025);
                $rawAge    = (int)($pr['age'] ?? 0);
                $pAge      = ($rawAge > 18) ? $rawAge : (($draftYear > 0 && $draftYear < 2026) ? (2026 - $draftYear + 18) : 18);
                $dest      = (($_POST['dest_roster'] ?? 'AHL') === 'PRO') ? 'PRO' : 'AHL';

                if ($pAge <= 21) $elcYears = 3;
                elseif ($pAge <= 23) $elcYears = 2;
                else $elcYears = 1;

                $elcMin    = 775000;
                $elcMax    = ($draftYear >= 2024) ? 975000 : 950000;
                $reqSalary = (int)preg_replace('/[^0-9]/', '', (string)($_POST['elc_salary'] ?? $elcMax));

                if ($reqSalary < $elcMin || $reqSalary > $elcMax) {
                    $rosterAlertType = 'error';
                    $rosterMsg       = "Invalid ELC salary for {$prName}: Must be between $" . number_format($elcMin) . " and $" . number_format($elcMax) . " (Term is fixed at {$elcYears} yr" . ($elcYears > 1 ? 's' : '') . ").";
                } else {
                    $capBlocked = false;
                    if ($dest === 'PRO' && function_exists('getTeamCapSummary')) {
                        $capCheck = getTeamCapSummary($pdo, $team_id);
                        if (($capCheck['cap_hit'] + $reqSalary) > $capCheck['cap_max']) {
                            $capBlocked      = true;
                            $rosterAlertType = 'error';
                            $rosterMsg       = "Cannot sign {$prName} directly to NHL: $" . number_format($reqSalary) . " ELC exceeds the $" . number_format($capCheck['cap_max']) . " Salary Cap ceiling (try signing to AHL instead).";
                        }
                    }

                    if (!$capBlocked) {
                        $pos = strtoupper(trim($pr['position'] ?? 'C'));
                        if ($pos === 'F' || $pos === '') $pos = 'C';
                        $isGoalie = ($pos === 'G');

                        $dupChk = $pdo->prepare("
                            SELECT player_id FROM players
                            WHERE team_id = ?
                              AND LOWER(TRIM(first_name)) = LOWER(TRIM(?))
                              AND LOWER(TRIM(last_name)) = LOWER(TRIM(?))
                              AND COALESCE(is_retired, FALSE) = FALSE
                            LIMIT 1
                        ");
                        $dupChk->execute(array($team_id, trim($pr['first_name']), trim($pr['last_name'])));
                        $existingPid = (int)$dupChk->fetchColumn();

                        if ($existingPid > 0) {
                            $pdo->prepare("
                                UPDATE players
                                SET roster_status  = ?,
                                    contract_years = ?,
                                    salary         = ?,
                                    age            = GREATEST(COALESCE(age, 18), ?),
                                    line_number    = 99
                                WHERE player_id = ?
                            ")->execute(array($dest, $elcYears, $reqSalary, $pAge, $existingPid));
                        } else {
                            $ins = $pdo->prepare("
                                INSERT INTO players (
                                    team_id, first_name, last_name, position, age,
                                    roster_status, contract_years, salary,
                                    overall_rating, skating, shooting, passing, defense, physical, goaltending,
                                    line_number, condition, is_injured, injury_days_remaining, is_retired
                                ) VALUES (
                                    ?, ?, ?, ?, ?,
                                    ?, ?, ?,
                                    ?, ?, ?, ?, ?, ?, ?,
                                    99, 100, FALSE, 0, FALSE
                                )
                            ");
                            $ins->execute(array(
                                    $team_id,
                                    trim($pr['first_name']),
                                    trim($pr['last_name']),
                                    $pos,
                                    $pAge,
                                    $dest,
                                    $elcYears,
                                    $reqSalary,
                                    68,
                                    $isGoalie ? 50 : 69,
                                    $isGoalie ? 50 : 68,
                                    $isGoalie ? 50 : 68,
                                    $isGoalie ? 50 : 67,
                                    $isGoalie ? 50 : 67,
                                    $isGoalie ? 69 : 50
                            ));
                        }

                        $pdo->prepare("DELETE FROM prospects WHERE prospect_id = ? AND team_id = ?")
                                ->execute(array($prospectId, $team_id));

                        $rosterAlertType = 'info';
                        $rosterMsg       = "ELC SIGNED! {$prName} signed a fixed {$elcYears}-year Entry-Level Contract at $" . number_format($reqSalary) . "/yr and assigned to {$dest}.";
                    }
                }
            }
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isCommish && !empty($_POST['commish_player_action'])) {
    $act  = $_POST['commish_player_action'];
    $pid  = (int)($_POST['player_id'] ?? 0);
    $note = trim($_POST['rights_note'] ?? '');

    if ($pid > 0) {
        $pInfoStmt = $pdo->prepare("SELECT first_name, last_name FROM players WHERE player_id = ? AND team_id = ?");
        $pInfoStmt->execute(array($pid, $team_id));
        $pInfo = $pInfoStmt->fetch(PDO::FETCH_ASSOC);

        if ($act === 'send_ltir') {
            $pdo->prepare("
                UPDATE players
                SET roster_status = 'LTIR',
                    line_number   = 99,
                    rights_notes  = COALESCE(NULLIF(?, ''), rights_notes)
                WHERE player_id = ? AND team_id = ?
            ")->execute(array($note, $pid, $team_id));

            if ($pInfo) {
                $pdo->prepare("DELETE FROM prospects WHERE team_id = ? AND LOWER(TRIM(first_name)) = LOWER(TRIM(?)) AND LOWER(TRIM(last_name)) = LOWER(TRIM(?))")
                        ->execute(array($team_id, $pInfo['first_name'], $pInfo['last_name']));
            }
            $commMsg = "Player placed on Injury / LTIR (contract & team rights preserved).";
        } elseif ($act === 'send_euro') {
            $pdo->prepare("
                UPDATE players
                SET roster_status = 'EURO',
                    line_number   = 99,
                    rights_notes  = COALESCE(NULLIF(?, ''), rights_notes)
                WHERE player_id = ? AND team_id = ?
            ")->execute(array($note, $pid, $team_id));

            if ($pInfo) {
                $pdo->prepare("DELETE FROM prospects WHERE team_id = ? AND LOWER(TRIM(first_name)) = LOWER(TRIM(?)) AND LOWER(TRIM(last_name)) = LOWER(TRIM(?))")
                        ->execute(array($team_id, $pInfo['first_name'], $pInfo['last_name']));
            }
            $commMsg = "Player assigned to Europe (contract & team rights preserved).";
        } elseif ($act === 'retire_player') {
            $pdo->prepare("
                UPDATE players
                SET is_retired    = TRUE,
                    roster_status = 'RETIRED',
                    team_id       = NULL,
                    line_number   = 99,
                    rights_notes  = ?
                WHERE player_id = ? AND team_id = ?
            ")->execute(array($note ?: 'Retired from HFNHL', $pid, $team_id));
            $commMsg = "Player retired from the sim and excluded from future roster updates.";
        } elseif ($act === 'reactivate_pro' || $act === 'reactivate_ahl') {
            $targetRoster = ($act === 'reactivate_pro') ? 'PRO' : 'AHL';
            $pdo->prepare("
                UPDATE players
                SET is_retired    = FALSE,
                    roster_status = ?,
                    line_number   = 99
                WHERE player_id = ? AND team_id = ?
            ")->execute(array($targetRoster, $pid, $team_id));
            $commMsg = "Player reactivated to {$targetRoster} roster with existing contract intact!";
        }
    }
}
$tStmt = $pdo->prepare("SELECT * FROM teams WHERE team_id = ?");
$tStmt->execute(array($team_id));
$team = $tStmt->fetch(PDO::FETCH_ASSOC);
if (!$team) die("Team not found.");

$ahlAffiliates = array(
        'ANA' => 'San Diego Gulls',            'BOS' => 'Providence Bruins',
        'BUF' => 'Rochester Americans',        'CGY' => 'Calgary Wranglers',
        'CAR' => 'Chicago Wolves',             'CHI' => 'Rockford IceHogs',
        'COL' => 'Colorado Eagles',            'CBJ' => 'Cleveland Monsters',
        'DAL' => 'Texas Stars',                'DET' => 'Grand Rapids Griffins',
        'EDM' => 'Bakersfield Condors',        'FLA' => 'Charlotte Checkers',
        'LAK' => 'Ontario Reign',              'MIN' => 'Iowa Wild',
        'MTL' => 'Laval Rocket',               'NSH' => 'Milwaukee Admirals',
        'NJD' => 'Utica Comets',               'NYI' => 'Bridgeport Islanders',
        'NYR' => 'Hartford Wolf Pack',         'OTT' => 'Belleville Senators',
        'PHI' => 'Lehigh Valley Phantoms',     'PIT' => 'Wilkes-Barre/Scranton Penguins',
        'SJS' => 'San Jose Barracuda',         'SEA' => 'Coachella Valley Firebirds',
        'STL' => 'Springfield Thunderbirds',   'TBL' => 'Syracuse Crunch',
        'TOR' => 'Toronto Marlies',            'UTA' => 'Tucson Roadrunners',
        'VAN' => 'Abbotsford Canucks',         'VGK' => 'Henderson Silver Knights',
        'WSH' => 'Hershey Bears',              'WPG' => 'Manitoba Moose'
);

$teamColors = array(
        'ANA' => array('#F47A38', '#B9975B'), 'BOS' => array('#FFB81C', '#111111'),
        'BUF' => array('#003087', '#FFB81C'), 'CAR' => array('#CE1126', '#111111'),
        'CBJ' => array('#002654', '#CE1126'), 'CGY' => array('#D2001C', '#FAAF19'),
        'CHI' => array('#CF0A2C', '#111111'), 'COL' => array('#6F263D', '#236192'),
        'DAL' => array('#006847', '#111111'), 'DET' => array('#CE1126', '#1e293b'),
        'EDM' => array('#041E42', '#FF4C00'), 'FLA' => array('#041E42', '#C8102E'),
        'LAK' => array('#111111', '#A2AAAD'), 'MIN' => array('#154734', '#A6192E'),
        'MTL' => array('#AF1E2D', '#192168'), 'NJD' => array('#CE1126', '#111111'),
        'NSH' => array('#FFB81C', '#041E42'), 'NYI' => array('#00539B', '#F47D30'),
        'NYR' => array('#0038A8', '#CE1126'), 'OTT' => array('#DA1A32', '#111111'),
        'PHI' => array('#F74902', '#111111'), 'PIT' => array('#FCB514', '#111111'),
        'SEA' => array('#001628', '#99D9D9'), 'SJS' => array('#006D75', '#EA7200'),
        'STL' => array('#002F87', '#FCB514'), 'TBL' => array('#002868', '#1e293b'),
        'TOR' => array('#00205B', '#1e293b'), 'UTA' => array('#69B3E7', '#111111'),
        'VAN' => array('#00205B', '#00843D'), 'VGK' => array('#333F42', '#B4975A'),
        'WPG' => array('#041E42', '#004C97'), 'WSH' => array('#041E42', '#C8102E')
);

$abbr    = strtoupper(trim($team['abbreviation'] ?? ''));
$nhlName = $team['name'] ?? 'NHL Team';
$ahlName = $ahlAffiliates[$abbr] ?? ($team['ahl_affiliate'] ?? 'AHL Affiliate');
$gmName  = $team['gm_name'] ?? 'Vacant';
$c1      = $teamColors[$abbr] ?? ($team['primary_color'] ?? '#0284c7');
$c2      = $teamColors[$abbr] ?? ($team['secondary_color'] ?? '#38bdf8');

$gmUserStmt = $pdo->prepare("SELECT username FROM users WHERE team_id = ? LIMIT 1");
$gmUserStmt->execute(array($team_id));
$dbGmUser = $gmUserStmt->fetchColumn();
if ($dbGmUser) $gmName = $dbGmUser;

$gStmt = $pdo->prepare("SELECT home_team_id, away_team_id, home_score, away_score FROM games WHERE status = 'FINAL' AND (home_team_id = ? OR away_team_id = ?)");
$gStmt->execute(array($team_id, $team_id));
$gp = $w = $l = $otl = $pts = $gf = $ga = 0;
foreach ($gStmt->fetchAll(PDO::FETCH_ASSOC) as $g) {
    $isHome = ((int)$g['home_team_id'] === $team_id);
    $myS    = $isHome ? (int)$g['home_score'] : (int)$g['away_score'];
    $opS    = $isHome ? (int)$g['away_score'] : (int)$g['home_score'];
    $gp++;
    $gf += $myS;
    $ga += $opS;
    $isOt = (abs($myS - $opS) === 1 && ($myS + $opS) >= 3);
    if ($myS > $opS) {
        $w++;
        $pts += 2;
    } elseif ($isOt) {
        $otl++;
        $pts += 1;
    } else {
        $l++;
    }
}
$pStmt = $pdo->prepare("SELECT * FROM players WHERE team_id = ? AND COALESCE(is_retired, FALSE) = FALSE ORDER BY overall_rating DESC, last_name ASC");
$pStmt->execute(array($team_id));
$allPlayers = $pStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch active salary retentions BY this team (counts against their 3 retention slots)
$teamRetainedList = array();
try {
    $retOutStmt = $pdo->prepare("
        SELECT rs.*,
               p.first_name,
               p.last_name,
               p.position,
               t.abbreviation AS current_team_abbr,
               t.name AS current_team_name
        FROM retained_salaries rs
        LEFT JOIN players p ON rs.player_id = p.player_id
        LEFT JOIN teams t ON p.team_id = t.team_id
        WHERE rs.retaining_team_id = ?
          AND rs.years_remaining > 0
        ORDER BY rs.retained_cap_hit DESC
    ");
    $retOutStmt->execute(array($team_id));
    $teamRetainedList = $retOutStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $teamRetainedList = array();
}
$retentionSlotsUsed = count($teamRetainedList);

// Fetch any retentions ON players currently on this team's roster (retained by former teams)
$playerRetentionsMap = array();
try {
    $retInStmt = $pdo->prepare("
        SELECT rs.player_id,
               rs.retained_cap_hit,
               t.abbreviation AS retaining_abbr
        FROM retained_salaries rs
        JOIN players p ON rs.player_id = p.player_id
        LEFT JOIN teams t ON rs.retaining_team_id = t.team_id
        WHERE p.team_id = ?
          AND rs.years_remaining > 0
    ");
    $retInStmt->execute(array($team_id));
    foreach ($retInStmt->fetchAll(PDO::FETCH_ASSOC) as $ri) {
        $pidKey = (int)$ri['player_id'];
        if (!isset($playerRetentionsMap[$pidKey])) {
            $playerRetentionsMap[$pidKey] = array(
                    'total_retained' => 0,
                    'sources'        => array(),
            );
        }
        $amt = (int)$ri['retained_cap_hit'];
        $playerRetentionsMap[$pidKey]['total_retained'] += $amt;
        $srcAbbr = !empty($ri['retaining_abbr']) ? strtoupper(trim($ri['retaining_abbr'])) : 'Prior Team';
        $playerRetentionsMap[$pidKey]['sources'][] = '$' . number_format($amt) . ' by ' . $srcAbbr;
    }
} catch (Exception $e) {
    $playerRetentionsMap = array();
}

$nhlPlayers              = array();
$ahlPlayers              = array();
$inactiveContractPlayers = array();

foreach ($allPlayers as $p) {
    $st = strtoupper(trim($p['roster_status'] ?? ''));
    if ($st === 'PRO') {
        $nhlPlayers[] = $p;
    } elseif (in_array($st, array('LTIR', 'INJURED', 'EURO', 'EUROPE', 'RIGHTS', 'INACTIVE'))) {
        $inactiveContractPlayers[] = $p;
    } elseif ($st === 'AHL') {
        $ahlPlayers[] = $p;
    }
}

$avgOvr = 0;
if (!empty($nhlPlayers)) {
    $sumOvr = 0;
    foreach ($nhlPlayers as $np) $sumOvr += (int)$np['overall_rating'];
    $avgOvr = round($sumOvr / count($nhlPlayers));
}
$prStmt = $pdo->prepare("SELECT * FROM prospects WHERE team_id = ? AND COALESCE(draft_year, 0) > 0 ORDER BY draft_year DESC, notes ASC");
$prStmt->execute(array($team_id));
$prospects = $prStmt->fetchAll(PDO::FETCH_ASSOC);

$rightsStmt = $pdo->prepare("SELECT * FROM prospects WHERE team_id = ? AND COALESCE(draft_year, 0) = 0 ORDER BY 1 ASC");
$rightsStmt->execute(array($team_id));
$rightsOwned = $rightsStmt->fetchAll(PDO::FETCH_ASSOC);

$pkStmt = $pdo->prepare("
    SELECT dp.draft_year, dp.round_num, orig.abbreviation AS orig_abbr, orig.name AS orig_name, dp.conditions
    FROM draft_picks dp
    JOIN teams orig ON dp.original_team_id = orig.team_id
    WHERE dp.current_team_id = ? AND COALESCE(dp.is_used, FALSE) = FALSE
    ORDER BY dp.draft_year ASC, dp.round_num ASC, orig.abbreviation ASC
");
$pkStmt->execute(array($team_id));
$ownedPicks = $pkStmt->fetchAll(PDO::FETCH_ASSOC);

$pickMatrix = array();
$pickYears  = array();
$condNotes  = array();
foreach ($ownedPicks as $pk) {
    $yr = (int)$pk['draft_year'];
    $rd = (int)$pk['round_num'];
    $pickYears[$yr] = $yr;
    $pickMatrix[$yr][$rd][] = strtoupper(trim($pk['orig_abbr']));
    if (trim((string)$pk['conditions']) !== '') {
        $condNotes[] = "{$yr} Rd {$rd} ({$pk['orig_abbr']}): " . trim($pk['conditions']);
    }
}
ksort($pickYears);

function getTeamLogoHtml($abbr) {
    $a = strtoupper(trim($abbr));
    return '<span class="pick-chip" title="' . htmlspecialchars($a) . '">'
            . '<img src="logos/' . htmlspecialchars($a) . '.svg" alt="' . htmlspecialchars($a) . '">'
            . '</span>';
}

function getLineLabel($p, $isPro) {
    if (!$isPro) return '<span class="badge-farm">AHL</span>';
    $pos = strtoupper(trim($p['position']));
    $ln  = (int)$p['line_number'];
    if ($pos === 'G') {
        if ($ln === 1) return '<span class="line-pill">Starter</span>';
        if ($ln === 2) return '<span class="line-pill">Backup</span>';
        return '<span class="line-pill scratch">Reserve</span>';
    }
    if ($pos === 'D') {
        if ($ln >= 1 && $ln <= 3) return '<span class="line-pill">Pair ' . $ln . '</span>';
        return '<span class="line-pill scratch">Scratch</span>';
    }
    if ($ln >= 1 && $ln <= 4) return '<span class="line-pill">Line ' . $ln . '</span>';
    return '<span class="line-pill scratch">Scratch</span>';
}

function extractProspectName($row) {
    $combined = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    if ($combined !== '') return $combined;
    $skipCols = array('id', 'prospect_id', 'team_id', 'draft_year', 'notes', 'status', 'overall_pick', 'round', 'position');
    foreach ($row as $col => $val) {
        if (!in_array(strtolower((string)$col), $skipCols) && !is_numeric($val) && trim((string)$val) !== '') {
            return trim((string)$val);
        }
    }
    return '';
}
function renderRosterTable($rows, $isPro, $isTeamGM = false, $ahlName = 'AHL', $playerRetentionsMap = array()) {
    if (empty($rows)) {
        echo '<p style="color:#94a3b8;">No players assigned to this roster.</p>';
        return;
    }
    echo '<table>
        <tr>
            <th style="width:90px;">Line</th><th>Player</th><th>Pos</th><th>OVR</th>
            <th>SK</th><th>SH</th><th>PA</th><th>DF</th><th>PH</th>
            <th>Contract</th><th>Cond</th>';
    if ($isTeamGM) {
        echo '<th style="width:135px; text-align:center;">Roster Move</th>';
    }
    echo '</tr>';

    foreach ($rows as $p) {
        $pid      = (int)$p['player_id'];
        $inj      = !empty($p['is_injured']) ? '<span class="inj"> (INJ ' . (int)$p['injury_days_remaining'] . 'd)</span>' : '';
        $fullName = trim($p['first_name'] . ' ' . $p['last_name']);
        $needsWvr = $isPro && requiresWaivers($p);
        $wvrBadge = $needsWvr ? ' <span class="badge-waiver">Waivers</span>' : '';

        $retInfo  = $playerRetentionsMap[$pid] ?? null;
        $retBadge = '';
        $retSub   = '';
        if ($retInfo && $retInfo['total_retained'] > 0) {
            $retTitle = htmlspecialchars(implode(', ', $retInfo['sources']), ENT_QUOTES);
            $retBadge = ' <span class="badge-retained" title="Retained: ' . $retTitle . '">Retained</span>';
            $retSub   = '<div style="font-size:0.78em; color:#c084fc; margin-top:2px;">Retained: $' . number_format($retInfo['total_retained']) . '</div>';
        }

        echo '<tr>
            <td>' . getLineLabel($p, $isPro) . '</td>
            <td><strong>' . htmlspecialchars($fullName) . '</strong>' . $inj . $wvrBadge . $retBadge . '</td>
            <td>' . htmlspecialchars($p['position']) . '</td>
            <td><strong>' . (int)$p['overall_rating'] . '</strong></td>
            <td>' . (int)$p['skating'] . '</td>
            <td>' . (int)$p['shooting'] . '</td>
            <td>' . (int)$p['passing'] . '</td>
            <td>' . (int)$p['defense'] . '</td>
            <td>' . (int)$p['physical'] . '</td>
            <td>$' . number_format((int)$p['salary']) . ' (' . (int)$p['contract_years'] . 'y)' . $retSub . '</td>
            <td>' . (int)$p['condition'] . '%</td>';

        if ($isTeamGM) {
            $jsName = htmlspecialchars($fullName, ENT_QUOTES);
            $jsAhl  = htmlspecialchars($ahlName, ENT_QUOTES);
            echo '<td style="text-align:center;">';
            if ($isPro) {
                $onsubmit = $needsWvr
                        ? "return confirm('WAIVER WARNING: {$jsName} requires WAIVERS to be sent down to {$jsAhl}. Continue?');"
                        : "return true;";
                $btnClass = $needsWvr ? 'btn-move-waiver' : 'btn-move-down';
                echo '<form method="POST" style="margin:0;" onsubmit="' . $onsubmit . '">
                        <input type="hidden" name="player_id" value="' . $pid . '">
                        <input type="hidden" name="gm_roster_action" value="send_to_ahl">
                        <button type="submit" class="btn-move ' . $btnClass . '">
                            Send Down' . ($needsWvr ? ' ⚠️' : '') . '
                        </button>
                      </form>';
            } else {
                echo '<form method="POST" style="margin:0;">
                        <input type="hidden" name="player_id" value="' . $pid . '">
                        <input type="hidden" name="gm_roster_action" value="call_up_pro">
                        <button type="submit" class="btn-move btn-move-up">
                            Call Up
                        </button>
                      </form>';
            }
            echo '</td>';
        }
        echo '</tr>';
    }
    echo '</table>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($nhlName) ?> - HFNHL</title>
    <style>
        body { font-family: Arial, sans-serif; background: #0f172a; color: #f8fafc; padding: 20px; max-width: 1200px; margin: auto; }
        nav { background: #020617; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; display: flex; gap: 20px; border: 1px solid #334155; align-items: center; }
        nav a { color: #38bdf8; text-decoration: none; font-weight: bold; }
        h1, h2 { color: #38bdf8; }
        .affiliate-sub { color: #94a3b8; font-size: 1em; margin-top: -12px; margin-bottom: 20px; }
        .league-tag-nhl { background: #0284c7; color: #fff; padding: 3px 9px; border-radius: 5px; font-size: 0.7em; vertical-align: middle; margin-right: 8px; }
        .league-tag-ahl { background: #475569; color: #f8fafc; padding: 3px 9px; border-radius: 5px; font-size: 0.7em; vertical-align: middle; margin-right: 8px; }
        table { width: 100%; border-collapse: collapse; background: #1e293b; border-radius: 8px; overflow: hidden; margin-bottom: 24px; }
        th, td { padding: 8px 11px; text-align: left; border-bottom: 1px solid #334155; font-size: 0.93em; }
        th { background: #020617; color: #38bdf8; font-size: 0.86em; }
        .line-pill { background: #0369a1; color: #e0f2fe; padding: 2px 8px; border-radius: 4px; font-size: 0.8em; font-weight: bold; display: inline-block; }
        .line-pill.scratch { background: #334155; color: #94a3b8; }
        .badge-farm { background: #1e3a8a; color: #93c5fd; padding: 2px 7px; border-radius: 4px; font-size: 0.8em; font-weight: bold; }
        .badge-rights { background: #581c87; color: #e9d5ff; padding: 2px 7px; border-radius: 4px; font-size: 0.8em; font-weight: bold; }
        .badge-waiver { background: #7c2d12; color: #fdba74; border: 1px solid #f97316; padding: 1px 6px; border-radius: 4px; font-size: 0.75em; font-weight: bold; margin-left: 5px; }
        .badge-retained { background: #4c1d95; color: #ddd6fe; border: 1px solid #8b5cf6; padding: 1px 6px; border-radius: 4px; font-size: 0.75em; font-weight: bold; margin-left: 5px; }
        .inj { color: #f87171; font-weight: bold; font-size: 0.85em; }
        .btn-move { color: #fff; border: 1px solid rgba(255,255,255,0.18); padding: 4px 10px; border-radius: 4px; cursor: pointer; font-size: 0.8em; font-weight: bold; }
        .btn-move-up { background: #0284c7; }
        .btn-move-up:hover { background: #0369a1; }
        .btn-move-down { background: #334155; }
        .btn-move-down:hover { background: #475569; }
        .btn-move-waiver { background: #c2410c; }
        .btn-move-waiver:hover { background: #9a3412; }
        .roster-alert { padding: 12px 16px; margin-bottom: 18px; border-radius: 8px; font-size: 0.95em; font-weight: bold; }
        .roster-alert.info { background: #0c4a6e; border: 1px solid #38bdf8; color: #e0f2fe; }
        .roster-alert.warning { background: #431407; border: 1px solid #f97316; color: #fed7aa; }
        .roster-alert.error { background: #7f1d1d; border: 1px solid #ef4444; color: #fecaca; }
        .picks-matrix th, .picks-matrix td { text-align: center; border: 1px solid #334155; padding: 8px 6px; }
        .pick-chip { display: inline-flex; align-items: center; justify-content: center; background: #e2e8f0; border: 1px solid #94a3b8; border-radius: 6px; padding: 3px; margin: 2px; }
        .pick-chip img { width: 38px; height: 38px; object-fit: contain; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; align-items: start; }
        .lineup-grid td { text-align: center; border: 1px solid #334155; padding: 9px 10px; background: #1e293b; }
        .lineup-banner { background: #0f172a; color: #e2e8f0; font-weight: bold; text-align: center; letter-spacing: 0.5px; }
        .ovr-tag { color: #94a3b8; font-size: 0.85em; margin-left: 4px; }
        .team-hero {
            position: relative;
            overflow: hidden;
            border-radius: 10px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 20px 28px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 24px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.45);
        }
        .team-hero-watermark {
            position: absolute;
            width: 340px;
            height: 340px;
            opacity: 0.14;
            right: 12%;
            top: -95px;
            pointer-events: none;
            transform: rotate(-10deg);
        }
        .team-hero-logo {
            width: 96px;
            height: 96px;
            object-fit: contain;
            z-index: 1;
            filter: drop-shadow(0 4px 10px rgba(0,0,0,0.65));
        }
        .team-hero-info {
            z-index: 1;
            text-align: left;
        }
        .team-hero-info h1 {
            margin: 0 0 6px 0;
            color: #ffffff;
            font-size: 1.85em;
            text-shadow: 0 2px 6px rgba(0,0,0,0.75);
        }
        .team-hero-line {
            color: #f1f5f9;
            font-size: 0.94em;
            line-height: 1.55;
            text-shadow: 0 1px 4px rgba(0,0,0,0.8);
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>

<?php if (!empty($rosterMsg)): ?>
    <div class="roster-alert <?= htmlspecialchars($rosterAlertType) ?>">
        <?= htmlspecialchars($rosterMsg) ?>
    </div>
<?php endif; ?>

<div class="team-hero" style="background: linear-gradient(115deg, <?= $c1 ?> 0%, #020617 52%, <?= $c2 ?> 100%);">
    <img class="team-hero-watermark" src="logos/<?= htmlspecialchars($abbr) ?>.svg" alt="">
    <img class="team-hero-logo" src="logos/<?= htmlspecialchars($abbr) ?>.svg" alt="<?= htmlspecialchars($abbr) ?>">
    <div class="team-hero-info">
        <h1><?= htmlspecialchars($nhlName) ?></h1>
        <div class="team-hero-line">
            <strong>GP:</strong> <?= $gp ?> &nbsp;|&nbsp;
            <strong>W:</strong> <?= $w ?> &nbsp;|&nbsp;
            <strong>L:</strong> <?= $l ?> &nbsp;|&nbsp;
            <strong>OTL:</strong> <?= $otl ?> &nbsp;|&nbsp;
            <strong>PTS:</strong> <?= $pts ?>
        </div>
        <div class="team-hero-line">
            <strong>GF:</strong> <?= $gf ?> &nbsp;|&nbsp;
            <strong>GA:</strong> <?= $ga ?> &nbsp;|&nbsp;
            <strong>AHL Affiliate:</strong> <?= htmlspecialchars($ahlName) ?>
        </div>
        <div class="team-hero-line">
            <strong>GM:</strong> <?= htmlspecialchars($gmName) ?> &nbsp;|&nbsp;
            <strong>Team Overall:</strong> <?= $avgOvr ?>
        </div>
    </div>
</div>
<?php
$viewTeamId = (int)($teamId ?? $tid ?? $_GET['id'] ?? $_GET['team_id'] ?? 0);
if ($viewTeamId > 0):
    $teamCap = getTeamCapSummary($pdo, $viewTeamId);
    $borderColor = $teamCap['is_over_cap'] ? '#ef4444' : ($teamCap['is_below_floor'] ? '#f59e0b' : '#334155');
    ?>
    <div style="background:#1e293b; border:1px solid <?= $borderColor ?>; border-radius:8px; padding:12px 16px; margin-bottom:18px; display:flex; gap:22px; flex-wrap:wrap; align-items:center; font-size:0.93em;">
        <div>
            <strong><?= htmlspecialchars($teamCap['season_label']) ?> Cap Hit:</strong>
            <span style="color:#38bdf8; font-weight:bold;">$<?= number_format($teamCap['cap_hit']) ?></span>
        </div>

        <div>
            <strong>Cap Space:</strong>
            <span style="color:<?= $teamCap['cap_space'] < 0 ? '#f87171' : '#4ade80' ?>; font-weight:bold;">
                $<?= number_format($teamCap['cap_space']) ?>
            </span>
            <?php if ($teamCap['is_over_cap']): ?>
                <span style="background:#7f1d1d; color:#fca5a5; padding:2px 6px; border-radius:4px; font-size:0.8em; font-weight:bold; margin-left:4px;">OVER CAP</span>
            <?php endif; ?>
        </div>

        <div>
            <strong>Retention Slots:</strong>
            <span style="color:<?= $retentionSlotsUsed >= 3 ? '#fbbf24' : '#c084fc' ?>; font-weight:bold;">
                <?= $retentionSlotsUsed ?> / 3
            </span>
            <?php if ($teamCap['retained_cap'] > 0): ?>
                <span style="color:#94a3b8; font-size:0.88em;">($<?= number_format($teamCap['retained_cap']) ?>)</span>
            <?php endif; ?>
        </div>

        <div>
            <strong>Cap Floor ($<?= number_format($teamCap['cap_min'] / 1000000, 1) ?>M):</strong>
            <?php if ($teamCap['is_below_floor']): ?>
                <span style="color:#fbbf24; font-weight:bold;">$<?= number_format($teamCap['under_floor']) ?> Below Floor</span>
            <?php else: ?>
                <span style="color:#4ade80; font-weight:bold;">✓ Compliant</span>
            <?php endif; ?>
        </div>

        <div style="margin-left:auto; color:#94a3b8; font-size:0.86em;">
            Limits: $<?= number_format($teamCap['cap_min'] / 1000000, 1) ?>M Floor &ndash; $<?= number_format($teamCap['cap_max'] / 1000000, 1) ?>M Ceiling
        </div>
    </div>
<?php endif; ?>
<h2>Draft Picks (5-Year Matrix)</h2>
<table class="picks-matrix">
    <tr>
        <th style="width:90px;">Year</th>
        <th>R1</th><th>R2</th><th>R3</th><th>R4</th><th>R5</th><th>R6</th><th>R7</th>
    </tr>
    <?php foreach ($pickYears as $yr): ?>
        <tr>
            <td><strong><?= $yr ?></strong></td>
            <?php for ($rd = 1; $rd <= 7; $rd++): ?>
                <td>
                    <?php
                    if (!empty($pickMatrix[$yr][$rd])) {
                        foreach ($pickMatrix[$yr][$rd] as $origAbbr) {
                            echo getTeamLogoHtml($origAbbr);
                        }
                    }
                    ?>
                </td>
            <?php endfor; ?>
        </tr>
    <?php endforeach; ?>
    <tr>
        <td><strong>Conditional Draft Picks</strong></td>
        <td colspan="7" style="text-align:left;color:#94a3b8;padding-left:12px;">
            <?= !empty($condNotes) ? htmlspecialchars(implode(' | ', $condNotes)) : 'None' ?>
        </td>
    </tr>
</table>

<h2>Retained Salary Contracts (<?= $retentionSlotsUsed ?> / 3 Slots Used)</h2>
<?php if (empty($teamRetainedList)): ?>
    <div style="background:#1e293b; padding:12px 16px; border-radius:8px; border:1px solid #334155; color:#94a3b8; margin-bottom:24px; font-size:0.93em;">
        No active retained salary contracts (0 of 3 retention slots used).
    </div>
<?php else: ?>
    <table>
        <tr>
            <th>Player</th>
            <th>Pos</th>
            <th>Current Team</th>
            <th>Retained Cap Hit</th>
            <th>Years Remaining</th>
        </tr>
        <?php foreach ($teamRetainedList as $ret):
            $rPlayerName = trim(($ret['first_name'] ?? '') . ' ' . ($ret['last_name'] ?? ''));
            if ($rPlayerName === '') {
                $rPlayerName = 'Player #' . (int)($ret['player_id'] ?? 0);
            }
            $rTeamLabel = !empty($ret['current_team_name'])
                    ? $ret['current_team_name'] . ' (' . strtoupper(trim($ret['current_team_abbr'])) . ')'
                    : 'Free Agent / Inactive';
            $rAmt = (int)($ret['retained_cap_hit'] ?? 0);
            $rPct = isset($ret['retention_percent']) && (float)$ret['retention_percent'] > 0
                    ? ' (' . rtrim(rtrim(number_format((float)$ret['retention_percent'], 1), '0'), '.') . '%)'
                    : '';
            $rYrs = (int)($ret['years_remaining'] ?? 0);
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($rPlayerName) ?></strong></td>
                <td><?= htmlspecialchars($ret['position'] ?? '—') ?></td>
                <td><?= htmlspecialchars($rTeamLabel) ?></td>
                <td><strong style="color:#c084fc;">$<?= number_format($rAmt) ?></strong><?= htmlspecialchars($rPct) ?></td>
                <td><?= $rYrs ?> yr<?= $rYrs === 1 ? '' : 's' ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>
<?php
$nhlById = array();
foreach ($nhlPlayers as $p) {
    $nhlById[(int)$p['player_id']] = $p;
}

$tlStmt = $pdo->prepare("SELECT * FROM team_lines WHERE team_id = ?");
$tlStmt->execute(array($team_id));
$tl = $tlStmt->fetch(PDO::FETCH_ASSOC) ?: array();

$usedIds = array();
$pickSlot = function($candidates) use ($tl, $nhlById, &$usedIds) {
    foreach ((array)$candidates as $col) {
        $pid = (int)($tl[$col] ?? 0);
        if ($pid > 0 && isset($nhlById[$pid])) {
            $usedIds[$pid] = true;
            return $nhlById[$pid];
        }
    }
    return null;
};

$fwdLines = array(
        1 => array(
                'LW' => $pickSlot(array('l1_lw', 'line1_lw', 'f1_lw')),
                'C'  => $pickSlot(array('l1_c',  'line1_c',  'f1_c')),
                'RW' => $pickSlot(array('l1_rw', 'line1_rw', 'f1_rw'))
        ),
        2 => array(
                'LW' => $pickSlot(array('l2_lw', 'line2_lw', 'f2_lw')),
                'C'  => $pickSlot(array('l2_c',  'line2_c',  'f2_c')),
                'RW' => $pickSlot(array('l2_rw', 'line2_rw', 'f2_rw'))
        ),
        3 => array(
                'LW' => $pickSlot(array('l3_lw', 'line3_lw', 'f3_lw')),
                'C'  => $pickSlot(array('l3_c',  'line3_c',  'f3_c')),
                'RW' => $pickSlot(array('l3_rw', 'line3_rw', 'f3_rw'))
        ),
        4 => array(
                'LW' => $pickSlot(array('l4_lw', 'line4_lw', 'f4_lw')),
                'C'  => $pickSlot(array('l4_c',  'line4_c',  'f4_c')),
                'RW' => $pickSlot(array('l4_rw', 'line4_rw', 'f4_rw'))
        ),
);

$defPairs = array(
        1 => array(
                $pickSlot(array('ld1', 'd1_ld', 'pair1_ld', 'd1_left', 'd1_l', 'def1_ld', 'def1_l')),
                $pickSlot(array('rd1', 'd1_rd', 'pair1_rd', 'd1_right', 'd1_r', 'def1_rd', 'def1_r'))
        ),
        2 => array(
                $pickSlot(array('ld2', 'd2_ld', 'pair2_ld', 'd2_left', 'd2_l', 'def2_ld', 'def2_l')),
                $pickSlot(array('rd2', 'd2_rd', 'pair2_rd', 'd2_right', 'd2_r', 'def2_rd', 'def2_r'))
        ),
        3 => array(
                $pickSlot(array('ld3', 'd3_ld', 'pair3_ld', 'd3_left', 'd3_l', 'def3_ld', 'def3_l')),
                $pickSlot(array('rd3', 'd3_rd', 'pair3_rd', 'd3_right', 'd3_r', 'def3_rd', 'def3_r'))
        ),
);

$goalies = array(
        1 => $pickSlot(array('g_starter', 'g1', 'goalie1', 'starter_id')),
        2 => $pickSlot(array('g_backup',  'g2', 'goalie2', 'backup_id')),
);

if (empty($usedIds)) {
    foreach ($nhlPlayers as $p) {
        $pid = (int)$p['player_id'];
        $pos = strtoupper(trim($p['position']));
        $ln  = (int)$p['line_number'];
        if ($ln >= 1 && $ln <= 4 && in_array($pos, array('LW', 'C', 'RW'))) {
            foreach (array($pos, 'LW', 'C', 'RW') as $slot) {
                if ($fwdLines[$ln][$slot] === null) {
                    $fwdLines[$ln][$slot] = $p;
                    $usedIds[$pid] = true;
                    break;
                }
            }
        } elseif ($pos === 'D' && $ln >= 1 && $ln <= 3) {
            if ($defPairs[$ln][0] === null) { $defPairs[$ln][0] = $p; $usedIds[$pid] = true; }
            elseif ($defPairs[$ln][1] === null) { $defPairs[$ln][1] = $p; $usedIds[$pid] = true; }
        } elseif ($pos === 'G' && ($ln === 1 || $ln === 2) && $goalies[$ln] === null) {
            $goalies[$ln] = $p;
            $usedIds[$pid] = true;
        }
    }
}

$scratches = array();
foreach ($nhlPlayers as $p) {
    if (empty($usedIds[(int)$p['player_id']])) {
        $scratches[] = $p;
    }
}

function fmtLinePlayer($p) {
    if (!$p) return '<span style="color:#475569;">—</span>';
    $inj = !empty($p['is_injured']) ? '<span class="inj"> (INJ ' . (int)$p['injury_days_remaining'] . 'd)</span>' : '';
    return '<strong>' . htmlspecialchars($p['first_name'] . ' ' . $p['last_name']) . '</strong>'
            . '<span class="ovr-tag">(' . (int)$p['overall_rating'] . ')</span>' . $inj;
}
?>

<h2><span class="league-tag-nhl">NHL</span><?= htmlspecialchars($nhlName) ?> Lineup</h2>

<table class="lineup-grid">
    <tr><th colspan="4" class="lineup-banner">5 vs 5 Forward</th></tr>
    <tr><th style="width:80px;">Line #</th><th>Left Wing</th><th>Center</th><th>Right Wing</th></tr>
    <?php for ($l = 1; $l <= 4; $l++): ?>
        <tr>
            <td><strong><?= $l ?></strong></td>
            <td><?= fmtLinePlayer($fwdLines[$l]['LW']) ?></td>
            <td><?= fmtLinePlayer($fwdLines[$l]['C']) ?></td>
            <td><?= fmtLinePlayer($fwdLines[$l]['RW']) ?></td>
        </tr>
    <?php endfor; ?>
</table>

<table class="lineup-grid">
    <tr><th colspan="4" class="lineup-banner">5 vs 5 Defense</th></tr>
    <tr><th style="width:80px;">Pair #</th><th>Defense</th><th>Defense</th><th style="width:220px;">Goalies</th></tr>
    <?php for ($d = 1; $d <= 3; $d++): ?>
        <tr>
            <td><strong><?= $d ?></strong></td>
            <td><?= fmtLinePlayer($defPairs[$d][0] ?? null) ?></td>
            <td><?= fmtLinePlayer($defPairs[$d][1] ?? null) ?></td>
            <td>
                <?php
                if ($d === 1) echo '<small style="color:#38bdf8;">Starter:</small> ' . fmtLinePlayer($goalies[1] ?? null);
                elseif ($d === 2) echo '<small style="color:#94a3b8;">Backup:</small> ' . fmtLinePlayer($goalies[2] ?? null);
                else echo '<span style="color:#475569;">—</span>';
                ?>
            </td>
        </tr>
    <?php endfor; ?>
</table>

<?php if (!empty($scratches)): ?>
    <p style="margin-top:-12px;margin-bottom:24px;color:#cbd5e1;font-size:0.92em;">
        <strong>Healthy Scratches / Extra Skaters:</strong>
        <?php
        $scratchLabels = array();
        foreach ($scratches as $sc) {
            $scratchLabels[] = htmlspecialchars($sc['first_name'] . ' ' . $sc['last_name']) . ' (' . htmlspecialchars($sc['position']) . ' ' . (int)$sc['overall_rating'] . ')';
        }
        echo implode(' &bull; ', $scratchLabels);
        ?>
    </p>
<?php endif; ?>


<details style="margin-bottom:24px;" <?= $isTeamGM ? 'open' : '' ?>>
    <summary style="cursor:pointer;color:#38bdf8;font-weight:bold;padding:8px 0;">View Full NHL Ratings &amp; Contracts Table (<?= count($nhlPlayers) ?> Players)</summary>
    <?php renderRosterTable($nhlPlayers, true, $isTeamGM, $ahlName, $playerRetentionsMap); ?>
</details>

<h2><span class="league-tag-ahl">AHL</span><?= htmlspecialchars($ahlName) ?> Roster (<?= count($ahlPlayers) ?>)</h2>
<?php renderRosterTable($ahlPlayers, false, $isTeamGM, $ahlName, $playerRetentionsMap); ?>
<?php

$rightsStmt = $pdo->prepare("
    SELECT *
    FROM players
    WHERE team_id = ?
      AND UPPER(COALESCE(roster_status, '')) IN ('RIGHTS', 'EURO', 'LTIR', 'INJURED')
      AND COALESCE(is_retired, FALSE) = FALSE
    ORDER BY is_eligible_activate DESC, overall_rating DESC, last_name ASC
");
$rightsStmt->execute(array($team_id));
$rightsPlayers = $rightsStmt->fetchAll(PDO::FETCH_ASSOC);


$prospStmt = $pdo->prepare("
    SELECT *
    FROM prospects
    WHERE team_id = ?
      AND UPPER(COALESCE(list_category, status, 'PROSPECT')) NOT LIKE '%RIGHTS%'
    ORDER BY
        COALESCE(draft_year, 9999) ASC,
        COALESCE(NULLIF(overall_pick, 0), NULLIF(REGEXP_REPLACE(COALESCE(notes, ''), '[^0-9]', '', 'g'), '')::INT, 999) ASC,
        last_name ASC
");
$prospStmt->execute(array($team_id));
$teamProspects = $prospStmt->fetchAll(PDO::FETCH_ASSOC);
$isCommishUser = (!empty($_SESSION['is_commish']) || !empty($_SESSION['is_admin']));
?>

<h2 style="margin-top:28px; color:#38bdf8;">
    Rights Owned &amp; Inactive Reserve (<?= count($rightsPlayers) ?>)
</h2>

<?php if (empty($rightsPlayers)): ?>
    <div style="background:#1e293b; padding:14px 16px; border-radius:8px; border:1px solid #334155; color:#94a3b8;">
        No inactive Rights Owned, Euro, or LTIR players currently held by this team.
    </div>
<?php else: ?>
    <div style="overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse; background:#1e293b; border-radius:8px; overflow:hidden;">
            <tr style="background:#020617; color:#38bdf8;">
                <th style="padding:10px; text-align:left;">Player</th>
                <th style="padding:10px; text-align:left;">Pos</th>
                <th style="padding:10px; text-align:left;">Age</th>
                <th style="padding:10px; text-align:left;">OVR</th>
                <th style="padding:10px; text-align:left;">Saved Contract</th>
                <th style="padding:10px; text-align:left;">Status / 1-Yr Release Timer</th>
                <?php if ($isTeamGM || $isCommishUser): ?>
                    <th style="padding:10px; text-align:left;">Actions</th>
                <?php endif; ?>
            </tr>
            <?php foreach ($rightsPlayers as $rp):
                $isElig    = !empty($rp['is_eligible_activate']);
                $reason    = trim($rp['rights_reason'] ?? strtoupper($rp['roster_status'] ?? 'Rights Owned'));
                $addedTs   = !empty($rp['rights_added_at']) ? strtotime($rp['rights_added_at']) : time();
                $daysHeld  = (int)floor((time() - $addedTs) / 86400);
                $canRel    = ($daysHeld >= 365 || $isCommishUser);
                $daysLeft  = max(0, 365 - $daysHeld);
                ?>
                <tr style="border-bottom:1px solid #334155;">
                    <td style="padding:9px 10px;"><strong><?= htmlspecialchars($rp['first_name'] . ' ' . $rp['last_name']) ?></strong></td>
                    <td style="padding:9px 10px;"><?= htmlspecialchars($rp['position']) ?></td>
                    <td style="padding:9px 10px;"><?= (int)($rp['age'] ?? 0) ?: '—' ?></td>
                    <td style="padding:9px 10px;"><strong style="color:#38bdf8;"><?= (int)$rp['overall_rating'] ?></strong></td>
                    <td style="padding:9px 10px; color:#cbd5e1;">
                        $<?= number_format((int)$rp['salary']) ?> (<?= (int)$rp['contract_years'] ?>y)
                    </td>
                    <td style="padding:9px 10px;">
                        <?php if ($isElig): ?>
                            <span style="background:#065f46; color:#6ee7b7; border:1px solid #10b981; padding:2px 7px; border-radius:4px; font-size:0.78em; font-weight:bold;">
                            Eligible (<?= htmlspecialchars($reason) ?>)
                        </span>
                        <?php else: ?>
                            <span style="background:#7f1d1d; color:#fca5a5; border:1px solid #ef4444; padding:2px 7px; border-radius:4px; font-size:0.78em; font-weight:bold;">
                            Ineligible &bull; Did Not Play Prior Season
                        </span>
                        <?php endif; ?>
                        <div style="font-size:0.78em; color:#94a3b8; margin-top:3px;">
                            <?= $daysHeld >= 365 ? '1-Yr Hold Complete (Release Unlocked)' : "Release unlocks in {$daysLeft} day(s)" ?>
                        </div>
                    </td>
                    <?php if ($isTeamGM || $isCommishUser): ?>
                        <td style="padding:9px 10px;">
                            <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                <?php if ($isElig || $isCommishUser): ?>
                                    <form method="post" style="display:flex; gap:5px; margin:0;">
                                        <input type="hidden" name="gm_roster_action" value="activate_rights_player">
                                        <input type="hidden" name="player_id" value="<?= (int)$rp['player_id'] ?>">
                                        <button type="submit" name="dest_roster" value="PRO"
                                                style="background:#0284c7; color:#fff; border:none; padding:5px 8px; border-radius:4px; font-weight:bold; cursor:pointer; font-size:0.78em;"
                                                onclick="return confirm('Activate <?= htmlspecialchars($rp['first_name'] . ' ' . $rp['last_name'], ENT_QUOTES) ?> to the NHL roster?');">
                                            To NHL
                                        </button>
                                        <button type="submit" name="dest_roster" value="AHL"
                                                style="background:#334155; color:#fff; border:none; padding:5px 8px; border-radius:4px; font-weight:bold; cursor:pointer; font-size:0.78em;"
                                                onclick="return confirm('Activate <?= htmlspecialchars($rp['first_name'] . ' ' . $rp['last_name'], ENT_QUOTES) ?> to the AHL roster?');">
                                            To AHL
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <small style="color:#94a3b8;">Locked until eligible</small>
                                <?php endif; ?>

                                <?php if ($canRel): ?>
                                    <form method="post" style="margin:0;">
                                        <input type="hidden" name="gm_roster_action" value="release_rights_player">
                                        <input type="hidden" name="player_id" value="<?= (int)$rp['player_id'] ?>">
                                        <button type="submit"
                                                style="background:#dc2626; color:#fff; border:none; padding:5px 8px; border-radius:4px; font-weight:bold; cursor:pointer; font-size:0.78em;"
                                                onclick="return confirm('Release <?= htmlspecialchars($rp['first_name'] . ' ' . $rp['last_name'], ENT_QUOTES) ?> to Free Agency?');">
                                            Release
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
<?php endif; ?>

<h2 style="margin-top:28px; color:#38bdf8;">
    Prospects List (<?= count($teamProspects) ?>)
</h2>

<?php if (empty($teamProspects)): ?>
    <div style="background:#1e293b; padding:14px 16px; border-radius:8px; border:1px solid #334155; color:#94a3b8;">
        No drafted prospects currently listed for this team.
    </div>
<?php else: ?>
<div style="overflow-x:auto;">
    <table style="width:100%; border-collapse:collapse; background:#1e293b; border-radius:8px; overflow:hidden;">
        <tr style="background:#020617; color:#38bdf8;">
            <th style="padding:10px; text-align:left;">Player</th>
            <th style="padding:10px; text-align:left;">Pos</th>
            <th style="padding:10px; text-align:left;">Age</th>
            <th style="padding:10px; text-align:left;">Draft Year</th>
            <th style="padding:10px; text-align:left;">Pick #</th>
            <th style="padding:10px; text-align:left;">Fixed Term &amp; ELC Range</th>
            <?php if ($isTeamGM || $isCommishUser): ?>
                <th style="padding:10px; text-align:left;">Negotiate Salary &amp; Sign</th>
            <?php endif; ?>
        </tr>
        <?php foreach ($teamProspects as $pr):
        $pickNum   = !empty($pr['overall_pick']) ? (int)$pr['overall_pick'] : (int)($pr['notes'] ?? 0);
        $draftYear = (int)($pr['draft_year'] ?? 2025);
        $rawAge    = (int)($pr['age'] ?? 0);
        $pAge      = ($rawAge > 18) ? $rawAge : (($draftYear > 0 && $draftYear < 2026) ? (2026 - $draftYear + 18) : 18);
        $elcYrs    = ($pAge <= 21) ? 3 : (($pAge <= 23) ? 2 : 1);
        $elcMin    = 775000;
        $elcMax    = ($draftYear >= 2024) ? 975000 : 950000;
        ?>
        <tr style="border-bottom:1px solid #334155;">
            <td style="padding:9px 10px;"><strong><?= htmlspecialchars(trim($pr['first_name'] . ' ' . $pr['last_name'])) ?></strong></td>
            <td style="padding:9px 10px;"><?= htmlspecialchars($pr['position'] ?? '—') ?></td>
            <td style="padding:9px 10px;"><?= $pAge ?></td>
            <td style="padding:9px 10px;"><?= $draftYear > 0 ? $draftYear : '—' ?></td>
            <td style="padding:9px 10px; color:#38bdf8; font-weight:bold;"><?= $pickNum > 0 ? '#' . $pickNum : '—' ?></td>
            <td style="padding:9px 10px; font-size:0.88em;">
                <strong style="color:#4ade80;"><?= $elcYrs ?> yr<?= $elcYrs > 1 ? 's' : '' ?> (Fixed)</strong>
                <div style="color:#94a3b8; font-size:0.85em;">$775k &ndash; $<?= number_format($elcMax / 1000) ?>k</div>
            </td>
            <?php if ($isTeamGM || $isCommishUser): ?>
                <td style="padding:9px 10px;">
                    <div style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
                        <form method="post" style="display:flex; gap:5px; align-items:center; margin:0;">
                            <input type="hidden" name="gm_roster_action" value="sign_prospect_elc">
                            <input type="hidden" name="prospect_id" value="<?= (int)$pr['prospect_id'] ?>">
                            <input type="number" name="elc_salary" value="<?= $elcMax ?>" min="<?= $elcMin ?>" max="<?= $elcMax ?>" step="5000"
                                   title="ELC Salary ($<?= number_format($elcMin) ?> - $<?= number_format($elcMax) ?>)"
                                   style="width:105px; padding:4px 7px; border-radius:4px; border:1px solid #475569; background:#0f172a; color:#fff; font-size:0.82em;" required>
                            <button type="submit" name="dest_roster" value="AHL"
                                    style="background:#16a34a; color:#fff; border:none; padding:5px 8px; border-radius:4px; font-weight:bold; cursor:pointer; font-size:0.78em;">
                                Sign AHL
                            </button>
                            <button type="submit" name="dest_roster" value="PRO"
                                    style="background:#0284c7; color:#fff; border:none; padding:5px 8px; border-radius:4px; font-weight:bold; cursor:pointer; font-size:0.78em;">
                                Sign NHL
                            </button>
                        </form>

                        <form method="post" style="margin:0;">
                            <input type="hidden" name="gm_roster_action" value="release_prospect">
                            <input type="hidden" name="prospect_id" value="<?= (int)$pr['prospect_id'] ?>">
                            <button type="submit"
                                    style="background:#dc2626; color:#fff; border:none; padding:5px 8px; border-radius:4px; font-weight:bold; cursor:pointer; font-size:0.78em;"
                                    onclick="return confirm('Relinquish draft rights to <?= htmlspecialchars($pr['first_name'] . ' ' . $pr['last_name'], ENT_QUOTES) ?>?');">
                                Release
                            </button>
                        </form>
                    </div>
                </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php endif; ?>


</body>
</html>













