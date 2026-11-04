import urllib.request,urllib.parse,http.cookiejar,re,json
from html.parser import HTMLParser
from html import unescape
BASE='http://127.0.0.1:8769/'
checks=0
class Forms(HTMLParser):
 def __init__(self,s):
  super().__init__();self.forms=[];self.f=None;self.sel=None;self.txt=None;self.opt_capture=False;self.feed(s)
 def handle_starttag(self,tag,attrs):
  a=dict(attrs)
  if tag=='form':self.f={};self.forms.append(self.f)
  elif self.f is not None:
   if tag=='input' and a.get('name') and a.get('type') not in ['file','checkbox','submit']:self.f[a['name']]=a.get('value','')
   elif tag=='select':self.sel=a.get('name');self.f[self.sel]=None
   elif tag=='option' and self.sel:
    self.opt_capture=False
    if self.f[self.sel] is None or 'selected' in a:
     self.f[self.sel]=a.get('value','');self.opt_capture='value' not in a
   elif tag=='textarea':self.txt=a.get('name');self.f[self.txt]=''
 def handle_endtag(self,tag):
  if tag=='form':self.f=None
  elif tag=='select':self.sel=None
  elif tag=='textarea':self.txt=None
  elif tag=='option':self.opt_capture=False
 def handle_data(self,data):
  if self.txt and self.f is not None:self.f[self.txt]+=data
  if self.opt_capture and self.sel and self.f is not None:self.f[self.sel]=data.strip()

def session():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def valid(s):
 global checks
 issue=re.search(r'(Fatal error|Parse error|<b>Warning</b>)',s)
 assert issue is None,s[max(0,issue.start()-100):issue.start()+1200] if issue else ''
 checks+=1;return s
def get(o,path):return valid(o.open(BASE+path).read().decode())
def send(o,path,data):return valid(o.open(BASE+path,urllib.parse.urlencode(data).encode()).read().decode())
def form(s,action=None,**match):
 for f in Forms(s).forms:
  if (action is None or f.get('action')==action) and all(str(f.get(k))==str(v) for k,v in match.items()):return f
 raise AssertionError(('Form missing',action,match))
def post(o,path,data):
 s=get(o,path);data['csrf_token']=re.search(r'name="csrf_token" value="([^"]+)"',s)[1];return send(o,path,data)
def login(role,user):
 o=session();s=post(o,'index.php',{'identifier':user,'password':'Test-only-password-2026','role':'admin'});assert {'admin':'Administrator dashboard','professor':'My classes','student':'My academic record'}[role] in s;return o
p=login('professor','test.faculty');admin=login('admin','test.registrar')
# Own administrator details belong in account settings, with password verification for a new login name.
s=get(admin,'profile.php');f=form(s,'save_profile');old_profile=dict(f)
f.update({'full_name':'Test Administrator','account_id':'999','role':'student'})
s=send(admin,'profile.php',f);assert 'Account settings saved.' in s and 'Test Administrator' in s
s=get(admin,'registrar.php?view=accounts&role=admin');assert 'aria-label="Open your account settings"' in s
assert not any(x.get('action')=='edit_account' for x in Forms(s).forms)
f=form(get(admin,'profile.php'),'save_profile');invalid=dict(f);invalid.update({'username':'Broken@Username','identity_password':'Test-only-password-2026'})
s=send(admin,'profile.php',invalid);assert 'Enter a complete email address' in s and 'Account settings saved.' not in s
invalid['username']='broken\nusername';s=send(admin,'profile.php',invalid);assert 'Remove unsupported characters' in s
f=form(get(admin,'profile.php'),'save_profile');f.update({'username':'test.registrar.updated','identity_password':'wrong-password'})
s=send(admin,'profile.php',f);assert 'Enter your current password' in s
f['username']='test.faculty';f['identity_password']='Test-only-password-2026'
s=send(admin,'profile.php',f);assert 'already assigned to another account' in s
f['username']='test.registrar.updated';s=send(admin,'profile.php',f);assert 'Account settings saved.' in s
old_profile['full_name']='Stale profile overwrite';old_profile['identity_password']='Test-only-password-2026';s=send(admin,'profile.php',old_profile);assert 'This account changed' in s
o=session();s=post(o,'index.php',{'identifier':'test.registrar.updated','password':'Test-only-password-2026'});assert 'Administrator dashboard' in s
s=post(admin,'registrar.php?view=accounts&role=admin',{'action':'edit_account','role':'admin','account_id':'1'});assert 'Use Account settings' in s
s=post(p,'profile.php',{'action':'save_profile','full_name':'Invalid self-edit','username':'tampered'});assert 'Only administrators' in s
s=post(p,'professor-dashboard.php',{'action':'create_class','subject_code':'IT-HTTP','subject_title':'Complete workflow','section':'A','school_year':'2026-2027','semester':'1st Semester','units':'3'})
class_id=re.search(r'subject_id=(\d+)',s)[1]
def path(view):return 'professor-dashboard.php?view='+view+'&subject_id='+class_id
def act(view,action,extra):
 f=form(get(p,path(view)),action);f.update(extra);return send(p,path(view),f)
