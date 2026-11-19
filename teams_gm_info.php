<?php
require 'db.php';

$isAdmin = !empty($_SESSION['is_commish']) || !empty($_SESSION['is_admin']);
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin && !empty($_POST['action'])) {
    $tid = (int)($_POST['team_id'] ?? 0);

    if ($tid > 0 && $_POST['action'] === 'update') {
        $gmName  = trim($_POST['gm_name'] ?? '');
        $gmEmail = trim($_POST['gm_email'] ?? '');

        $pdo->prepare("UPDATE teams SET gm_name = ?, gm_email = ? WHERE team_id = ?")
            ->execute(array($gmName !== '' ? $gmName : null, $gmEmail !== '' ? $gmEmail : null, $tid));
        $msg = "GM details updated successfully.";
    } elseif ($tid > 0 && $_POST['action'] === 'remove') {
        $pdo->prepare("UPDATE teams SET gm_name = NULL, gm_email = NULL WHERE team_id = ?")
            ->execute(array($tid));
        $msg = "GM removed from team.";
    }
}

$nhlArenas = array(
    'ANA' => 'Honda Center',                  'BOS' => 'TD Garden',
    'BUF' => 'KeyBank Center',                'CGY' => 'Scotiabank Saddledome',
    'CAR' => 'Lenovo Center',                 'CHI' => 'United Center',
    'COL' => 'Ball Arena',                    'CBJ' => 'Nationwide Arena',
    'DAL' => 'American Airlines Center',      'DET' => 'Little Caesars Arena',
    'EDM' => 'Rogers Place',                  'FLA' => 'Amerant Bank Arena',
    'LAK' => 'Crypto.com Arena',              'MIN' => 'Grand Casino Arena',
    'MTL' => 'Bell Centre',                   'NSH' => 'Bridgestone Arena',
    'NJD' => 'Prudential Center',             'NYI' => 'UBS Arena',
    'NYR' => 'Madison Square Garden',         'OTT' => 'Canadian Tire Centre',
    'PHI' => 'Xfinity Mobile Arena',          'PIT' => 'PPG Paints Arena',
    'SJS' => 'SAP Center',                    'SEA' => 'Climate Pledge Arena',
    'STL' => 'Enterprise Center',             'TBL' => 'Benchmark International Arena',
    'TOR' => 'Scotiabank Arena',              'UTA' => 'Delta Center',
    'VAN' => 'Rogers Arena',                  'VGK' => 'T-Mobile Arena',
    'WSH' => 'Capital One Arena',             'WPG' => 'Canada Life Centre'
);

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

$teams = $pdo->query("
    SELECT DISTINCT ON (t.team_id)
           t.team_id, t.name, t.abbreviation, t.gm_name, t.gm_email,
           u.username AS user_gm, u.email AS user_email
    FROM teams t
    LEFT JOIN users u ON u.team_id = t.team_id
    ORDER BY t.team_id ASC, u.user_id ASC
")->fetchAll(PDO::FETCH_ASSOC);
usort($teams, function($a, $b) {
    return strcmp($a['name'], $b['name']);
});

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Teams &amp; GM Directory - HFNHL</title>
    <style>
        body { font-family: Arial, sans-serif; background: #0f172a; color: #f8fafc; padding: 20px; max-width: 1200px; margin: auto; }
        h1 { color: #38bdf8; }
        table { width: 100%; border-collapse: collapse; background: #1e293b; border-radius: 8px; overflow: hidden; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #334155; font-size: 0.92em; }
        th { background: #020617; color: #38bdf8; }
        a { color: #38bdf8; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .team-cell { display: flex; align-items: center; gap: 10px; font-weight: bold; }
        .team-cell img { width: 28px; height: 28px; object-fit: contain; }
        .vacant { color: #f87171; font-style: italic; font-weight: bold; }
        .input-dark { padding: 5px 8px; border-radius: 4px; border: 1px solid #475569; background: #0f172a; color: #fff; font-size: 0.88em; width: 155px; }
        .btn { border: none; padding: 6px 10px; border-radius: 4px; font-weight: bold; cursor: pointer; font-size: 0.8em; color: #fff; }
        .btn-save { background: #0284c7; }
        .btn-save:hover { background: #0369a1; }
        .btn-remove { background: #dc2626; }
        .btn-remove:hover { background: #b91c1c; }
        .alert { background: #0c4a6e; border: 1px solid #38bdf8; color: #e0f2fe; padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; font-weight: bold; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<h1>Teams &amp; General Manager Directory</h1>

<?php if ($msg): ?>
    <div class="alert"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<table>
    <tr>
        <th>Team</th>
        <th>Arena</th>
        <th>AHL Affiliate</th>
        <th>General Manager</th>
        <th>Email</th>
        <?php if ($isAdmin): ?>
            <th>Actions</th>
        <?php endif; ?>
    </tr>
    <?php foreach ($teams as $t):
        $abbr    = strtoupper(trim($t['abbreviation'] ?? ''));
        $arena   = $nhlArenas[$abbr] ?? '—';
        $ahl     = $ahlAffiliates[$abbr] ?? '—';
        $gmName  = trim($t['gm_name'] ?? '') ?: trim($t['user_gm'] ?? '');
        $gmEmail = trim($t['gm_email'] ?? '') ?: trim($t['user_email'] ?? '');
        $formId  = 'gm_form_' . (int)$t['team_id'];
        ?>
        <tr>
            <td>
                <div class="team-cell">
                    <img src="logos/<?= htmlspecialchars($abbr) ?>.svg" alt="<?= htmlspecialchars($abbr) ?>">
                    <a href="team.php?id=<?= (int)$t['team_id'] ?>"><?= htmlspecialchars($t['name']) ?></a>
                </div>
            </td>
            <td style="color:#cbd5e1;"><?= htmlspecialchars($arena) ?></td>
            <td style="color:#94a3b8;"><?= htmlspecialchars($ahl) ?></td>

            <?php if ($isAdmin): ?>
                <td>
                    <form id="<?= $formId ?>" method="POST" style="margin:0;">
                        <input type="hidden" name="team_id" value="<?= (int)$t['team_id'] ?>">
                    </form>
                    <input type="text" form="<?= $formId ?>" name="gm_name" class="input-dark"
                           placeholder="Vacant" value="<?= htmlspecialchars($gmName) ?>">
                </td>
                <td>
                    <input type="email" form="<?= $formId ?>" name="gm_email" class="input-dark"
                           placeholder="No email" value="<?= htmlspecialchars($gmEmail) ?>">
                </td>
                <td style="white-space:nowrap;">
                    <button type="submit" form="<?= $formId ?>" name="action" value="update" class="btn btn-save">Update</button>
                    <?php if ($gmName !== '' || $gmEmail !== ''): ?>
                        <button type="submit" form="<?= $formId ?>" name="action" value="remove" class="btn btn-remove"
                                onclick="return confirm('Remove GM from <?= htmlspecialchars($t['name'], ENT_QUOTES) ?>?');">
                            Remove
                        </button>
                    <?php endif; ?>
                </td>
            <?php else: ?>
                <td>
                    <?= $gmName !== '' ? '<strong>' . htmlspecialchars($gmName) . '</strong>' : '<span class="vacant">Vacant</span>' ?>
                </td>
                <td>
                    <?php if ($gmEmail !== ''): ?>
                        <a href="mailto:<?= htmlspecialchars($gmEmail) ?>"><?= htmlspecialchars($gmEmail) ?></a>
                    <?php else: ?>
                        <span style="color:#64748b;">—</span>
                    <?php endif; ?>
                </td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
</table>

</body>
</html>

