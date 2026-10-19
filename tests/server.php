<?php
// Local integration harness: implements only the YOURLS boundary, never deployed.
define('YOURLS_ABSPATH', __DIR__);
define('YOURLS_SITE', 'http://127.0.0.1:18974/short');
require __DIR__ . '/yourls-stubs.php';
$statePath = sys_get_temp_dir() . '/open-links-test-' . getmypid() . '.json';
if (is_file($statePath)) $state = json_decode(file_get_contents($statePath), true);
register_shutdown_function(function () use ($statePath) {
    global $state;
    file_put_contents($statePath, json_encode($state));
});
require __DIR__ . '/../open-links/plugin.php';
if ($_SERVER['REQUEST_URI'] === '/short/yourls-api.php') {
    // Test-only stand-in for YOURLS authentication and API dispatch.
    if (($_REQUEST['signature'] ?? '') !== 'test-signature') {
        http_response_code(403); echo 'Unauthorized'; exit;
    }
    $actions = ($hooks['api_actions'])(['shorturl'=>fn()=>yourls_add_new_link($_REQUEST['url']??'',$_REQUEST['keyword']??'',$_REQUEST['title']??'')]);
    $callback = $actions[$_REQUEST['action'] ?? ''] ?? null;
    $result = $callback ? $callback() : ['statusCode' => 400];
    http_response_code($result['statusCode']);
    header('Content-Type: application/json');
    unset($result['simple']);
    echo json_encode($result); exit;
}
if ($_SERVER['REQUEST_URI'] === '/__test/reset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $state = ['rows' => [], 'logs' => [], 'errors' => 0, 'nostats' => false];
    yourls_insert_link_in_db(YOURLS_SITE . '/_/obsidian/MijnVault/MijnNotitie', 'inbox', 'Inbox');
    yourls_insert_link_in_db(YOURLS_SITE . '/open/obsidian/MijnVault/MijnNotitie', 'legacy', 'Legacy');
    yourls_insert_link_in_db('https://example.org/', 'ordinary', 'Ordinary');
    yourls_insert_link_in_db(YOURLS_SITE . '/_/javascript:alert(1)', 'invalid', 'Invalid');
    echo 'reset'; exit;
}
if ($_SERVER['REQUEST_URI'] === '/__test/state') {
    header('Content-Type: application/json'); echo json_encode($state); exit;
}
if ($_SERVER['REQUEST_URI'] === '/__test/admin') { hmm_open_admin(); exit; }
$hooks['pre_load_template']();
$keyword = substr(explode('?', $_SERVER['REQUEST_URI'])[0], strlen('/short/'));
if (yourls_keyword_is_taken($keyword)) {
    yourls_do_action('redirect_shorturl', yourls_get_keyword_longurl($keyword), $keyword);
    // If not intercepted by Open Links, YOURLS performs its ordinary redirect.
    yourls_update_clicks($keyword);
    yourls_log_redirect($keyword);
}
echo 'YOURLS passthrough';
