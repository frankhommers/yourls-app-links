#!/usr/bin/env python3
"""Package only distributable plugin files; never include hosting/deployment data."""
from pathlib import Path
import argparse
import hashlib
import re
import zipfile

root = Path(__file__).resolve().parents[1]
args = argparse.ArgumentParser()
args.add_argument('--tag', help='Require this release tag to match plugin.php')
options = args.parse_args()
version = re.search(r'^Version: (\d+\.\d+\.\d+)$', (root / 'open-links/plugin.php').read_text(), re.M)[1]
if options.tag and options.tag != f'v{version}':
    raise SystemExit(f'Tag {options.tag} does not match plugin version {version}')
out = root / 'dist'
out.mkdir(exist_ok=True)
archive = out / f'yourls-app-links-{version}.zip'
files = [(p, f'open-links/{p.name}') for p in sorted((root / 'open-links').glob('*.php'))]
files.append((root / 'README.md', 'open-links/README.md'))
with zipfile.ZipFile(archive, 'w', compression=zipfile.ZIP_DEFLATED) as z:
    for source, name in files:
        entry = zipfile.ZipInfo(name, date_time=(2020, 1, 1, 0, 0, 0))
        entry.compress_type = zipfile.ZIP_DEFLATED
        entry.external_attr = 0o100644 << 16
        z.writestr(entry, source.read_bytes())
digest = hashlib.sha256(archive.read_bytes()).hexdigest()
archive.with_suffix('.zip.sha256').write_text(f'{digest}  {archive.name}\n')
print(archive)