s=post(admin,'registrar.php?view=accounts&role=student',{'action':'create_account','role':'student','student_no':'TEST-002','username':'test.two','first_name':'Second','last_name':'Student','email':'secondstudent@example.invalid','course':'BSIT','year_level':'1st Year'});assert 'Account updated' in s
assert 'record-actions' not in s and 'manage-account.php?' in s
second_id=re.search(r'manage-account\.php\?role=student&amp;account_id=(\d+)',s)[1]
second_manage='manage-account.php?role=student&account_id='+second_id
s=get(admin,second_manage);student_form=form(s,'edit_account',username='test.two');second_number=student_form['student_no'];assert re.fullmatch(r'STUD-\d{4}-\d{2}-\d{5}',second_number) and second_number!='TEST-002'
# Directory context is retained and the URL, rather than hidden fields, selects the managed user.
context=get(admin,second_manage+'&q=Second&status=pending&page=2');assert 'registrar.php?view=accounts&amp;role=student&amp;q=Second&amp;status=pending&amp;page=2' in context
f=form(s,'edit_account');f.update({'role':'admin','account_id':'1','reason':'Check managed target remains selected'})
s=send(admin,second_manage,f);assert 'Account updated.' in s and second_number in s
stale=dict(f);stale['first_name']='Stale overwrite';s=send(admin,second_manage,stale);assert 'This account changed' in s
s=get(admin,second_manage)
f=form(s,'issue_code',account_id=second_id);s=send(admin,second_manage,f);code=re.search(r'One-time activation code: ([a-f0-9]{32})',s)[1]
s=get(admin,second_manage);assert code not in s
activate=session();data={'identifier':second_number,'token':code,'password':'Test-only-password-2026','confirm_password':'Test-only-password-2026'}
s=post(activate,'account-activate.php?role=student',data);assert 'Password set.' in s
s=post(activate,'account-activate.php?role=student',data);assert 'already used' in s
for number in ['TEST-001',second_number]:
 s=act('roster','enroll',{'student_no':number,'starts_on':'2026-01-01'});assert 'Saved successfully' in s
s=act('settings','save_policy',{'lecture_percent':'100','midterm_percent':'0','passing_percent':'75','policy_label':'Test attendance subject','conversion':'0 = F\n75 = P'});assert 'Saved successfully' in s
s=get(p,path('criteria'));f=form(s,'save_criteria')
for key in list(f):
 if key.endswith('[weight]'):
  stem=key[:-len('[weight]')];f[key]='100' if f.get(stem+'[name]')=='Attendance' else '0'
s=send(p,path('criteria'),f);assert 'Saved successfully' in s
s=act('attendance','save_rules',{'late_points':'3','absent_points':'0','excused_mode':'exclude','online_full_minutes':'10','online_partial_minutes':'5'});assert 'Saved successfully' in s
s=get(p,path('attendance'));f=form(s,'create_meeting');f['criterion_id']=re.findall(r'<option value="(\d+)"[^>]*>Final(?: Lecture)?',s)[0];criterion=f['criterion_id']
f=form(get(p,path('import')),'preview_import');f.update({'criterion_id':criterion,'meeting_date':'2026-10-10'})
boundary='GRC_TEST_BOUNDARY_72690';parts=[]
for k,v in f.items():parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n')
parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="report"; filename="test.csv"\r\nContent-Type: text/csv\r\n\r\nstudent_no,duration_minutes\nTEST-001,12.5\n{second_number},7\n\r\n--{boundary}--\r\n')
request=urllib.request.Request(BASE+path('import'),''.join(parts).encode(),{'Content-Type':'multipart/form-data; boundary='+boundary})
s=valid(p.open(request).read().decode());assert 'Review test.csv' in s
f=form(s,'commit_import');assert f['statuses[0]']=='present' and f['statuses[1]']=='late';assert f['matches[0]'] and f['matches[1]'];first_enrollment=f['matches[0]'];s=send(p,path('import'),f);assert 'Saved successfully' in s
# Directory -> an older (non-default) sheet -> save must stay on that sheet.
for title in ['Earlier score sheet','Later score sheet']:
 f=form(get(p,path('activities')),'create_activity');f.update({'title':title,'maximum':'10','due_on':''})
 # A normal category with 0% weight keeps the attendance-only formula unchanged.
 page=get(p,path('activities'));normal=re.search(r'<option value="(\d+)"[^>]*>Midterm · Recitation</option>',page)[1]
 f['criterion_id']=normal;s=send(p,path('activities'),f);assert 'Saved successfully' in s
