<?php

declare(strict_types=1);

namespace App\Domain\Tenant;

use App\Infrastructure\Repositories\Tenant\OrganizationRepository;

final class TenantContextService
{
    public function __construct(private readonly OrganizationRepository $organizations)
    {
    }

    public function resolveOrganizationId(string $organizationCode): ?int
    {
        $org = $this->organizations->findActiveByCode($organizationCode);
        return $org !== null ? (int) $org['id'] : null;
    }
}
