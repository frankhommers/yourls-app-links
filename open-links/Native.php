<?php
if (!defined('YOURLS_ABSPATH')) exit;

/** Recognize a registered app URI or one of this installation's app URLs. */
function hmm_open_target(string $url): ?string {
    $router=hmm_open_router();
    if (preg_match('/^([a-z][a-z0-9+.-]*):/i',$url,$m) && in_array(strtolower($m[1]),array_column(hmm_open_apps(),'scheme'),true)) return $router->validateUri($url);
    $base=rtrim(YOURLS_SITE,'/');
    if (strpos($url,$base.'/')!==0) return null;
    $request=rtrim((string)parse_url(YOURLS_SITE,PHP_URL_PATH),'/').substr($url,strlen($base));
    [$request,$fragment]=array_pad(explode('#',$request,2),2,null);
    $match=hmm_open_match($request);
    if ($match===null) return null;
    if ($match['route']==='') throw new InvalidArgumentException('Provide an app destination, not a gateway URL.');
    $uri=$router->resolve($match['route']);
    if ($fragment!==null && strpos($match['route'],'_/')===0 && !str_contains($uri,'#')) $uri=$router->validateUri($uri.'#'.$fragment);
    return $uri;
}

function hmm_open_identity(string $uri): string {
    $router=hmm_open_router();
    return hash('sha256',$router->resolve($router->simplify($uri)));
}

function hmm_open_native_error(string $message, int $code=400): array {
    return ['status'=>'fail','code'=>'error:open-links','message'=>$message,'errorCode'=>(string)$code,'statusCode'=>$code];
}

function hmm_open_native_add($pre, $url, $keyword='', $title='') {
    static $inside=false;
    if ($inside || $pre!==yourls_shunt_default()) return $pre;
    try {
        $uri=hmm_open_target(trim((string)$url));
        if ($uri===null) return $pre;
        if (yourls_is_valid_user()!==true && !(hmm_open_auto_enabled() && hmm_open_auto_context()===$uri)) return hmm_open_native_error('Sign in to YOURLS to create app links.',403);
        $matches=hmm_open_candidates($uri);
        if (count($matches)===1) hmm_open_register_owner($uri,(string)array_key_first($matches));
        $destination=hmm_open_link($uri);
        if ($matches && !yourls_allow_duplicate_longurls()) {
            $owner=hmm_open_owner($uri);
            if ($owner===null) return hmm_open_native_error('Select a linked short URL in App Links before reusing this destination.',409);
            $destination=$matches[$owner];
        }
        $inside=true;
        try { $result=yourls_add_new_link($destination,$keyword,$title ?: 'App Links',(int)($_REQUEST['rowid']??1)); }
        finally { $inside=false; }
        if (isset($result['url']['keyword'])) {
            hmm_open_register_owner($uri,(string)$result['url']['keyword']);
            $result['appurl']=hmm_open_link($uri);
        }
        return $result;
    } catch (InvalidArgumentException $e) { return hmm_open_native_error($e->getMessage()); }
}

function hmm_open_native_edit($pre, $unused, $url, $keyword, $newkeyword='', $title='') {
    static $inside=false;
    if ($inside || $pre!==yourls_shunt_default()) return $pre;
    try {
        $uri=hmm_open_target(trim((string)$url));
        if ($uri===null) return $pre;
        if (yourls_is_valid_user()!==true) return hmm_open_native_error('Sign in to YOURLS to edit app links.',403);
        $inside=true;
        try { return yourls_edit_link(hmm_open_link($uri),$keyword,$newkeyword,$title); }
        finally { $inside=false; }
    } catch (InvalidArgumentException $e) { return hmm_open_native_error($e->getMessage()); }
}

function hmm_open_after_edit($result, $url, $keyword, $newkeyword, $title) {
    if (($result['status']??'')!=='success' || yourls_is_valid_user()!==true) return $result;
    try {
        $uri=hmm_open_target($url);
        if ($uri===null) return $result;
        $id=hmm_open_identity($uri); $owners=yourls_get_option('hmm_open_owners',[]);
        if (($owners[$id]??null)===$keyword) {
            $owners[$id]=$newkeyword; yourls_update_option('hmm_open_owners',$owners);
        } else hmm_open_register_owner($uri,$newkeyword);
    } catch (InvalidArgumentException $e) { /* Ordinary edits remain native. */ }
    return $result;
}

