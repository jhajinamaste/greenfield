<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories\Audit;

use App\Infrastructure\Repositories\BaseRepository;

final class AuditLogRepository extends BaseRepository
{
    public function store(array $log): void
    {
        $sql = 'INSERT INTO audit_logs
        (organization_id, branch_id, user_id, action_type, entity_type, entity_id, metadata_json, before_state, after_state, request_id, correlation_id, ip, ip_address, user_agent)
        VALUES (:organization_id, :branch_id, :user_id, :action_type, :entity_type, :entity_id, :metadata_json, :before_state, :after_state, :request_id, :correlation_id, :ip, :ip_address, :user_agent)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':organization_id' => $this->organizationId(isset($log['organization_id']) ? (int) $log['organization_id'] : null),
            ':branch_id' => $this->branchId(isset($log['branch_id']) ? (int) $log['branch_id'] : null),
            ':user_id' => $log['user_id'] ?? null,
            ':action_type' => $log['action_type'],
            ':entity_type' => $log['entity_type'],
            ':entity_id' => $log['entity_id'],
            ':metadata_json' => json_encode($log['metadata'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':before_state' => json_encode($log['before_state'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':after_state' => json_encode($log['after_state'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':request_id' => $log['request_id'] ?? null,
            ':correlation_id' => $log['correlation_id'] ?? null,
            ':ip' => $log['ip'] ?? $log['ip_address'] ?? null,
            ':ip_address' => $log['ip_address'] ?? $log['ip'] ?? null,
            ':user_agent' => $log['user_agent'] ?? null,
        ]);
    }
}
