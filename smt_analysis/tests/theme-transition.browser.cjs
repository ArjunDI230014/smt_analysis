// Run with Playwright available through NODE_PATH or PLAYWRIGHT_MODULE; no server is needed.
const assert = require('node:assert/strict');
const path = require('node:path');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assets = path.join(__dirname, '../public/assets');

(async () => {
    const browser = await chromium.launch({ channel: process.env.BROWSER_CHANNEL || 'chrome', headless: true });
    const errors = [];
    try {
        const fixture = async ({ fallback = false, reducedMotion = 'no-preference' } = {}) => {
            const page = await browser.newPage({ viewport: { width: 1000, height: 750 }, colorScheme: 'light', reducedMotion });
            page.setDefaultTimeout(5000);
            page.on('pageerror', error => errors.push(error.message));
            await page.setContent(`<!doctype html><html><head></head><body>
                <button class="theme-toggle" id="theme-toggle" type="button"><span class="theme-icon"></span><span class="theme-label"></span></button>
                <section class="panel" style="margin:24px;padding:24px;max-width:500px">
                    <p id="fixture-copy" style="color:var(--ink)">Readable feedback in either appearance.</p>
                    <label>View<select name="view"><option>Daily</option><option>Weekly</option></select></label>
                    <label>From<input type="date" name="from" value="2024-02-29"></label>
                    <canvas id="chart-fixture" width="300" height="90"></canvas>
                </section></body></html>`);
            for (const file of ['app.css', 'enhancements.css', 'controls.css']) await page.addStyleTag({ path: path.join(assets, file) });
            await page.evaluate(missing => {
                window.transitions = [];
                window.themeEvents = [];
                window.unhandled = [];
                window.addEventListener('unhandledrejection', event => window.unhandled.push(String(event.reason)));
                const native = document.startViewTransition?.bind(document);
                window.supportsTransitions = Boolean(native) && !missing;
                Object.defineProperty(document, 'startViewTransition', { configurable: true, value: missing || !native ? undefined : update => {
                    const transition = native(update);
                    transition.ready.catch(() => {});
                    window.transitions.push(transition);
                    return transition;
                } });
                document.addEventListener('themechange', event => {
                    window.themeEvents.push(event.detail.theme);
                    const canvas = document.getElementById('chart-fixture');
                    const context = canvas.getContext('2d');
                    context.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--chart-text').trim();
                    context.fillRect(0, 0, canvas.width, canvas.height);
                    canvas.dataset.theme = event.detail.theme;
                });
            }, fallback);
            for (const file of ['theme.js', 'select-controls.js', 'date-controls.js']) await page.addScriptTag({ path: path.join(assets, file) });
            assert.equal(await page.getAttribute('html', 'data-theme'), 'light');
            assert.equal(await page.evaluate(() => window.transitions.length), 0, 'Initial appearance never starts a screenshot transition');
            assert.equal(await page.locator('html').evaluate(root => root.classList.contains('theme-transition')), false);
            assert.equal(await page.evaluate(() => document.getAnimations({ subtree: true }).filter(animation => animation.playState === 'running').length), 0, 'Initial appearance does not animate');
            return page;
        };
        const finish = page => page.evaluate(async () => {
            await Promise.allSettled(window.transitions.flatMap(transition => [transition.updateCallbackDone, transition.finished]));
        });
        const mode = page => page.getAttribute('html', 'data-theme');
        const runningAnimations = page => page.evaluate(() => document.getAnimations({ subtree: true }).filter(animation => animation.playState === 'running').map(animation => ({
            duration: animation.effect.getComputedTiming().duration,
            property: animation.transitionProperty || animation.animationName,
        })));

        const native = await fixture();
        if (await native.evaluate(() => window.supportsTransitions)) {
            for (const target of ['dark', 'light']) {
                await native.evaluate(async () => {
                    document.getElementById('theme-toggle').click();
                    const transition = window.transitions.at(-1);
                    await transition.updateCallbackDone;
                    await transition.ready;
                });
                assert.equal(await mode(native), target);
                assert.equal(await native.locator('#chart-fixture').getAttribute('data-theme'), target, 'Canvas receives the new palette before the snapshot fades');
                assert.ok((await runningAnimations(native)).some(animation => animation.duration >= 300 && animation.duration <= 450), 'A real page crossfade runs');
                await finish(native);
            }
            await native.evaluate(() => {
                const toggle = document.getElementById('theme-toggle');
                toggle.click(); toggle.click(); toggle.click();
            });
            await finish(native);
            assert.equal(await mode(native), 'dark', 'Rapid odd clicks settle to the final requested mode');
            assert.equal(await native.locator('#theme-toggle').getAttribute('aria-label'), 'Switch to light mode');
            await native.evaluate(async () => {
                document.getElementById('theme-toggle').click();
                await window.transitions.at(-1).ready;
            });
            await native.emulateMedia({ reducedMotion: 'reduce' });
            await finish(native);
            assert.equal((await runningAnimations(native)).length, 0, 'Enabling reduced motion stops the active fade');
            const count = await native.evaluate(() => window.transitions.length);
            await native.locator('#theme-toggle').click();
            assert.equal(await native.evaluate(() => window.transitions.length), count, 'Reduced motion bypasses screenshot transitions');
        }
        assert.deepEqual(await native.evaluate(() => window.unhandled), []);
        await native.close();

        const fallback = await fixture({ fallback: true });
        await fallback.getByRole('combobox', { name: 'View', exact: true }).click();
        const colors = () => fallback.evaluate(() => Object.fromEntries([
            ['page', document.body, 'backgroundColor'],
            ['text', document.getElementById('fixture-copy'), 'color'],
            ['dropdown', document.querySelector('.select-popover:not([hidden])'), 'backgroundColor'],
        ].map(([key, node, property]) => [key, getComputedStyle(node)[property]])));
        const before = await colors();
        await fallback.evaluate(() => document.getElementById('theme-toggle').click());
        assert.equal(await mode(fallback), 'dark');
        await fallback.evaluate(() => new Promise(resolve => setTimeout(resolve, 100)));
        const middle = await colors();
        assert.ok((await runningAnimations(fallback)).some(animation => animation.duration >= 300), 'Fallback uses real CSS color transitions');
        await fallback.waitForFunction(() => !document.documentElement.classList.contains('theme-transition'));
        const after = await colors();
        for (const key of Object.keys(before)) {
            assert.notEqual(before[key], after[key], `${key} changes with appearance`);
            assert.notEqual(middle[key], before[key], `${key} has started fading`);
            assert.notEqual(middle[key], after[key], `${key} interpolates instead of snapping`);
        }
        assert.equal(await fallback.evaluate(() => window.transitions.length), 0);
        await fallback.evaluate(() => document.getElementById('theme-toggle').click());
        await fallback.emulateMedia({ reducedMotion: 'reduce' });
        await fallback.waitForFunction(() => !document.documentElement.classList.contains('theme-transition'));
        assert.equal((await runningAnimations(fallback)).length, 0);
        assert.deepEqual(await fallback.evaluate(() => window.unhandled), []);
        await fallback.close();

        const reduced = await fixture({ reducedMotion: 'reduce' });
        await reduced.locator('#theme-toggle').click();
        assert.equal(await mode(reduced), 'dark');
        assert.equal(await reduced.evaluate(() => window.transitions.length), 0);
        assert.equal(await reduced.locator('html').evaluate(root => root.classList.contains('theme-transition')), false);
        assert.equal((await runningAnimations(reduced)).length, 0);
        assert.deepEqual(await reduced.evaluate(() => window.unhandled), []);
        assert.deepEqual(errors, []);
        console.log('Theme transition browser checks passed: page crossfade, canvas synchronization, rapid toggles, CSS fallback interpolation, reduced motion.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