selected_url=unescape(re.search(r'href="([^"]+)"[^>]*aria-label="Open score sheet for Earlier score sheet"',s)[1])
f=form(get(p,selected_url),'save_scores');saved_activity=f['activity_id']
for key in list(f):
 if key.endswith('[status]'):f[key]='scored'
 elif key.endswith('[earned]'):f[key]='0'
response=p.open(BASE+selected_url,urllib.parse.urlencode(f).encode());s=valid(response.read().decode())
assert urllib.parse.parse_qs(urllib.parse.urlsplit(response.url).query)['activity_id']==[saved_activity]
assert form(s,'save_scores')['activity_id']==saved_activity and 'Back to activities' in s
assert not any(x.get('action')=='create_activity' for x in Forms(s).forms)
for title,date in [('Earlier attendance sheet','2026-10-08'),('Later attendance sheet','2026-10-09')]:
 s=act('attendance','create_meeting',{'title':title,'meeting_date':date});assert 'Saved successfully' in s
selected_url=unescape(re.search(r'href="([^"]+)"[^>]*aria-label="Open attendance sheet for Earlier attendance sheet"',s)[1])
f=form(get(p,selected_url),'save_attendance');saved_meeting=f['meeting_id']
for key in list(f):
 if key.startswith('attendance['):f[key]='present'
response=p.open(BASE+selected_url,urllib.parse.urlencode(f).encode());s=valid(response.read().decode())
assert urllib.parse.parse_qs(urllib.parse.urlsplit(response.url).query)['meeting_id']==[saved_meeting]
assert form(s,'save_attendance')['meeting_id']==saved_meeting and 'Back to attendance records' in s
assert not any(x.get('action') in ['create_meeting','save_rules'] for x in Forms(s).forms)
s=get(p,path('grades'));assert '100.00%' in s and '60.00%' in s
for view in ['settings','classes','criteria','roster','activities','attendance','grades','import']:get(p,path(view))
for kind in ['grades','attendance','activities','roster']:
 s=get(p,'report.php?subject_id='+class_id+'&type='+kind)
 csv=get(p,'report.php?subject_id='+class_id+'&type='+kind+'&format=csv');assert 'Student number' in csv
