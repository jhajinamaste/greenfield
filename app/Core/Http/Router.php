<?php

declare(strict_types=1);

namespace App\Core\Http;

final class Router
{
    private array $routes = [];

    public function get(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->map('GET', $path, $handler, $middleware);
    }

    public function post(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->map('POST', $path, $handler, $middleware);
    }

    public function map(string $method, string $path, callable|array $handler, array $middleware = []): void
    {
        $this->routes[$method][$path] = ['handler' => $handler, 'middleware' => $middleware];
    }

    public function dispatch(Request $request): mixed
    {
        $route = $this->routes[$request->method][$request->path] ?? null;
        if ($route === null) {
            http_response_code(404);
            echo 'Not Found';
            return null;
        }

        foreach ($route['middleware'] as $middleware) {
            $middleware($request);
        }

        $handler = $route['handler'];
        if (is_array($handler)) {
            [$controller, $method] = $handler;
            return $controller->$method($request);
        }

        return $handler($request);
    }
}
