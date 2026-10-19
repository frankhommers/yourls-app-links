<?php
declare(strict_types=1);

namespace HmmOpen;

use InvalidArgumentException;
require_once __DIR__ . "/Templates.php";

final class Router
{
    private array $apps;
    public function __construct(?array $apps = null)
    {
        $this->apps = $apps ?? self::defaults();
    }

    public static function defaults(): array
    {
        return Templates::compile(Templates::presets());
    }

    public function validateUri(string $uri): string
    {
        if (strlen($uri) > 16384 || preg_match('/[\x00-\x20\x7f]/', $uri) || !preg_match('/^([a-z][a-z0-9+.-]*):/i', $uri, $m)) {
            throw new InvalidArgumentException('Invalid app link. Encode spaces as %20.');
        }
        $scheme = strtolower($m[1]);
        if (in_array($scheme, ['javascript', 'data', 'vbscript', 'file', 'http', 'https', 'about', 'chrome'], true) || !in_array($scheme, array_column($this->apps, 'scheme'), true)) {
            throw new InvalidArgumentException('This app scheme is not enabled.');
        }
        return $uri;
    }

    public function resolve(string $route): string
    {
        if (strpos($route, '_/') === 0) {
            $uri = substr($route, 2);
            // Accept both a pasted URI and the API's fully encoded, lossless form.
            return $this->validateUri(preg_match('/^[a-z][a-z0-9+.-]*:/i', $uri) ? $uri : rawurldecode($uri));
        }
        if (strpbrk($route, '?#') !== false) throw new InvalidArgumentException('Create links with additional options through the YOURLS URL field or authenticated API.');
        $parts = explode('/', $route, 2);
        if (!isset($this->apps[$parts[0]], $parts[1])) throw new InvalidArgumentException('Unknown app pattern.');
        $uri = ($this->apps[$parts[0]]['decode'])($parts[1], []);
        return $this->validateUri($uri);
    }

    public function simplify(string $uri): string
    {
        $this->validateUri($uri);
        foreach ($this->apps as $name => $app) {
            try {
                $path = ($app['encode'])($uri, []);
                if ($path !== null) {
                    $route = $name . '/' . $path;
                    $this->resolve($route);
                    return $route;
                }
            } catch (InvalidArgumentException $e) {
                // Unrepresentable paths (including dot segments) use the lossless fallback.
            }
        }
        return '_/' . rawurlencode($uri);
    }

}
