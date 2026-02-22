<?php

declare(strict_types=1);

namespace App\Core\Security;

final class CsrfToken
{
    public static function ensure(array &$session): string
    {
        if (empty($session['_csrf'])) {
            $session['_csrf'] = bin2hex(random_bytes(32));
        }

        return $session['_csrf'];
    }
}
