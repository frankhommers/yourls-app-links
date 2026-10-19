<?php
declare(strict_types=1);
define('YOURLS_ABSPATH', __DIR__);
define('YOURLS_SITE', 'https://hmm.rs/short');
require __DIR__ . '/yourls-stubs.php';
require __DIR__ . '/../open-links/plugin.php';
$state['authenticated']=true;
$checks = 0;
function expect($condition): void {
    global $checks;
    if (!$condition) throw new RuntimeException('API check failed: ' . ($checks + 1));
    $checks++;
}
$actions = hmm_open_api_actions(['shorturl' => 'native']);
expect($actions['shorturl'] === 'native' && $actions['open-convert'] === 'hmm_open_api_convert');
$_REQUEST = ['uri' => 'obsidian://open?vault=v&file=Folder%2FCaf%C3%A9.md'];
$converted = hmm_open_api_convert();
expect($converted['url'] === 'https://hmm.rs/short/obsidian/v/Folder/Caf%C3%A9.md');
$_REQUEST = ['url' => $converted['url']];
expect(hmm_open_api_resolve()['uri'] === 'obsidian://open?vault=v&file=Folder%2FCaf%C3%A9.md');
foreach (['', 'javascript:alert(1)', 'unknown:foo', ['nested']] as $bad) {
    $_REQUEST = ['uri' => $bad]; expect(hmm_open_api_convert()['statusCode'] === 400);
}
foreach (['https://evil.example/short/_/spotify/track/abc', 'https://hmm.rs.evil.example/short/_/spotify/track/abc', 'https://hmm.rs/short/_/', 'https://hmm.rs/short/open/_/spotify:track:abc', 'https://hmm.rs/short/admin', ['nested']] as $bad) {
    $_REQUEST = ['url' => $bad]; expect(hmm_open_api_resolve()['statusCode'] === 400);
}
$_REQUEST = ['url' => 'https://hmm.rs/short/_/obsidian://open?vault=v&file=n#heading'];
expect(hmm_open_api_resolve()['uri'] === 'obsidian://open?vault=v&file=n#heading');
yourls_update_option('hmm_open_routes', ['gateways' => ['go'], 'primary' => 'go', 'direct' => ['obsidian' => 'notes'], 'prefer_direct' => true]);
yourls_update_option('hmm_open_aliases', ['obsidian' => ['brain' => 'My Vault']]);
$_REQUEST = ['uri' => 'obsidian://open?vault=My%20Vault&file=Inbox'];
expect(hmm_open_api_convert()['url'] === 'https://hmm.rs/short/notes/My%20Vault/Inbox');
$_REQUEST = ['url' => 'https://hmm.rs/short/notes/My%20Vault/Inbox'];
expect(hmm_open_api_resolve()['uri'] === 'obsidian://open?vault=My%20Vault&file=Inbox');
$_REQUEST = ['url' => 'https://hmm.rs/short/notes/brain/Inbox'];
expect(hmm_open_api_resolve()['uri'] === 'obsidian://open?vault=brain&file=Inbox');
$_REQUEST = ['uri' => 'obsidian://advanced-uri?vault=v&line=2#Heading'];
$converted = hmm_open_api_convert();
expect(strpos($converted['url'], '/go/obsidian%3A') !== false);
$_REQUEST = ['url' => $converted['url']];
expect(hmm_open_api_resolve()['uri'] === 'obsidian://advanced-uri?vault=v&line=2#Heading');
$apps = hmm_open_api_apps();
expect(count($apps['apps']) === 3 && $apps['routes']['primary'] === 'go');
expect($apps['gateway'] === 'https://hmm.rs/short/go/');
expect(!isset($apps['apps'][0]['encode']));
expect($state['rows'] === [] && $state['logs'] === []);
echo "$checks API checks passed.\n";
