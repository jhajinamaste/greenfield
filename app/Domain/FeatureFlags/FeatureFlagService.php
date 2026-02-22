<?php

declare(strict_types=1);

namespace App\Domain\FeatureFlags;

use App\Infrastructure\Repositories\FeatureFlags\FeatureFlagRepository;

final class FeatureFlagService
{
    public function __construct(private readonly FeatureFlagRepository $repository)
    {
    }

    public function enabled(string $code, ?int $organizationId = null): bool
    {
        return $this->repository->isEnabled($code, $organizationId);
    }
}
