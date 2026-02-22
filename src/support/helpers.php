<?php

declare(strict_types=1);

use App\Core\App;

function app(): App
{
    return App::instance();
}

function config(string $key, mixed $default = null): mixed
{
    return app()->config()->get($key, $default);
}

function service(string $id): mixed
{
    return app()->container()->get($id);
}

function view(string $view, array $data = []): string
{
    return app()->view()->render($view, $data);
}
