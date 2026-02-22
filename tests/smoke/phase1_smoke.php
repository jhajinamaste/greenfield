<?php

declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

$config = require __DIR__ . '/../../config/database.php';
$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $config['host'], $config['port'], $config['name'], $config['charset']);

try {
    $pdo = new PDO($dsn, $config['user'], $config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $checks = [
        'organizations' => 'SELECT COUNT(*) AS c FROM organizations',
        'branches' => 'SELECT COUNT(*) AS c FROM branches',
        'users' => 'SELECT COUNT(*) AS c FROM users',
        'roles' => 'SELECT COUNT(*) AS c FROM roles',
        'feature_flags' => 'SELECT COUNT(*) AS c FROM feature_flags',
        'audit_logs' => 'SELECT COUNT(*) AS c FROM audit_logs',
        'user_branch_access' => 'SELECT COUNT(*) AS c FROM user_branch_access',
        'user_branch_roles' => 'SELECT COUNT(*) AS c FROM user_branch_roles',
    ];

    foreach ($checks as $name => $sql) {
        $row = $pdo->query($sql)->fetch();
        echo $name . ': ' . ($row['c'] ?? 0) . PHP_EOL;
    }

    $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE username = :username AND organization_id = :org LIMIT 1');
    $stmt->execute([':username' => 'admin', ':org' => 1]);
    $user = $stmt->fetch();
    $passwordOk = $user ? password_verify('Admin@12345', $user['password_hash']) : false;

    echo 'login_hash_check: ' . ($passwordOk ? 'PASS' : 'FAIL') . PHP_EOL;

    $requiredAuditColumns = [
        'before_state',
        'after_state',
        'request_id',
        'correlation_id',
        'ip',
        'user_agent',
    ];
    $missingColumns = [];
    foreach ($requiredAuditColumns as $column) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS c
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = :schema_name
              AND TABLE_NAME = 'audit_logs'
              AND COLUMN_NAME = :column_name
        ");
        $stmt->execute([
            ':schema_name' => $config['name'],
            ':column_name' => $column,
        ]);
        $row = $stmt->fetch();
        if ((int) ($row['c'] ?? 0) === 0) {
            $missingColumns[] = $column;
        }
    }

    echo 'audit_schema_check: ' . (empty($missingColumns) ? 'PASS' : ('FAIL missing=' . implode(',', $missingColumns))) . PHP_EOL;
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS c
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = :schema_name
          AND TABLE_NAME = 'system_configs'
          AND COLUMN_NAME = 'config_encrypted'
    ");
    $stmt->execute([':schema_name' => $config['name']]);
    $configEncryptedColumnOk = ((int) (($stmt->fetch()['c'] ?? 0)) === 1);
    echo 'config_encryption_schema_check: ' . ($configEncryptedColumnOk ? 'PASS' : 'FAIL') . PHP_EOL;

    $ok = $passwordOk && empty($missingColumns) && $configEncryptedColumnOk;
    exit($ok ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Smoke test failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
