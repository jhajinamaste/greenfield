<?php

declare(strict_types=1);

namespace App\Core\Http;

final class Request
{
    public function __construct(
        public string $method,
        public string $path,
        public array $query,
        public array $body,
        public array $server,
        public array $cookies,
        public array $files,
        public array &$session,
        public string $requestId,
        public string $correlationId
    ) {
    }

    public static function capture(array &$session): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $_GET,
            $_POST,
            $_SERVER,
            $_COOKIE,
            $_FILES,
            $session,
            self::resolveRequestId($_SERVER),
            self::resolveCorrelationId($_SERVER)
        );
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function header(string $key): ?string
    {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
        return $this->server[$serverKey] ?? null;
    }

    public function isAjax(): bool
    {
        return strtolower((string) ($this->header('X-Requested-With') ?? '')) === 'xmlhttprequest';
    }

    private static function resolveRequestId(array $server): string
    {
        $header = $server['HTTP_X_REQUEST_ID'] ?? null;
        if (is_string($header) && $header !== '') {
            return $header;
        }

        return bin2hex(random_bytes(16));
    }

    private static function resolveCorrelationId(array $server): string
    {
        $header = $server['HTTP_X_CORRELATION_ID'] ?? null;
        if (is_string($header) && $header !== '') {
            return $header;
        }

        return bin2hex(random_bytes(16));
    }
}
