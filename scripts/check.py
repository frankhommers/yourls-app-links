#!/usr/bin/env python3
"""Run PHP checks and the local HTTP integration harness, without deployment access."""
from pathlib import Path
import subprocess
import tempfile
import time
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
for path in sorted((ROOT / 'open-links').glob('*.php')):
    subprocess.run(['php', '-l', str(path)], cwd=ROOT, check=True)
for suite in ['run.php', 'templates.php', 'stats.php', 'api.php']:
    subprocess.run(['php', str(ROOT / 'tests' / suite)], cwd=ROOT, check=True)
with tempfile.TemporaryFile(mode='w+') as log:
    server = subprocess.Popen(['php', '-S', '127.0.0.1:18974', 'tests/server.php'], cwd=ROOT, stdout=log, stderr=log)
    try:
        for attempt in range(50):
            if server.poll() is not None:
                raise RuntimeError('PHP test server exited before becoming ready')
            try:
                with urllib.request.urlopen('http://127.0.0.1:18974/__test/state', timeout=1):
                    break
            except OSError:
                time.sleep(.1)
        else:
            raise RuntimeError('PHP test server did not become ready')
        subprocess.run(['python3', 'tests/check_http.py'], cwd=ROOT, check=True)
    except BaseException:
        log.seek(0)
        print(log.read())
        raise
    finally:
        server.terminate()
        try:
            server.wait(timeout=5)
        except subprocess.TimeoutExpired:
            server.kill()
            server.wait()
        (Path(tempfile.gettempdir()) / f'open-links-test-{server.pid}.json').unlink(missing_ok=True)
