<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal front-controller router: method + path matched against an array
 * route table. Handlers are either callables or absolute paths to PHP files
 * (the admin/webhook page scripts), which are simply included.
 *
 * Supports exact paths and simple `{param}` segments; matched params are
 * merged into $_GET so file-based handlers can read them uniformly.
 */
final class Router
{
    /** @var array<string, array<string, callable|string>> */
    private array $static = [];

    /** @var array<int, array{method:string, regex:string, params:string[], handler:callable|string}> */
    private array $dynamic = [];

    public function add(string $method, string $path, callable|string $handler): void
    {
        $method = strtoupper($method);
        $path = self::normalize($path);

        if (!str_contains($path, '{')) {
            $this->static[$method][$path] = $handler;
            return;
        }

        // preg_quote escapes { } — restore them so the {param} pattern can match.
        $params = [];
        $regex = str_replace(['\{', '\}'], ['{', '}'], preg_quote($path, '#'));
        $regex = preg_replace_callback('/\{(\w+)\}/', function ($m) use (&$params) {
            $params[] = $m[1];
            return '([^/]+)';
        }, $regex);

        $this->dynamic[] = [
            'method'  => $method,
            'regex'   => '#^' . $regex . '$#',
            'params'  => $params,
            'handler' => $handler,
        ];
    }

    public function get(string $path, callable|string $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable|string $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    /** Register the same handler for GET and POST (form pages, webhooks). */
    public function any(string $path, callable|string $handler): void
    {
        $this->add('GET', $path, $handler);
        $this->add('POST', $path, $handler);
    }

    public function dispatch(string $method, string $uri): void
    {
        $method = strtoupper($method);
        $path = self::normalize($uri);

        $handler = $this->static[$method][$path] ?? null;

        if ($handler === null) {
            foreach ($this->dynamic as $route) {
                if ($route['method'] !== $method) {
                    continue;
                }
                if (preg_match($route['regex'], $path, $m)) {
                    array_shift($m);
                    foreach ($route['params'] as $i => $name) {
                        $_GET[$name] = $m[$i] ?? null;
                    }
                    $handler = $route['handler'];
                    break;
                }
            }
        }

        if ($handler === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo "404 — no route for {$method} {$path}";
            return;
        }

        if (is_callable($handler)) {
            $handler();
            return;
        }

        require $handler;
    }

    private static function normalize(string $path): string
    {
        $path = parse_url($path, PHP_URL_PATH) ?: '/';
        $path = '/' . trim($path, '/');
        return $path === '//' ? '/' : $path;
    }
}
