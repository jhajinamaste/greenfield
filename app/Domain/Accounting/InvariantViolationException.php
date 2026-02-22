<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

final class InvariantViolationException extends \DomainException
{
    public function __construct(
        private readonly array $details,
        string $message = 'Accounting invariant violation.'
    ) {
        parent::__construct($message);
    }

    public function details(): array
    {
        return $this->details;
    }
}
