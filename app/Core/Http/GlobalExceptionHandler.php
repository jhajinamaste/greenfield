<?php

declare(strict_types=1);

namespace App\Core\Http;

final class GlobalExceptionHandler
{
    public function __construct(
        private readonly bool $debug,
        private readonly string $logFile
    ) {
    }

    public function register(): void
    {
        set_exception_handler([$this, 'handle']);
    }

    public function handle(\Throwable $e): never
    {
        $line = sprintf(
            "[%s] %s in %s:%d\n",
            gmdate('c'),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );
        error_log($line, 3, $this->logFile);

        $status = 500;
        $payload = [
            'ok' => false,
            'message' => $this->debug ? $e->getMessage() : 'Internal server error',
        ];

        if (method_exists($e, 'details')) {
            $status = 422;
            $payload['message'] = $e->getMessage();
            $payload['error'] = $e->details();
        }

        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
