<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories\FeatureFlags;

use App\Infrastructure\Repositories\BaseRepository;

final class FeatureFlagRepository extends BaseRepository
{
    public function isEnabled(string $code, ?int $organizationId = null): bool
    {
        $organizationId = $this->organizationId($organizationId);
        $sql = 'SELECT is_enabled FROM feature_flags
                WHERE code = :code AND organization_id = :organization_id LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':code' => $code, ':organization_id' => $organizationId]);
        $row = $stmt->fetch();
        return isset($row['is_enabled']) && (int) $row['is_enabled'] === 1;
    }
}
