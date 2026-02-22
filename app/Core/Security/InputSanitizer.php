<?php

declare(strict_types=1);

namespace App\Core\Security;

final class InputSanitizer
{
    public static function clean(?string $value): string
    {
        return trim(strip_tags((string) $value));
    }

    public static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
