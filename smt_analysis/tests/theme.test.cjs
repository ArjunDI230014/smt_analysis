const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../public/assets/theme.js'), 'utf8');

function openPage({ saved = null, dark = false, blocked = false, reducedMotion = false, controls = true } = {}) {
    const stored = new Map(saved === null ? [] : [['smt-theme', saved]]);
    const classes = new Set();
    const documentEvents = new Map();
    const windowEvents = new Map();
    const changes = [];
    const root = { dataset: {}, classList: { add: value => classes.add(value), remove: value => classes.delete(value) } };
    const element = () => ({
        attributes: {}, events: {}, value: '',
        addEventListener(type, callback) { this.events[type] = callback; },
        setAttribute(name, value) { this.attributes[name] = value; },
        removeAttribute(name) { delete this.attributes[name]; },
    });
    const button = element();
    const selector = element();
    const label = {};
    const icon = {};
    button.querySelector = name => name === '.theme-label' ? label : icon;
    const system = { matches: dark, addEventListener(type, callback) { this.change = callback; } };
    const motion = { matches: reducedMotion };
    const storage = {
        getItem(key) { if (blocked) throw new Error('Storage is blocked'); return stored.get(key) ?? null; },
        setItem(key, value) { if (blocked) throw new Error('Storage is blocked'); stored.set(key, value); },
        removeItem(key) { if (blocked) throw new Error('Storage is blocked'); stored.delete(key); },
    };
    vm.runInNewContext(source, {
        document: {
            documentElement: root, readyState: 'loading',
            getElementById: id => controls ? (id === 'theme-toggle' ? button : selector) : null,
            addEventListener: (type, callback) => documentEvents.set(type, callback),
            dispatchEvent: event => { changes.push(event.detail); documentEvents.get(event.type)?.(event); },
        },
        window: {
            matchMedia: query => query.includes('color-scheme') ? system : motion,
            addEventListener: (type, callback) => windowEvents.set(type, callback),
        },
        localStorage: storage,
        CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
        setTimeout: () => 1, clearTimeout: () => {},
    });
    documentEvents.get('DOMContentLoaded')();
    return {
        root, stored, classes, button, selector, label, changes,
        system(value) { system.matches = value; system.change(); },
        choose(value) { selector.value = value; selector.events.change(); },
        switchMode() { button.events.click(); },
        fromAnotherTab(value) { windowEvents.get('storage')({ key: 'smt-theme', newValue: value }); },
        clearStorageInAnotherTab() { windowEvents.get('storage')({ key: null, newValue: null }); },
    };
}

test('system appearance responds to OS changes while explicit preferences stay fixed', () => {
    const page = openPage({ dark: true });
    assert.equal(page.root.dataset.theme, 'dark');
    assert.equal(page.selector.value, 'system');
    page.system(false);
    assert.equal(page.root.dataset.theme, 'light');
    page.choose('dark');
    page.system(false);
    assert.equal(page.root.dataset.theme, 'dark');
    assert.equal(page.stored.get('smt-theme'), 'dark');
    page.choose('system');
    assert.equal(page.root.dataset.theme, 'light');
    assert.equal(page.stored.has('smt-theme'), false);
});

test('saved choices restore and quick switch communicates its next action', () => {
    const page = openPage({ saved: 'light', dark: true });
    assert.equal(page.root.dataset.theme, 'light');
    assert.equal(page.label.textContent, 'Dark mode');
    assert.equal(page.button.attributes['aria-label'], 'Switch to dark mode');
    page.switchMode();
    assert.equal(page.selector.value, 'dark');
    assert.equal(page.label.textContent, 'Light mode');
    assert.equal(page.button.attributes['aria-label'], 'Switch to light mode');
    assert.equal(page.stored.get('smt-theme'), 'dark');
});

test('appearance stays usable when browser storage is blocked', () => {
    const page = openPage({ blocked: true });
    page.choose('dark');
    page.system(false);
    assert.equal(page.root.dataset.theme, 'dark');
    page.switchMode();
    assert.equal(page.root.dataset.theme, 'light');
});

test('preferences synchronize across tabs, including returning to system', () => {
    const page = openPage({ saved: 'light', dark: true });
    page.fromAnotherTab('dark');
    assert.equal(page.root.dataset.theme, 'dark');
    assert.equal(page.selector.value, 'dark');
    page.fromAnotherTab(null);
    assert.equal(page.selector.value, 'system');
    page.system(false);
    assert.equal(page.root.dataset.theme, 'light');
    page.fromAnotherTab('dark');
    page.clearStorageInAnotherTab();
    assert.equal(page.root.dataset.themeChoice, 'system');
    assert.equal(page.root.dataset.theme, 'light');
});

test('reduced motion avoids transition effects', () => {
    const page = openPage({ reducedMotion: true });
    page.switchMode();
    assert.equal(page.root.dataset.theme, 'dark');
    assert.equal(page.classes.has('theme-transition'), false);
});

test('invalid stored preferences and pages without controls use the system safely', () => {
    const page = openPage({ saved: 'invalid', dark: true, controls: false });
    assert.equal(page.root.dataset.themeChoice, 'system');
    assert.equal(page.root.dataset.theme, 'dark');
    page.system(false);
    assert.equal(page.root.dataset.theme, 'light');
});
