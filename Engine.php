<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Italix\Mvc;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use GuzzleHttp\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class Engine implements RequestHandlerInterface
{
    private array            $config;
    private ?ServiceRegistry $registry   = null;
    private ?Dispatcher      $dispatcher = null;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    public function run(): void
    {
        $request = ServerRequest::fromGlobals();

        try {
            $this->emit_response($this->pipeline()->handle($request));

        } catch (\Throwable $e) {
            $this->emit_response($this->handle_exception($e, $request, $this->registry));
        }
    }

    /**
     * Build the container and the dispatcher without handling anything.
     *
     * Idempotent, so it is safe to call before pipeline() or on its own.
     * Exists so that a caller which is not run() — an in-process test client,
     * a console command — can get a usable Engine without emitting output.
     */
    public function boot(): self
    {
        $this->registry();

        if ($this->dispatcher === null) {
            $this->dispatcher = $this->build_dispatcher();
        }

        return $this;
    }

    /**
     * The service registry, building it if necessary.
     *
     * Deliberately does *not* build the dispatcher: a console command needs the
     * container and has no route to dispatch, and building one would write a
     * route cache file as a side effect of running `ix version`.
     */
    public function registry(): ServiceRegistry
    {
        if ($this->registry === null) {
            $this->registry = $this->build_container();
        }

        return $this->registry;
    }

    /**
     * The complete request handler: global middleware wrapping the router.
     *
     * This is what run() executes, so anything driving this handler exercises
     * the same stack a real request does — locale, CSRF, authentication — and
     * not a reduced one.
     */
    public function pipeline(): RequestHandlerInterface
    {
        $this->boot();

        // Global middleware wraps everything — routing included.
        return Pipeline::build(
            $this->resolve_middlewares($this->config['middleware.global'] ?? []),
            $this
        );
    }

    // -------------------------------------------------------------------------
    // RequestHandlerInterface — terminal handler for the global pipeline
    // -------------------------------------------------------------------------

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Parse URI
        $uri = $request->getUri()->getPath();
        if (false !== $pos = strpos($uri, '?')) {
            $uri = substr($uri, 0, $pos);
        }
        $uri = rawurldecode($uri);

        $route_info = $this->dispatcher->dispatch($request->getMethod(), $uri);

        switch ($route_info[0]) {

            case Dispatcher::NOT_FOUND:
                return $this->make_error_response(404, 'not_found', 'Not found.', $request);

            case Dispatcher::METHOD_NOT_ALLOWED:
                return $this->make_error_response(405, 'method_not_allowed', 'Method not allowed.', $request);

            case Dispatcher::FOUND:
                $handler      = $route_info[1];
                $route_params = $route_info[2];

                // Inject regex URL parameters into the Request as attributes
                foreach ($route_params as $key => $value) {
                    $request = $request->withAttribute($key, $value);
                }

                // --- RESOLVE CONTROLLER CLASS AND METHOD ---
                $per_route_middleware = null;

                if (is_array($handler)) {
                    // e.g. [ArticleController::class, 'store']
                    // or   [ArticleController::class, 'store', [AuthMiddleware::class]]
                    [$action_class, $method_name] = $handler;
                    $per_route_middleware = $handler[2] ?? null;
                } elseif (class_exists($handler)) {
                    // Single-action class — invoked via __invoke
                    $action_class = $handler;
                    $method_name  = '__invoke';
                } else {
                    // Legacy string action
                    $action_class = $this->legacy_mapping_lookup($handler);
                    $method_name  = 'execute';
                }

                // --- DEPENDENCY INJECTION ---
                $needed_services = is_a($action_class, BaseController::class, true)
                    ? $action_class::depends_on($method_name)
                    : [];

                $services = [];
                foreach ($needed_services as $property_name => $service_key) {
                    $services[$property_name] = $this->registry->get($service_key);
                }

                $controller = new $action_class($services);

                // Legacy action — convert string outcome to a PSR-7 response
                if ($method_name === 'execute') {
                    $_GET    = array_merge($_GET, $route_params);
                    $outcome = $controller->execute($_GET, $_POST);
                    $response = Responses::make(200);
                    $response->getBody()->write(htmlspecialchars((string) $outcome));
                    return $response;
                }

                // Route middleware pipeline wraps the controller invocation.
                // Per-route middleware (from handler[2]) overrides the conf.php default when present.
                $route_middlewares = $this->resolve_middlewares(
                    $per_route_middleware ?? $this->config['middleware'] ?? []
                );

                return Pipeline::build(
                    $route_middlewares,
                    new ControllerHandler($controller, $method_name)
                )->handle($request);

            default:
                return $this->make_error_response(500, 'server_error', 'Internal server error.', $request);
        }
    }

    // -------------------------------------------------------------------------
    // Middleware
    // -------------------------------------------------------------------------

    private function resolve_middlewares(array $classes): array
    {
        $instances = [];

        foreach ($classes as $class) {
            if (is_a($class, BaseMiddleware::class, true)) {
                // Italix middleware — resolve services via depends_on()
                $needed = $class::depends_on('process');
                $services = [];
                foreach ($needed as $prop => $key) {
                    $services[$prop] = $this->registry->get($key);
                }
                $instances[] = new $class($services);
            } else {
                // Third-party PSR-15 middleware — instantiate without injection
                $instances[] = new $class();
            }
        }

        return $instances;
    }

    // -------------------------------------------------------------------------
    // Route caching (Fix 1)
    // -------------------------------------------------------------------------

    private function build_dispatcher(): Dispatcher
    {
        $route_callback = $this->config['routes'] ?? function(RouteCollector $r) {};

        if (!empty($this->config['cache.disabled'])) {
            return \FastRoute\simpleDispatcher($route_callback);
        }

        $host      = $this->config['host']     ?? 'default';
        $base_dir  = $this->config['base_dir'] ?? sys_get_temp_dir();

        // Both paths are configurable, and the defaults are only defaults.
        // Before they were, the engine assumed the application kept its routes
        // at `src/sites/{host}/routes.php`; an application that did not simply
        // failed `is_file()`, the invalidation never fired, and a stale
        // dispatcher was served forever — silently, which is the worst way for
        // a cache to be wrong.
        $cache_dir = $this->config['cache.dir'] ?? ($base_dir . '/data/' . $host);

        if (!is_dir($cache_dir)) {
            mkdir($cache_dir, 0755, true);
        }

        $cache_file  = $cache_dir . '/routes.cache';
        $routes_file = $this->config['routes.file']
            ?? ($base_dir . '/src/sites/' . $host . '/routes.php');

        // Invalidate the cache when the route definitions are newer than it.
        if (is_file($cache_file) && is_file($routes_file)
            && filemtime($routes_file) > filemtime($cache_file)
        ) {
            @unlink($cache_file);
        }

        return \FastRoute\cachedDispatcher($route_callback, [
            'cacheFile' => $cache_file,
        ]);
    }

    // -------------------------------------------------------------------------
    // Typed exception handler map (Fix 3)
    // -------------------------------------------------------------------------

    private function handle_exception(
        \Throwable $e,
        ServerRequestInterface $request,
        ?ServiceRegistry $registry
    ): ResponseInterface {
        $handlers = $this->config['error_handlers'] ?? [];

        foreach ($handlers as $exception_class => $handler) {
            if ($e instanceof $exception_class) {
                return $handler($e, $request, $registry);
            }
        }

        return $this->make_error_response(500, 'server_error', 'Internal server error.', $request);
    }

    // -------------------------------------------------------------------------
    // Output-buffer-aware, chunked response emitter (Fix 4)
    // -------------------------------------------------------------------------

    private function emit_response(ResponseInterface $response): void
    {
        // Drain any open output buffers so headers can still be sent
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            http_response_code($response->getStatusCode());
            foreach ($response->getHeaders() as $name => $values) {
                foreach ($values as $value) {
                    header(sprintf('%s: %s', $name, $value), false);
                }
            }
        }

        // Stream body in 8 KB chunks — safe for large responses
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        while (!$body->eof()) {
            echo $body->read(8192);
        }
    }

    private function make_error_response(
        int $code,
        string $error_code,
        string $message,
        ?ServerRequestInterface $request = null
    ): ResponseInterface {
        $wants_json = $request !== null
            && strpos($request->getHeaderLine('Accept'), 'application/json') !== false;

        $response = Responses::make($code);

        if ($wants_json) {
            $body = (string) json_encode(['error' => ['code' => $error_code, 'message' => $message]]);
            $response->getBody()->write($body);
            return $response->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write($code . ' ' . $message);
        return $response;
    }

    // -------------------------------------------------------------------------
    // Service registry
    // -------------------------------------------------------------------------

    private function build_container(): ServiceRegistry
    {
        $safe_config = $this->config;
        unset(
            $safe_config['di'],
            $safe_config['routes'],
            $safe_config['error_handlers'],
            $safe_config['cache.disabled'],
            $safe_config['middleware'],
            $safe_config['middleware.global']
        );

        $factories = ['config' => $safe_config];

        if (isset($this->config['di']) && is_array($this->config['di'])) {
            $factories = array_merge($factories, $this->config['di']);
        }

        return new ServiceRegistry($factories);
    }

    // -------------------------------------------------------------------------
    // Legacy
    // -------------------------------------------------------------------------

    private function legacy_mapping_lookup(string $action_name): string
    {
        // TODO: Require your legacy mapping.php file here and return the mapped class name
        return $action_name;
    }
}
