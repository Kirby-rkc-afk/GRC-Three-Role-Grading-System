"""Search and mutation checks on a disposable database; never live reassignment."""
import os,secrets,subprocess,time,urllib.request,urllib.parse,urllib.error,http.cookiejar,re
from pathlib import Path
from html.parser import HTMLParser
ROOT=Path(__file__).resolve().parent.parent;PHP=r'C:\xampp\php\php.exe';BASE='http://127.0.0.1:8772/';checks=0
def check(ok,label):
 global checks
 assert ok,label
 checks+=1
def get(c,path):
 with c.open(BASE+path) as r:s=r.read().decode()
 issue=re.search('Fatal error|Parse error|<b>Warning</b>',s);check(issue is None,s[issue.start():issue.start()+1000] if issue else '');return s
def post(c,path,data):return c.open(BASE+path,urllib.parse.urlencode(data).encode()).read().decode()
def login(user):
 c=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));s=get(c,'index.php');token=re.search(r'name="csrf_token" value="([^"]+)"',s)[1];post(c,'index.php',{'csrf_token':token,'identifier':user,'password':'Test-only-password-2026'});return c
class Forms(HTMLParser):
 def __init__(self,s):super().__init__();self.forms=[];self.f=None;self.feed(s)
 def handle_starttag(self,t,attrs):
  a=dict(attrs)
  if t=='form':self.f={};self.forms.append(self.f)
  elif t=='input' and self.f is not None and a.get('name'):self.f[a['name']]=a.get('value','')
 def handle_endtag(self,t):
  if t=='form':self.f=None
def form(s):return next(f for f in Forms(s).forms if f.get('action')=='reassign')
def denied(c,path,code):
 try:c.open(BASE+path)
 except urllib.error.HTTPError as e:check(e.code==code,'Expected denied request');return
 raise AssertionError('Unexpected access: '+path)
env=os.environ.copy();env['GRC_DB_NAME']='grc_revision_http_'+secrets.token_hex(5);server=None
try:
 subprocess.run([PHP,'tests/reassignment_fixture.php'],cwd=ROOT,env=env,check=True)
 sessions=Path(os.environ['TEMP'])/('grc-reassign-'+secrets.token_hex(4));sessions.mkdir()
 server=subprocess.Popen([PHP,'-d','session.save_path="'+sessions.as_posix()+'"','-S','127.0.0.1:8772','-t',str(ROOT)],cwd=ROOT,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=subprocess.CREATE_NO_WINDOW)
 for _ in range(40):
  try:urllib.request.urlopen(BASE+'index.php',timeout=1);break
  except OSError:time.sleep(.15)
 admin=login('test.registrar');prof=login('test.faculty');student=login('test.student')
 s=get(admin,'class.php?subject_id=1&tab=review');check('reassign-class.php?subject_id=1' in s and 'name="professor_id"' not in s,'No professor dropdown in class actions')
 path='reassign-class.php?subject_id=1';s=get(admin,path);check('Page 1 of 2' in s and 'Candidate 20' in s and 'Candidate 21' not in s,'Server pagination bounds')
 check('Unavailable pending' not in s and 'Unavailable inactive' not in s,'Only active replacements')
 check('name="professor_id"' not in s,'No dropdown or default professor selection')
 s=get(admin,path+'&page=2');check('Candidate 35' in s and 'Page 2 of 2' in s,'Second page works')
 s=get(admin,path+'&q=Candidate+35');check('Candidate 35' in s and 'Candidate 34' not in s,'Name search')
 s=get(admin,path+'&q=candidate35%40example.invalid');check('Candidate 35' in s,'Email search')
 s=get(admin,path+'&q=FAC-35');check('Candidate 35' in s,'School ID search')
 s=get(admin,path+'&department=College+of+Computer+Studies');check('Candidate 03' in s and 'Candidate 02' not in s,'Department filter')
 check('data-auto-filter' in s,'Department auto-filter enabled')
 s=get(admin,path+'&q=NoSuchFaculty');check('No active professors match your search.' in s,'Empty results')
 s=get(admin,path+'&q=%22%3E%3Cscript%3E');check('<script>' not in s,'Search escaped')
 s=get(admin,path+'&professor_id=2');stale=form(s);check('SELECTED PROFESSOR' in s and 'Candidate 01' in s,'Selected professor confirmation')
 f=dict(stale);f['reason']='';s=post(admin,path+'&professor_id=2',f);check('A required text field is empty' in s,'Reason required')
 f=dict(stale);f.update(reason='CSRF attempt',csrf_token='invalid')
 try:s=post(admin,path+'&professor_id=2',f)
 except urllib.error.HTTPError as e:s=e.read().decode()
 check('Class reassigned.' not in s,'CSRF rejected')
 s=get(admin,path+'&professor_id=3');f=form(s);f['reason']='Duplicate offering test';s=post(admin,path+'&professor_id=3',f);check('already has this offering' in s,'Duplicate offering prevented')
 s=get(admin,path+'&professor_id=37');check('That professor is unavailable' in s,'Pending professor cannot be selected')
 s=get(admin,'reassign-class.php?subject_id=3');check('Return this class to draft' in s and 'name="action" value="reassign"' not in s,'Locked class cannot reassign')
 denied(prof,path,403);denied(student,path,403);denied(admin,'reassign-class.php?subject_id=9999',404)
 f=form(get(admin,path+'&professor_id=2'));f.update(reason='Verified reassignment',subject_id='3',professor_id='3');s=post(admin,path+'&professor_id=2',f);check('Class reassigned.' in s and 'Candidate 01' in s,'URL class and selected professor override forged hidden fields')
 stale['reason']='Stale retry';s=post(admin,path+'&professor_id=4',stale);check('changed in another session' in s,'Stale revision rejected')
 subprocess.run([PHP,'tests/reassignment_fixture.php','verify'],cwd=ROOT,env=env,check=True)
 s=get(admin,'class.php?subject_id=1');check('data-auto-filter' in s,'Masterlist auto-filter enabled')
 print(str(checks)+' reassignment HTTP checks passed.')
finally:
 if server:server.terminate();server.wait(timeout=10)
 subprocess.run([PHP,'tests/reassignment_fixture.php','cleanup'],cwd=ROOT,env=env,check=True)
