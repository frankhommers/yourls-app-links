<?php
define('YOURLS_DB_TABLE_URL', 'test_url');
// Deliberately small in-memory YOURLS API stand-in, shared by the test suites.
$hooks = [];
$state = ['rows' => [], 'logs' => [], 'errors' => 0, 'nostats' => false];
function yourls_add_action($name, $callback) { global $hooks; $hooks[$name] = $callback; }
function yourls_add_filter($name, $callback) { global $hooks; $hooks[$name] = $callback; }
function yourls_apply_filter($name, $value, ...$args) { global $hooks; return isset($hooks[$name]) ? ($hooks[$name])($value,...$args) : $value; }
function yourls_get_option($name, $default) { global $state; return $state['options'][$name] ?? $default; }
function yourls_update_option($name, $value) { global $state; $state['options'][$name] = $value; }
function yourls_nonce_field($action) { echo '<input type="hidden" name="nonce" value="test-nonce">'; }
function yourls_verify_nonce($action) {
    if (($_POST['nonce'] ?? '') !== 'test-nonce') { http_response_code(403); exit; }
}
function yourls_do_action($name, ...$args) {
    global $hooks, $state;
    if ($name === 'hmm_open_stats_error') $state['errors']++;
    if (isset($hooks[$name])) ($hooks[$name])($args);
}
function yourls_keyword_is_taken($keyword) { global $state; return isset($state['rows'][$keyword]); }
function yourls_is_page($keyword) { global $state; return in_array($keyword, $state['pages'] ?? [], true); }
function yourls_keyword_is_reserved($keyword) { return hmm_open_reserved(false, $keyword) || yourls_is_page($keyword); }
function yourls_get_keyword_longurl($keyword) { global $state; return $state['rows'][$keyword]['url'] ?? false; }
function yourls_get_keyword_infos($keyword, $cache = true) { global $state; return $state['rows'][$keyword] ?? false; }
function yourls_long_url_exists($url) {
    global $state;
    if (!empty($state['db_failure'])) throw new RuntimeException('Test database unavailable.');
    foreach ($state['rows'] as $keyword => $row) if ($row['url'] === $url) return (object)($row + ['keyword' => $keyword]);
    return null;
}
function yourls_insert_link_in_db($url, $keyword, $title = '') {
    global $state;
    if (isset($state['rows'][$keyword])) throw new RuntimeException('Duplicate key.');
    $state['rows'][$keyword] = ['url' => $url, 'title' => $title, 'clicks' => 0];
    if (!empty($state['race'])) { unset($state['race']); throw new RuntimeException('Simulated concurrent insert winner.'); }
    return true;
}
function yourls_update_clicks($keyword) { global $state; $state['rows'][$keyword]['clicks']++; }
function yourls_log_redirect($keyword) {
    global $state;
    if (!$state['nostats']) $state['logs'][] = ['keyword' => $keyword, 'referrer' => $_SERVER['HTTP_REFERER'] ?? ''];
}
function yourls_shunt_default() { return null; }
function yourls_is_valid_user() { global $state; return !empty($state['authenticated']) || ($_REQUEST['signature']??'')==='test-signature'; }
function yourls_get_db() {
    return new class {
        function fetchValue($sql,$params=[]) { return 1; }
        function fetchAll($sql) { global $state; return array_map(fn($key,$row)=>['keyword'=>$key,'url'=>$row['url']],array_keys($state['rows']),array_values($state['rows'])); }
    };
}
function yourls_add_new_link($url,$keyword='',$title='') {
    $pre=yourls_apply_filter('shunt_add_new_link',null,$url,$keyword,$title);
    if ($pre!==null) return $pre;
    global $state;
    if (empty($state['allow_duplicates']) && ($row=yourls_long_url_exists($url))) return ['status'=>'fail','statusCode'=>409,'url'=>['keyword'=>$row->keyword,'url'=>$url],'shorturl'=>YOURLS_SITE.'/'.$row->keyword];
    $keyword=$keyword?:'short'.count($state['rows']);
    if (yourls_keyword_is_taken($keyword)||yourls_keyword_is_reserved($keyword)) return ['status'=>'fail','statusCode'=>400];
    yourls_insert_link_in_db($url,$keyword,$title);
    return ['status'=>'success','statusCode'=>200,'url'=>['keyword'=>$keyword,'url'=>$url],'shorturl'=>YOURLS_SITE.'/'.$keyword];
}
function yourls_edit_link($url,$keyword,$newkeyword='',$title='') {
    $pre=yourls_apply_filter('shunt_edit_link',null,$keyword,$url,$keyword,$newkeyword,$title);
    if ($pre!==null) return $pre;
    global $state;
    if (!isset($state['rows'][$keyword])) return ['status'=>'fail'];
    $state['rows'][$newkeyword]=$state['rows'][$keyword];$state['rows'][$newkeyword]['url']=$url;
    if ($newkeyword!==$keyword) unset($state['rows'][$keyword]);
    return yourls_apply_filter('edit_link',['status'=>'success'],$url,$keyword,$newkeyword,$title);
}

function yourls_allow_duplicate_longurls() { global $state; return !empty($state['allow_duplicates']); }

function yourls_link($keyword) { return YOURLS_SITE.'/'.$keyword; }
