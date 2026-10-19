<?php
declare(strict_types=1);
namespace HmmOpen;
use InvalidArgumentException;

/** Restricted, bidirectional templates. No executable code or user-supplied regex. */
final class Templates
{
    public static function presets(): array
    {
        return [
            'obsidian' => ['label'=>'Obsidian', 'path'=>'{vault}[/{file...}]', 'uri'=>'obsidian://open?vault={vault}[&file={file}]', 'constraints'=>[]],
            'vscode' => ['label'=>'VS Code', 'path'=>'{path...}', 'uri'=>'vscode://file/{path...}', 'constraints'=>['path'=>'file_path']],
            'spotify' => ['label'=>'Spotify', 'path'=>'{type}/{id}', 'uri'=>'spotify:{type}:{id}', 'constraints'=>['type'=>['track','album','artist','playlist','episode','show'], 'id'=>'alphanumeric']],
        ];
    }

    private static function tokens(string $template): array
    {
        if ($template === '' || strlen($template)>2048 || preg_match('/[\x00-\x20\x7f]/', $template)) throw new InvalidArgumentException('Templates must be non-empty, without spaces, and at most 2048 bytes.');
        $tokens = preg_split('/(\[[^\[\]]*\]|\{[a-z][a-z0-9_]*(?:\.\.\.)?\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $out=[];
        foreach ($tokens as $token) {
            if ($token[0]==='[') {
                $children=self::tokens(substr($token,1,-1));
                if (count(array_filter($children, fn($t)=>$t['kind']==='value'))!==1 || count(array_filter($children, fn($t)=>$t['kind']==='optional'))) throw new InvalidArgumentException('Each optional block must contain exactly one placeholder; nesting is not supported.');
                $out[]=['kind'=>'optional','children'=>$children];
            } elseif ($token[0]==='{') {
                $out[]=['kind'=>'value','name'=>str_replace('...','',substr($token,1,-1)), 'multi'=>str_contains($token,'...')];
            } else {
                if (strpbrk($token,'{}[]')!==false) throw new InvalidArgumentException('Invalid template syntax. Use {name}, {name...}, or [/{name}].');
                $out[]=['kind'=>'literal','text'=>$token];
            }
        }
        return $out;
    }

    private static function fields(array $tokens, bool $optional=false): array
    {
        $fields=[];
        foreach ($tokens as $t) {
            $new=$t['kind']==='optional' ? self::fields($t['children'],true) : ($t['kind']==='value' ? [$t['name']=>['optional'=>$optional,'multi'=>$t['multi']]] : []);
            if (array_intersect_key($fields,$new)) throw new InvalidArgumentException('Use each placeholder only once in each template.');
            $fields += $new;
        }
        return $fields;
    }

    public static function compile(array $definitions): array
    {
        if (!$definitions || count($definitions)>50) throw new InvalidArgumentException('Configure between 1 and 50 apps.');
        $apps=[];
        foreach ($definitions as $key=>$d) {
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_-]{0,39}$/D',$key) || !is_array($d)) throw new InvalidArgumentException('App keys must start with a letter and contain only lowercase letters, numbers, underscores or hyphens.');
            foreach (['label','path','uri'] as $field) if (!isset($d[$field]) || !is_string($d[$field]) || trim($d[$field])==='') throw new InvalidArgumentException('Each app needs a label, path template and URI template.');
            if (strlen($d['label'])>100) throw new InvalidArgumentException('App labels must be at most 100 bytes.');
            if (!preg_match('/^([a-z][a-z0-9+.-]*):/',$d['uri'],$m) || in_array($m[1],['javascript','data','vbscript','file','http','https','about','chrome'],true)) throw new InvalidArgumentException('Use an allowed, fixed app scheme in the URI template.');
            if (strpbrk($d['path'],'?#\\')!==false || str_starts_with($d['path'],'/') || str_ends_with($d['path'],'/')) throw new InvalidArgumentException('Path templates are relative paths without queries or fragments.');
            // Keep path matching unambiguous: whole segments, one optional tail,
            // and a multi-segment placeholder only at the very end.
            $segment='(?:[A-Za-z0-9_~-]+|\{[a-z][a-z0-9_]*\})';
            $tail='\{[a-z][a-z0-9_]*\.\.\.\}';
            if (!preg_match('#^(?:'.$segment.'/)*(?:'.$segment.'|'.$tail.')(?:\[/(?:'.$segment.'|'.$tail.')\])?$#D', $d['path'])) {
                throw new InvalidArgumentException('Use whole path segments, with {name...} only at the end and at most one optional trailing segment.');
            }
            if (preg_match('/\{[a-z][a-z0-9_]*\.\.\.\}.*\[/', $d['path'])) throw new InvalidArgumentException('A multi-segment placeholder must be last.');
            if (preg_match('/\}\{/', $d['uri'])) throw new InvalidArgumentException('Separate URI placeholders with a delimiter.');
            $queryParts=explode('?', $d['uri'],2);
            if (str_contains($queryParts[0],'[') || str_contains($queryParts[0],']')) throw new InvalidArgumentException('Optional URI blocks are supported only for query parameters.');
            if (isset($queryParts[1])) {
                if (str_contains($queryParts[1],'...') || str_contains($queryParts[1],'#') || str_contains($queryParts[1],'?')) throw new InvalidArgumentException('Use scalar placeholders in URI query values, without fragments.');
                $query=preg_replace('/\[(&[a-zA-Z0-9_-]+=\{[a-z][a-z0-9_]*\})\]/','$1',$queryParts[1]);
                $keys=[];
                foreach (explode('&',$query) as $pair) {
                    if (!preg_match('/^([a-zA-Z0-9_-]+)=(?:\{[a-z][a-z0-9_]*\}|[a-zA-Z0-9%_.~-]*)$/D',$pair,$qm) || isset($keys[$qm[1]])) throw new InvalidArgumentException('Query parameters need unique fixed keys and one placeholder or literal value. Optional parameters use [&key={name}].');
                    $keys[$qm[1]]=true;
                }
            }
            $path=self::tokens($d['path']); $uri=self::tokens($d['uri']);
            $pf=self::fields($path); $uf=self::fields($uri);
            foreach (array_keys($pf + $uf) as $name) if (strlen($name)>32) throw new InvalidArgumentException('Placeholder names must be at most 32 characters.');
            if (count($pf)>16) throw new InvalidArgumentException('Use at most 16 placeholders per app.');
            if (!$pf || array_diff_key($pf,$uf) || array_diff_key($uf,$pf)) throw new InvalidArgumentException('Both templates must contain the same placeholders.');
            foreach ($pf as $name=>$f) if ($f['optional']!==$uf[$name]['optional']) throw new InvalidArgumentException('Optional placeholders must be optional in both templates.');
            $constraints=$d['constraints']??[];
            if (!is_array($constraints) || array_diff_key($constraints,$pf)) throw new InvalidArgumentException('Constraints must refer to existing placeholders.');
            foreach ($constraints as $rule) {
                if (is_array($rule)) {
                    if (!$rule || count($rule)>100) throw new InvalidArgumentException('Allowed values must be a non-empty list of at most 100 strings.');
                    foreach ($rule as $v) if (!is_string($v) || $v==='') throw new InvalidArgumentException('Allowed values must be non-empty strings.');
                } elseif (!in_array($rule,['alphanumeric','file_path'],true)) throw new InvalidArgumentException('Constraint must be an allowed-values list, alphanumeric, or file_path.');
            }
            $apps[$key]=['label'=>$d['label'],'scheme'=>$m[1],'pattern'=>$key.'/'.$d['path'],'path_template'=>$d['path'],'uri_template'=>$d['uri'],'constraints'=>$constraints,
                'decode'=>static function(string $input) use($path,$uri,$constraints): string {
                    $values=self::match($path,$input,false);
                    if ($values===null) throw new InvalidArgumentException('The link does not match this app’s path template.');
                    self::check($values,$constraints);
                    return self::render($uri,$values);
                },
                'encode'=>static function(string $input) use($path,$uri,$constraints): ?string {
                    $values=self::match($uri,$input,true);
                    if ($values===null) return null;
                    self::check($values,$constraints);
                    $result=self::render($path,$values);
                    if (self::match($path,$result,false)!=$values) return null;
                    return $result;
                }];
        }
        return $apps;
    }

