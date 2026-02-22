<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Core\Support\TenantScope;
use PDO;

abstract class BaseRepository
{
    public function __construct(
        protected readonly PDO $pdo,
        protected readonly TenantScope $scope
    ) {
    }

    protected function organizationId(?int $override = null): int
    {
        $scopedOrganizationId = $this->scope->organizationId();
        if ($scopedOrganizationId !== null) {
            if ($override !== null && $override !== $scopedOrganizationId) {
                throw new \RuntimeException('Organization scope violation.');
            }

            return $scopedOrganizationId;
        }

        if ($override === null) {
            throw new \RuntimeException('Missing organization context.');
        }

        return $override;
    }

    protected function branchId(?int $override = null): ?int
    {
        $scopedBranchId = $this->scope->branchId();
        if ($scopedBranchId !== null) {
            if ($override !== null && $override !== $scopedBranchId) {
                throw new \RuntimeException('Branch scope violation.');
            }

            return $scopedBranchId;
        }

        return $override;
    }
}
