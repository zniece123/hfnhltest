<?php
require 'db.php';
/** @var PDO $pdo */

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("
        SELECT u.*, t.name AS team_name, t.abbreviation AS team_abbr
        FROM users u
        LEFT JOIN teams t ON u.team_id = t.team_id
        WHERE LOWER(u.username) = LOWER(?)
    ");
    $stmt->execute(array($username));
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id']            = $user['user_id'];
        $_SESSION['username']           = $user['username'];
        $_SESSION['team_id']            = $user['team_id'];
        $_SESSION['team_name']          = $user['team_name'] ?? 'Commissioner';
        $_SESSION['is_admin']           = !empty($user['is_admin']);
        $_SESSION['is_commish']         = !empty($user['is_commish']);
        $_SESSION['is_trade_committee'] = !empty($user['is_trade_committee']);
        $_SESSION['can_manage_draft']   = !empty($user['can_manage_draft']);
        $_SESSION['is_fa_committee']    = !empty($user['is_fa_committee']);

        header('Location: index.php');
        exit;
    } else {
        $error = 'Invalid username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>GM Login - HFNHL</title>
    <style>
        body { font-family: Arial, sans-serif; background: #0f172a; color: #f8fafc; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .login-box { background: #1e293b; padding: 30px; border-radius: 10px; border: 1px solid #334155; width: 340px; }
        h1 { color: #38bdf8; margin-top: 0; font-size: 1.5em; text-align: center; }
        label { display: block; margin-top: 14px; font-size: 0.9em; color: #cbd5e1; }
        input { width: 100%; padding: 10px; margin-top: 6px; border-radius: 6px; border: 1px solid #475569; background: #0f172a; color: #fff; box-sizing: border-box; }
        button { width: 100%; padding: 12px; margin-top: 22px; background: #0284c7; color: #fff; border: none; border-radius: 6px; font-weight: bold; font-size: 1em; cursor: pointer; }
        button:hover { background: #0369a1; }
        .err { background: #7f1d1d; color: #fecaca; padding: 10px; border-radius: 6px; margin-bottom: 12px; font-size: 0.9em; text-align: center; }
        .back { display: block; text-align: center; margin-top: 16px; color: #38bdf8; text-decoration: none; font-size: 0.9em; }
    </style>
</head>
<body>
<div class="login-box">
    <h1>HFNHL GM Login</h1>
    <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post">
        <label>Username</label>
        <input type="text" name="username" required autofocus>
        <label>Password</label>
        <input type="password" name="password" required>
        <button type="submit">Log In</button>
    </form>
    <a class="back" href="index.php">&larr; Back to League Portal</a>
</div>
</body>
</html>


