// Run with PHP available and Playwright provided through NODE_PATH or PLAYWRIGHT_MODULE.
// Renders the actual PHP views with synthetic users; never connects to a database.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = path.join(__dirname, '..');
const build = path.join(root, 'build');
fs.mkdirSync(build, { recursive: true });
const renderer = path.join(build, 'users-browser-render.php');
fs.writeFileSync(renderer, `<?php
$_ENV['app.baseURL'] = $_SERVER['app.baseURL'] = 'http://users.test/';
putenv('app.baseURL=http://users.test/');
require dirname(__DIR__) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';
config('App')->baseURL = 'http://users.test/';
config('App')->indexPage = '';
config('Session')->driver = \\CodeIgniter\\Session\\Handlers\\ArrayHandler::class;
service('request')->getUri()->setPath('admin/users');
$input = json_decode($argv[1] ?? '{}', true);
$users = [];
foreach (range(1, 25) as $id) {
    $users[] = ['id' => $id, 'name' => match ($id) {
        1 => 'Ada Chen', 2 => 'Alex Morgan', 3 => 'Beatrice Lee', 4 => 'Charlotte Ng',
        5 => 'Jordan Nguyen', default => 'Team member ' . str_pad((string) $id, 2, '0', STR_PAD_LEFT),
    }, 'email' => $id === 5 ? str_repeat('long.email.', 9) . '@example.test' : 'member' . $id . '@example.test',
    'role' => $id <= 2 ? 'admin' : 'user', 'active' => in_array($id, [3, 5, 6], true) ? 0 : 1,
    'archived' => in_array($id, [4, 5], true) ? 1 : 0, 'created_at' => '2026-10-03 12:00:00'];
}
session()->set(['user_id' => 1, 'user' => $users[0]]);
$stats = ['total' => count($users), 'active' => 0, 'inactive' => 0, 'archived' => 0, 'admins' => 0];
foreach ($users as $user) {
    $stats['admins'] += $user['role'] === 'admin' ? 1 : 0;
    $stats[$user['archived'] ? 'archived' : ($user['active'] ? 'active' : 'inactive')]++;
}
$status = $input['status'] ?? 'all';
$role = $input['role'] ?? 'all';
$search = $input['q'] ?? '';
$users = array_values(array_filter($users, static fn ($user) =>
    ($status === 'all' || ($status === 'archived' ? $user['archived'] : !$user['archived'] && (bool) $user['active'] === ($status === 'active')))
    && ($role === 'all' || $user['role'] === $role)
    && ($search === '' || stripos($user['name'] . ' ' . $user['email'], $search) !== false)));
$total = count($users);
$perPage = 20;
$pages = max(1, (int) ceil($total / $perPage));
$page = min($pages, max(1, (int) ($input['page'] ?? 1)));
$users = array_slice($users, ($page - 1) * $perPage, $perPage);
if (isset($input['recover'])) {
    session()->setFlashdata('user_form', ['mode' => 'create', 'values' => ['name' => 'Unsaved member', 'email' => 'invalid', 'role' => 'user'], 'errors' => ['email' => 'Enter a valid email address.']]);
}
echo view('users/index', compact('users', 'stats', 'total', 'perPage', 'pages', 'page', 'status', 'role', 'search') + ['title' => 'Manage users', 'recoveryUser' => null]);
`);
const rendered = new Map();
const render = params => {
    const key = JSON.stringify(params);
    if (!rendered.has(key)) rendered.set(key, execFileSync(process.env.PHP_BIN || 'php', [renderer, key], { cwd: root, encoding: 'utf8', maxBuffer: 4 * 1024 * 1024 }));
    return rendered.get(key);
};

