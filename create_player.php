<?php
require 'db.php';
/** @var PDO $pdo */

if (empty($_SESSION['is_commish']) && empty($_SESSION['is_admin'])) {
    die("Commissioner access only.");
}

$msg     = '';
$msgType = 'info';

$teams = $pdo->query("SELECT team_id, name, abbreviation FROM teams ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['create_player_submit'])) {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName  = trim($_POST['last_name'] ?? '');
    $pos       = strtoupper(trim($_POST['position'] ?? 'C'));
    $age       = max(17, min(45, (int)($_POST['age'] ?? 19)));
    $nhlGp     = max(0, (int)($_POST['nhl_games_played'] ?? 0));
    $destType  = $_POST['dest_type'] ?? 'PRO'; // PRO, AHL, EURO, FA, PROSPECT
    $teamId    = !empty($_POST['team_id']) ? (int)$_POST['team_id'] : null;
    $years     = max(0, min(8, (int)($_POST['contract_years'] ?? 3)));
    $salary    = max(0, (int)preg_replace('/[^0-9]/', '', (string)($_POST['salary'] ?? '950000')));

    $ovr = max(50, min(99, (int)($_POST['overall_rating'] ?? 68)));
    $sk  = max(50, min(99, (int)($_POST['skating'] ?? 68)));
    $sh  = max(50, min(99, (int)($_POST['shooting'] ?? 68)));
    $pa  = max(50, min(99, (int)($_POST['passing'] ?? 68)));
    $df  = max(50, min(99, (int)($_POST['defense'] ?? 68)));
    $ph  = max(50, min(99, (int)($_POST['physical'] ?? 68)));
    $go  = max(50, min(99, (int)($_POST['goaltending'] ?? ($pos === 'G' ? $ovr : 50))));

    $draftYear = max(0, (int)($_POST['draft_year'] ?? 2026));
    $notes     = trim($_POST['notes'] ?? 'Created by Commissioner');

    if ($firstName === '' || $lastName === '') {
        $msgType = 'error';
        $msg     = 'First Name and Last Name are required.';
    } elseif ($destType !== 'FA' && !$teamId) {
        $msgType = 'error';
        $msg     = 'Please select an NHL team unless placing the player in Free Agency (FA).';
    } else {
        $chk1 = $pdo->prepare("SELECT COUNT(*) FROM players WHERE LOWER(TRIM(first_name)) = LOWER(TRIM(?)) AND LOWER(TRIM(last_name)) = LOWER(TRIM(?))");
        $chk1->execute([$firstName, $lastName]);
        $chk2 = $pdo->prepare("SELECT COUNT(*) FROM prospects WHERE LOWER(TRIM(first_name)) = LOWER(TRIM(?)) AND LOWER(TRIM(last_name)) = LOWER(TRIM(?))");
        $chk2->execute([$firstName, $lastName]);

        if ((int)$chk1->fetchColumn() > 0 || (int)$chk2->fetchColumn() > 0) {
            $msgType = 'error';
            $msg     = "A player named {$firstName} {$lastName} already exists in your database.";
        } elseif ($destType === 'PROSPECT') {
            $fullName  = trim("{$firstName} {$lastName}");
            $fullNotes = trim("{$pos} | Age {$age}" . ($notes ? " - {$notes}" : ''));
            $insPr = $pdo->prepare("
                INSERT INTO prospects (team_id, full_name, draft_year, notes)
                VALUES (?, ?, ?, ?)
            ");
            $insPr->execute([$teamId, $fullName, $draftYear ?: 2027, $fullNotes]);
            $msgType = 'info';
            $msg     = "Created prospect {$fullName} ({$pos}, Age {$age}) and added to team's Prospects list!";
        } else {
            $assignTeam = ($destType === 'FA') ? null : $teamId;
            $status     = ($destType === 'FA') ? 'FA' : $destType;
            if ($status === 'FA') {
                $years  = 0;
                $salary = 0;
            }
            if ($pos === 'G') {
                $sk = $sh = $pa = $df = $ph = 50;
            } else {
                $go = 50;
            }

            $insPl = $pdo->prepare("
                INSERT INTO players (
                    team_id, first_name, last_name, position, age, nhl_games_played,
                    roster_status, contract_years, salary,
                    overall_rating, skating, shooting, passing, defense, physical, goaltending,
                    line_number, condition, is_injured, injury_days_remaining, is_retired
                ) VALUES (
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?,
                    99, 100, FALSE, 0, FALSE
                )
            ");
            $insPl->execute([
                $assignTeam, $firstName, $lastName, $pos, $age, $nhlGp,
                $status, $years, $salary,
                $ovr, $sk, $sh, $pa, $df, $ph, $go
            ]);
            $msgType = 'info';
            $msg     = "Created {$firstName} {$lastName} ({$pos}, {$ovr} OVR, Age {$age}) in {$status}!";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Commissioner Player Generator - HFNHL</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #0f172a; color: #f8fafc; padding: 16px 24px 40px 24px; max-width: 960px; margin: auto; }
        h1 { color: #38bdf8; margin-bottom: 4px; }
        .sub { color: #94a3b8; margin-bottom: 20px; font-size: 0.92em; }
        .card { background: #1e293b; border: 1px solid #334155; border-top: 4px solid #0284c7; border-bottom: 3px solid #dc2626; border-radius: 10px; padding: 22px; }
        .preset-bar { background: #0f172a; border: 1px solid #334155; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; display: flex; gap: 12px; align-items: end; flex-wrap: wrap; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
        .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-bottom: 16px; }
        .grid-7 { display: grid; grid-template-columns: repeat(7, 1fr); gap: 10px; margin-bottom: 20px; }
        @media (max-width: 780px) { .grid-2, .grid-3, .grid-7 { grid-template-columns: 1fr 1fr; } }
        label { display: block; color: #cbd5e1; font-weight: bold; font-size: 0.84em; margin-bottom: 5px; }
        input, select { width: 100%; background: #0f172a; color: #f8fafc; border: 1px solid #475569; padding: 8px 10px; border-radius: 6px; font-size: 0.92em; box-sizing: border-box; }
        .btn { background: #0284c7; color: #fff; border: none; padding: 10px 20px; border-radius: 6px; font-weight: bold; cursor: pointer; font-size: 0.95em; }
        .btn:hover { background: #0369a1; }
        .btn.slate { background: #334155; }
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-weight: bold; }
        .alert.info { background: rgba(2, 132, 199, 0.2); border: 1px solid #38bdf8; color: #7dd3fc; }
        .alert.error { background: rgba(220, 38, 38, 0.2); border: 1px solid #ef4444; color: #fca5a5; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>

<h1>Commissioner Player &amp; Rookie Generator</h1>
<div class="sub">Generate incoming offseason rookies, European signees, or custom players who haven't played NHL/AHL games yet.</div>

<?php if ($msg): ?>
    <div class="alert <?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="card">
    <div class="preset-bar">
        <div style="flex:1;min-width:180px;">
            <label for="archetype">Quick Archetype Preset:</label>
            <select id="archetype">
                <option value="sniper">Top-6 Sniper (LW/RW)</option>
                <option value="playmaker">Playmaking Center (C)</option>
                <option value="twoway">Two-Way Forward (C/LW/RW)</option>
                <option value="power">Power Forward (LW/RW)</option>
                <option value="off_d">Offensive Defenseman (D)</option>
                <option value="shut_d">Shutdown Defenseman (D)</option>
                <option value="goalie">Goalie (G)</option>
            </select>
        </div>
        <div style="flex:1;min-width:160px;">
            <label for="tier">Skill Tier:</label>
            <select id="tier">
                <option value="68">Rookie / AHL Prospect (68 OVR)</option>
                <option value="75">Bottom-6 / Depth NHL (75 OVR)</option>
                <option value="81">Top-6 / Top-4 NHL (81 OVR)</option>
                <option value="87">Elite / Star NHL (87 OVR)</option>
            </select>
        </div>
        <button type="button" class="btn slate" onclick="applyPreset()">Auto-Fill Ratings</button>
    </div>

    <form method="POST">
        <input type="hidden" name="create_player_submit" value="1">

        <div class="grid-3">
            <div>
                <label>First Name *</label>
                <input type="text" name="first_name" required placeholder="e.g. Ivan">
            </div>
            <div>
                <label>Last Name *</label>
                <input type="text" name="last_name" required placeholder="e.g. Demidov">
            </div>
            <div>
                <label>Position *</label>
                <select name="position" id="pos_field">
                    <option value="C">Center (C)</option>
                    <option value="LW">Left Wing (LW)</option>
                    <option value="RW">Right Wing (RW)</option>
                    <option value="D">Defense (D)</option>
                    <option value="G">Goalie (G)</option>
                </select>
            </div>
        </div>

        <div class="grid-3">
            <div>
                <label>Age</label>
                <input type="number" name="age" value="19" min="17" max="45">
            </div>
            <div>
                <label>Career NHL GP</label>
                <input type="number" name="nhl_games_played" value="0" min="0">
            </div>
            <div>
                <label>Destination / Roster Status</label>
                <select name="dest_type">
                    <option value="PRO">NHL Active Roster (PRO)</option>
                    <option value="AHL" selected>AHL Affiliate Roster (AHL)</option>
                    <option value="EURO">Rights Owned / Europe (EURO)</option>
                    <option value="PROSPECT">Drafted Prospects List (No Contract)</option>
                    <option value="FA">Unrestricted Free Agent (FA)</option>
                </select>
            </div>
        </div>

        <div class="grid-3">
            <div>
                <label>Assign to Team</label>
                <select name="team_id">
                    <option value="">-- Free Agent (No Team) --</option>
                    <?php foreach ($teams as $t): ?>
                        <option value="<?= (int)$t['team_id'] ?>">
                            <?= htmlspecialchars($t['name']) ?> (<?= htmlspecialchars($t['abbreviation']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Contract Years (ELC = 3)</label>
                <input type="number" name="contract_years" value="3" min="0" max="8">
            </div>
            <div>
                <label>Annual Salary / Cap Hit ($)</label>
                <input type="number" name="salary" value="950000" min="0" step="25000">
            </div>
        </div>

        <div class="grid-7">
            <div><label>OVR</label><input type="number" name="overall_rating" id="r_ovr" value="68" min="50" max="99"></div>
            <div><label>SK</label><input type="number" name="skating" id="r_sk" value="69" min="50" max="99"></div>
            <div><label>SH</label><input type="number" name="shooting" id="r_sh" value="68" min="50" max="99"></div>
            <div><label>PA</label><input type="number" name="passing" id="r_pa" value="68" min="50" max="99"></div>
            <div><label>DF</label><input type="number" name="defense" id="r_df" value="67" min="50" max="99"></div>
            <div><label>PH</label><input type="number" name="physical" id="r_ph" value="67" min="50" max="99"></div>
            <div><label>GO</label><input type="number" name="goaltending" id="r_go" value="50" min="50" max="99"></div>
        </div>

        <div class="grid-2">
            <div>
                <label>Draft Year (If Prospect)</label>
                <input type="number" name="draft_year" value="2026" min="2000" max="2035">
            </div>
            <div>
                <label>Notes (Optional)</label>
                <input type="text" name="notes" placeholder="e.g. KHL / NCAA Signing">
            </div>
        </div>

        <button type="submit" class="btn">Generate Player Into Sim</button>
    </form>
</div>

<script>
    function applyPreset() {
        const arch = document.getElementById('archetype').value;
        const base = parseInt(document.getElementById('tier').value, 10);
        const clamp = (v) => Math.max(50, Math.min(99, v));

        const posMap = { sniper: 'LW', playmaker: 'C', twoway: 'C', power: 'RW', off_d: 'D', shut_d: 'D', goalie: 'G' };
        document.getElementById('pos_field').value = posMap[arch];

        document.getElementById('r_ovr').value = base;
        if (arch === 'goalie') {
            document.getElementById('r_sk').value = 50;
            document.getElementById('r_sh').value = 50;
            document.getElementById('r_pa').value = 50;
            document.getElementById('r_df').value = 50;
            document.getElementById('r_ph').value = 50;
            document.getElementById('r_go').value = base;
            return;
        }

        document.getElementById('r_go').value = 50;
        const offsets = {
            sniper:    [+2, +4, +1, -3, -2],
            playmaker: [+2,  0, +4, -1, -3],
            twoway:    [+1,  0, +1, +3,  0],
            power:     [-1, +2, -1,  0, +5],
            off_d:     [+2, +1, +3,  0, -2],
            shut_d:    [ 0, -3, -1, +4, +3]
        }[arch];

        document.getElementById('r_sk').value = clamp(base + offsets.at(0));
        document.getElementById('r_sh').value = clamp(base + offsets.at(1));
        document.getElementById('r_pa').value = clamp(base + offsets.at(2));
        document.getElementById('r_df').value = clamp(base + offsets.at(3));
        document.getElementById('r_ph').value = clamp(base + offsets.at(4));
    }
</script>

</body>
</html>


