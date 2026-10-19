<?php
if (!defined('YOURLS_ABSPATH')) exit;

function hmm_open_auto_enabled(): bool { return (bool)yourls_get_option('hmm_open_auto_create',false); }

/** Request-local authorization for one internal creation, never for the public API. */
function hmm_open_auto_context(?string $set=null): string {
    static $uri='';
    if ($set!==null) $uri=$set;
    return $uri;
}

function hmm_open_auto_create(string $uri): ?string {
    if (!hmm_open_auto_enabled() || ($_SERVER['REQUEST_METHOD']??'GET')!=='GET') return null;
    $db=yourls_get_db(); $lock='open-links:'.substr(hash('sha256',YOURLS_SITE.':'.hmm_open_identity($uri)),0,48);
    if (!(int)$db->fetchValue('SELECT GET_LOCK(:name, 2)',['name'=>$lock])) return null;
    try {
        $keyword=hmm_open_owner($uri);
        if ($keyword!==null) return $keyword;
        // Ambiguous links and previously assigned/deleted links need an explicit choice.
        if (hmm_open_candidates($uri) || isset(yourls_get_option('hmm_open_owners',[])[hmm_open_identity($uri)])) return null;
        hmm_open_auto_context($uri);
        try { $result=yourls_add_new_link($uri,'','App Links'); }
        finally { hmm_open_auto_context(''); }
        return isset($result['url']['keyword']) ? hmm_open_owner($uri) : null;
    } finally { $db->fetchValue('SELECT RELEASE_LOCK(:name)',['name'=>$lock]); }
}

/** Keep native flood limits, but don't let its exit() prevent opening the app. */
function hmm_open_auto_flood($pre,string $ip='') {
    if (hmm_open_auto_context()==='' || $pre!==yourls_shunt_default()) return $pre;
    $delay=yourls_get_flood_delay();
    if ($delay<=0 || in_array($ip,yourls_get_flood_ip_whitelist(),true) || (yourls_is_private() && yourls_is_valid_user()===true)) return true;
    $table=YOURLS_DB_TABLE_URL;
    $last=yourls_get_db()->fetchValue("SELECT timestamp FROM `$table` WHERE ip=:ip ORDER BY timestamp DESC LIMIT 1",['ip'=>$ip]);
    if ($last && time()-strtotime($last)<=$delay) throw new RuntimeException('Automatic short-link creation was rate limited.');
    return true;
}

function hmm_open_auto_form(): void {
    echo '<h3>Unregistered app URLs</h3><p>Valid app URLs always open. Without a linked short URL, visits are not counted unless automatic creation is enabled.</p><form method="post">';
    yourls_nonce_field('hmm-open-settings');
    echo '<input type="hidden" name="operation" value="auto"><p><label><input type="checkbox" name="auto_create" value="1"'.(hmm_open_auto_enabled()?' checked':'').'> Automatically create a short link for unregistered app URLs</label></p><p>Off by default. When enabled, public GET visits may create a native YOURLS short link and count the first visit. HEAD requests never create links. Existing ambiguous links are not duplicated.</p><button type="submit">Save automatic creation</button></form>';
}
