const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../public/assets/dashboard-qol.js'), 'utf8');

function element(properties = {}) {
    const listeners = new Map();
    return Object.assign({
        addEventListener(type, callback) {
            const callbacks = listeners.get(type) || [];
            callbacks.push(callback);
            listeners.set(type, callbacks);
        },
        emit(type, event = {}) { return Promise.all((listeners.get(type) || []).map(callback => callback(event))); },
        setAttribute() {}, removeAttribute() {},
    }, properties);
}

function openDashboard({ clipboard, reportFrom = '2026-01-01' } = {}) {
    const from = element({ value: '' });
    const to = element({ value: '', setCustomValidity() {} });
    const report = element({ value: '21' });
    const ranges = { hidden: true };
    const rangeHint = { textContent: '' };
    const range90 = element({ dataset: { range: '90' }, disabled: false });
    const apply = element({ disabled: false, textContent: 'Apply filters' });
    const copy = element({ disabled: false });
    const status = { textContent: 'Showing the selected report.' };
    const copyStatus = { textContent: '' };
    let submissions = 0;
    const fields = { from, to, import: report };
    const form = element({
        dataset: { reportFrom, reportTo: '2026-10-25', reportId: '21' },
        elements: { namedItem: name => fields[name] },
        querySelector: selector => ({
            '.quick-ranges': ranges, '.range-hint': rangeHint,
            'button[type="submit"]': apply, '[data-copy-status]': copyStatus,
        })[selector] || null,
        querySelectorAll: () => [range90],
        requestSubmit: () => { submissions++; },
    });
    const nodes = { 'dashboard-filters': form, 'filter-status': status, 'copy-report-link': copy };
    const document = element({ getElementById: id => nodes[id] || null });
    const window = element({
        navigator: { clipboard },
        location: { href: 'https://analytics.example.test/marketplace/dashboard?import=21&tab=dsat&dsat_reason=Speed&dsat_page=2' },
    });
    vm.runInNewContext(source, { document, window });
    return { from, to, copy, copyStatus, status, form, document, window, range90, submissions: () => submissions };
}

test('copy view link waits for a click and copies the currently applied module, tab, and filters', async () => {
    const copied = [];
    const page = openDashboard({ clipboard: { writeText: async text => { copied.push(text); } } });
    assert.deepEqual(copied, []);
    page.window.location.href += '&dsat_search=late';
    page.from.value = '2026-10-01';
    let prevented = false;
    await page.copy.emit('click', { preventDefault: () => { prevented = true; } });
    assert.equal(prevented, true);
    assert.deepEqual(copied, [page.window.location.href]);
    assert.equal(copied[0].includes('from='), false);
    assert.equal(page.submissions(), 0);
    assert.equal(page.copy.disabled, false);
    assert.equal(page.copyStatus.textContent, 'View link copied.');
    assert.equal(page.status.textContent, 'Showing the selected report.');
    await page.document.emit('dashboard:updated');
    assert.equal(page.copyStatus.textContent, '');
});

test('clipboard rejection and unavailable clipboard leave the dashboard usable', async () => {
    for (const clipboard of [undefined, { writeText: async () => { throw new Error('Denied'); } }]) {
        const page = openDashboard({ clipboard });
        await page.copy.emit('click', { preventDefault() {} });
        assert.match(page.copyStatus.textContent, /Copy the address from your browser/);
        assert.equal(page.copy.disabled, false);
        assert.equal(page.submissions(), 0);
        assert.equal(page.status.textContent, 'Showing the selected report.');
    }
});

test('90-day quick range includes its last day and clamps to the report start', async () => {
    const full = openDashboard();
    await full.range90.emit('click');
    assert.equal(full.from.value, '2026-07-28');
    assert.equal(full.to.value, '2026-10-25');
    assert.equal(full.submissions(), 1);
    const short = openDashboard({ reportFrom: '2026-10-01' });
    await short.range90.emit('click');
    assert.equal(short.from.value, '2026-10-01');
    assert.equal(short.to.value, '2026-10-25');
});
