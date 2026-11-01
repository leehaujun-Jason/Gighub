"""Offline lifecycle validators only: no runtime, OS process observation or database."""
import ast
import json
import pathlib
import io
from unittest.mock import patch

path = pathlib.Path(__file__).with_name('browse_lifecycle.py')
if not path.is_file():
    raise SystemExit('FAIL: browsing lifecycle recorder missing')
scope = {'__name__': 'offline_lifecycle', '__file__': str(path)}
exec(compile(ast.parse(path.read_text(encoding='utf-8-sig')), str(path), 'exec'), scope)
checks = 0

def check(value):
    global checks
    if not value: raise AssertionError('Offline lifecycle assertion failed')
    checks += 1

run = 'accept-0123456789abcdef'
manifest = {'run': run, 'users': [1], 'skills': [2], 'jobs': [3]}
raw = json.dumps(manifest).encode()
start = scope['startup_payload'](run, 123, 'C:/project/tests/http-acceptance/server.py', raw, 'b' * 32, 1000)
check(start['phase'] == 'attempted' and start['run'] == run)
check(start['fixture_sha256'] == scope['hashlib'].sha256(raw).hexdigest())
failed = scope['terminal_payload'](start, False, [{'pid': 124, 'returncode': 1}], 2000)
check(failed['phase'] == 'failed_before_ready' and failed['ready_reached'] is False)
normal = scope['terminal_payload'](start, True, [{'pid': 124, 'returncode': 0}], 2000)
check(normal['phase'] == 'stopped' and normal['attempt'] == start['attempt'])
uncertain = scope['terminal_payload'](start, False, [{'pid': 124, 'returncode': None}], 2000)
check(uncertain['children'][0]['returncode'] is None)
sealed = scope['seal'](start, 'e' * 64)
check(scope['hmac'].compare_digest(sealed['mac'], scope['hmac'].new(('e' * 64).encode(), sealed['payload_json'].encode(), 'sha256').hexdigest()))
check(json.loads(sealed['payload_json']) == start)
for value in [{}, {**manifest, 'run': 'other'}, {**manifest, 'jobs': [3, 3]}, {**manifest, 'users': []}, {**manifest, 'skills': ['2']}]:
    try:
        scope['startup_payload'](run, 123, 'server.py', json.dumps(value).encode(), 'b' * 32, 1000)
    except ValueError:
        check(True)
    else:
        check(False)
server = pathlib.Path(__file__).with_name('server.py').read_text()
check('lifecycle.begin()' in server and 'lifecycle.finish()' in server)
check('children.append(preflight)' in server and 'children.append(child)' in server)
check('timeout=30' in server and 'preflight.kill' not in server)
recorder = path.read_text()
check("open('x'" in recorder and 'browse-cleanup-claimed.json' in recorder)
check('server-ready.json' in recorder and 'browse-startup.json' in recorder)

# Exercise the actual recorder against an in-memory filesystem; no process is launched.
files = {}
class MemoryPath:
    def __init__(self, value): self.value = str(value).replace('\\', '/').rstrip('/')
    def __str__(self): return self.value
    def __eq__(self, other): return self.value.lower() == str(other).lower()
    def __truediv__(self, name): return MemoryPath(self.value + '/' + name)
    def with_name(self, name): return MemoryPath(self.value.rsplit('/', 1)[0] + '/' + name)
    def resolve(self, strict=False): return self
    def exists(self): return self.value in files
    def read_text(self): return files[self.value]
    def read_bytes(self): return files[self.value].encode()
    def open(self, mode, encoding=None):
        if mode != 'x' or self.exists(): raise FileExistsError()
        files[self.value] = ''
        name = self.value
        class Writer(io.StringIO):
            def close(self):
                if not self.closed: files[name] = self.getvalue()
                super().close()
        return Writer()

directory = MemoryPath('C:/MariaDB_Recovery/GigHub_Clean_Test/http-acceptance/' + run)
def reset_memory():
    files.clear()
    files[str(directory / 'job-fixtures.json')] = raw.decode()
    files[str(directory / 'stop.key')] = 'e' * 64

with patch.object(scope['pathlib'], 'Path', MemoryPath), patch.object(scope['os'], 'getpid', return_value=123):
    reset_memory()
    lifecycle = scope['BrowseLifecycle'](directory, run, [])
    lifecycle.begin()
    check((directory / 'browse-startup.json').exists() and not (directory / 'server-ready.json').exists())
    lifecycle.finish()
    terminal = json.loads(json.loads(files[str(directory / 'browse-terminal.json')])['payload_json'])
    check(terminal['phase'] == 'failed_before_ready' and terminal['children'] == [])
    try:
        scope['BrowseLifecycle'](directory, run, []).begin()
    except ValueError:
        check(True)
    else:
        check(False)
    reset_memory()
    children = []
    lifecycle = scope['BrowseLifecycle'](directory, run, children)
    lifecycle.begin()
    class Child:
        pid = 124
        def poll(self): return 0
    children.append(Child())
    lifecycle.mark_ready()
    lifecycle.finish()
    terminal = json.loads(json.loads(files[str(directory / 'browse-terminal.json')])['payload_json'])
    check(terminal['phase'] == 'stopped' and terminal['children'] == [{'pid': 124, 'returncode': 0}])
    reset_memory()
    files[str(directory / 'browse-cleanup-claimed.json')] = '{}'
    try:
        scope['BrowseLifecycle'](directory, run, []).begin()
    except ValueError:
        check(True)
    else:
        check(False)
print(f'PASS: {checks} lifecycle offline checks; no runtime or database.')
