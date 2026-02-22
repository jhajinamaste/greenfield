<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories\Config;

use App\Core\Security\ConfigCrypto;
use App\Infrastructure\Repositories\BaseRepository;

final class ConfigRepository extends BaseRepository
{
    public function __construct(
        \PDO $pdo,
        \App\Core\Support\TenantScope $scope,
        private readonly ConfigCrypto $crypto
    )
    {
        parent::__construct($pdo, $scope);
    }

    public function get(string $group, string $key, ?int $organizationId = null): ?string
    {
        $organizationId = $this->organizationId($organizationId);
        $sql = 'SELECT config_value FROM system_configs
                WHERE config_group = :config_group
                AND config_key = :config_key
                AND organization_id = :organization_id
                LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':config_group' => $group,
            ':config_key' => $key,
            ':organization_id' => $organizationId,
        ]);

        $row = $stmt->fetch();
        return $this->crypto->decrypt($row['config_value'] ?? null);
    }

    public function set(
        string $group,
        string $key,
        string $value,
        ?int $organizationId = null
    ): void {
        $organizationId = $this->organizationId($organizationId);
        $cipher = $this->crypto->encrypt($value);
        $sql = 'INSERT INTO system_configs (organization_id, config_group, config_key, config_value, config_encrypted)
                VALUES (:organization_id, :config_group, :config_key, :config_value, 1)
                ON DUPLICATE KEY UPDATE config_value = VALUES(config_value), config_encrypted = 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':organization_id' => $organizationId,
            ':config_group' => $group,
            ':config_key' => $key,
            ':config_value' => $cipher,
        ]);
    }
}
