<?php

$databaseUrl = getenv('DATABASE_URL');

if ($databaseUrl) {
    // Live Render connection
    $dbParts = parse_url($databaseUrl);
    $host    = $dbParts['host'];
    $port    = $dbParts['port'] ?? '5432';
    $db      = ltrim($dbParts['path'], '/');
    $user    = $dbParts['user'];
    $pass    = $dbParts['pass'];
    $sslmode = 'require';
} else {
    $host    = 'localhost';
    $db      = 'HFNHL';
    $user    = 'postgres';
    $pass    = '';
    $port    = '5432';
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

if (session_status() === PHP_SESSION_NONE) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS php_sessions (
            id VARCHAR(128) PRIMARY KEY,
            data TEXT NOT NULL,
            last_access INT NOT NULL
        )
    ");

    class PgSessionHandler implements SessionHandlerInterface {
        private PDO $pdo;
        public function __construct(PDO $pdo) { $this->pdo = $pdo; }
        public function open(string $path, string $name): bool { return true; }
        public function close(): bool { return true; }
        public function read(string $id): string|false {
            $stmt = $this->pdo->prepare("SELECT data FROM php_sessions WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetchColumn();
            return $row !== false ? $row : '';
        }
        public function write(string $id, string $data): bool {
            $stmt = $this->pdo->prepare("
                INSERT INTO php_sessions (id, data, last_access)
                VALUES (?, ?, ?)
                ON CONFLICT (id) DO UPDATE SET data = EXCLUDED.data, last_access = EXCLUDED.last_access
            ");
            return $stmt->execute([$id, $data, time()]);
        }
        public function destroy(string $id): bool {
            $stmt = $this->pdo->prepare("DELETE FROM php_sessions WHERE id = ?");
            return $stmt->execute([$id]);
        }
        public function gc(int $max_lifetime): int|false {
            $stmt = $this->pdo->prepare("DELETE FROM php_sessions WHERE last_access < ?");
            $stmt->execute([time() - $max_lifetime]);
            return $stmt->rowCount();
        }
    }

    $lifetime = 60 * 60 * 24 * 30;
    ini_set('session.gc_maxlifetime', (string)$lifetime);
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_set_save_handler(new PgSessionHandler($pdo), true);
    session_start();
}


