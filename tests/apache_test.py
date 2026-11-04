import urllib.request, urllib.error
base='http://localhost/GRC_Three_Role_Grading_System-main/'
r=urllib.request.urlopen(base+'index.php')
assert r.status==200 and r.url.endswith('index.php')
print('PASS: live Apache entry opens the shared sign-in page.')
for p in ['includes/db.php','database/classbook.sql','tests/http_test.py','GRC_SYSTEM_MASTER_PLAN.html','README.md','README.txt']:
 try:
  urllib.request.urlopen(base+p)
  raise AssertionError(p+' was exposed')
 except urllib.error.HTTPError as e:
  assert e.code==403,(p,e.code)
print('PASS: Apache denies direct access to private includes, SQL, tests, planning workbook and internal README files.')
