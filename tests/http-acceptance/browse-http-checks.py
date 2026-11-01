"""Explicitly authorized isolated browsing acceptance; never seeds or grants privileges."""
import sys
sys.dont_write_bytecode = True
import html, http.cookiejar, json, pathlib, re, subprocess, urllib.error, urllib.parse, urllib.request
from server import PRIVATE, PHP, minimal_env

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args): return None

class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())
    def request(self, route, query=None, data=None):
        url = 'http://127.0.0.1:8765/index.php?' + urllib.parse.urlencode({'route': route, **(query or {})}, doseq=True)
        request = urllib.request.Request(url, data=None if data is None else urllib.parse.urlencode(data).encode(), method='GET' if data is None else 'POST')
        try: response = self.opener.open(request, timeout=45)
        except urllib.error.HTTPError as error: response = error
        return response.code, response.headers, response.read().decode('utf-8')

checks = []
def check(value, label):
    if not value: raise RuntimeError(label)
    checks.append(label)

def main():
    if len(sys.argv) != 3 or sys.argv[1] != '--run-isolated-http' or not re.fullmatch(r'accept-[0-9a-f]{16}', sys.argv[2]):
        raise RuntimeError('Explicit isolated browsing opt-in required')
    run = sys.argv[2]; directory = PRIVATE / run
    state = json.loads((directory / 'run.json').read_text(encoding='utf-8-sig'))
    if state.get('run') != run or state.get('port') != 8765 or state.get('job_browsing_enabled') is not True or state.get('job_posting_enabled') is not False or state.get('mutations_enabled') is not False or state.get('browse_fixture_writes_enabled') is not False or state.get('auth_requests_enabled') is not True:
        raise RuntimeError('Unexpected browsing runtime')
    command = [PHP, '-d', 'log_errors=0', str(pathlib.Path(__file__).with_name('browse-control.php')), 'inspect', run]
    inspection = subprocess.run(command, env=minimal_env(run), capture_output=True, timeout=45)
    if inspection.returncode: raise RuntimeError('Fixture inspection failed')
    manifest = json.loads(inspection.stdout); jobs = manifest['jobs']; skills = manifest['skills']
    check(len(jobs) == 28 and len(skills) == 3 and len(manifest['users']) == 4, 'complete tracked fixture inventory')
    def ids(body): return [int(value) for value in re.findall(r'href="\?route=/jobs/show&amp;id=([0-9]+)"', body)]
    guest = Client()
    code, headers, body = guest.request('/jobs')
    check(code == 200 and ids(body) == list(reversed(jobs[:24]))[:20], 'guest catalogue and newest ordering')
    check(len(ids(body)) == 20 and 'Next' in body, 'twenty results and next navigation')
    check(headers.get('Cache-Control') == 'no-store' and headers.get('X-Content-Type-Options') == 'nosniff', 'response safety headers')
    check('@example.invalid' not in body and 'hidden project' not in body, 'catalogue contact and hidden-job privacy')
    check(ids(guest.request('/jobs', {'page':'2'})[2]) == list(reversed(jobs[:24]))[20:], 'second page ordering')
    for sort, expected in [('budget_low', jobs[:20]), ('budget_high', list(reversed(jobs[:24]))[:20])]:
        check(ids(guest.request('/jobs', {'sort':sort})[2]) == expected, 'budget sort and tie-breaking ' + sort)
    filters = {'q':'j' + run[7:] + '-', 'min_budget':'0.01', 'max_budget':'99999999.99', 'skills[]':skills, 'sort':'budget_low'}
    code, _, body = guest.request('/jobs', filters)
    match = re.search(r'<a href="([^"]+)">Next</a>', body)
    check(code == 200 and match is not None, 'filtered next link exists')
    next_query = urllib.parse.parse_qs(urllib.parse.urlsplit(html.unescape(match[1])).query)
    check(next_query.get('page') == ['2'] and next_query.get('q') == [filters['q']] and next_query.get('skills[0]') == [str(skills[0])] and next_query.get('min_budget') == ['0.01'] and next_query.get('sort') == ['budget_low'], 'filter values preserved in pagination')
    query = {key:value for key,value in next_query.items() if key != 'route'}
    check(ids(guest.request('/jobs', query)[2]) == jobs[20:24], 'follow preserved filter page')
    for keyword in ['%', '_', '!', '%_!', 'literal', 'café', '界']:
        check(ids(guest.request('/jobs', {'q':keyword})[2]) == [jobs[0]], 'literal/Unicode keyword ' + keyword)
    check(ids(guest.request('/jobs', {'min_budget':'10', 'max_budget':'10'})[2]) == [jobs[2],jobs[1]], 'inclusive equal-budget boundary')
    for budget, job in [('0.01',jobs[0]), ('99999999.99',jobs[23])]:
        check(ids(guest.request('/jobs', {'min_budget':budget,'max_budget':budget})[2]) == [job], 'monetary endpoint ' + budget)
    selected = [jobs[i] for i in range(24) if i % 3 in (0,1)]
    rows = ids(guest.request('/jobs', {'skills[]':skills[:2]})[2])
    check(rows == list(reversed(selected)) and len(rows) == len(set(rows)), 'ANY matching skill without duplicate cards')
    check(ids(guest.request('/jobs', {'q':'Literal','min_budget':'0.01','max_budget':'0.01','skills[]':[skills[1]]})[2]) == [jobs[0]], 'combined filters')
    check(ids(guest.request('/jobs', {'q':'Literal','skills[]':[skills[2]]})[2]) == [], 'combined filters use AND')
    code, _, empty = guest.request('/jobs', {'q':'no synthetic project matches this'})
    check(code == 200 and 'No jobs found' in empty and 'Page 1' not in empty, 'empty state without redundant pagination')
    missing = guest.request('/jobs/show', {'id':'4294967295'})
    check(missing[0] == 404, 'missing detail')
    for job in jobs[24:]:
        hidden = guest.request('/jobs/show', {'id':str(job)})
        check(hidden[0] == missing[0] and hidden[2] == missing[2], 'uniform hidden detail response')
    code, _, body = guest.request('/jobs/show', {'id':str(jobs[0])})
    check(code == 200 and '&lt;unsafe&gt;' in body and '&lt;script&gt;fixture&lt;/script&gt;' in body and '<script>fixture</script>' not in body, 'escaped public detail')
    check('Delivery deadline has passed' in body and '2030' not in body.split('Delivery deadline (Malaysia)')[1].split('</dd>')[0], 'overdue detail stays visible')
    check('@example.invalid' not in body and 'Edit Draft' not in body and 'Publish Draft' not in body, 'detail excludes contact and owner controls')
    for query in [{'page':'0'}, {'page[]':'1'}, {'q[]':'bad'}, {'q':'界'*101}, {'min_budget':'1e2'}, {'min_budget':'20','max_budget':'10'}, {'sort':'bad'}, {'skills[]':['4294967295']}, {'skills[]':[skills[0],skills[0]]}]:
        check(guest.request('/jobs', query)[0] == 422, 'invalid filter rejected')
    check(guest.request('/jobs/show', {'id[]':'1'})[0] == 404, 'malformed detail ID')
    for label in ['client','freelancer']:
        client = Client(); code, _, login = client.request('/login')
        token = re.search(r'name="csrf_token" value="([0-9a-f]{64})"', login)
        check(code == 200 and token is not None, 'fixture login form ' + label)
        code, head, _ = client.request('/login', data={'email':'j'+run[7:]+'-'+label+'@example.invalid','password':'Synthetic browsing HTTP passphrase','csrf_token':token[1]})
        check(code == 303 and head.get('Location') == '?route=/' + label, 'fixture login ' + label)
        check(client.request('/login')[0] == 303, 'session persists ' + label)
        check(ids(client.request('/jobs')[2]) == list(reversed(jobs[:24]))[:20], 'signed-in browsing ' + label)
        check(client.request('/jobs/show', {'id':str(jobs[0])})[0] == 200, 'signed-in detail ' + label)
    for route in ['/client/jobs/create','/client/jobs/update','/client/jobs/publish','/register','/verify-email','/verify-email/resend','/jobs','/jobs/show']:
        check(guest.request(route, data={'csrf_token':'synthetic'})[0] == 403, 'forbidden POST ' + route)
    for route in ['/client/jobs','/client/jobs/edit','/admin','/health']:
        check(guest.request(route)[0] == 403, 'unrelated GET denied ' + route)
    check(guest.request('/login', data={'email':'outside@example.invalid','password':'synthetic'})[0] == 403, 'login restricted to exact fixture accounts')
    with (directory/'browse-http-results.json').open('x',encoding='utf-8') as output:
        json.dump({'status':'PASS','passed':len(checks),'failed':0,'checks':checks},output,indent=2)
    print(json.dumps({'status':'PASS','passed':len(checks),'failed':0}))

if __name__ == '__main__':
    try: main()
    except Exception:
        print(json.dumps({'status':'FAIL','passed':len(checks),'failed':1,'details_withheld':True}),file=sys.stderr)
        raise SystemExit(1)