st=login('student','TEST-001');s=get(st,'student-dashboard.php');assert 'Awaiting released grade' in s
s=act('grades','submit',{});assert 'Saved successfully' in s
review='registrar.php?view=classes&subject_id='+class_id
f=form(get(admin,review),'release');s=send(admin,review,f);assert 'Class record updated' in s
s=get(st,'student-dashboard.php');assert '100.00%' in s and '60.00%' not in s
# The student directory links to one released subject; evidence is not bulk-expanded.
assert 'View released grades for IT-HTTP' in s and 'RELEASED SUBJECT RECORD' not in s
s=get(st,'student-dashboard.php?subject_id='+class_id)
assert 'RELEASED SUBJECT RECORD' in s and 'My released report / CSV' in s and '60.00%' not in s
category=re.search(r'href="([^"]+)"[^>]*>Final · Attendance</a>',s)[1]
s=get(st,'student-dashboard.php'+unescape(category))
assert 'Released score evidence' in s and 'Present' in s and 'Late' not in s and re.search(r'>5(?:\.00)? / 5</td>',s), [bool('Released score evidence' in s),bool('Present' in s),bool('Late' in s),re.sub(r'<[^>]+>',' ',s[s.find('RELEASED SUBJECT RECORD'):])[-1400:]]
s=get(st,'student-dashboard.php?subject_id=999999')
assert 'RELEASED SUBJECT RECORD' not in s and '100.00%' in s
s=get(st,'report.php?subject_id='+class_id);assert 'TEST-001' in s and second_number not in s
try:st.open(BASE+'report.php?subject_id='+class_id+'&type=attendance');raise AssertionError('Student read class attendance')
except urllib.error.HTTPError as e:assert e.code==403
st2=login('student',second_number);s=get(st2,'student-dashboard.php');assert '60.00%' in s and '100.00%' not in s
# Account identity corrections cannot rewrite a released snapshot.
accounts=second_manage;f=form(get(admin,accounts),'edit_account',account_id=second_id);f.update({'last_name':'Corrected','reason':'Verified identity correction'});s=send(admin,accounts,f);assert 'Account updated' in s
s=get(st2,'report.php?subject_id='+class_id);assert 'Student, Second' in s and 'Corrected, Second' not in s
# Reopen and change a draft while keeping publication stable.
f=form(get(admin,review),'return');f['reason']='Review attendance correction';send(admin,review,f)
s=get(p,path('attendance'));f=form(s,'save_attendance');keys=[k for k in f if k.startswith('attendance[')];f['attendance['+first_enrollment+']']='late';send(p,path('attendance'),f)
s=get(p,path('grades'));assert s.count('<td>60.00% F</td>')==2 and '<td>100.00% P</td>' not in s
s=get(st,'student-dashboard.php');assert '100.00%' in s
# Selecting a released evidence category must remain on the immutable publication too.
s=get(st,'student-dashboard.php'+unescape(category));assert 'Present' in s and 'Late' not in s and '100.00%' in s
for view in ['overview','accounts','classes','audit','maintenance']:get(admin,'registrar.php?view='+view)
for role in ['professor','student','admin']:get(admin,'registrar.php?view=accounts&role='+role+'&q=Test')
# Recovery payload must not contain temporary codes or sessions.
s=get(admin,'registrar.php?view=maintenance');token=re.search(r'name="csrf_token" value="([^"]+)"',s)[1]
backup=json.loads(send(admin,'backup-download.php',{'csrf_token':token}));assert backup['version']==2 and 'classbook_tokens' not in backup['tables'];assert len(backup['tables']['classbook_releases'])==1
# Password changes invalidate other sessions for this account.
faculty_manage='manage-account.php?role=professor&account_id=1';f=form(get(admin,faculty_manage),'issue_code',account_id='1');s=send(admin,faculty_manage,f)
old_reset_code=re.search(r'One-time activation code: ([a-f0-9]{32})',s)[1]
other=login('professor','test.faculty');s=post(p,'profile.php',{'current_password':'Test-only-password-2026','password':'Changed-test-password-2026','confirm_password':'Changed-test-password-2026'});assert 'Password changed.' in s
s=post(session(),'account-activate.php',{'identifier':'test.faculty','token':old_reset_code,'password':'Old-code-reuse-password-2026','confirm_password':'Old-code-reuse-password-2026'});assert 'invalid, expired or already used' in s and 'Password set.' not in s
s=get(other,'professor-dashboard.php');assert 'My classes' not in s and 'credentials changed' in s
# Management rejects invalid targets, actions and CSRF, and redirects self-service to settings.
s=get(admin,'manage-account.php?role=admin&account_id=1');assert 'Account settings' in s and 'Save account settings' in s
for bad in ['manage-account.php?role=invalid&account_id=1','manage-account.php?role=student&account_id=999999']:
 try:admin.open(BASE+bad);raise AssertionError('Invalid management target accepted')
 except urllib.error.HTTPError as e:assert e.code==404
s=send(admin,faculty_manage,{'action':'deactivate'});assert 'Your form expired' in s
s=post(admin,faculty_manage,{'action':'create_account'});assert 'Choose a valid account action' in s
s=get(st,faculty_manage);assert 'Manage account</h1>' not in s and 'My academic record' in s
# CSRF and role checks still reject unauthorized writes and reads.
s=send(p,'professor-dashboard.php',{'action':'create_class'});assert 'Your form expired' in s
s=get(p,'registrar.php');assert 'My classes' in s and 'Administrator dashboard' not in s
print('PASS:',checks,'HTTP checks: activation, import review, formulas, reports, release privacy, corrections, backup and session invalidation.')
