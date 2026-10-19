<?php
if (!defined('YOURLS_ABSPATH')) exit;

function hmm_open_apps(): array {
    return yourls_apply_filter('hmm_open_apps', \HmmOpen\Templates::compile(hmm_open_app_templates()));
}

function hmm_open_routes(): array {
    $config = yourls_get_option('hmm_open_routes', [
        'gateways' => ['open', '_'], 'primary' => '_',
        'direct' => [], 'prefer_direct' => true,
    ]);
    foreach (hmm_open_apps() as $key=>$app) {
        if (empty($config['direct'][$key])) $config['direct'][$key]=$key;
    }
    return $config;
}

function hmm_open_validate_routes(array $config, ?array $apps = null): array {
    $gateways = $config['gateways'] ?? [];
    $direct = $config['direct'] ?? [];
    if (!is_array($gateways) || !$gateways || !is_array($direct)) {
        throw new InvalidArgumentException('Configure at least one gateway.');
    }
    $seen = [];
    foreach (array_merge(array_values($gateways), array_values($direct)) as $slug) {
        if (!is_string($slug) || !preg_match('/^[a-z0-9_-]{1,40}$/D', $slug) || in_array($slug, ['admin', 'user', 'includes', 'css', 'js', 'images'], true)) {
            throw new InvalidArgumentException('Use one path segment containing lowercase letters, numbers, _ or -. System directories are not allowed.');
        }
        if (isset($seen[$slug])) throw new InvalidArgumentException('Each route must have a unique name.');
        $seen[$slug] = true;
    }
    if (!in_array($config['primary'] ?? null, $gateways, true)) {
        throw new InvalidArgumentException('The preferred gateway must be included in the gateway list.');
    }
    $apps = $apps ?? hmm_open_apps();
    foreach ($direct as $app => $slug) {
        if (!isset($apps[$app])) throw new InvalidArgumentException('Unknown app for a direct route.');
    }
    return ['gateways' => array_values($gateways), 'primary' => $config['primary'], 'direct' => $direct, 'prefer_direct' => !empty($config['prefer_direct'])];
}

function hmm_open_roots(): array {
    $config = hmm_open_routes();
    return array_merge($config['gateways'], array_values($config['direct']));
}

function hmm_open_routes_form(): void {
    $config = hmm_open_routes();
    echo '<h3>Gateways</h3><p>Gateways support both readable and raw app links. A direct app route removes the gateway prefix: <code>/obsidian/MyVault/Inbox.md</code>.</p>';
    echo '<form method="post">';
    yourls_nonce_field('hmm-open-settings');
    echo '<input type="hidden" name="operation" value="routes">';
    echo '<p><label>Gateways (comma-separated) <input name="gateways" required value="' . hmm_open_escape(implode(', ', $config['gateways'])) . '"></label></p>';
    echo '<p><label>Preferred gateway for new links <input name="primary" required value="' . hmm_open_escape($config['primary']) . '"></label></p>';
    echo '<p>Removing or renaming a route breaks existing links that use it. Keep old gateways in the list to preserve those links.</p>';
    echo '<button type="submit">Save routes</button></form>';
}
