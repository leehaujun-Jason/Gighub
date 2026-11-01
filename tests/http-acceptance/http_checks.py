"""Black-box acceptance through real PHP CGI. Never prints bodies/cookies/keys."""
import http.cookiejar, json, os, pathlib, re, subprocess, sys, time, urllib.error, urllib.parse, urllib.request
sys.dont_write_bytecode = True
from server import minimal_env, ROOT, PRIVATE, PHP

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args): return None

class Client:
    def __init__(self):
        self.jar=http.cookiejar.CookieJar(); self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar),NoRedirect())
    def request(self,route,data=None,headers=None):
        req=urllib.request.Request('http://127.0.0.1:8765/index.php?'+urllib.parse.urlencode({'route':route}),
            data=None if data is None else urllib.parse.urlencode(data).encode(),headers=headers or {},method='GET' if data is None else 'POST')
        try: response=self.opener.open(req,timeout=45)
        except urllib.error.HTTPError as error: response=error
        return response.code,response.headers,response.read().decode()
    def session(self): return next(c.value for c in self.jar if c.name.startswith('GHaccept'))

def main():
    run=sys.argv[1]; directory=PRIVATE/run; state=json.loads((directory/'run.json').read_text(encoding='utf-8-sig')); base=state['clock']
    prefix='http'+run+'-'; password='Synthetic acceptance passphrase 2026!'; passed=[]
    def check(ok,label):
        if not ok: raise RuntimeError(label)
        passed.append(label)
    def control(action,payload=None):
        p=subprocess.run([PHP,'-d','log_errors=0',str(pathlib.Path(__file__).with_name('control.php')),action,run],
                         input=json.dumps(payload or {}).encode(),capture_output=True,env=minimal_env(run))
        if p.returncode: raise RuntimeError('isolated control '+action)
        out=p.stdout.decode(); return json.loads(out) if out.startswith('{') else out
    def csrf(html):
        m=re.search(r'name="csrf_token" value="([0-9a-f]{64})"',html)
        if not m: raise RuntimeError('CSRF form token missing')
        return m[1]
    def login(client,label,pw=password,extra=None):
        _,_,html=client.request('/login'); return client.request('/login',{'csrf_token':csrf(html),'email':prefix+label+'@example.invalid','password':pw,**(extra or {})})
    try:
        for role in ['client','freelancer','admin']:
            c=Client(); status,headers,html=c.request('/login'); old=c.session()
            check(status==200,'login form '+role); cookie=headers.get('Set-Cookie','')
            check('HttpOnly' in cookie and 'SameSite=Lax' in cookie and 'path=/' in cookie.lower(),'isolated cookie flags '+role)
            status,headers,html=c.request('/login',{'csrf_token':csrf(html),'email':' '+(prefix+role+'@example.invalid').upper()+' ','password':password,'next':'https://example.invalid/'})
            check(status==303 and headers.get('Location')=='?route=/'+role,'fixed successful redirect '+role)
            check(old!=c.session(),'session regeneration '+role)
            status,_,html=c.request('/'+role);check(status==200 and 'You are signed in.' in html,'cross-request role home '+role)
            check(c.request('/'+('admin' if role!='admin' else 'client'))[0]==403,'wrong role '+role)
            check(c.request('/logout')[0]==405,'GET logout rejected '+role)
            check(c.request('/logout',{'csrf_token':'invalid'})[0]==403 and c.request('/'+role)[0]==200,'logout CSRF preserves identity '+role)
            _,_,html=c.request('/'+role); old=c.session(); status,headers,_=c.request('/logout',{'csrf_token':csrf(html)})
            check(status==303 and headers.get('Location')=='?route=/login','POST logout redirect '+role)
            check('Max-Age=0' in headers.get('Set-Cookie',''),'logout cookie expiration '+role)
            check(c.request('/'+role)[0]==303,'post-logout authorization '+role)
            check(Client().request('/'+role,headers={'Cookie':state['cookie']+'='+old})[0]==303,'logged-out ID replay '+role)
        for role in ['client','freelancer','admin']: check(Client().request('/'+role)[0]==303,'anonymous protected '+role)
        c=Client();check(c.request('/login',{'email':prefix+'client@example.invalid','password':password,'csrf_token':'wrong'})[0]==403,'login CSRF rejection')
        for name,pw in [('client','Incorrect synthetic password'),('absent',password),('pending',password),('suspended',password)]:
            status,_,html=login(Client(),name,pw);check(status==422 and "We couldn't sign you in" in html and 'Please check your email address and password, then try again.' in html and pw not in html,'generic credential rejection '+name)
        for name in ['short','common','mismatch']:
            c=Client();_,_,html=c.request('/register');pw={'short':'short','common':'123456789012','mismatch':password}[name]
            status,_,html=c.request('/register',{'csrf_token':csrf(html),'display_name':'Synthetic '+run,'email':prefix+'reject-'+name+'@example.invalid','role':'client','password':pw,'password_confirmation':'different' if name=='mismatch' else pw})
            check(status==422 and 'value="'+pw+'"' not in html,'registration rejection '+name)
        for role in ['client','freelancer']:
            c=Client();_,_,html=c.request('/register');status,headers,_=c.request('/register',{'csrf_token':csrf(html),'display_name':'Synthetic '+run,'email':prefix+'registered-'+role+'@example.invalid','role':role,'password':password,'password_confirmation':password})
            check(status==303 and headers.get('Location')=='?route=/register/pending','registration success '+role)
            status,_,html=c.request('/register/pending');check(status==200 and 'remains pending' in html,'disabled delivery pending '+role)
            info=control('inspect',{'label':'registered-'+role});check(info['status']=='pending_verification' and not info['verified'] and int(info['tokens']['total'])==1 and int(info['tokens']['lifetime'])==1800,'registration token policy '+role)
            check(c.request('/'+role)[0]==303,'registration never logs in '+role)
        c=Client();_,_,html=c.request('/register');status,_,html=c.request('/register',{'csrf_token':csrf(html),'display_name':'Synthetic '+run,'email':prefix+'client@example.invalid','role':'client','password':password,'password_confirmation':password})
        check(status==422 and 'This email is already registered.' in html,'approved duplicate message')
        c=Client();_,_,html=c.request('/register');status,_,html=c.request('/register',{'csrf_token':csrf(html),'display_name':'Synthetic '+run,'email':prefix+'fallback@example.invalid','role':'freelancer','intent':'update_role'})
        check(status==200 and 'Optional profile details' in html,'no-JavaScript role refresh fallback')
        def resend(label):
            c=Client();_,_,h=c.request('/verify-email/resend');status,_,_=c.request('/verify-email/resend',{'csrf_token':csrf(h),'email':prefix+label+'@example.invalid'});check(status==303,'generic resend PRG '+label);return c.request('/verify-email/result')[2]
        original=control('inspect',{'label':'registered-client'});a=resend('registered-client');check(control('inspect',{'label':'registered-client'})['tokens']['total']==original['tokens']['total'],'persisted cooldown blocks immediate resend')
        control('clock',{'time':base+59});resend('registered-client');check(int(control('inspect',{'label':'registered-client'})['tokens']['total'])==1,'59 second cooldown boundary')
        control('clock',{'time':base+60});resend('registered-client');check(int(control('inspect',{'label':'registered-client'})['tokens']['total'])==2,'60 second cooldown boundary')
        b=resend('absent');check(a==b,'resend account enumeration response')
        for delta in [120,180,240]:control('clock',{'time':base+delta});resend('registered-client')
        control('clock',{'time':base+300});resend('registered-client');check(int(control('inspect',{'label':'registered-client'})['tokens']['total'])==5,'persisted five-per-hour cap')
        control('clock',{'time':base+3600});resend('registered-client');check(int(control('inspect',{'label':'registered-client'})['tokens']['total'])==6,'hour boundary permits new issuance')
        control('clock',{'time':base});control('issue',{'label':'pending'});key=json.loads((directory/'key-pending.json').read_text())['key'];c=Client()
        _,_,html=c.request('/verify-email');check(control('inspect',{'label':'pending'})['status']=='pending_verification','verification GET does not activate')
        check(c.request('/verify-email',{'verification_key':key,'csrf_token':'bad'})[0]==403,'verification CSRF rejection')
        check(control('inspect',{'label':'pending'})['status']=='pending_verification','invalid CSRF preserves pending')
        status,headers,_=c.request('/verify-email',{'verification_key':key,'csrf_token':csrf(html)});check(status==303,'explicit confirmation PRG')
        _,_,html=c.request('/verify-email/result');info=control('inspect',{'label':'pending'});check('Your email is verified.' in html and info['status']=='active' and info['verified'],'explicit verification activation')
        check(c.request('/client')[0]==303,'verification does not authenticate')
        _,_,html=c.request('/verify-email');c.request('/verify-email',{'verification_key':key,'csrf_token':csrf(html)});_,_,html=c.request('/verify-email/result');check('This verification link cannot be used.' in html,'single-use token replay')
        # Expiry via the existing injectable clock; no Windows/server clock changes.
        control('clock',{'time':base+4000});control('issue',{'label':'registered-freelancer'});key=json.loads((directory/'key-registered-freelancer.json').read_text())['key'];control('clock',{'time':base+5800});c=Client();_,_,html=c.request('/verify-email');c.request('/verify-email',{'csrf_token':csrf(html),'verification_key':key});_,_,html=c.request('/verify-email/result');check('This verification link cannot be used.' in html,'token exact 1800-second expiry')
        control('clock',{'time':base})
        for kind in ['idle','absolute']:
            c=Client();login(c,'client');control('session',{'id':c.session(),'issued_at':base-(28800 if kind=='absolute' else 2000),'last_activity':base-(1800 if kind=='idle' else 0)})
            check(c.request('/client')[0]==303,kind+' expiry across HTTP requests')
        c=Client();login(c,'client');control('session',{'id':c.session(),'issued_at':base-2000,'last_activity':base-1799});check(c.request('/client')[0]==200 and c.request('/client')[0]==200,'idle refresh before expiry')
        c=Client();c.request('/login')
        for i in range(5):check(login(c,'client','Wrong acceptance password')[0]==422,'failed login allowance '+str(i+1))
        status,headers,html=login(c,'client');check(status==429 and int(headers.get('Retry-After','0'))>0,'sixth login throttled')
        control('session',{'id':c.session(),'login_failures':[int(time.time())-900]*5});check(login(c,'client')[0]==303,'expired throttle window permits login')
        # Invalid credentials are not retained in responses. Source/config paths are not web routes.
        for route in ['/app/Core/Database.php','/config/local.php','/tests/http-acceptance/privileges.php']:
            check(Client().request(route)[0]==404,'private route denied '+route)
        result={'status':'PASS','passed':len(passed),'checks':passed,'clock_boundaries':'test-only clock/session fixtures; no system clock changes','real_smtp':False}
        (directory/'http-results.json').write_text(json.dumps(result,indent=2));print(json.dumps({'status':'PASS','checks':len(passed)}))
    except Exception as error:
        (directory/'http-results.json').write_text(json.dumps({'status':'FAIL','passed':len(passed),'failed_check':str(error),'checks':passed},indent=2))
        print(json.dumps({'status':'FAIL','passed':len(passed),'failed_check':str(error)}));raise SystemExit(1)
    finally:
        control('clock',{'time':base})

if __name__=='__main__': main()
