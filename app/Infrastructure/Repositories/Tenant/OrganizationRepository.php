<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories\Tenant;

use PDO;

final class OrganizationRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findActiveByCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, code, status FROM organizations WHERE code = :code AND status = :status LIMIT 1');
        $stmt->execute([':code' => $code, ':status' => 'active']);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
