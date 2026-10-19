"""Integration tests against php -S 127.0.0.1:18974 tests/server.php."""
import json, urllib.request, urllib.parse, urllib.error
BASE='http://127.0.0.1:18974/short/'
def req(url,values=None,method=None):
    data=urllib.parse.urlencode(values).encode() if values is not None else None
    try:r=urllib.request.urlopen(urllib.request.Request(url,data=data,method=method))
    except urllib.error.HTTPError as e:r=e
    return r.status,r.headers,r.read().decode()
def request(path,values=None,method=None):return req(BASE+path,values,method)
def state():return json.loads(req('http://127.0.0.1:18974/__test/state')[2])
def configure(values):return req('http://127.0.0.1:18974/__test/admin',values)
def api(action,**values):return request('yourls-api.php',dict(signature='test-signature',action=action,**values))
req('http://127.0.0.1:18974/__test/reset',{},'POST')
before=state()
for path in ['_/','open/','_/javascript:alert(1)']:
    status,headers,body=request(path)
    assert status==404 and '<form' not in body and 'id="launch"' not in body,path
    assert 'no-store' in headers['Cache-Control']
assert request('_/',{'uri':'spotify:track:abc'})[0]==405
assert request('_/',method='DELETE')[0]==405
assert state()==before
assert request('ordinary')[2]=='YOURLS passthrough'
# Two legacy rows for one destination are ambiguous until the administrator chooses.
assert request('obsidian/MijnVault/MijnNotitie')[0]==200
assert 'id="short-url"' not in request('obsidian/MijnVault/MijnNotitie')[2]
assert request('inbox')[0]==200
assert state()['rows']['inbox']['clicks']==1
status,_,html=configure({'operation':'owners'})
assert status==403
status,_,html=req('http://127.0.0.1:18974/__test/admin')
assert 'Create a YOURLS short link' not in html and 'Open the link converter' not in html
import re
owner_id=re.search(r'name="owners\[([a-f0-9]+)\]"',html)[1]
status,_,html=configure({'operation':'owners','nonce':'test-nonce',f'owners[{owner_id}]':'inbox'})
assert 'Linked short URLs saved' in html
for path in ['obsidian/MijnVault/MijnNotitie','_/obsidian/MijnVault/MijnNotitie','open/obsidian/MijnVault/MijnNotitie']:
    assert request(path)[0]==200
assert state()['rows']['inbox']['clicks']==4
assert request('legacy')[0]==200
assert state()['rows']['legacy']['clicks']==1
before=state()
for path in ['inbox','obsidian/MijnVault/MijnNotitie']:
    assert request(path,method='HEAD')[0]==200
assert state()==before
# Native authenticated creation creates one row and returns both addresses.
uri='obsidian://open?vault=Team%20A&file=Folder%2FCaf%C3%A9.md'
assert request('yourls-api.php',{'action':'shorturl','url':uri})[0]==403
before=state()
status,_,body=api('shorturl',url=uri,keyword='team')
data=json.loads(body);assert status==200 and data['shorturl']==BASE+'team'
assert data['appurl']==BASE+'obsidian/Team%20A/Folder/Caf%C3%A9.md'
assert len(state()['rows'])==len(before['rows'])+1
assert state()['rows']['team']['clicks']==0
assert request('team')[0]==200
assert request('obsidian/Team%20A/Folder/Caf%C3%A9.md')[0]==200
assert request('_/obsidian://open?vault=Team%20A&file=Folder%2FCaf%C3%A9.md')[0]==200
assert state()['rows']['team']['clicks']==3
assert len([l for l in state()['logs'] if l['keyword']=='team'])==3
assert request('yourls-api.php',{'action':'open-convert','uri':uri})[0]==403
before=state()
status,_,body=api('open-convert',uri='spotify:track:prospective')
assert status==200 and json.loads(body)['url']==BASE+'spotify/track/prospective'
status,_,html=request('spotify/track/prospective');assert status==200 and 'id="short-url"' not in html
assert state()==before
# Settings and custom app registration still work, with automatic direct routes.
_,_,body=api('open-apps');data=json.loads(body)
fields={'operation':'templates','nonce':'test-nonce','prefer_direct':'1'}
apps=data['apps']+[{'key':'example','label':'Example','path_template':'{workspace}/{item...}','uri_template':'example://open?space={workspace}&item={item}','constraints':{}}]
for i,a in enumerate(apps):
    for k,v in {'key':a['key'],'label':a['label'],'path':a['path_template'],'uri':a['uri_template'],'constraints':json.dumps(a['constraints']),'direct':''}.items():fields[f'apps[{i}][{k}]']=v
assert configure({k:v for k,v in fields.items() if k!='nonce'})[0]==403
assert 'App registrations saved.' in configure(fields)[2]
assert api('shorturl',url='example://open?space=Team&item=Folder%2FNote',keyword='custom')[0]==200
assert request('example/Team/Folder/Note')[0]==200
before=state()['options']
assert 'unique name' in configure(fields|{'apps[3][direct]':'_'})[2]
assert state()['options']==before
assert 'already used' in configure(fields|{'apps[3][direct]':'inbox'})[2]
assert state()['options']==before
settings={'operation':'routes','nonce':'test-nonce','gateways':'go, _','primary':'go'}
assert 'URL routes saved.' in configure(settings)[2]
assert state()['options']['hmm_open_routes']['direct']==before['hmm_open_routes']['direct']
assert request('go/example/Team/Folder/Note')[0]==200
# Special URI options and fragments survive the encoded link without public editing.
uri='obsidian://open?vault=Team&file=Note&newpane=true#Heading'
status,_,body=api('shorturl',url=uri,keyword='fragment');assert status==200
path=json.loads(body)['appurl'][len(BASE):]
status,_,body=request(path);assert status==200 and 'newpane=true#Heading' in body
assert 'location.hash' not in body
print('HTTP checks passed: no public creation; native authenticated shorturl; shared exact counts; HEAD exclusion; explicit legacy owner; automatic routes; app/gateway settings; Unicode and lossless fragments.')

# Linked short URL is displayed; copying has no HTTP action.
status,_,html=request('team')
assert f'value="{BASE}team"' in html and 'id="copy-short-url"' in html
assert 'navigator.clipboard.writeText' in html
before=state()
assert configure({'operation':'auto','auto_create':'1'})[0]==403
assert state()==before
assert 'Automatic creation settings saved.' in configure({'operation':'auto','auto_create':'1','nonce':'test-nonce'})[2]
before=state()
assert request('spotify/track/auto123',method='HEAD')[0]==200
assert state()==before
status,_,html=request('spotify/track/auto123')
assert status==200 and 'id="short-url"' in html
added=set(state()['rows'])-set(before['rows']);assert len(added)==1
key=added.pop();assert state()['rows'][key]['clicks']==1
status,_,html=request('spotify/track/auto123')
assert f'value="{BASE}{key}"' in html
assert state()['rows'][key]['clicks']==2 and len(state()['rows'])==len(before['rows'])+1
assert request('yourls-api.php',{'action':'shorturl','url':'spotify:track:blocked'})[0]==403
assert 'Automatic creation settings saved.' in configure({'operation':'auto','nonce':'test-nonce'})[2]
before=state();assert request('spotify/track/noauto')[0]==200;assert state()==before
print('Short URL display and opt-in auto creation checks passed: default off, auth/nonce, HEAD, first click, reuse, no public API bypass.')
