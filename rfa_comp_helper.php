<?php


function getRfaCompensation(int $annualSalary, int $years): array {
    $years    = max(1, min(7, $years));
    $totalVal = $annualSalary * $years;
    $compDiv  = min($years, 5);
    $compAav  = (int)round($totalVal / $compDiv);

    if ($compAav <= 1544424) {
        return array('aav' => $compAav, 'rounds' => array(), 'label' => 'None');
    } elseif ($compAav <= 2340037) {
        return array('aav' => $compAav, 'rounds' => array(3), 'label' => '3rd Round Pick');
    } elseif ($compAav <= 4680076) {
        return array('aav' => $compAav, 'rounds' => array(2), 'label' => '2nd Round Pick');
    } elseif ($compAav <= 7020113) {
        return array('aav' => $compAav, 'rounds' => array(1, 3), 'label' => '1st + 3rd Round Picks');
    } elseif ($compAav <= 9360152) {
        return array('aav' => $compAav, 'rounds' => array(1, 2, 3), 'label' => '1st + 2nd + 3rd Round Picks');
    } elseif ($compAav <= 11700192) {
        return array('aav' => $compAav, 'rounds' => array(1, 1, 2, 3), 'label' => 'Two 1sts + 2nd + 3rd Round Picks');
    } else {
        return array('aav' => $compAav, 'rounds' => array(1, 1, 1, 1), 'label' => 'Four 1st Round Picks');
    }
}

function verifyTeamOwnPicksForRfa(PDO $pdo, int $offeringTeamId, array $requiredRounds): array {
    if (empty($requiredRounds)) {
        return array('ok' => true, 'missing' => '', 'pick_ids' => array());
    }

    $neededByRound   = array_count_values($requiredRounds);
    $selectedPickIds = array();

    $baseYear = (int)$pdo->query("SELECT COALESCE(MIN(draft_year), 2027) FROM draft_picks WHERE COALESCE(is_used, FALSE) = FALSE")->fetchColumn();

    foreach ($neededByRound as $round => $qtyNeeded) {
        $stmt = $pdo->prepare("
            SELECT pick_id, draft_year
            FROM draft_picks
            WHERE original_team_id = ?
              AND current_team_id  = ?
              AND round_num        = ?
              AND draft_year      >= ?
              AND COALESCE(is_used, FALSE) = FALSE
            ORDER BY draft_year ASC
            LIMIT ?
        ");
        $stmt->execute(array($offeringTeamId, $offeringTeamId, $round, $baseYear, $qtyNeeded));
        $found = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($found) < $qtyNeeded) {
            return array(
                'ok'       => false,
                'missing'  => "Missing required own Round {$round} draft pick(s) (must be your own original pick, not acquired via trade).",
                'pick_ids' => array()
            );
        }

        for ($i = 0; $i < $qtyNeeded; $i++) {
            $expectedYear = $baseYear + $i;
            if ((int)$found[$i]['draft_year'] !== $expectedYear) {
                return array(
                    'ok'       => false,
                    'missing'  => "You do not own your own {$expectedYear} Round {$round} pick required for this RFA offer sheet.",
                    'pick_ids' => array()
                );
            }
            $selectedPickIds[] = (int)$found[$i]['pick_id'];
        }
    }

    return array('ok' => true, 'missing' => '', 'pick_ids' => $selectedPickIds);
}

function transferRfaCompensationPicks(PDO $pdo, int $offeringTeamId, int $rightsTeamId, int $annualSalary, int $years): array {
    $comp  = getRfaCompensation($annualSalary, $years);
    $check = verifyTeamOwnPicksForRfa($pdo, $offeringTeamId, $comp['rounds']);

    if (!$check['ok']) {
        return $check;
    }

    if (!empty($check['pick_ids'])) {
        $upd = $pdo->prepare("UPDATE draft_picks SET current_team_id = ? WHERE pick_id = ? AND current_team_id = ?");
        foreach ($check['pick_ids'] as $pkid) {
            $upd->execute(array($rightsTeamId, $pkid, $offeringTeamId));
        }
    }

    return array('ok' => true, 'label' => $comp['label'], 'pick_ids' => $check['pick_ids']);
}



