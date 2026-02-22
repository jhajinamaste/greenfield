<?php

declare(strict_types=1);

namespace App\Core\Support;

final class TenantScope
{
    public function __construct(
        private readonly ?int $organizationId,
        private readonly ?int $branchId
    ) {
    }

    public function organizationId(): ?int
    {
        return $this->organizationId;
    }

    public function branchId(): ?int
    {
        return $this->branchId;
    }
}
