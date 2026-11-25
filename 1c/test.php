<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
ini_set('display_errors', '0');

function respond(int $status, array $data): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $config = require __DIR__ . '/config.php';
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, ['ok' => false, 'message' => 'POST required']);
    }
    $key = $config['api_key'];
    if ($key === 'REPLACE_WITH_RANDOM_SECRET_KEY' || strlen($key) < 32 ||
        !hash_equals($key, $_SERVER['HTTP_X_API_KEY'] ?? '')) {
        respond(403, ['ok' => false, 'message' => 'Access denied']);
    }
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data) || ($data['action'] ?? '') !== 'ping') {
        respond(400, ['ok' => false, 'message' => 'Invalid request']);
    }
    $pdo = new PDO(
        'mysql:host=' . $config['host'] . ';dbname=' . $config['database'] . ';charset=utf8mb4',
        $config['username'], $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $pdo->exec("CREATE TABLE IF NOT EXISTS connection_test (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        source VARCHAR(32) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $stmt = $pdo->prepare('INSERT INTO connection_test (source) VALUES (?)');
    $stmt->execute(['1C']);
    respond(200, ['ok' => true, 'message' => 'Request received; MySQL write successful', 'id' => $pdo->lastInsertId()]);
} catch (Throwable $e) {
    error_log('1C connection test: ' . $e->getMessage());
    respond(500, ['ok' => false, 'message' => 'Server or MySQL error']);
}
