<?php
declare(strict_types=1);
require_once __DIR__ . '/../open-links/Router.php';
use HmmOpen\Router;

$count = 0;
function same($actual, $expected): void {
    global $count;
    $count++;
    if ($actual !== $expected) throw new RuntimeException("Expected " . var_export($expected, true) . ", got " . var_export($actual, true));
}
function rejects(callable $fn): void {
    global $count;
    $count++;
    try { $fn(); } catch (InvalidArgumentException $e) { return; }
    throw new RuntimeException('Expected rejection.');
}
$router = new Router();
same($router->resolve('obsidian/my-brain/file/path/here.md'), 'obsidian://open?vault=my-brain&file=file%2Fpath%2Fhere.md');
same($router->resolve('obsidian/Mijn%20Vault/Notities/Caf%C3%A9%20%26%20thee.md'), 'obsidian://open?vault=Mijn%20Vault&file=Notities%2FCaf%C3%A9%20%26%20thee.md');
same($router->resolve('obsidian/my-brain'), 'obsidian://open?vault=my-brain');
same($router->resolve('obsidian/v/100%25%20%23%3F%2B.md'), 'obsidian://open?vault=v&file=100%25%20%23%3F%2B.md');
same($router->simplify('obsidian://open?vault=Mijn%20Vault&file=folder%2FNote.md'), 'obsidian/Mijn%20Vault/folder/Note.md');
same($router->resolve('vscode/Users/frank/project/main.ts:42:7'), 'vscode://file/Users/frank/project/main.ts:42:7');
same($router->resolve('vscode/C:/Users/Frank/My%20Project/main.ts:42'), 'vscode://file/C:/Users/Frank/My%20Project/main.ts:42');
same($router->simplify('spotify:track:abc123'), 'spotify/track/abc123');
same($router->resolve('spotify/playlist/abc123'), 'spotify:playlist:abc123');
foreach ([
    'obsidian://open?vault=v&file=Note&newpane=true',
    'obsidian://advanced-uri?vault=v&filepath=Note&line=2',
    'obsidian://open?vault=v&vault=w',
    'obsidian://open?vault=v&file=Note#Heading',
    'obsidian://open?vault=v&file=../Note',
    'obsidian://open?vault=v&file=folder//Note',
    'vscode://file/Users/frank//Note',
    'vscode://vscode.git/clone?url=https%3A%2F%2Fexample.org%2Frepo',
    'spotify:search:hello',
] as $uri) {
    $route = $router->simplify($uri);
    same(strpos($route, '_/'), 0);
    same($router->resolve($route), $uri);
}
same($router->resolve('_/obsidian://open?vault=MijnVault&file=MijnNotitie'), 'obsidian://open?vault=MijnVault&file=MijnNotitie');
// Different users' vault names and paths stay explicit and independent.
same($router->resolve('obsidian/Alice%20Vault/Inbox.md'), 'obsidian://open?vault=Alice%20Vault&file=Inbox.md');
same($router->resolve('obsidian/Bob%20Vault/Inbox.md'), 'obsidian://open?vault=Bob%20Vault&file=Inbox.md');
same($router->simplify('vscode://file/Users/alex/project/src/main.ts:42'), 'vscode/Users/alex/project/src/main.ts:42');
foreach (['_/javascript:alert(1)', '_/data:text/html,test', '_/file:///etc/passwd', '_/https://example.org', '_/unknown:foo', 'obsidian/v/../secret', 'obsidian/v/%2e%2e/secret', 'obsidian/v/%0a', 'obsidian/v/%GG', 'obsidian/v/x?newpane=true', 'spotify/track/', 'unknown/foo', 'vscode/@missing/a'] as $route) {
    rejects(fn() => $router->resolve($route));
}
$apps = Router::defaults();
$apps['example'] = ['label' => 'Example', 'scheme' => 'example', 'decode' => fn($p, $a) => 'example:' . $p, 'encode' => fn($u, $a) => strpos($u, 'example:') === 0 ? substr($u, 8) : null];
$custom = new Router($apps);
same($custom->simplify('example:hello'), 'example/hello');
same($custom->resolve('example/hello'), 'example:hello');
echo "$count router checks passed.\n";
