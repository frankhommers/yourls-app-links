<?php
/*
Plugin Name: App Links for YOURLS
Description: Readable app links with configurable routes, native YOURLS statistics and API support.
Version: 0.8.4
Author: App Links contributors
*/
if (!defined('YOURLS_ABSPATH')) exit;
require_once __DIR__ . '/Router.php';
require_once __DIR__ . '/Stats.php';
require_once __DIR__ . '/Routes.php';
require_once __DIR__ . '/Apps.php';
require_once __DIR__ . '/Api.php';
require_once __DIR__ . '/Native.php';
require_once __DIR__ . '/Auto.php';
yourls_add_filter('shunt_check_IP_flood','hmm_open_auto_flood');

yourls_add_action('pre_load_template', 'hmm_open_route');
yourls_add_action('plugins_loaded', 'hmm_open_register');
yourls_add_filter('keyword_is_reserved', 'hmm_open_reserved');
yourls_add_action('redirect_shorturl', 'hmm_open_short_redirect');
yourls_add_filter('api_actions', 'hmm_open_api_actions');
yourls_add_filter('shunt_add_new_link', 'hmm_open_native_add');
yourls_add_filter('shunt_edit_link', 'hmm_open_native_edit');
yourls_add_filter('edit_link', 'hmm_open_after_edit');

function hmm_open_reserved($reserved, $keyword) {
    if ($reserved) return true;
    foreach (hmm_open_roots() as $root) {
        if ($keyword === $root || strpos($keyword, $root . '/') === 0) return true;
    }
    return false;
}

function hmm_open_register() {
    yourls_register_plugin_page('open-links', 'App Links', 'hmm_open_admin');
}

function hmm_open_router(): \HmmOpen\Router {
    return new \HmmOpen\Router(hmm_open_apps());
}

function hmm_open_escape($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function hmm_open_base(): string {
    return rtrim(YOURLS_SITE, '/') . '/' . hmm_open_routes()['primary'] . '/';
}

function hmm_open_link(string $uri): string {
    $route = hmm_open_router()->simplify($uri);
    $config = hmm_open_routes();
    if ($config['prefer_direct'] && strpos($route, '_/') !== 0) {
        [$app, $tail] = explode('/', $route, 2);
        if (isset($config['direct'][$app])) {
            return rtrim(YOURLS_SITE, '/') . '/' . $config['direct'][$app] . '/' . $tail;
        }
    }
    // The generic marker is internal only; public URLs never repeat it.
    if (strpos($route, '_/') === 0) $route = substr($route, 2);
    return hmm_open_base() . $route;
}

function hmm_open_match(string $request): ?array {
    $base = rtrim((string)parse_url(YOURLS_SITE, PHP_URL_PATH), '/');
    $path = explode('?', $request, 2)[0];
    $config = hmm_open_routes();
    foreach (hmm_open_roots() as $root) {
        $prefix = $base . '/' . $root;
        if ($path !== $prefix && strpos($path, $prefix . '/') !== 0) continue;
        if (yourls_keyword_is_taken($root) || yourls_is_page($root)) return null;
        $route = $path === $prefix ? '' : substr($request, strlen($prefix) + 1);
        $app = array_search($root, $config['direct'], true);
        if ($app !== false) return ['root' => $root, 'route' => $app . '/' . $route];
        // Removed public double-prefix syntax. The router's _/ remains internal.
        if (strpos($route, '_/') === 0) return ['root' => $root, 'route' => '!removed-double-prefix'];
        // Both /_/obsidian://... and /_/obsidian%3A%2F%2F... are generic.
        if (preg_match('/^[a-z][a-z0-9+.-]*(?::|%3a)/i', $route)) $route = '_/' . $route;
        return ['root' => $root, 'route' => $route];
    }
    return null;
}

function hmm_open_short_redirect($args) {
    [$url, $keyword] = $args;
    try {
        $uri=hmm_open_target($url);
        if ($uri===null) return;
        hmm_open_render('_/'.rawurlencode($uri), (string)$keyword);
    } catch (InvalidArgumentException $e) {
        if (in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),array_column(hmm_open_apps(),'scheme'),true)) hmm_open_render('!invalid');
        // Never pass a malformed local app URL on to a second redirect/count.
        if (strpos($url,rtrim(YOURLS_SITE,'/').'/')===0 && hmm_open_match(substr($url,strlen(rtrim(YOURLS_SITE,'/'))))!==null) hmm_open_render('!invalid');
    }
}

function hmm_open_input(array $source, string $key): string {
    return isset($source[$key]) && is_string($source[$key]) ? $source[$key] : '';
}

