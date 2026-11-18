<?php
require 'db.php';
/** @var PDO $pdo */

if (empty($_SESSION['can_manage_draft']) && empty($_SESSION['is_commish']) && empty($_SESSION['is_admin'])) {
    die("Access denied. Draft Uploader or Commissioner permissions required.");
}

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['draft_csv'])) {
    $draftYear = (int)($_POST['draft_year'] ?? 0);
    $fileTmp   = $_FILES['draft_csv']['tmp_name'] ?? '';

    if ($draftYear < 2026 || $draftYear > 2050) {
        $err = "Please enter a valid Draft Year (e.g., 2027).";
    } elseif (!is_uploaded_file($fileTmp)) {
        $err = "Please select a valid CSV file to upload.";
    } else {
        $teamMap = array();
        foreach ($pdo->query("SELECT team_id, UPPER(abbreviation) AS abbr FROM teams") as $t) {
            $teamMap[$t['abbr']] = (int)$t['team_id'];
        }

        $handle   = fopen($fileTmp, 'r');
        $header   = fgetcsv($handle);
        $imported = 0;

        $pdo->beginTransaction();
        $insProspect = $pdo->prepare("
            INSERT INTO prospects (team_id, full_name, draft_year, notes)
            VALUES (:tid, :name, :yr, :notes)
        ");

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 2) continue;
            $abbr  = strtoupper(trim($row[0]));
            $name  = trim($row[1]);
            $notes = isset($row[2]) ? trim($row[2]) : '';

            if (!isset($teamMap[$abbr]) || $name === '') continue;

            $insProspect->execute(array(
                'tid'   => $teamMap[$abbr],
                'name'  => $name,
                'yr'    => $draftYear,
                'notes' => $notes
            ));
            $imported++;
        }
        fclose($handle);

        $pdo->prepare("UPDATE draft_picks SET is_used = TRUE WHERE draft_year = ?")->execute(array($draftYear));

        $nextYear = $draftYear + 5;
        $genPicks = $pdo->prepare("
            INSERT INTO draft_picks (draft_year, round_num, original_team_id, current_team_id, is_used)
            SELECT :nyr, r.rd, t.team_id, t.team_id, FALSE
            FROM teams t
            CROSS JOIN (VALUES (1), (2), (3), (4), (5), (6), (7)) AS r(rd)
            ON CONFLICT (draft_year, round_num, original_team_id) DO NOTHING
        ");
        $genPicks->execute(array('nyr' => $nextYear));

        $pdo->commit();
        $msg = "Imported {$imported} prospects into the {$draftYear} Draft Pool! Closed {$draftYear} picks and generated Rounds 1-7 for {$nextYear}.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Draft CSV Importer - HFNHL</title>
    <style>
        body { font-family: Arial, sans-serif; background: #0f172a; color: #f8fafc; padding: 20px; max-width: 850px; margin: auto; }
        nav { background: #020617; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; display: flex; gap: 20px; border: 1px solid #334155; align-items: center; }
        nav a { color: #38bdf8; text-decoration: none; font-weight: bold; }
        .card { background: #1e293b; padding: 24px; border-radius: 8px; border: 1px solid #334155; }
        h1 { color: #38bdf8; margin-top: 0; }
        label { display: block; font-weight: bold; margin-top: 14px; margin-bottom: 6px; }
        input[type=number], input[type=file] { padding: 10px; border-radius: 6px; border: 1px solid #475569; background: #0f172a; color: #fff; width: 100%; box-sizing: border-box; }
        .btn { background: #0284c7; color: #fff; border: none; padding: 12px 24px; border-radius: 6px; font-weight: bold; cursor: pointer; margin-top: 18px; font-size: 1em; }
        .btn:hover { background: #0369a1; }
        .alert { background: #14532d; color: #86efac; padding: 12px 16px; border-radius: 6px; border: 1px solid #22c55e; margin-bottom: 16px; font-weight: bold; }
        .err { background: #7f1d1d; color: #fecaca; padding: 12px 16px; border-radius: 6px; border: 1px solid #ef4444; margin-bottom: 16px; font-weight: bold; }
        code { background: #020617; padding: 2px 6px; border-radius: 4px; color: #38bdf8; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>

<div class="card">
    <h1>Post-Draft Prospect CSV Importer</h1>
    <p style="color:#94a3b8;">Upload the completed draft CSV to populate each team's <strong>Drafted Prospect Pool</strong>, close out that draft year's picks, and automatically generate Rounds 1–7 five years ahead.</p>

    <?php if ($msg): ?><div class="alert"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="err"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <label for="draft_year">Completed Draft Year</label>
        <input type="number" id="draft_year" name="draft_year" value="2027" min="2026" max="2050" required>

        <label for="draft_csv">Draft Results CSV File</label>
        <input type="file" id="draft_csv" name="draft_csv" accept=".csv" required>
        <p style="color:#94a3b8;font-size:0.88em;margin-top:6px;">
            Required CSV column order (with header row):<br>
            <code>TeamAbbr, FullName, PickNumberOrNotes</code><br>
            Example row: <code>EDM, Gavin McKenna, 1</code>
        </p>

        <button type="submit" class="btn">Import Draft &amp; Generate +5 Year Picks</button>
    </form>
</div>
</body>
</html>



