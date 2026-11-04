"""End-to-end masterlist checks against an isolated, disposable database only."""
import csv, http.cookiejar, io, os, re, secrets, subprocess, time, urllib.error, urllib.parse, urllib.request
from pathlib import Path
from html.parser import HTMLParser

ROOT=Path(__file__).resolve().parent.parent
PHP=r'C:\xampp\php\php.exe'
BASE='http://127.0.0.1:8770/'
checks=0
def check(ok,message):
    global checks
    assert ok,message
    checks+=1
def client(): return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def get(c,path):
    with c.open(BASE+path) as r: text=r.read().decode('utf-8-sig')
    issue=re.search(r'Fatal error|Parse error|<b>Warning</b>',text)
    check(issue is None,('PHP error on '+path+': '+text[issue.start():issue.start()+700]) if issue else '')
    return text
def post(c,path,data): return c.open(BASE+path,urllib.parse.urlencode(data).encode()).read().decode()
def login(username):
    c=client();s=get(c,'index.php');token=re.search(r'name="csrf_token" value="([^"]+)"',s)[1]
    post(c,'index.php',{'csrf_token':token,'identifier':username,'password':'GrcDemo!2026'})
    return c
def denied(c,path,code):
    try: c.open(BASE+path)
    except urllib.error.HTTPError as e: check(e.code==code,'Expected denied request');return
    raise AssertionError('Request was allowed: '+path)
class Forms(HTMLParser):
    def __init__(self,s):super().__init__();self.forms=[];self.current=None;self.feed(s)
    def handle_starttag(self,t,a):
        a=dict(a)
        if t=='form':self.current={};self.forms.append(self.current)
        elif t=='input' and self.current is not None and a.get('name'):self.current[a['name']]=a.get('value','')
    def handle_endtag(self,t):
        if t=='form':self.current=None
def form(s,action):return next(f for f in Forms(s).forms if f.get('action')==action)

env=os.environ.copy();env['GRC_DB_NAME']='grc_revision_masterlist_'+secrets.token_hex(5)
server=None;created=False
try:
    # Mark cleanup eligible before fixture creation, including a partially failed fixture.
    created=True;subprocess.run([PHP,'tests/masterlist_fixture.php'],cwd=ROOT,env=env,check=True)
    sessions=Path(os.environ['TEMP'])/('grc-masterlist-sessions-'+secrets.token_hex(5));sessions.mkdir()
    server=subprocess.Popen([PHP,'-d','session.save_path="'+sessions.as_posix()+'"','-S','127.0.0.1:8770','-t',str(ROOT)],cwd=ROOT,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=subprocess.CREATE_NO_WINDOW)
    for _ in range(40):
        try:urllib.request.urlopen(BASE+'index.php',timeout=1);break
        except OSError:time.sleep(.15)
    admin=login('demo.admin');teacher=login('demo.faculty.ccs');other=login('demo.faculty.coa');student=login('demo.student17')
    s=get(admin,'registrar.php?view=classes&professor_id=4');check('DEMO-IT101' in s and 'DEMO-IT102' in s and 'DEMO-ACC101' in s,'Subject-first landing ignores old professor filter')
    check('subject=' in s,'Grouped subject links')
    s=get(teacher,'class.php?subject_id=1');check('40</strong>' in s and 'Page 1 of 2' in s,'40 enrolled students with pagination')
    check('Drop; retain history' not in s,'No per-row inline forms')
    check('DEMO-IT101' in get(teacher,'professor-dashboard.php?view=classes') and 'DEMO-IT102' in get(teacher,'professor-dashboard.php?view=classes'),'One professor has multiple subjects')
    s=get(teacher,'class.php?subject_id=1&page=2');check('Page 2 of 2' in s,'Second page')
    s=get(teacher,'class.php?subject_id=1&size=50');check('Page 1 of 1' in s and 'Learner 63' in s,'All 40 fit 50-row view')
    s=get(teacher,'class.php?subject_id=1&q=Learner+63');check('1 record' in s and 'Learner 62' not in s,'Search matches only target')
    s=get(admin,'class.php?subject_id=1&status=dropped');check('1 record' in s and 'Tan, Demo' not in s,'Dropped filter')
    s=get(admin,'class.php?subject_id=1&q=Demo&status=dropped&size=50');check('active-filter-banner' in s and 'Enrollment: Dropped' in s and 'Search: Demo' in s,'Active filter summary describes applied filters')
    reset=re.search(r'class="filter-reset" href="([^"]+)"',s)[1].replace('&amp;','&')
    check(reset=='class.php?subject_id=1&size=50','Reset preserves class and page-size preference')
    s=get(admin,reset);check('active-filter-banner" role=' not in s and '41 records' in s and 'Page 1 of 1' in s,'Reset restores full roster and removes banner')
    s=get(admin,'class.php?subject_id=1&page=999');check('Page 2 of 2' in s,'Page bounds clamped')
    s=get(admin,'class.php?subject_id=1&q=%22%3E%3Cscript%3Ealert(1)%3C%2Fscript%3E');check('<script>alert(1)</script>' not in s,'Search output escaped')
    data=get(teacher,'class.php?subject_id=1&status=active&export=csv');records=list(csv.reader(io.StringIO(data)));check(len(records)==41,'CSV exports all 40 active records')
    check(len(records[0])==9 and records[0][0]=='Account ID','CSV columns')
    check(len(list(csv.reader(io.StringIO(get(teacher,'class.php?subject_id=1&q=Learner+63&export=csv')))))==2,'CSV respects search')
    denied(other,'class.php?subject_id=1',404);denied(student,'class.php?subject_id=1',403);denied(admin,'class.php?subject_id=999999',404)
    s=get(admin,'class.php?subject_id=2&tab=review');check('Release complete grade sheet' in s,'Review actions on dedicated page')
    s=get(teacher,'class.php?subject_id=1&student=1');check('Grade breakdown' in s and 'Pending' in s,'Individual record evidence')
    f=form(s,'drop_enrollment');f.update(reason='Masterlist test drop',subject_id='2')
    s=post(teacher,'class.php?subject_id=1&student=1',f);check('Class record updated.' in s,'Drop action ignores forged class target')
    s=get(teacher,'class.php?subject_id=1&student=1');f=form(s,'restore_enrollment');f['reason']='Masterlist test restore'
    s=post(teacher,'class.php?subject_id=1&student=1',f);check('Class record updated.' in s,'Restore with retained evidence')
    denied(teacher,'class.php?subject_id=1&student=9',404)
    s=get(teacher,'class.php?subject_id=1&student=1');f=form(s,'drop_enrollment');f['csrf_token']='invalid';f['reason']='CSRF attempt'
    try:s=post(teacher,'class.php?subject_id=1',f)
    except urllib.error.HTTPError as e:s=e.read().decode()
    check('Class record updated.' not in s,'CSRF mutation rejected')
    s=get(admin,'class.php?subject_id=1');check('name="action" value="enroll"' not in s,'Admin masterlist read-only')
    print(str(checks)+' masterlist HTTP checks passed.')
finally:
    if server:server.terminate();server.wait(timeout=10)
    if created:subprocess.run([PHP,'tests/masterlist_fixture.php','cleanup'],cwd=ROOT,env=env,check=True)