    private static function check(array $values,array $constraints): void
    {
        foreach ($constraints as $key=>$rule) {
            if (!isset($values[$key])) continue;
            $v=$values[$key];
            if (is_array($rule) ? !in_array($v,$rule,true) : ($rule==='alphanumeric' ? !preg_match('/^[A-Za-z0-9]+$/D',$v) : str_starts_with($v,'@'))) throw new InvalidArgumentException('Invalid value for '.$key.'.');
        }
    }

    private static function regex(array $tokens, bool $uri): string
    {
        $regex='';
        foreach ($tokens as $t) {
            if ($t['kind']==='literal') $regex.=preg_quote($t['text'],'~');
            elseif ($t['kind']==='optional') $regex.='(?:'.self::regex($t['children'],$uri).')?';
            else $regex.='(?P<'.$t['name'].'>'.($t['multi'] ? '[^?#&]+?' : ($uri ? '[^/:?&#=]+?' : '[^/]+?')).')';
        }
        return $regex;
    }

    private static function match(array $tokens,string $input,bool $uri): ?array
    {
        // Query order is immaterial, but duplicate or additional parameters must never be dropped.
        $template=self::renderSkeleton($tokens);
        if ($uri && str_contains($template,'?') && str_contains($input,'?')) {
            [$head,$query]=explode('?',$input,2);
            if (str_contains($query,'#')) return null;
            $pairs=[];
            foreach (explode('&',$query) as $pair) {
                $kv=explode('=',$pair,2); $key=urldecode($kv[0]);
                if (count($kv)!==2 || isset($pairs[$key])) return null;
                $pairs[$key]=$kv[1];
            }
            $order=[];
            foreach (explode('&',explode('?',$template,2)[1]) as $pair) $order[]=explode('=',$pair,2)[0];
            if (array_diff(array_keys($pairs),$order)) return null;
            $sorted=[];
            foreach ($order as $key) if (isset($pairs[$key])) $sorted[]=$key.'='.str_replace('+','%20',$pairs[$key]);
            $input=$head.'?'.implode('&',$sorted);
        }
        if (!preg_match('~^'.self::regex($tokens,$uri).'$~D',$input,$m,PREG_UNMATCHED_AS_NULL)) return null;
        $values=[];
        foreach (self::fields($tokens) as $name=>$field) {
            if ($m[$name]===null) continue;
            $raw=$m[$name];
            if (preg_match('/%(?![0-9a-f]{2})/i',$raw)) throw new InvalidArgumentException('Invalid URL encoding.');
            // URI scalar values may contain a path encoded as %2F. Path segments may not.
            if (!$uri) self::safePath($raw,$field['multi']);
            $value=rawurldecode($raw);
            if (preg_match('/[\x00-\x1f\x7f]/',$value)) throw new InvalidArgumentException('Invalid control character.');
            $values[$name]=$value;
        }
        return $values;
    }

    private static function safePath(string $raw,bool $multi): void
    {
        foreach ($multi ? explode('/',$raw) : [$raw] as $part) {
            $v=rawurldecode($part);
            if ($v==='' || $v==='.' || $v==='..' || strpbrk($v,'/\\')!==false) throw new InvalidArgumentException('Invalid path segment.');
        }
    }

    private static function renderSkeleton(array $tokens): string
    {
        $s=''; foreach ($tokens as $t) $s.=$t['kind']==='literal'?$t['text']:($t['kind']==='optional'?self::renderSkeleton($t['children']):'VALUE'); return $s;
    }

    private static function render(array $tokens,array $values): string
    {
        $out='';
        foreach ($tokens as $t) {
            if ($t['kind']==='literal') $out.=$t['text'];
            elseif ($t['kind']==='optional') {
                if (!array_diff_key(self::fields($t['children']),$values)) $out.=self::render($t['children'],$values);
            } else {
                $value=$values[$t['name']]??null;
                if ($value===null) throw new InvalidArgumentException('Missing template value.');
                $out.=$t['multi'] ? str_replace('%3A',':',implode('/',array_map('rawurlencode',explode('/',$value)))) : rawurlencode($value);
            }
        }
        return $out;
    }
}
