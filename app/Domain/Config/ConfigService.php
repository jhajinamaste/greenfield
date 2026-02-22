<?php

declare(strict_types=1);

namespace App\Domain\Config;

use App\Infrastructure\Repositories\Config\ConfigRepository;

final class ConfigService
{
    public function __construct(
        private readonly ConfigRepository $repository,
        private readonly bool $defaultAccountingStrictMode = true
    )
    {
    }

    public function get(string $group, string $key, ?int $organizationId = null, ?string $default = null): ?string
    {
        return $this->repository->get($group, $key, $organizationId) ?? $default;
    }

    public function set(string $group, string $key, string $value, ?int $organizationId = null): void
    {
        $this->repository->set($group, $key, $value, $organizationId);
    }

    public function accountingStrictMode(?int $organizationId = null): bool
    {
        if ($organizationId === null) {
            return $this->defaultAccountingStrictMode;
        }

        $value = $this->repository->get('accounting', 'strict_mode', $organizationId);
        if ($value === null || trim($value) === '') {
            return $this->defaultAccountingStrictMode;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }
}
