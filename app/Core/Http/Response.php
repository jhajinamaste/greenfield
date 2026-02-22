<?php

declare(strict_types=1);

namespace App\Core\Http;

final class Response
{
    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function view(string $path, array $vars = []): never
    {
        extract($vars, EXTR_SKIP);
        require __DIR__ . '/../../../' . $path;
        exit;
    }

    public static function redirect(string $location): never
    {
        header('Location: ' . $location);
        exit;
    }
}
