<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Deliberately small router — no external routing library dependency.
 * Supports static paths and simple {param} segments, GET/POST only for
 * Phase 1 (PUT/DELETE via method-override are added when the REST API
 * phase lands).
 */
final class Router
{
    /** @var array<string, array<int, array{pattern:string, handler:callable, keys:string[]}>> */
    private array $routes = ['GET' => [], 'POST' => []];

    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    private function add(string $method, string $path, callable $handler): void
    {
        $keys = [];
        $pattern = preg_replace_callback('#\{([a-zA-Z_]+)\}#', function ($m) use (&$keys) {
            $keys[] = $m[1];
            return '([^/]+)';
        }, $path);

        $this->routes[$method][] = [
            'pattern' => '#^' . $pattern . '$#',
            'handler' => $handler,
            'keys' => $keys,
        ];
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }

        foreach ($this->routes[$method] ?? [] as $route) {
            if (preg_match($route['pattern'], $path, $matches)) {
                array_shift($matches);
                $params = array_combine($route['keys'], $matches) ?: [];
                call_user_func($route['handler'], $params);
                return;
            }
        }

        http_response_code(404);
        echo '404 — Not found';
    }
}
