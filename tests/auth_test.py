import urllib.request,urllib.parse,urllib.error,http.cookiejar,re
BASE='http://127.0.0.1:8769/'
checks=0
def check(ok,label):
 global checks
 assert ok,label
 checks+=1

def session():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def get(o,path='index.php'):
 r=o.open(BASE+path);s=r.read().decode();check(not re.search(r'Fatal error|Parse error|<b>Warning</b>',s),'no runtime error');return s,r

def send(o,data,path='index.php',csrf=True):
 s,_=get(o,path)
 if csrf:data['csrf_token']=re.search(r'name="csrf_token" value="([^"]+)"',s)[1]
 r=o.open(BASE+path,urllib.parse.urlencode(data).encode());s=r.read().decode();check(not re.search(r'Fatal error|Parse error|<b>Warning</b>',s),'no runtime error');return s,r

anon=session();s,r=get(anon)
check('GRC_SYSTEM_MASTER_PLAN' not in s and 'Planning workbook' not in s,'internal workbook absent from public DOM')
check('School ID or registered email' in s,'school ID primary label')
cookies=' '.join(r.headers.get_all('Set-Cookie') or [])
check('GRC_GRADING_SESSION=' in cookies and 'HttpOnly' in cookies and 'SameSite=Lax' in cookies,'isolated cookie flags')
s,_=send(anon,{})
check('Enter your school ID or registered email.' in s and 'Enter your password.' in s,'server validates empty fields')
check('aria-invalid="true"' in s,'server marks fields invalid')
s,_=send(anon,{'identifier':'bad@email','password':'notempty'})
check('Enter a complete email address' in s,'server validates email format')
s,_=send(anon,{'identifier':'TEST-001','password':'x'*73})
check('up to 72 bytes' in s,'server rejects oversize password')
s,_=send(anon,{'identifier[]':'array','password[]':'array'})
check('Enter your school ID' in s and 'Enter your password' in s,'array payload rejected without warnings')
s,_=send(anon,{'identifier':'TEST-001','password':'notempty'},csrf=False)
check('Your form expired' in s,'CSRF missing rejected')
marker='Never-reflect-password-2026'
s,_=send(anon,{'identifier':'unknown-account','password':marker})
check('Unable to sign in.' in s and marker not in s,'generic failure and no password reflection')
s,_=send(anon,{'identifier':"'OR'1'='1",'password':marker})
check('Unable to sign in.' in s,'prepared lookup rejects injection login')
s,_=send(anon,{'identifier':'<script>alert(1)</script>','password':marker})
check('<script>alert(1)</script>' not in s and '&lt;script&gt;' in s,'identifier output escaped')
for role,user in [('student','TEST-001'),('professor','TEST'),('admin','test.registrar')]:
 o=session();before,_=get(o);oldtoken=re.search(r'name="csrf_token" value="([^"]+)"',before)[1]
 body,r=send(o,{'identifier':user,'password':'Test-only-password-2026','role':'admin'})
 expected={'student':'student-dashboard.php','professor':'professor-dashboard.php','admin':'registrar.php'}[role]
 check(r.url.endswith(expected),'role derived from credentials')
 check('GRC_SYSTEM_MASTER_PLAN' not in body,'no planning link in role workspace')
 newtoken=re.search(r'name="csrf_token" value="([^"]+)"',body)[1]
 check(oldtoken!=newtoken,'CSRF rotates after authentication')
 if role=='admin':admin=o
# Same message for a known deactivated account and unknown credentials.
accounts='manage-account.php?role=professor&account_id=1'
body,_=get(admin,accounts)
revision=re.search(r'name="account_revision" value="(\d+)"',body)[1]
s,_=send(admin,{'action':'deactivate','role':'professor','account_id':'1','account_revision':revision,'reason':'Isolated authentication test'},accounts)
s,_=send(session(),{'identifier':'test.faculty','password':'Test-only-password-2026'})
check('Unable to sign in.' in s and 'deactivated' not in s,'generic inactive-account failure')
body,_=get(admin,accounts);revision=re.search(r'name="account_revision" value="(\d+)"',body)[1]
send(admin,{'action':'approve','role':'professor','account_id':'1','account_revision':revision,'reason':'Restore isolated test account'},accounts)
for i in range(11):s,_=send(anon,{'identifier':'unknown-rate-test','password':marker})
check('Too many sign-in attempts' in s,'persistent login throttling')
for path in ['GRC_SYSTEM_MASTER_PLAN.html','private/GRC_SYSTEM_MASTER_PLAN.html']:
 try:anon.open(BASE+path);raise AssertionError('Internal workbook was served')
 except urllib.error.HTTPError as e:check(e.code in (403,404),'workbook unavailable in development server')
print('PASS:',checks,'authentication checks: server validation, privacy, cookies, role routing, CSRF, generic failures, escaping and throttling.')
