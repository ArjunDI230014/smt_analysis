"""Run against the isolated smoke database. Optional argument: a real Daily CSAT workbook."""
import http.cookiejar
import json
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

BASE = 'http://localhost:8080/'
PASSWORD = 'Preview-only-test-123!'


def client():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))


def get(agent, path):
    with agent.open(BASE + path) as response:
        return response.read().decode(), response.geturl()


def token(html):
    return re.search(r'name="csrf_test_name" value="([^"]+)"', html).group(1)


def post(agent, path, data):
    with agent.open(BASE + path, urllib.parse.urlencode(data).encode()) as response:
        return response.read().decode(), response.geturl()


admin = client()
login, _ = get(admin, 'login')
dashboard, _ = post(admin, 'login', {'csrf_test_name': token(login), 'email': 'admin@example.test', 'password': PASSWORD})
assert 'Admin access' in dashboard
if len(sys.argv) > 1:
    workbook = Path(sys.argv[1])
    upload, _ = get(admin, 'admin/uploads')
    boundary = '----CsatSmokeBoundary2026'
    body = (f'--{boundary}\r\nContent-Disposition: form-data; name="csrf_test_name"\r\n\r\n{token(upload)}\r\n'
            f'--{boundary}\r\nContent-Disposition: form-data; name="workbook"; filename="{workbook.name}"\r\n'
            'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet\r\n\r\n').encode()
    body += workbook.read_bytes() + f'\r\n--{boundary}--\r\n'.encode()
    request = urllib.request.Request(BASE + 'admin/uploads', body, {'Content-Type': f'multipart/form-data; boundary={boundary}'})
    with admin.open(request, timeout=120) as response:
        result = response.read().decode()
    assert 'imported successfully' in result or 'already been imported' in result, re.sub('<[^>]+>', '', result)[-3000:]

dashboard, _ = get(admin, 'dashboard')
data = json.loads(re.search(r'<script id="report-data" type="application/json">(.*?)</script>', dashboard, re.S).group(1))
assert data['summary']['total'] == 2261, data['summary']
assert data['summary']['good'] == 1796
assert data['summary']['bad'] == 465
assert data['summary']['score'] == 79.43
assert data['summary']['flagged'] == 0
all_rows, _ = get(admin, 'dashboard?scope=all')
data = json.loads(re.search(r'<script id="report-data" type="application/json">(.*?)</script>', all_rows, re.S).group(1))
assert data['summary']['total'] == 4786, data['summary']
assert data['summary']['good'] == 3605
assert data['summary']['bad'] == 1181
assert data['summary']['score'] == 75.32
assert data['summary']['flagged'] == 2525
filtered, _ = get(admin, 'dashboard?scope=included')
assert '79.43' in filtered
empty, _ = get(admin, 'dashboard?from=2026-09-07')
assert 'No responses match' in empty
try:
    post(admin, 'admin/users', {'name': 'Forged'})
    # Production CSRF rejection redirects with a flash message; no account must be created.
except urllib.error.HTTPError as error:
    assert error.code == 403
users, _ = get(admin, 'admin/users')
assert 'Forged' not in users
viewer = client()
login, _ = get(viewer, 'login')
dashboard, _ = post(viewer, 'login', {'csrf_test_name': token(login), 'email': 'user@example.test', 'password': PASSWORD})
assert 'View access' in dashboard and 'Manage users' not in dashboard
for path in ['admin/uploads', 'admin/users']:
    try:
        get(viewer, path)
        raise AssertionError('Viewer reached ' + path)
    except urllib.error.HTTPError as error:
        assert error.code == 403
try:
    post(viewer, 'admin/uploads', {'csrf_test_name': token(dashboard)})
    raise AssertionError('Viewer could submit upload')
except urllib.error.HTTPError as error:
    assert error.code == 403
dashboard, _ = get(viewer, 'dashboard')
post(viewer, 'logout', {'csrf_test_name': token(dashboard)})
_, url = get(viewer, 'dashboard')
assert url.endswith('/login')
dashboard, _ = get(admin, 'dashboard')
post(admin, 'logout', {'csrf_test_name': token(dashboard)})
print('HTTP smoke passed: sample import/totals, filters, CSRF, admin/viewer permissions, logout.')
