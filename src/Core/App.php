<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Middleware\AuthMiddleware;
use App\Core\Middleware\BranchIsolationMiddleware;
use App\Core\Middleware\CsrfMiddleware;
use App\Core\Middleware\TenantIsolationMiddleware;
use App\Repository\AuditLogRepository;
use App\Repository\BranchRepository;
use App\Repository\FeatureFlagRepository;
use App\Repository\OrganizationRepository;
use App\Repository\RoleRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogService;
use App\Service\AuthService;
use App\Service\DashboardService;
use App\Service\FeatureFlagService;
use App\Service\RbacService;
use App\Service\TenantContextService;

final class App
{
    private static ?self $instance = null;

    public function __construct(
        private readonly Container $container,
        private readonly Config $config,
        private readonly Router $router,
        private readonly View $view
    ) {
    }

    public static function boot(): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $config = new Config(__DIR__ . '/../../config');
        $container = new Container();
        $database = new Database($config->get('database'));
        $session = new Session($config->get('app.session_timeout_minutes', 30));
        $view = new View(__DIR__ . '/../../views');
        $router = new Router();
        $events = new EventBus();

        $container->set(Database::class, $database);
        $container->set(Session::class, $session);
        $container->set(EventBus::class, $events);

        $container->set(OrganizationRepository::class, new OrganizationRepository($database));
        $container->set(BranchRepository::class, new BranchRepository($database));
        $container->set(UserRepository::class, new UserRepository($database));
        $container->set(RoleRepository::class, new RoleRepository($database));
        $container->set(FeatureFlagRepository::class, new FeatureFlagRepository($database));
        $container->set(AuditLogRepository::class, new AuditLogRepository($database));

        $container->set(AuditLogService::class, new AuditLogService(
            $container->get(AuditLogRepository::class),
            $session
        ));
        $container->set(RbacService::class, new RbacService($container->get(RoleRepository::class)));
        $container->set(TenantContextService::class, new TenantContextService($session));
        $container->set(FeatureFlagService::class, new FeatureFlagService(
            $container->get(FeatureFlagRepository::class),
            $container->get(AuditLogService::class),
            $events
        ));
        $container->set(AuthService::class, new AuthService(
            $container->get(UserRepository::class),
            $container->get(RbacService::class),
            $container->get(TenantContextService::class),
            $container->get(AuditLogService::class),
            $session,
            $events
        ));
        $container->set(DashboardService::class, new DashboardService(
            $database,
            $container->get(TenantContextService::class)
        ));

        $router->addMiddleware(new CsrfMiddleware($session));
        $router->addMiddleware(new AuthMiddleware($session));
        $router->addMiddleware(new TenantIsolationMiddleware($container->get(TenantContextService::class)));
        $router->addMiddleware(new BranchIsolationMiddleware($container->get(TenantContextService::class)));

        require __DIR__ . '/../../routes/web.php';
        require __DIR__ . '/../../routes/api.php';

        self::$instance = new self($container, $config, $router, $view);

        return self::$instance;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('Application not booted.');
        }

        return self::$instance;
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function view(): View
    {
        return $this->view;
    }
}
