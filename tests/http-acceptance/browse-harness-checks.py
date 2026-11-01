"""Offline only: parses/loads server definitions without starting main or creating caches."""
import ast
import pathlib

root = pathlib.Path(__file__).resolve().parent
source = (root / 'server.py').read_text(encoding='utf-8-sig')
scope = {'__name__': 'offline_server_definitions', '__file__': str(root / 'server.py')}
exec(compile(ast.parse(source), str(root / 'server.py'), 'exec'), scope)
select = scope['preflight_control']
checks = 0

def check(value):
    global checks
    if not value: raise AssertionError('Offline browsing host guard failed')
    checks += 1

check(select({}) == 'control.php')
check(select({'job_posting_enabled': True}) == 'job-control.php')
check(select({'job_browsing_enabled': True}) == 'browse-control.php')
check(select({'job_browsing_enabled': False, 'job_posting_enabled': True}) == 'job-control.php')
for value in ['true', 1, None, [], {}]:
    try:
        select({'job_browsing_enabled': value})
    except ValueError:
        check(True)
    else:
        check(False)
for value in [True, 'true', None]:
    try:
        select({'job_browsing_enabled': True, 'job_posting_enabled': value})
    except ValueError:
        check(True)
    else:
        check(False)
env = scope['minimal_env']('accept-0123456789abcdef')
check(set(env) == {'SystemRoot', 'WINDIR', 'PATH', 'TEMP', 'TMP', 'GIGHUB_HTTP_RUN'})
check(env['TEMP'] == env['TMP'] and 'GigHub_Clean_Test' in env['TEMP'])
print(f'PASS: {checks} offline browsing host checks; no server, database or cache writes.')
