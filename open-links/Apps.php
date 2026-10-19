<?php
if (!defined('YOURLS_ABSPATH')) exit;

function hmm_open_app_templates(): array {
    return yourls_get_option('hmm_open_templates', \HmmOpen\Templates::presets());
}

function hmm_open_save_templates(array $rows, ?bool $preferDirect = null): void {
    $definitions=[];
    $routes=hmm_open_routes();
    $direct=$routes['direct'];
    foreach ($rows as $row) {
        if (!is_array($row)) throw new InvalidArgumentException('Invalid app settings.');
        $key=trim(hmm_open_input($row,'key'));
        if (hmm_open_input($row,'remove')==='1') { unset($direct[$key]); continue; }
        if ($key==='' && trim(hmm_open_input($row,'label'))==='' && trim(hmm_open_input($row,'path'))==='' && trim(hmm_open_input($row,'uri'))==='' && trim(hmm_open_input($row,'direct'))==='') continue;
        if (isset($definitions[$key])) throw new InvalidArgumentException('Each app must have a unique key.');
        if (array_key_exists('direct', $row)) {
            unset($direct[$key]);
            $slug=trim(hmm_open_input($row,'direct'));
            $direct[$key]=$slug!==''?$slug:$key;
        }
        $rules=trim(hmm_open_input($row,'constraints'));
        $constraints=$rules==='' ? [] : json_decode($rules,true,16,JSON_THROW_ON_ERROR);
        if (!is_array($constraints)) throw new InvalidArgumentException('Constraints must be a JSON object.');
        $definitions[$key]=['label'=>trim(hmm_open_input($row,'label')), 'path'=>trim(hmm_open_input($row,'path')), 'uri'=>trim(hmm_open_input($row,'uri')), 'constraints'=>$constraints];
    }
    $apps=\HmmOpen\Templates::compile($definitions);
    foreach (hmm_open_app_templates() as $key=>$unused) {
        if (!isset($definitions[$key])) unset($direct[$key]);
    }
    foreach ($definitions as $key=>$definition) if (empty($direct[$key])) $direct[$key]=$key;
    $routes['direct']=$direct;
    if ($preferDirect!==null) $routes['prefer_direct']=$preferDirect;
    $routes=hmm_open_validate_routes($routes, yourls_apply_filter('hmm_open_apps', $apps));
    foreach ($direct as $slug) {
        if (yourls_keyword_is_taken($slug) || yourls_is_page($slug)) throw new InvalidArgumentException('The route '.$slug.' is already used by a short link or page.');
    }
    // Validate both sets before saving either; retain the existing storage format.
    yourls_update_option('hmm_open_templates',$definitions);
    yourls_update_option('hmm_open_routes',$routes);
}

function hmm_open_templates_form(?array $submitted=null): void {
    echo '<h3>App registrations</h3><p>Edit the readable path and app URI for each app. The same templates are used by YOURLS link creation, routes and API. No PHP is needed.</p>';
    echo '<p><code>{name}</code> is one value. <code>{name...}</code> preserves path separators. Use a trailing optional path such as <code>[/{file...}]</code> and the matching optional URI parameter <code>[&amp;file={file}]</code>. Values are URL-encoded automatically.</p>';
    echo '<p>The app key goes after a gateway: <code>/_/obsidian/…</code>. Set a direct route below to enable a URL such as <code>/obsidian/…</code>. Leave it blank to use the app key. Changing templates or removing apps can break existing links.</p>';
    echo '<form method="post">'; yourls_nonce_field('hmm-open-settings');
    echo '<input type="hidden" name="operation" value="templates">';
    $rows=[];
    foreach (hmm_open_app_templates() as $key=>$d) $rows[]=['key'=>$key, 'direct'=>hmm_open_routes()['direct'][$key]??'']+$d+['constraints'=>[]];
    if ($submitted!==null) $rows=$submitted;
    $rows[]=['key'=>'','label'=>'','path'=>'','uri'=>'','constraints'=>[]];
    foreach (array_values($rows) as $i=>$d) {
        if (!is_array($d)) continue;
        echo '<fieldset style="margin:1em 0;padding:1em;border:1px solid #ccc"><legend>'.hmm_open_escape(($d['label']??'')!==''?$d['label']:'Add an app').'</legend>';
        foreach (['key'=>'App key','label'=>'Display name','direct'=>'Direct route (defaults to app key)','path'=>'Path template','uri'=>'URI template'] as $field=>$label) {
            echo '<p><label>'.$label.' <input style="max-width:100%;box-sizing:border-box" size="'.(in_array($field,['path','uri'],true)?75:30).'" name="apps['.$i.']['.$field.']" value="'.hmm_open_escape(is_string($d[$field]??null)?$d[$field]:'').'"></label></p>';
        }
        $constraints=$d['constraints']??[];
        $value=is_string($constraints)?$constraints:json_encode((object)$constraints,JSON_UNESCAPED_SLASHES);
        echo '<details><summary>Value constraints (optional)</summary><p>JSON object. Values may be an allowed-values list, <code>alphanumeric</code>, or <code>file_path</code> (rejects legacy @project paths). Example: <code>{&quot;type&quot;:[&quot;track&quot;,&quot;album&quot;],&quot;id&quot;:&quot;alphanumeric&quot;}</code>.</p><textarea style="max-width:100%;box-sizing:border-box" rows="3" cols="85" name="apps['.$i.'][constraints]">'.hmm_open_escape($value).'</textarea></details>';
        if (($d['key']??'')!=='') echo '<p><label><input type="checkbox" name="apps['.$i.'][remove]" value="1"'.(($d['remove']??'')==='1'?' checked':'').'> Remove this app</label></p>';
        echo '</fieldset>';
    }
    echo '<p><label><input type="checkbox" name="prefer_direct" value="1"'.((($submitted!==null ? hmm_open_input($_POST, 'prefer_direct')==='1' : hmm_open_routes()['prefer_direct']))?' checked':'').'> Prefer direct app routes when creating readable links</label></p>';
    echo '<button type="submit">Save app registrations</button></form>';
}
