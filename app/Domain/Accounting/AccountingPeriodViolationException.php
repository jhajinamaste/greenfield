<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

final class AccountingPeriodViolationException extends \RuntimeException
{
    public function __construct(
        private readonly array $details,
        string $message = 'Accounting period guard blocked mutation.'
    ) {
        parent::__construct($message);
    }

    public function details(): array
    {
        return $this->details;
    }
}
