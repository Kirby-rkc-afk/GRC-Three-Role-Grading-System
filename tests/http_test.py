import urllib.request,urllib.parse,http.cookiejar,re
base='http://127.0.0.1:8769/'
count=0
def session():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def get(o,path):
 global count
 r=o.open(base+path);s=r.read().decode();assert r.status==200
 assert not re.search(r'(Fatal error|Warning:|Parse error)',s),s[:1000]
 count+=1;return s

def post(o,path,data):
 s=get(o,path);data['csrf_token']=re.search(r'name="csrf_token" value="([^"]+)"',s)[1]
 r=o.open(base+path,urllib.parse.urlencode(data).encode());s=r.read().decode();assert not re.search(r'(Fatal error|Warning:|Parse error)',s),s[:1000];return s
p=session();s=post(p,'index.php',{'identifier':'test.faculty','password':'Test-only-password-2026'});assert 'My classes' in s
s=post(p,'professor-dashboard.php',{'action':'create_class','subject_code':'IT-TEST','subject_title':'Web development','section':'A','school_year':'2026-2027','semester':'1st Semester','units':'3'})
id=re.search(r'subject_id=(\d+)',s)[1]
def act(view,data):
 path='professor-dashboard.php?view='+view+'&subject_id='+id
 s=get(p,path);data.update({'subject_id':id,'revision':re.search(r'name="revision" value="(\d+)"',s)[1]});return post(p,path,data)
s=act('roster',{'action':'enroll','student_no':'TEST-001','starts_on':'2026-01-01'});assert 'Saved successfully' in s
s=get(p,'professor-dashboard.php?view=activities&subject_id='+id);criterion=re.search(r'name="criterion_id".*?<option value="(\d+)"',s,re.S)[1]
s=act('activities',{'action':'create_activity','criterion_id':criterion,'title':'Recitation test','maximum':'10'});assert 'Recitation test' in s
s=get(p,'professor-dashboard.php?view=attendance&subject_id='+id);criterion=re.search(r'name="criterion_id".*?<option value="(\d+)"',s,re.S)[1]
s=act('attendance',{'action':'create_meeting','criterion_id':criterion,'title':'Class meeting','meeting_date':'2026-10-10'});assert 'Class meeting' in s
for view in ['classes','criteria','roster','activities','attendance','grades','import','google']:get(p,'professor-dashboard.php?view='+view+'&subject_id='+id)
# CSRF failure is displayed and causes no save.
r=p.open(base+'professor-dashboard.php',urllib.parse.urlencode({'action':'create_class'}).encode()).read().decode();assert 'Your form expired' in r
# Professor must not read Registrar pages.
s=get(p,'registrar.php');assert 'My classes' in s and 'Registrar workspace' not in s
admin=session();s=post(admin,'index.php',{'identifier':'test.registrar','password':'Test-only-password-2026'});assert 'Registrar workspace' in s
for view in ['overview','accounts','classes','audit']:get(admin,'registrar.php?view='+view)
get(admin,'registrar.php?view=classes&subject_id='+id)
st=session();s=post(st,'index.php',{'identifier':'TEST-001','password':'Test-only-password-2026'});assert 'Awaiting released grade' in s;assert 'Recitation test' not in s
get(st,'profile.php');get(st,'professor-dashboard.php?subject_id='+id)
print('PASS:',count,'HTTP page checks, role sign-ins, form submissions, CSRF rejection and unpublished-grade privacy.')
