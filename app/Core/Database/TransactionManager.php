<?php

declare(strict_types=1);

namespace App\Core\Database;

use PDO;
use Throwable;

final class TransactionManager
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function run(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
