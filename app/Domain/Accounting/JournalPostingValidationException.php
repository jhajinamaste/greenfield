<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

final class JournalPostingValidationException extends \RuntimeException
{
    public function __construct(
        private readonly array $details,
        string $message = 'Journal posting validation failed.'
    ) {
        parent::__construct($message);
    }

    public function details(): array
    {
        return $this->details;
    }
}
