<?php

if (session_status() === PHP_SESSION_NONE) {
    session_save_path(sys_get_temp_dir());
    session_start();
}

$databaseUrl = getenv('DATABASE_URL');

if ($databaseUrl) {
    $dbParts = parse_url($databaseUrl);
    $host = $dbParts['host'];
    $port = $dbParts['port'] ?? '5432';
    $db   = ltrim($dbParts['path'], '/');
    $user = $dbParts['user'];
    $pass = $dbParts['pass'];
    $sslmode = 'require';
} else {
    $host = 'localhost';
    $db   = 'HFNHL';
    $user = 'postgres';
    $pass = ''; // Put your local password here only when testing locally, clear before pushing
    $port = '5432';
    $sslmode = 'prefer';
}

$dsn = "pgsql:host=$host;port=$port;dbname=$db;sslmode=$sslmode;";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