(async () => {
    const browser = await chromium.launch({ channel: process.env.BROWSER_CHANNEL || 'chrome', headless: true });
    const errors = [];
    let failNext = false;
    const requests = [];
    try {
        const page = await browser.newPage({ viewport: { width: 1440, height: 1000 }, colorScheme: 'light' });
        page.setDefaultTimeout(6000);
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', async route => {
            const url = new URL(route.request().url());
            assert.equal(url.hostname, 'users.test', 'Fixture must stay isolated from external services');
            if (url.pathname.startsWith('/assets/')) {
                const file = path.join(root, 'public', url.pathname);
                const contentType = file.endsWith('.css') ? 'text/css' : file.endsWith('.js') ? 'text/javascript' : 'image/png';
                return route.fulfill({ path: file, contentType });
            }
            assert.equal(route.request().method(), 'GET', 'Browser checks never submit account changes');
            requests.push(url.search);
            if (failNext) { failNext = false; return route.fulfill({ status: 503, body: 'Temporarily unavailable' }); }
            const params = Object.fromEntries(url.searchParams);
            const html = render(params);
            if (params.q === 'Beatrice') await new Promise(resolve => setTimeout(resolve, 450));
            return route.fulfill({ contentType: 'text/html', body: html });
        });
        await page.goto('http://users.test/admin/users');
        await page.waitForFunction(() => document.querySelector('#user-role').classList.contains('enhanced-select'));
        assert.equal(await page.locator('.user-table tbody tr').count(), 20);
        assert.equal(await page.locator('#user-match-count').innerText(), '25 users');
        const dialog = page.locator('#user-editor');
        const search = page.getByRole('searchbox', { name: 'Search users', exact: true });
        const settled = async text => page.waitForFunction(expected => !document.getElementById('user-results').hasAttribute('aria-busy') && document.getElementById('user-match-count').textContent.trim() === expected, text);
        const noOverflow = async () => assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'Page fits the viewport');
        const closed = async () => assert.equal(await dialog.evaluate(node => node.open), false);
        await noOverflow();
        await page.screenshot({ path: path.join(build, 'users-light.png'), fullPage: true });

        await page.getByText('Add user', { exact: true }).click();
        assert.equal(await dialog.evaluate(node => node.open), true);
        assert.equal(await page.locator('#create-name').evaluate(node => document.activeElement === node), true);
        await dialog.getByLabel('Full name', { exact: true }).fill('Draft account');
        await dialog.getByLabel('Password', { exact: true }).fill('temporary-password-123');
        await dialog.getByRole('button', { name: 'Show', exact: true }).click();
        assert.equal(await page.locator('#create-password').getAttribute('type'), 'text');
        await page.keyboard.press('Escape');
        await closed();
        assert.equal(await page.locator('#create-name').inputValue(), '');
        assert.equal(await page.locator('#create-password').inputValue(), '');
        assert.equal(await page.locator('#create-password').getAttribute('type'), 'password');
        assert.equal(await page.locator('#user-create > summary').evaluate(node => document.activeElement === node), true);

        await page.getByLabel('Manage Alex Morgan', { exact: true }).click();
        assert.equal(await dialog.getByLabel('Full name', { exact: true }).inputValue(), 'Alex Morgan');
        await page.screenshot({ path: path.join(build, 'users-editor.png') });
        await dialog.getByLabel('Full name', { exact: true }).fill('Discard this edit');
        await dialog.locator('.password-action > summary').click();
        await dialog.getByLabel('New password', { exact: true }).fill('temporary-password-123');
        await dialog.getByLabel('Confirm new password', { exact: true }).fill('different-password-123');
        assert.equal(await dialog.locator('.user-password-form').evaluate(node => node.checkValidity()), false);
        await dialog.getByLabel('Confirm new password', { exact: true }).fill('temporary-password-123');
        assert.equal(await dialog.locator('.user-password-form').evaluate(node => node.checkValidity()), true);
        await dialog.locator('.user-delete-box > summary').click();
        assert.equal(await dialog.locator('form[action$="/delete"]').evaluate(node => node.checkValidity()), false, 'Deletion requires acknowledgement');
        await dialog.getByLabel('I understand this permanently deletes the account.').check();
        assert.equal(await dialog.locator('form[action$="/delete"]').evaluate(node => node.checkValidity()), true);
        await dialog.getByRole('button', { name: 'Close account settings' }).click();
        await closed();
        await page.getByLabel('Manage Alex Morgan', { exact: true }).click();
        assert.equal(await dialog.getByLabel('Full name', { exact: true }).inputValue(), 'Alex Morgan');
        assert.equal(await dialog.getByLabel('New password', { exact: true }).inputValue(), '');
        assert.equal(await dialog.locator('.password-action').getAttribute('open'), null);
        await page.keyboard.press('Escape');

        await page.getByLabel('Manage Ada Chen', { exact: true }).click();
        assert.equal(await dialog.locator('select[name="role"] option[value="user"]').evaluate(node => node.disabled), true);
        assert.equal(await dialog.locator('form[action$="/delete"], form[action$="/toggle"], form[action$="/archive"]').count(), 0);
        await page.keyboard.press('Escape');

        await page.locator('[data-user-filter-link][href$="status=archived"]').click();
        await settled('2 users');
        assert.equal(await page.locator('#user-status').inputValue(), 'archived');
        assert.equal(await page.locator('.user-stat-archived').getAttribute('aria-current'), 'true');
        await page.getByLabel('Manage Charlotte Ng', { exact: true }).click();
        assert.equal(await dialog.getByRole('button', { name: 'Restore account', exact: true }).isVisible(), true);
        assert.equal(await dialog.locator('form[action$="/toggle"]').count(), 0);
        await page.keyboard.press('Escape');
        await page.getByRole('link', { name: 'Reset', exact: true }).click();
        await settled('25 users');

        await page.getByRole('combobox', { name: 'Role', exact: true }).click();
        await page.getByRole('option', { name: 'Administrator', exact: true }).click();
        await settled('2 users');
        assert.equal(new URL(page.url()).searchParams.get('role'), 'admin');
        await page.goBack();
        await settled('25 users');
        assert.equal(await page.locator('#user-role').inputValue(), 'all');

        await page.getByRole('link', { name: 'Next', exact: true }).click();
        await settled('25 users');
        await page.waitForFunction(() => document.querySelector('.user-page-number').textContent.trim() === 'Page 2 of 2');
        assert.equal(await page.locator('.user-table tbody tr').count(), 5);
        await page.getByRole('link', { name: 'Previous', exact: true }).click();
        await page.waitForFunction(() => document.querySelector('.user-table tbody tr')?.textContent.includes('Ada Chen'));

        await search.fill('Beatrice');
        await page.waitForFunction(() => document.getElementById('user-results').getAttribute('aria-busy') === 'true');
        await page.waitForTimeout(210);
        await search.fill('Alex');
        await settled('1 user');
        await page.waitForTimeout(500);
        assert.match(await page.locator('.user-table tbody').innerText(), /Alex Morgan/);
        assert.equal(new URL(page.url()).searchParams.get('q'), 'Alex');
        await search.fill('nobody-matches');
        await settled('0 users');
        assert.equal(await page.getByText('No matching accounts', { exact: true }).isVisible(), true);
        await page.getByRole('link', { name: 'Clear filters', exact: true }).click();
        await settled('25 users');

        failNext = true;
        await search.fill('Alex');
        await page.waitForFunction(() => document.getElementById('user-search-feedback').textContent.includes('Could not update'));
        assert.equal(await page.locator('.user-table tbody tr').count(), 20, 'Failed search preserves the existing directory');
        await page.getByRole('button', { name: 'Search', exact: true }).click();
        await settled('1 user');
        await page.getByRole('link', { name: 'Reset', exact: true }).click();
        await settled('25 users');

        await page.locator('#theme-toggle').click();
        await page.waitForFunction(() => document.documentElement.dataset.theme === 'dark');
        // The theme commits before its 360 ms snapshot blend has finished.
        await page.waitForTimeout(500);
        await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
        await page.screenshot({ path: path.join(build, 'users-dark.png'), fullPage: true });
        await page.setViewportSize({ width: 390, height: 844 });
        await noOverflow();
        await page.screenshot({ path: path.join(build, 'users-mobile.png'), fullPage: true });
        await page.getByLabel('Manage Alex Morgan', { exact: true }).click();
        assert.equal(await dialog.evaluate(node => {
            const bounds = node.getBoundingClientRect();
            return bounds.left >= 0 && bounds.right <= innerWidth && bounds.top >= 0 && bounds.bottom <= innerHeight;
        }), true, 'Mobile account dialog stays within the viewport');
        await page.screenshot({ path: path.join(build, 'users-mobile-editor.png') });
        await page.keyboard.press('Escape');

        await page.goto('http://users.test/admin/users?recover=1');
        await page.waitForFunction(() => document.getElementById('user-editor').open);
        assert.equal(await dialog.getByLabel('Full name', { exact: true }).inputValue(), 'Unsaved member');
        assert.equal(await dialog.getByLabel('Email address', { exact: true }).evaluate(node => node === document.activeElement), true, 'Validation recovery focuses the invalid field');
        assert.equal(await dialog.getByText('Enter a valid email address.', { exact: true }).isVisible(), true);

        assert.deepEqual(errors, [], 'No browser runtime errors');
        assert(requests.length > 10, 'Server-rendered fixtures exercised filter requests');
        console.log('User directory browser checks passed: dialogs, forms, password validation, access protection, filters, pagination, request races, history, error recovery, themes, and mobile layout.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
