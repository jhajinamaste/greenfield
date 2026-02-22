<?php

declare(strict_types=1);

namespace App\Domain\Auth;

final class RbacService
{
    public function can(array $roles, string $permission): bool
    {
        $matrix = [
            'super_admin' => ['*'],
            'org_admin' => ['dashboard.view', 'users.manage', 'branches.manage', 'settings.manage'],
            'auditor' => ['dashboard.view', 'audit.view'],
        ];

        foreach ($roles as $role) {
            $grants = $matrix[$role] ?? [];
            if (in_array('*', $grants, true) || in_array($permission, $grants, true)) {
                return true;
            }
        }

        return false;
    }
}
