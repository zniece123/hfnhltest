<?php
if (!function_exists('getNhlLogoUrl')) {
    function getNhlLogoUrl($abbr) {
        $a = strtoupper(trim($abbr));
        return "logos/{$a}.svg";
    }
}

$navTeams  = $pdo->query("SELECT team_id, abbreviation, name FROM teams ORDER BY abbreviation ASC")->fetchAll(PDO::FETCH_ASSOC);
$navTeamId = (int)($_SESSION['team_id'] ?? 0);

$navPhase = 'REGULAR_SEASON';
try {
    $navPhaseStmt = $pdo->query("SELECT setting_value FROM league_settings WHERE setting_key = 'league_phase'");
    if ($navPhaseStmt) {
        $navPhase = $navPhaseStmt->fetchColumn() ?: 'REGULAR_SEASON';
    }
} catch (Throwable $e) {
    $navPhase = 'REGULAR_SEASON';
}
?>
<style>
    body { max-width: 1440px !important; margin: auto; }
    .logo-strip {
        background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
        border: 1px solid #334155;
        border-radius: 10px;
        padding: 8px 10px;
        margin-bottom: 12px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        width: 100%;
        box-sizing: border-box;
        box-shadow: 0 4px 14px rgba(0, 0, 0, 0.4);
    }
    .logo-strip a {
        flex: 1;
        min-width: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 3px 0;
        transition: transform 0.15s;
    }
    .logo-strip a:hover { transform: scale(1.24); }
    .logo-strip img {
        width: 38px;
        height: 38px;
        max-width: 100%;
        object-fit: contain;
        filter: drop-shadow(0 0 3px rgba(255, 255, 255, 0.45));
    }

    nav.main-nav {
        background: #020617;
        padding: 0 18px;
        border-radius: 10px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 6px;
        border: 1px solid #334155;
        width: 100%;
        box-sizing: border-box;
        box-shadow: 0 4px 14px rgba(0, 0, 0, 0.35);
    }
    nav.main-nav > a, .nav-drop-btn {
        color: #38bdf8;
        text-decoration: none;
        font-weight: bold;
        font-size: 0.94em;
        padding: 12px 14px;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        background: transparent;
        border: none;
        cursor: pointer;
        font-family: inherit;
        border-radius: 6px;
        transition: background 0.15s, color 0.15s;
    }
    nav.main-nav > a:hover, .nav-dropdown:hover .nav-drop-btn {
        background: #1e293b;
        color: #f8fafc;
    }
    .nav-dropdown {
        position: relative;
        display: inline-block;
    }
    .nav-drop-menu {
        display: none;
        position: absolute;
        top: 100%;
        left: 0;
        min-width: 205px;
        background: #0f172a;
        border: 1px solid #334155;
        border-radius: 8px;
        padding: 6px;
        z-index: 999;
        box-shadow: 0 12px 28px rgba(0, 0, 0, 0.65);
    }
    .nav-dropdown:hover .nav-drop-menu {
        display: block;
    }
    .nav-drop-menu a {
        display: block;
        color: #e2e8f0;
        text-decoration: none;
        padding: 9px 12px;
        border-radius: 6px;
        font-size: 0.9em;
        font-weight: bold;
        transition: background 0.12s, color 0.12s;
    }
    .nav-drop-menu a:hover {
        background: #0284c7;
        color: #ffffff;
    }
    .nav-fa-badge {
        background: #0284c7;
        color: #ffffff;
        padding: 1px 6px;
        border-radius: 4px;
        font-size: 0.72em;
        margin-left: 5px;
        vertical-align: middle;
    }
    .nav-right {
        margin-left: auto;
        display: flex;
        align-items: center;
    }
</style>

<div class="logo-strip">
    <?php foreach ($navTeams as $nt): ?>
        <a href="team.php?id=<?= (int)$nt['team_id'] ?>" title="<?= htmlspecialchars($nt['name']) ?>">
            <img src="<?= getNhlLogoUrl($nt['abbreviation']) ?>" alt="<?= htmlspecialchars($nt['abbreviation']) ?>">
        </a>
    <?php endforeach; ?>
</div>

<nav class="main-nav">
    <a href="index.php">Home &amp; News</a>

    <div class="nav-dropdown">
        <button type="button" class="nav-drop-btn">League ▾</button>
        <div class="nav-drop-menu">
            <a href="standings.php">Standings</a>
            <a href="schedule.php">Scores &amp; Schedule</a>
        </div>
    </div>

    <div class="nav-dropdown">
        <button type="button" class="nav-drop-btn">
            Transactions ▾
            <?php if ($navPhase === 'OFFSEASON'): ?>
                <span class="nav-fa-badge">FA</span>
            <?php endif; ?>
        </button>
        <div class="nav-drop-menu">
            <a href="free_agents.php">
                Free Agents
                <?php if ($navPhase === 'OFFSEASON'): ?>
                    <span class="nav-fa-badge">OPEN</span>
                <?php endif; ?>
            </a>
            <a href="trade_block.php">Trade Block</a>
            <a href="waivers.php">Waiver Wire</a>
            <?php if (isset($_SESSION['user_id'])): ?>
                <a href="trades.php">Trade Center</a>
                <a href="contracts.php">Contract Center</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (isset($_SESSION['user_id'])): ?>
        <div class="nav-dropdown">
            <button type="button" class="nav-drop-btn">My Franchise ▾</button>
            <div class="nav-drop-menu">
                <?php if ($navTeamId > 0): ?>
                    <a href="team.php?id=<?= $navTeamId ?>">My Team Roster</a>
                <?php endif; ?>
                <a href="lines.php">My Line Editor</a>
                <a href="notifications.php">Notifications</a>
            </div>
        </div>

        <?php if (!empty($_SESSION['can_manage_draft']) || !empty($_SESSION['is_commish'])): ?>
            <div class="nav-dropdown">
                <button type="button" class="nav-drop-btn" style="color:#fbbf24;">Commish &amp; Tools ▾</button>
                <div class="nav-drop-menu">
                    <?php if (!empty($_SESSION['is_commish'])): ?>
                        <a href="commish_hub.php" style="color:#fbbf24;">Commish Hub ★</a>
                    <?php endif; ?>
                    <a href="import_draft.php">Draft Importer</a>
                </div>
            </div>
        <?php endif; ?>

        <div class="nav-right">
            <a href="logout.php" style="color:#f87171; font-weight:bold; text-decoration:none; padding:10px 12px;">
                Log Out (<?= htmlspecialchars($_SESSION['username']) ?>)
            </a>
        </div>
    <?php else: ?>
        <div class="nav-right">
            <a href="login.php" style="color:#38bdf8; font-weight:bold; text-decoration:none; padding:10px 12px;">GM Login</a>
        </div>
    <?php endif; ?>
</nav>




