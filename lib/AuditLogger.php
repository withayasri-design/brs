<?php
declare(strict_types=1);

class AuditLogger
{
    public function __construct(private readonly PDO $pdo) {}

    public function log(
        string $action,
        ?int $userId     = null,
        ?string $targetType = null,
        ?int $targetId   = null,
        ?string $ip      = null,
        mixed $detail    = null,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_logs (user_id, action, target_type, target_id, ip_address, detail_json)
             VALUES (:user_id, :action, :target_type, :target_id, :ip, :detail)'
        );
        $stmt->execute([
            'user_id'     => $userId,
            'action'      => $action,
            'target_type' => $targetType,
            'target_id'   => $targetId,
            'ip'          => $ip,
            'detail'      => $detail !== null ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }
}
