<?php
define('YOURLS_ABSPATH',__DIR__);
define('YOURLS_SITE','https://links.example');
require __DIR__.'/yourls-stubs.php';
require __DIR__.'/../open-links/plugin.php';
$n=0;
function check($value) { global $n; $n++; if (!$value) throw new RuntimeException('Check failed: '.$n); }
$uri='obsidian://open?vault=Team&file=Inbox.md';
check(yourls_add_new_link($uri,'inbox')['statusCode']===403);
check($state['rows']===[]);
$state['authenticated']=true;
$r=yourls_add_new_link($uri,'inbox');
check($r['status']==='success');
check($r['url']['url']==='https://links.example/obsidian/Team/Inbox.md');
check(hmm_open_owner($uri)==='inbox');
check(count($state['rows'])===1);
hmm_open_count(hmm_open_owner($uri));check($state['rows']['inbox']['clicks']===1 && count($state['logs'])===1);
check(hmm_open_owner('spotify:track:unknown')===null && count($state['rows'])===1);
$state['allow_duplicates']=true;
yourls_add_new_link($uri,'second');check(hmm_open_owner($uri)==='inbox');
hmm_open_count('second');check($state['rows']['second']['clicks']===1 && $state['rows']['inbox']['clicks']===1);
hmm_open_save_owners([hmm_open_identity($uri)=>'second']);check(hmm_open_owner($uri)==='second');
yourls_edit_link($uri,'second','renamed');check(hmm_open_owner($uri)==='renamed');
unset($state['rows']['renamed']);check(hmm_open_owner($uri)===null);
hmm_open_save_owners([hmm_open_identity($uri)=>'inbox']);check(hmm_open_owner($uri)==='inbox');
yourls_edit_link('https://example.org/','inbox','inbox');check(hmm_open_owner($uri)===null);
// Old duplicates require an explicit choice; public reads do not persist options.
$state=['rows'=>[],'logs'=>[],'errors'=>0];
yourls_insert_link_in_db('https://links.example/_/obsidian/Team/Inbox.md','old');
$before=$state;check(hmm_open_owner($uri)==='old');check($state===$before);
yourls_insert_link_in_db('https://links.example/open/obsidian/Team/Inbox.md','legacy');
$before=$state;check(hmm_open_owner($uri)===null);check($state===$before);
try { hmm_open_save_owners([hmm_open_identity('spotify:track:x')=>'old']);check(false); } catch (InvalidArgumentException $e) {check(true);}
check($state===$before);
check(hmm_open_routes()['direct']['obsidian']==='obsidian');
check(hmm_open_match('/obsidian/Team/Inbox.md')['route']==='obsidian/Team/Inbox.md');
echo "$n native ownership/statistics checks passed.\n";

$state=['rows'=>[],'logs'=>[],'errors'=>0];
check(hmm_open_auto_create('spotify:track:auto')===null);
yourls_update_option('hmm_open_auto_create',true);
$_SERVER['REQUEST_METHOD']='HEAD';check(hmm_open_auto_create('spotify:track:auto')===null);
$_SERVER['REQUEST_METHOD']='GET';$key=hmm_open_auto_create('spotify:track:auto');check($key!==null);
check(hmm_open_auto_create('spotify:track:auto')===$key && count($state['rows'])===1);
check(hmm_open_auto_context()==='');
check(yourls_add_new_link('spotify:track:other')['statusCode']===403);
yourls_insert_link_in_db('https://links.example/spotify/track/ambiguous','one');
yourls_insert_link_in_db('https://links.example/_/spotify/track/ambiguous','two');
check(hmm_open_auto_create('spotify:track:ambiguous')===null && count($state['rows'])===3);
unset($state['rows'][$key]);check(hmm_open_auto_create('spotify:track:auto')===null);
echo "Automatic creation authorization, reuse and ambiguity checks passed.\n";
