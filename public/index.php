<?php

declare(strict_types=1);

use App\Core\App;
use App\Core\Request;

require_once __DIR__ . '/../src/bootstrap.php';

$app = App::boot();
$request = Request::capture();
$response = $app->router()->dispatch($request);
$response->send();
