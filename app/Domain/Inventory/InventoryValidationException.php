<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

final class InventoryValidationException extends \RuntimeException
{
    public function __construct(
        private readonly array $details,
        string $message = 'Inventory validation failed.'
    ) {
        parent::__construct($message);
    }

    public function details(): array
    {
        return $this->details;
    }
}
