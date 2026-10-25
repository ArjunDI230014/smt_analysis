// Run with Playwright available through NODE_PATH or PLAYWRIGHT_MODULE; no server is needed.
const assert = require('node:assert/strict');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assets = path.join(__dirname, '../public/assets');

(async () => {
    const browser = await chromium.launch({ channel: process.env.BROWSER_CHANNEL || 'chrome', headless: true });
    try {
        const page = await browser.newPage({ viewport: { width: 1100, height: 900 }, locale: 'en-US' });
        page.setDefaultTimeout(5000);
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.setContent(`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body>
            <button id="theme-toggle" type="button"><span class="theme-icon"></span><span class="theme-label">Theme</span></button>
            <form id="fixture" class="fixture" data-report-to="2024-02-29">
                <label>View<select name="view"><option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option></select></label>
                <label>Team<select name="team">${Array.from({ length: 10 }, (_, i) => `<option value="${i}">Team ${i}</option>`).join('')}</select></label>
                <label>Required choice<select name="required" required><option value="">Choose one</option><option value="yes">Chosen</option></select></label>
                <label>Grouped<select name="grouped"><option value="a">Alpha</option><optgroup label="Unavailable" disabled><option value="b">Beta</option></optgroup><option value="c" disabled>Charlie</option><option value="d">Delta</option></select></label>
                <fieldset id="disabled-fields" disabled><legend>Disabled controls</legend><label>Unavailable<select name="disabled"><option>Unavailable</option></select></label><label>Disabled date<input type="date" name="disabled_date"></label></fieldset>
                <label>From<input type="date" name="from" value="2024-02-28" min="2024-02-01" max="2024-03-31"></label>
                <label>To<input type="date" name="to" required value="2024-03-01"></label>
                <label>Any date<input type="date" name="any_date"></label>
                <label>Native list<select multiple name="native"><option>A</option><option>B</option></select></label>
                <button type="reset">Reset form</button><button type="submit">Submit form</button>
            </form><div id="dashboard-results"></div><button id="outside">Outside</button></body></html>`);
        for (const file of ['app.css', 'enhancements.css', 'controls.css']) await page.addStyleTag({ path: path.join(assets, file) });
        await page.addStyleTag({ content: '.fixture{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;max-width:700px;padding:18px}.fixture fieldset{min-width:0}#outside{margin:18px}@media(max-width:600px){.fixture{grid-template-columns:minmax(0,1fr)}}' });
        await page.evaluate(() => {
            window.events = [];
            const form = document.getElementById('fixture');
            ['input', 'change'].forEach(type => form.addEventListener(type, event => window.events.push(`${event.target.name}:${type}`)));
            form.addEventListener('submit', event => event.preventDefault());
        });
        for (const file of ['theme.js', 'select-controls.js', 'date-controls.js']) await page.addScriptTag({ path: path.join(assets, file) });
        const combo = name => page.getByRole('combobox', { name, exact: true });
        const popup = () => page.locator('.select-popover:visible');
        const calendar = () => page.getByRole('dialog');
        const dateTrigger = name => page.getByRole('button', { name: `Choose ${name} date`, exact: true });
        const value = name => page.locator(`[name="${name}"]`).inputValue();
        const formValue = name => page.evaluate(key => new FormData(document.getElementById('fixture')).get(key), name);
        const focusName = () => page.evaluate(() => document.activeElement.getAttribute('aria-label') || document.activeElement.textContent.trim());

        await page.getByText('View', { exact: true }).click();
        assert.equal(await combo('View').evaluate(node => node === document.activeElement), true, 'Label focuses enhanced select');
        await page.keyboard.press('Escape');
        await page.keyboard.press('ArrowDown');
        await page.keyboard.press('ArrowDown');
        await page.keyboard.press('Enter');
        assert.equal(await value('view'), 'weekly');
        assert.equal(await formValue('view'), 'weekly');
        assert.deepEqual(await page.evaluate(() => window.events.slice(-2)), ['view:input', 'view:change']);
        await combo('View').press('End');
        await page.keyboard.press('Enter');
        assert.equal(await value('view'), 'monthly');
        await combo('View').press('Home');
        await page.keyboard.press('Enter');
        assert.equal(await value('view'), 'daily');
        await combo('View').press('w');
        await page.keyboard.press('Enter');
        assert.equal(await value('view'), 'weekly', 'Typeahead selects matching option');

        await combo('Team').click();
        const search = page.getByRole('combobox', { name: 'Search options', exact: true });
        await search.fill('not present');
        assert.equal(await popup().getByText('No matching options').isVisible(), true);
        await search.press('Enter');
        assert.equal(await value('team'), '0');
        await search.fill('Team 9');
        await search.press('Enter');
        assert.equal(await formValue('team'), '9');
        await combo('Team').click();
        await search.press('Escape');
        assert.equal(await combo('Team').evaluate(node => node === document.activeElement), true);
        await combo('Team').click();
        await search.press('Tab');
        assert.equal(await combo('Required choice').evaluate(node => node === document.activeElement), true, 'Search Tab resumes form order');
        await combo('Team').click();
        await search.press('Shift+Tab');
        assert.equal(await combo('View').evaluate(node => node === document.activeElement), true, 'Search Shift Tab resumes reverse form order');
        await combo('Grouped').press('ArrowDown');
        assert.equal(await popup().getByRole('option', { name: 'Beta' }).getAttribute('aria-disabled'), 'true');
        await page.keyboard.press('ArrowDown');
        await page.keyboard.press('Enter');
        assert.equal(await value('grouped'), 'd', 'Disabled options and groups are skipped');
        assert.equal(await combo('Unavailable').isDisabled(), true);
        assert.equal(await dateTrigger('Disabled date').isDisabled(), true);
        await page.evaluate(() => { document.getElementById('disabled-fields').disabled = false; });
        await page.waitForFunction(() => !document.querySelector('#disabled-fields .select-trigger').disabled);
        assert.equal(await dateTrigger('Disabled date').isEnabled(), true);

        await page.getByRole('button', { name: 'Submit form', exact: true }).click();
        assert.equal(await combo('Required choice').getAttribute('aria-invalid'), 'true');
        assert.equal(await combo('Required choice').evaluate(node => node === document.activeElement), true);
        await combo('Required choice').press('End');
        await page.keyboard.press('Enter');
        assert.equal(await page.locator('#fixture').evaluate(form => form.checkValidity()), true);
        await page.getByRole('button', { name: 'Reset form', exact: true }).click();
        assert.equal(await value('team'), '0');
        await page.waitForFunction(() => document.querySelector('[name="team"]').nextElementSibling.textContent.includes('Team 0'));
        assert.match(await combo('Team').innerText(), /Team 0/);
        assert.equal(await combo('Required choice').getAttribute('aria-invalid'), null);

        await dateTrigger('From').click();
        assert.match(await calendar().locator('.date-title').textContent(), /February 2024/);
        assert.equal(await calendar().locator('[data-date="2024-01-31"]').isDisabled(), true);
        assert.equal(await calendar().getByRole('button', { name: 'Previous month' }).isDisabled(), true);
        await page.keyboard.press('ArrowRight');
        await page.keyboard.press('Enter');
        assert.equal(await value('from'), '2024-02-29', 'Leap day can be selected by keyboard');
        assert.deepEqual(await page.evaluate(() => window.events.slice(-2)), ['from:input', 'from:change']);
        await dateTrigger('From').click();
        await page.keyboard.press('PageDown');
        assert.match(await focusName(), /March 29, 2024/);
        await page.keyboard.press('End');
        await page.keyboard.press('ArrowDown');
        await page.keyboard.press('Enter');
        assert.equal(await value('from'), '2024-03-31', 'Keyboard navigation honors maximum');
        await dateTrigger('From').click();
        await calendar().getByRole('button', { name: 'Clear', exact: true }).click();
        assert.equal(await formValue('from'), '');
        await dateTrigger('To').click();
        assert.equal(await calendar().getByRole('button', { name: 'Clear', exact: true }).isVisible(), false);
        await page.keyboard.press('Escape');
        await dateTrigger('Any date').click();
        await calendar().getByRole('button', { name: 'Today', exact: true }).click();
        const today = await page.evaluate(() => { const now = new Date(); return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`; });
        assert.equal(await value('any_date'), today);

        await combo('View').click();
        await dateTrigger('From').click();
        assert.equal(await popup().count(), 0, 'Calendar dismisses other dropdown');
        await combo('Team').click();
        assert.equal(await calendar().count(), 0, 'Dropdown dismisses calendar');
        await page.locator('#outside').click();
        assert.equal(await popup().count(), 0);
        for (const theme of ['light', 'dark']) {
            await page.emulateMedia({ colorScheme: theme });
            await page.evaluate(value => { document.documentElement.dataset.theme = value; }, theme);
            await combo('View').click();
            assert.equal(await popup().evaluate(node => getComputedStyle(node).backgroundColor), theme === 'dark' ? 'rgb(36, 40, 49)' : 'rgb(255, 255, 255)');
            await page.keyboard.press('Escape');
            await dateTrigger('From').click();
            assert.equal(await calendar().evaluate(node => getComputedStyle(node).backgroundColor), theme === 'dark' ? 'rgb(36, 40, 49)' : 'rgb(255, 255, 255)');
            await page.keyboard.press('Escape');
        }
        for (let i = 0; i < 3; i++) {
            await page.locator('#dashboard-results').evaluate((node, index) => { node.innerHTML = `<label>Dynamic<select name="dynamic"><option>${index}</option><option>Other</option></select></label><label>Dynamic day<input type="date"></label>`; document.dispatchEvent(new CustomEvent('dashboard:updated')); }, i);
            await combo('Dynamic').waitFor();
        }
        assert.equal(await page.locator('.select-popover').count(), await page.locator('select.enhanced-select').count());
        assert.equal(await page.locator('.date-control').count(), await page.locator('input[type="date"]').count());
        for (const width of [320, 390]) {
            await page.setViewportSize({ width, height: 740 });
            for (const trigger of [combo('Team'), dateTrigger('From')]) {
                await trigger.click();
                const box = await page.locator('.select-popover:visible, .date-popover').boundingBox();
                assert.ok(box.x >= 0 && box.x + box.width <= width + 1, `Popup fits ${width}px viewport`);
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
                await page.keyboard.press('Escape');
            }
        }
        assert.deepEqual(errors, []);
        console.log('Form controls browser checks passed: keyboard, forms, validation, disabled states, leap dates, themes, replacements, mobile.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
