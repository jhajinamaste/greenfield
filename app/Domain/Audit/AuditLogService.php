<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Infrastructure\Repositories\Audit\AuditLogRepository;

final class AuditLogService
{
    public function __construct(private readonly AuditLogRepository $repository)
    {
    }

    public function log(array $payload): void
    {
        $this->repository->store($payload);
    }
}
