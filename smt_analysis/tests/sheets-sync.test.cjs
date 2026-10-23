const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../public/assets/sheets-sync.js'), 'utf8');

function page({url = 'http://localhost/dashboard?import=7&period=weekly', state = 'unchanged', ok = true, hidden = false} = {}) {
    const message = {textContent: ''};
    const token = {name: 'csrf_test_name', value: 'old-token'};
    const logoutToken = {name: 'csrf_test_name', value: 'old-token'};
    const panel = {
        dataset: {sheetSync: '/sheets/refresh', sheetImport: '7', sheetUpdated: '2026-10-23 01:00:00'},
        querySelector: selector => selector.includes('input') ? token : message,
    };
    const events = new Map();
    const requests = [];
    const navigations = [];
    let interval;
    const document = {
        hidden, querySelector: () => panel,
        querySelectorAll: () => [token, logoutToken],
        addEventListener: (event, callback) => events.set(event, callback),
    };
    const result = {state, import_id: 7, updated_at: '2026-10-23 01:15:00', csrf_name: 'csrf_test_name', csrf_hash: 'new-token', message: 'The last successful report is still available.'};
    vm.runInNewContext(source, {
        document, URL, URLSearchParams, Date, Error,
        window: {location: {href: url, replace: value => navigations.push(value)}, setInterval: callback => { interval = callback; }},
        fetch: async (endpoint, options) => { requests.push({endpoint, options}); return {ok, status: ok ? 200 : 503, json: async () => result}; },
    });
    return {message, token, logoutToken, requests, navigations, document, result, tick: () => interval(), visibility: () => events.get('visibilitychange')()};
}
const flushed = () => new Promise(resolve => setImmediate(resolve));

test('automatic check uses CSRF and rotates all form tokens without reloading an unchanged report', async () => {
    const tab = page();
    await flushed();
    assert.equal(tab.requests[0].options.method, 'POST');
    assert.equal(tab.requests[0].options.body.get('csrf_test_name'), 'old-token');
    assert.equal(tab.token.value, 'new-token');
    assert.equal(tab.logoutToken.value, 'new-token');
    assert.equal(tab.navigations.length, 0);
    await tab.tick();
    assert.equal(tab.requests[1].options.body.get('csrf_test_name'), 'new-token');
});

test('a changed connected report reloads while preserving period and date filters', async () => {
    const tab = page({state: 'synced', url: 'http://localhost/dashboard?import=7&period=monthly&from=2026-10-01'});
    await flushed();
    const navigation = new URL(tab.navigations[0]);
    assert.equal(navigation.searchParams.get('import'), '7');
    assert.equal(navigation.searchParams.get('period'), 'monthly');
    assert.equal(navigation.searchParams.get('from'), '2026-10-01');
});

test('a refresh does not replace an independently selected uploaded report', async () => {
    const tab = page({state: 'synced', url: 'http://localhost/dashboard?import=42'});
    await flushed();
    assert.equal(tab.navigations.length, 0);
});

test('hidden dashboards defer automatic network checks until visible', async () => {
    const tab = page({hidden: true});
    await flushed();
    assert.equal(tab.requests.length, 0);
    tab.document.hidden = false;
    await tab.visibility();
    assert.equal(tab.requests.length, 1);
});

test('failed syncs retain the report and remain retryable with the new CSRF token', async () => {
    const tab = page({state: 'error', ok: false});
    await flushed();
    assert.equal(tab.navigations.length, 0);
    assert.match(tab.message.textContent, /last successful report/);
    await tab.tick();
    assert.equal(tab.requests.length, 2);
    assert.equal(tab.requests[1].options.body.get('csrf_test_name'), 'new-token');
});

test('pausing the connection stops repeated automatic checks', async () => {
    const tab = page({state: 'disabled'});
    await flushed();
    await tab.tick();
    assert.equal(tab.requests.length, 1);
    assert.match(tab.message.textContent, /paused/);
});
