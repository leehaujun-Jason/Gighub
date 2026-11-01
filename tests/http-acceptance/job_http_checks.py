"""Prepared mutating Job Posting acceptance. Separate explicit future authorization required."""
import sys
sys.dont_write_bytecode = True
import http.cookiejar, json, pathlib, re, subprocess, urllib.error, urllib.parse, urllib.request
from server import minimal_env, PRIVATE, PHP

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args): return None

class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())
    def request(self, route, data=None, job_id=None):
        query={'route':route}
        if job_id is not None: query['id']=str(job_id)
        req=urllib.request.Request('http://127.0.0.1:8765/index.php?'+urllib.parse.urlencode(query),
            data=None if data is None else urllib.parse.urlencode(data,doseq=True).encode(),method='GET' if data is None else 'POST')
        try: r=self.opener.open(req,timeout=45)
        except urllib.error.HTTPError as e: r=e
        return r.code,r.headers,r.read().decode()

def main():
    if len(sys.argv)!=3 or sys.argv[1]!='--run-isolated-http': raise RuntimeError('Explicit isolated HTTP opt-in required')
    run=sys.argv[2]
    if not re.fullmatch(r'accept-[0-9a-f]{16}',run):raise RuntimeError('Invalid run')
    directory=PRIVATE/run; state=json.loads((directory/'run.json').read_text(encoding='utf-8-sig'))
    if state.get('run')!=run or state.get('port')!=8765 or state.get('job_posting_enabled') is not True or state.get('mutations_enabled') is not True:raise RuntimeError('Job HTTP run is not enabled')
    prefix='j'+run[7:]+'-';checks=[]
    def check(ok,label):
        if not ok:raise RuntimeError(label)
        checks.append(label)
    def control(action):
        p=subprocess.run([PHP,'-d','log_errors=0',str(pathlib.Path(__file__).with_name('job-control.php')),action,run],capture_output=True,env=minimal_env(run))
        if p.returncode:raise RuntimeError('Isolated fixture inspection failed')
        return json.loads(p.stdout) if action=='inspect' else None
    def csrf(html):
        m=re.search(r'name="csrf_token" value="([0-9a-f]{64})"',html)
        if not m:raise RuntimeError('Expected CSRF token')
        return m[1]
    def login(label):
        c=Client();code,_,html=c.request('/login');check(code==200,'login form '+label)
        code,headers,_=c.request('/login',{'email':prefix+label+'@example.invalid','password':'Synthetic job HTTP passphrase','csrf_token':csrf(html)})
        check(code==303,'login '+label);return c
    def form(c,route='/client/jobs/create',job_id=None):
        code,_,html=c.request(route,job_id=job_id);check(code==200,'owner form');return csrf(html)
    control('preflight');inventory=control('inspect');check(len(inventory['users'])==4 and len(inventory['skills'])==3 and not inventory['jobs'],'fresh tracked fixture inventory')
    check(Client().request('/client/jobs/create')[0]==303,'anonymous redirect')
    for role in ['freelancer','admin']:check(login(role).request('/client/jobs/create')[0]==403,role+' denied')
    owner=login('client');other=login('other')
    from datetime import datetime,timedelta,timezone
    tomorrow=(datetime.fromtimestamp(state['clock'],timezone(timedelta(hours=8)))+timedelta(days=1)).date().isoformat()
    base={'title':prefix+'A synthetic website job','description':'Synthetic job acceptance description. '*3,'budget':'1234.50','deadline':tomorrow,'skills[]':inventory['skills'][:2],'intent':'save_draft'}
    before=control('inspect');check(owner.request('/client/jobs/create',{**base,'csrf_token':'bad'})[0]==403,'CSRF rejected');check(control('inspect')['jobs']==before['jobs'],'CSRF creates no job')
    token=form(owner);code,_,html=owner.request('/client/jobs/create',{**base,'title':'bad','csrf_token':token});check(code==422 and 'title-error' in html,'validation error association')
    token=form(owner);code,h,_=owner.request('/client/jobs/create',{**base,'client_id':str(inventory['users'][1]),'status':'completed','csrf_token':token,'next':'https://example.invalid/'})
    check(code==303,'draft created');location=h.get('Location','');m=re.fullmatch(r'\?route=/client/jobs/show&id=([0-9]+)',location);check(m is not None,'fixed safe redirect');job=int(m[1])
    record=next(x for x in control('inspect')['jobs'] if int(x['id'])==job);check(int(record['client_id'])==inventory['users'][0] and record['status']=='draft','forged owner/status ignored')
    check(owner.request('/client/jobs/show',job_id=job)[0]==200,'owner detail');check(other.request('/client/jobs/show',job_id=job)[0]==404,'other Client draft privacy');check(other.request('/client/jobs/edit',job_id=job)[0]==404,'other Client edit denied')
    token=form(other);check(other.request('/client/jobs/update',{**base,'id':str(job),'csrf_token':token})[0]==404,'forged owner update denied')
    token=form(owner,'/client/jobs/edit',job);check(owner.request('/client/jobs/update',{**base,'title':prefix+'Edited synthetic job','id':str(job),'csrf_token':token})[0]==303,'draft edited')
    code,_,html=owner.request('/client/jobs/show',job_id=job);check('Edited synthetic job' in html,'edited detail')
    check(owner.request('/client/jobs/publish',{'id':str(job),'csrf_token':csrf(html)})[0]==303,'saved draft published')
    check(next(x for x in control('inspect')['jobs'] if int(x['id'])==job)['status']=='open','open persisted')
    token=form(owner);check(owner.request('/client/jobs/publish',{'id':str(job),'csrf_token':token})[0]==409,'repeat publish rejected');check(owner.request('/client/jobs/edit',job_id=job)[0]==409,'open job immutable')
    token=form(owner);check(owner.request('/client/jobs/create',{**base,'intent':'publish','csrf_token':token})[0]==303,'direct publish')
    check(other.request('/client/jobs')[0]==200,'other owner list');check(prefix+'Edited synthetic job' not in other.request('/client/jobs')[2],'owner list private')
    (directory/'job-http-results.json').write_text(json.dumps({'passed':len(checks),'checks':checks},indent=2),encoding='utf-8')
    print('PASS:',len(checks),'isolated Job Posting HTTP checks')

if __name__=='__main__':
    try:main()
    except Exception:raise SystemExit('FAIL: isolated Job Posting acceptance; details withheld. Stop activity, preserve inventory, cleanup and revoke separately; no automatic retry.')
