<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Infrastructure\Repositories\Accounting\AccountingPeriodRepository;

final class AccountingPeriodGuard
{
    public function __construct(private readonly AccountingPeriodRepository $periods)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function assertMutationAllowed(
        \DateTimeInterface|string $entryDate,
        string $mutationType,
        ?int $organizationId = null
    ): array {
        $date = $entryDate instanceof \DateTimeInterface
            ? $entryDate->format('Y-m-d')
            : (new \DateTimeImmutable($entryDate))->format('Y-m-d');

        $closed = $this->periods->findClosedPeriodByDate($date, $organizationId);
        if ($closed !== null) {
            throw new AccountingPeriodViolationException([
                'code' => 'period_closed',
                'mutation_type' => $mutationType,
                'entry_date' => $date,
                'period_id' => (int) $closed['id'],
                'period_start' => $closed['period_start'],
                'period_end' => $closed['period_end'],
            ], 'Mutation blocked because the accounting period is closed.');
        }

        $open = $this->periods->findOpenPeriodByDate($date, $organizationId);
        if ($open === null) {
            throw new AccountingPeriodViolationException([
                'code' => 'period_not_open',
                'mutation_type' => $mutationType,
                'entry_date' => $date,
            ], 'Mutation blocked because no open accounting period exists for this date.');
        }

        return $open;
    }
}