function hmm_open_register_owner(string $uri,string $keyword): void {
    $id=hmm_open_identity($uri); $owners=yourls_get_option('hmm_open_owners',[]);
    // First authenticated creation owns the readable URL. Never replace an owner
    // merely because a duplicate was created, or because an old owner was deleted.
    if (!isset($owners[$id])) {
        $matches=hmm_open_candidates($uri);
        if (count($matches)===1 && isset($matches[$keyword])) {
            $owners[$id]=$keyword; yourls_update_option('hmm_open_owners',$owners);
        }
    }
}

function hmm_open_saved_rows(): array {
    $table=YOURLS_DB_TABLE_URL;
    return yourls_get_db()->fetchAll("SELECT keyword,url FROM `$table`");
}

function hmm_open_candidates(string $uri): array {
    $id=hmm_open_identity($uri); $matches=[];
    foreach (hmm_open_saved_rows() as $row) {
        $row=(array)$row;
        try {
            $target=hmm_open_target($row['url']);
            if ($target!==null && hmm_open_identity($target)===$id) $matches[(string)$row['keyword']]=$row['url'];
        } catch (InvalidArgumentException $e) { /* A legacy invalid destination is not an owner. */ }
    }
    return $matches;
}

function hmm_open_owner(string $uri): ?string {
    $owners=yourls_get_option('hmm_open_owners',[]); $id=hmm_open_identity($uri);
    if (isset($owners[$id])) {
        $keyword=(string)$owners[$id]; $row=yourls_get_keyword_infos($keyword,false);
        if (!$row) return null;
        try { $target=hmm_open_target($row['url']); return $target!==null && hmm_open_identity($target)===$id ? $keyword : null; }
        catch (InvalidArgumentException $e) { return null; }
    }
    // Compatibility for existing links: a single matching row is unambiguous.
    // Public reads never create rows or persist ownership.
    $matches=hmm_open_candidates($uri);
    return count($matches)===1 ? (string)array_key_first($matches) : null;
}

function hmm_open_owner_form(): void {
    $groups=[];
    foreach (hmm_open_saved_rows() as $row) {
        $row=(array)$row;
        try {
            $uri=hmm_open_target($row['url']); if ($uri===null) continue;
            $id=hmm_open_identity($uri); $groups[$id]['uri']=$uri; $groups[$id]['keywords'][]=(string)$row['keyword'];
        } catch (InvalidArgumentException $e) { continue; }
    }
    if (!$groups) return;
    echo '<h3>Linked short URLs</h3><p>Each readable URL counts visits against one existing YOURLS keyword. Duplicate short links keep their own counters. If the linked short URL is deleted or its destination changes, select another short URL.</p><form method="post">';
    yourls_nonce_field('hmm-open-settings');
    echo '<input type="hidden" name="operation" value="owners">';
    foreach ($groups as $id=>$group) {
        $owner=hmm_open_owner($group['uri']);
        echo '<p><label><code>'.hmm_open_escape(hmm_open_link($group['uri'])).'</code><br><select name="owners['.$id.']"><option value="">Select a short URL</option>';
        foreach ($group['keywords'] as $key) echo '<option value="'.hmm_open_escape($key).'"'.($owner===$key?' selected':'').'>'.hmm_open_escape($key).'</option>';
        echo '</select></label></p>';
    }
    echo '<button type="submit">Save linked short URLs</button></form>';
}

function hmm_open_save_owners(array $selected): void {
    $owners=yourls_get_option('hmm_open_owners',[]);
    foreach ($selected as $id=>$keyword) {
        if ($keyword==='') continue;
        if (!is_string($keyword)) throw new InvalidArgumentException('Invalid linked short URL.');
        $row=yourls_get_keyword_infos($keyword,false);
        $uri=$row ? hmm_open_target($row['url']) : null;
        if ($uri===null || hmm_open_identity($uri)!==$id) throw new InvalidArgumentException('The linked short URL must be an existing short link to this app destination.');
        $owners[$id]=$keyword;
    }
    yourls_update_option('hmm_open_owners',$owners);
}