function hmm_open_route() {
    // YOURLS sanitizes its request and removes queries. Read the original URI instead.
    $match = hmm_open_match($_SERVER['REQUEST_URI'] ?? '');
    if ($match === null) return;
    hmm_open_render($match['route']);
}

function hmm_open_render(string $route, ?string $sourceKeyword = null) {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD'); http_response_code(405); exit;
    }
    $nonce = base64_encode(random_bytes(18));
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    header("Content-Security-Policy: default-src 'none'; script-src 'nonce-$nonce'; style-src 'self' 'nonce-$nonce'; img-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
    $target = $error = $shortUrl = '';
    try {
        if ($route==='') throw new InvalidArgumentException('This app link is not registered.');
        $target=hmm_open_router()->resolve($route);
        $keyword=$sourceKeyword;
        try {
            $keyword=$keyword ?? hmm_open_owner($target);
            if ($keyword===null && $method==='GET') $keyword=hmm_open_auto_create($target);
        } catch (Exception $e) { yourls_do_action('hmm_open_stats_error',$e); }
        if ($keyword!==null) {
            $shortUrl=yourls_link($keyword);
            if ($method==='GET') hmm_open_count($keyword);
        }
    } catch (InvalidArgumentException $e) {
        http_response_code(404); $target=''; $error='This app link is unavailable. Create and manage links in the YOURLS administration.';
    } catch (Exception $e) {
        http_response_code(503); $target=''; $error='This app link is temporarily unavailable.';
        yourls_do_action('hmm_open_stats_error',$e);
    }
    if ($method==='HEAD') exit;
    require __DIR__ . '/page.php';
    exit;
}

function hmm_open_admin() {
    $message = $error = '';
    $submittedApps = null;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        yourls_verify_nonce('hmm-open-settings');
        try {
            if (hmm_open_input($_POST, 'operation') === 'routes') {
                $config = hmm_open_validate_routes([
                    'gateways' => array_values(array_filter(array_map('trim', explode(',', hmm_open_input($_POST, 'gateways'))), static fn($s) => $s !== '')),
                    'primary' => trim(hmm_open_input($_POST, 'primary')),
                    'direct' => hmm_open_routes()['direct'], 'prefer_direct' => hmm_open_routes()['prefer_direct'],
                ]);
                foreach (array_merge($config['gateways'], array_values($config['direct'])) as $root) {
                    if (yourls_keyword_is_taken($root) || yourls_is_page($root)) throw new InvalidArgumentException('The route ' . $root . ' is already used by a short link or page.');
                }
                yourls_update_option('hmm_open_routes', $config);
                $message = 'URL routes saved.';
            } elseif (hmm_open_input($_POST, 'operation') === 'templates') {
                $submittedApps = is_array($_POST['apps'] ?? null) ? $_POST['apps'] : [];
                hmm_open_save_templates($submittedApps, hmm_open_input($_POST, 'prefer_direct') === '1');
                $submittedApps = null;
                $message = 'App registrations saved.';
            } elseif (hmm_open_input($_POST, 'operation') === 'auto') {
                yourls_update_option('hmm_open_auto_create',hmm_open_input($_POST,'auto_create')==='1');
                $message='Automatic creation settings saved.';
            } elseif (hmm_open_input($_POST, 'operation') === 'owners') {
                hmm_open_save_owners(is_array($_POST['owners']??null)?$_POST['owners']:[]);
                $message='Linked short URLs saved.';
            }
        } catch (\InvalidArgumentException | \JsonException $e) {
            $error = $e->getMessage();
        }
    }
    echo '<h2>App Links</h2><p>Paste an app URI into the normal YOURLS URL field or use the authenticated shorturl API. YOURLS creates and manages the short link; its stored destination is the readable app URL.</p>';
    echo '<p>Short-link visits count once against that keyword. Readable URL visits count once against their linked short URL, if available. Automatic creation is optional and off by default. HEAD requests do not count.</p>';
    foreach (hmm_open_roots() as $root) {
        if (yourls_keyword_is_taken($root) || yourls_is_page($root)) {
            echo '<p><strong>A short link or page already uses the name ' . hmm_open_escape($root) . '. Move it before enabling this route.</strong></p>';
        }
    }
    if ($message) echo '<p role="status">' . hmm_open_escape($message) . '</p>';
    if ($error) echo '<p role="alert">' . hmm_open_escape($error) . '</p>';
    hmm_open_auto_form();
    hmm_open_routes_form();
    hmm_open_templates_form($submittedApps);
    echo '<p>API at <code>yourls-api.php</code>: <code>open-convert</code> with <code>uri</code>, <code>open-resolve</code> with <code>url</code>, and <code>open-apps</code>. Use standard YOURLS authentication. These actions use the saved transformations and do not create short links or count visits.</p>';
    hmm_open_owner_form();
}
