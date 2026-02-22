<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Core\Database\TransactionManager;
use App\Domain\Audit\AuditLogService;
use App\Infrastructure\Repositories\Auth\UserRepository;

final class AuthService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AuditLogService $audit,
        private readonly TransactionManager $tx
    ) {
    }

    public function login(string $username, string $password, int $organizationId, array $meta): ?array
    {
        return $this->tx->run(function () use ($username, $password, $organizationId, $meta): ?array {
            $user = $this->users->findByUsernameForTenant($username, $organizationId);
            if ($user === null || $user['status'] !== 'active') {
                return null;
            }

            if (!password_verify($password, $user['password_hash'])) {
                return null;
            }

            $roles = $this->users->findRoles((int) $user['id'], $organizationId, (int) $user['branch_id']);

            $this->audit->log([
                'organization_id' => $organizationId,
                'branch_id' => (int) $user['branch_id'],
                'user_id' => (int) $user['id'],
                'action_type' => 'auth.login',
                'entity_type' => 'user',
                'entity_id' => (string) $user['id'],
                'metadata' => ['username' => $username],
                'before_state' => ['authenticated' => false],
                'after_state' => ['authenticated' => true],
                'request_id' => $meta['request_id'] ?? null,
                'correlation_id' => $meta['correlation_id'] ?? null,
                'ip' => $meta['ip'] ?? null,
                'ip_address' => $meta['ip_address'] ?? null,
                'user_agent' => $meta['user_agent'] ?? null,
            ]);

            return [
                'id' => (int) $user['id'],
                'organization_id' => (int) $user['organization_id'],
                'branch_id' => (int) $user['branch_id'],
                'username' => (string) $user['username'],
                'email' => (string) $user['email'],
                'roles' => $roles,
            ];
        });
    }
}
