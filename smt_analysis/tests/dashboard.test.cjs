const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../public/assets/dashboard.js'), 'utf8');

function openDashboard() {
    const colors = { '--chart-accent': '#18796a', '--chart-positive': '#18796a', '--chart-negative': '#c45f4b', '--chart-text': '#60706a', '--chart-grid': '#e3eae5', '--surface': '#ffffff', '--ink': '#203b35' };
    const nodes = new Map(['trend-chart', 'mix-chart', 'team-chart', 'channel-chart'].map(id => [id, { setAttribute() {} }]));
    nodes.set('chart-error', { hidden: true });
    nodes.set('report-data', { textContent: JSON.stringify({
        summary: { good: 90, bad: 10, total: 100, score: 90 },
        groups: { trend: [{ label: '2026-10-09', score: 90, total: 100 }], teams: [{ label: 'Team', score: 90, total: 100 }], channels: [{ label: 'Email', good: 90, bad: 10 }] },
    }) });
    const instances = [];
    class Chart {
        static defaults = { font: {} };
        constructor(canvas, config) { this.canvas = canvas; this.config = config; this.data = config.data; this.options = config.options; this.destroyed = false; instances.push(this); }
        destroy() { this.destroyed = true; this.canvas = null; }
        update(mode) { this.updateMode = mode; }
    }
    const window = { Chart, matchMedia: () => ({ matches: true }) };
    const events = new Map();
    vm.runInNewContext(source, {
        window, Chart,
        document: { documentElement: {}, getElementById: id => nodes.get(id), addEventListener: (type, callback) => events.set(type, callback) },
        getComputedStyle: () => ({ getPropertyValue: name => colors[name] || '' }),
    });
    return { window, colors, nodes, Chart, instances, changeTheme: () => events.get('themechange')() };
}

test('dark charts use orange for scores and preserve Good/Bad semantics', () => {
    const page = openDashboard();
    Object.assign(page.colors, { '--chart-accent': '#ee4d2d', '--chart-positive': '#73d4ad', '--chart-negative': '#ff8b82', '--chart-text': '#b3b9c4', '--chart-grid': '#343a45', '--surface': '#20242c', '--ink': '#f4f5f7' });
    page.changeTheme();
    const [trend, mix, team, channel] = page.instances;
    assert.equal(trend.data.datasets[0].borderColor, '#ee4d2d');
    assert.equal(team.data.datasets[0].backgroundColor, '#ee4d2d');
    assert.equal(mix.data.datasets[0].backgroundColor[0], '#73d4ad');
    assert.equal(mix.data.datasets[0].backgroundColor[1], '#ff8b82');
    assert.equal(channel.data.datasets[0].backgroundColor, '#73d4ad');
    assert.equal(channel.data.datasets[1].backgroundColor, '#ff8b82');
    assert.equal(trend.options.scales.y.grid.color, '#343a45');
    assert.equal(trend.options.plugins.tooltip.backgroundColor, '#20242c');
    assert.equal(trend.options.plugins.tooltip.titleColor, '#f4f5f7');
    assert.equal(trend.updateMode, 'none');
    assert.equal(trend.options.animation, false);
});

test('live re-renders and empty results release only dashboard chart instances', () => {
    const page = openDashboard();
    const external = new page.Chart({}, { data: { datasets: [] }, options: {} });
    const originalCharts = page.instances.slice(0, 4);
    page.window.renderDashboard();
    assert.equal(originalCharts.every(chart => chart.destroyed), true);
    assert.equal(external.destroyed, false);
    assert.equal(page.instances.filter(chart => !chart.destroyed).length, 5);
    page.nodes.delete('chart-error');
    page.window.renderDashboard();
    assert.equal(page.instances.filter(chart => !chart.destroyed).length, 1);
    assert.equal(external.destroyed, false);
});

test('malformed chart data exposes the fallback and leaves no stale charts', () => {
    const page = openDashboard();
    page.nodes.get('report-data').textContent = '{broken';
    page.window.renderDashboard();
    assert.equal(page.nodes.get('chart-error').hidden, false);
    assert.equal(page.instances.every(chart => chart.destroyed), true);
});
