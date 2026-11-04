"""Subject -> professor -> masterlist -> single-student review, isolated DB."""
import os, secrets, subprocess, time, urllib.request, urllib.error, urllib.parse, http.cookiejar, re
from pathlib import Path
PHP=r'C:\xampp\php\php.exe';ROOT=Path(__file__).resolve().parent.parent;BASE='http://127.0.0.1:8771/';checks=0
def check(ok,label):
 global checks
 assert ok,label
 checks+=1
def get(c,path):
 with c.open(BASE+path) as r:s=r.read().decode()
 issue=re.search('Fatal error|Parse error|<b>Warning</b>',s)
 check(issue is None,s[issue.start():issue.start()+900] if issue else '')
 return s
def login(username):
 c=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));s=get(c,'index.php');csrf=re.search(r'name="csrf_token" value="([^"]+)"',s)[1]
 c.open(BASE+'index.php',urllib.parse.urlencode({'csrf_token':csrf,'identifier':username,'password':'GrcDemo!2026'}).encode()).read();return c
def denied(c,path,code):
 try:c.open(BASE+path)
 except urllib.error.HTTPError as e:check(e.code==code,'Unauthorized request');return
 raise AssertionError('Access incorrectly granted: '+path)
env=os.environ.copy();env['GRC_DB_NAME']='grc_revision_masterlist_'+secrets.token_hex(5);env['GRC_REALISTIC_DEMO']='1';server=None
try:
 subprocess.run([PHP,'tests/masterlist_fixture.php'],cwd=ROOT,env=env,check=True)
 sessions=Path(os.environ['TEMP'])/('grc-subject-test-'+secrets.token_hex(4));sessions.mkdir()
 server=subprocess.Popen([PHP,'-d','session.save_path="'+sessions.as_posix()+'"','-S','127.0.0.1:8771','-t',str(ROOT)],cwd=ROOT,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=subprocess.CREATE_NO_WINDOW)
 for _ in range(40):
  try:urllib.request.urlopen(BASE+'index.php',timeout=1);break
  except OSError:time.sleep(.15)
 admin=login('demo.admin');prof=login('demo.faculty.ccs');second=login('demo.faculty.ccs2');student=login('demo.student17')
 s=get(admin,'registrar.php?view=classes');check('Introduction to Computing' in s and 'Financial Accounting and Reporting' in s,'Subjects first');check('2 professors' in s,'Two teachers per subject');check('Student masterlist' not in s,'No class roster on subject landing')
 s=get(admin,'registrar.php?view=classes&subject=IT101&title=Introduction+to+Computing');check('Adrian Miguel Dela Cruz' in s and 'Angela Marie Villanueva' in s,'Professor selection');check('BSIT-1A' in s and 'BSIT-1B' in s,'Independent sections');check('aria-label="Open masterlist:' in s,'Accessible icon actions')
 s=get(admin,'class.php?subject_id=1');check('Miguel Angelo' in s and 'Learner 31' not in s,'Realistic fictional names');check('Review grades for' in s and '>View record ' not in s,'Icon review action');check('student-review.php?' in s,'Standalone review link');check('40</strong>' in s,'40 enrolled students');check('Course grade' not in s,'No grade review bulk content in masterlist')
 s=get(admin,'student-review.php?subject_id=1&student=1');check('STUDENT RECORD / GRADE REVIEW' in s and 'Grade breakdown' in s,'Dedicated grade review');check('SCORE DETAILS' in s and 'criterion-evidence' in s,'Grade source evidence');check('Bautista, Demo' not in s and 'sample.student001' not in s,'Only selected student content');check('Contribution' in s and 'Pending' in s,'Weighted grade explanation and unresolved work')
 s=get(prof,'student-review.php?subject_id=1&student=3');check('Grade breakdown' in s,'Assigned professor review');check('0.00' in s,'Scored zero kept')
 s=get(admin,'class.php?subject_id=1&q=Miguel');check('Miguel Angelo' in s,'Search fictional name')
 s=get(admin,'registrar.php?view=classes&q=Accounting');check('Financial Accounting and Reporting' in s and 'Introduction to Computing' not in s,'Subject search')
 s=get(admin,'registrar.php?view=classes&subject=IT101&q=Angela');check('Angela Marie Villanueva' in s and 'Adrian Miguel Dela Cruz' not in s,'Professor search')
 s=get(admin,'registrar.php?view=classes&subject=UNKNOWN');check('No class offerings match' in s,'Unknown subject empty state')
 s=get(admin,'registrar.php?view=classes&q=%22%3E%3Cscript%3E');check('<script>' not in s,'Escaped subject search')
 denied(second,'class.php?subject_id=1',404);denied(second,'student-review.php?subject_id=1&student=1',404);denied(student,'student-review.php?subject_id=1&student=1',403);denied(admin,'student-review.php?subject_id=1&student=9',404)
 s=get(admin,'class.php?subject_id=2&tab=review');check('Release complete grade sheet' in s,'Grade sheet workflow retained')
 print(str(checks)+' subject flow checks passed.')
finally:
 if server:server.terminate();server.wait(timeout=10)
 subprocess.run([PHP,'tests/masterlist_fixture.php','cleanup'],cwd=ROOT,env=env,check=True)
