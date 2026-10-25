const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../public/assets/agent-performance.js'), 'utf8');
const chartIds = ['agent-csat-trend', 'agent-feedback-mix', 'agent-response-trend', 'agent-channel-chart', 'agent-comparison-score', 'agent-comparison-volume'];
function openCharts({ ids = chartIds, chartAvailable = true, data } = {}) {
    const colors = { '--chart-accent': '#18796a', '--chart-positive': '#18796a', '--chart-negative': '#c45f4b', '--chart-text': '#60706a', '--chart-grid': '#e3eae5', '--surface': '#ffffff', '--ink': '#203b35' };
    const nodes = new Map(ids.map(id => [id, { setAttribute(name, value) { this[name] = value; } }]));
    nodes.set('agent-chart-error', { hidden: true });
    nodes.set('agent-performance-data', { textContent: JSON.stringify(data || {
        summary: { total: 10, good: 8, bad: 2, score: 80 }, period: 'weekly',
        groups: { trend: [{ label: '2026-10-19', total: 10, good: 8, bad: 2, score: 80 }], channels: [{ label: 'Chat', total: 10, good: 8, bad: 2, score: 80 }] },
        agents: [{ agent_name: 'Alex', team: 'Alpha', total: 10, good: 8, bad: 2, score: 80 }, { agent_name: 'Alex', team: 'Beta', total: 5, good: 5, bad: 0, score: 100 }],
    }) });
    const instances = [];
    class Chart {
        static defaults = { font: {} };
        constructor(canvas, config) { this.canvas = canvas; this.config = config; this.options = config.options; this.data = config.data; this.destroyed = false; instances.push(this); }
        destroy() { this.destroyed = true; this.canvas = null; }
        update(mode) { this.updateMode = mode; }
    }
    const events = new Map();
    const window = { Chart: chartAvailable ? Chart : undefined, matchMedia: () => ({ matches: true }) };
    vm.runInNewContext(source, {
        window, getComputedStyle: () => ({ getPropertyValue: name => colors[name] || '' }),
        document: { documentElement: {}, getElementById: id => nodes.get(id), addEventListener: (type, callback) => events.set(type, callback) },
    });
    return { nodes, instances, window, colors, Chart, changeTheme: () => events.get('themechange')() };
}

test('agent charts present scores and response counts with correct scales and tooltips', () => {
    const page = openCharts();
    assert.equal(page.instances.length, 6);
    const [trend, mix, volume, channels, comparison] = page.instances;
    assert.equal(trend.options.scales.y.max, 100);
    assert.equal(trend.options.scales.y.min, 0);
    assert.equal(comparison.options.scales.x.max, 100);
    assert.equal(trend.options.animation, false);
    assert.equal(volume.options.scales.y.ticks.precision, 0);
    assert.equal(channels.options.scales.x.ticks.precision, 0);
    assert.equal(channels.options.indexAxis, 'y');
    assert.equal(trend.options.plugins.tooltip.callbacks.label({ parsed: { y: 80 }, dataIndex: 0 }), '80.00% CSAT · 10 responses');
    assert.equal(mix.options.plugins.tooltip.callbacks.label({ label: 'Bad / DSAT', parsed: 2 }), 'Bad / DSAT: 2 responses (20.00%)');
    assert.equal(volume.options.plugins.tooltip.callbacks.footer([{ dataIndex: 0 }]), 'CSAT: 80.00% · 10 responses');
    assert.equal(comparison.data.labels[0], 'Alex · Alpha');
    assert.match(page.nodes.get('agent-csat-trend')['aria-label'], /weeks beginning Monday/);
    assert.equal(page.nodes.get('agent-chart-error').hidden, true);
});

test('optional chart subsets do not require other canvases or comparison data', () => {
    const page = openCharts({ ids: ['agent-csat-trend', 'agent-feedback-mix'] });
    assert.equal(page.instances.length, 2);
    assert.equal(page.nodes.get('agent-chart-error').hidden, true);
    const noCanvases = openCharts({ ids: [], chartAvailable: false });
    assert.equal(noCanvases.nodes.get('agent-chart-error').hidden, true);
});

test('theme changes update all chart colors while preserving Good and Bad semantics', () => {
    const page = openCharts();
    Object.assign(page.colors, { '--chart-accent': '#ee4d2d', '--chart-positive': '#73d4ad', '--chart-negative': '#ff8b82', '--chart-text': '#b3b9c4', '--chart-grid': '#343a45', '--surface': '#20242c', '--ink': '#f4f5f7' });
    page.changeTheme();
    const [trend, mix, volume, channels, comparison] = page.instances;
    assert.equal(trend.data.datasets[0].borderColor, '#ee4d2d');
    assert.equal(mix.data.datasets[0].backgroundColor[0], '#73d4ad');
    assert.equal(mix.data.datasets[0].backgroundColor[1], '#ff8b82');
    assert.equal(volume.data.datasets[0].backgroundColor, '#73d4ad');
    assert.equal(channels.data.datasets[1].backgroundColor, '#ff8b82');
    assert.equal(comparison.data.datasets[0].backgroundColor, '#ee4d2d');
    assert.equal(trend.options.plugins.tooltip.backgroundColor, '#20242c');
    assert.equal(trend.options.scales.y.grid.color, '#343a45');
    assert.equal(page.instances.every(chart => chart.updateMode === 'none'), true);
});

test('live rerenders dispose their own charts and clear them when charts leave the page', () => {
    const page = openCharts();
    const originals = page.instances.slice();
    const external = new page.Chart({}, { data: { datasets: [] }, options: {} });
    page.window.renderAgentPerformance();
    assert.equal(originals.every(chart => chart.destroyed), true);
    assert.equal(external.destroyed, false);
    chartIds.forEach(id => page.nodes.delete(id));
    page.window.renderAgentPerformance();
    assert.equal(page.instances.filter(chart => !chart.destroyed).length, 1);
    assert.equal(external.destroyed, false);
});

test('missing Chart.js and malformed data reveal the fallback without stale charts', () => {
    const unavailable = openCharts({ chartAvailable: false });
    assert.equal(unavailable.instances.length, 0);
    assert.equal(unavailable.nodes.get('agent-chart-error').hidden, false);
    const page = openCharts();
    page.nodes.get('agent-performance-data').textContent = '{broken';
    page.window.renderAgentPerformance();
    assert.equal(page.instances.every(chart => chart.destroyed), true);
    assert.equal(page.nodes.get('agent-chart-error').hidden, false);
});

test('comparison charts cap the current-page rows at 12 and preserve unavailable CSAT', () => {
    const agents = Array.from({ length: 15 }, (_, index) => ({ agent_name: `Agent ${index}`, team: 'Team', total: 0, good: 0, bad: 0, score: null }));
    const page = openCharts({ ids: ['agent-comparison-score', 'agent-comparison-volume'], data: { agents } });
    const [score, volume] = page.instances;
    assert.equal(score.data.labels.length, 12);
    assert.equal(score.data.datasets[0].data[0], null);
    assert.equal(volume.data.datasets[0].data.length, 12);
    assert.equal(score.data.labels[0], 'Agent 0');
});
