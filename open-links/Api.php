<?php
if (!defined('YOURLS_ABSPATH')) exit;

function hmm_open_api_actions(array $actions): array {
    $actions['open-convert'] = 'hmm_open_api_convert';
    $actions['open-resolve'] = 'hmm_open_api_resolve';
    $actions['open-apps'] = 'hmm_open_api_apps';
    return $actions;
}

function hmm_open_api_error(string $message): array {
    return ['status' => 'fail', 'statusCode' => 400, 'errorCode' => '400', 'code' => 'error:open-links', 'message' => $message, 'simple' => $message];
}

function hmm_open_api_convert(): array {
    if (yourls_is_valid_user()!==true) return hmm_open_native_error('Authentication required.',403);
    try {
        $uri = trim(hmm_open_input($_REQUEST, 'uri'));
        $url = hmm_open_link($uri);
        return ['status' => 'success', 'statusCode' => 200, 'uri' => $uri, 'url' => $url, 'simple' => $url];
    } catch (\InvalidArgumentException $e) {
        return hmm_open_api_error($e->getMessage());
    }
}

function hmm_open_api_resolve(): array {
    if (yourls_is_valid_user()!==true) return hmm_open_native_error('Authentication required.',403);
    try {
        $url = trim(hmm_open_input($_REQUEST, 'url'));
        $base = rtrim(YOURLS_SITE, '/');
        if (strlen($url) > 65536 || strpos($url, $base . '/') !== 0) {
            throw new \InvalidArgumentException('Provide a complete App Links URL from this YOURLS installation.');
        }
        // No network requests or app launch: only reverse the configured route.
        $request = rtrim((string)parse_url(YOURLS_SITE, PHP_URL_PATH), '/') . substr($url, strlen($base));
        [$request, $fragment] = array_pad(explode('#', $request, 2), 2, null);
        $match = hmm_open_match($request);
        if ($match === null || $match['route'] === '') throw new \InvalidArgumentException('This URL is not an active app link.');
        $router = hmm_open_router();
        $uri = $router->resolve($match['route']);
        if ($fragment !== null && strpos($match['route'], '_/') === 0 && strpos($uri, '#') === false) {
            $uri = $router->validateUri($uri . '#' . $fragment);
        }
        return ['status' => 'success', 'statusCode' => 200, 'url' => $url, 'uri' => $uri, 'simple' => $uri];
    } catch (\InvalidArgumentException $e) {
        return hmm_open_api_error($e->getMessage());
    }
}

function hmm_open_api_apps(): array {
    if (yourls_is_valid_user()!==true) return hmm_open_native_error('Authentication required.',403);
    $apps = [];
    foreach (hmm_open_apps() as $key => $definition) {
        $apps[] = ['key' => $key, 'label' => $definition['label'], 'scheme' => $definition['scheme'], 'pattern' => $definition['pattern'] ?? null, 'path_template' => $definition['path_template'] ?? null, 'uri_template' => $definition['uri_template'] ?? null, 'constraints' => $definition['constraints'] ?? null];
    }
    return ['status' => 'success', 'statusCode' => 200, 'apps' => $apps, 'routes' => hmm_open_routes(), 'gateway' => hmm_open_base(), 'simple' => implode(', ', array_column($apps, 'key'))];
}
