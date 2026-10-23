(() => {
    let charts = [];
    const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)');
    const count = value => Number(value).toLocaleString();
    const percent = value => value === null || value === undefined ? '—' : Number(value).toFixed(2) + '%';
    const palette = () => {
        const styles = getComputedStyle(document.documentElement);
        const color = (name, fallback) => styles.getPropertyValue(name).trim() || fallback;
        return {
            text: color('--chart-text', '#60706a'),
            grid: color('--chart-grid', '#e3eae5'),
            accent: color('--chart-accent', '#18796a'),
            positive: color('--chart-positive', color('--chart-green', '#18796a')),
            negative: color('--chart-negative', '#c45f4b'),
            surface: color('--surface', '#ffffff'),
            ink: color('--ink', '#203b35'),
        };
    };
    const fillColor = color => /^#[a-f\d]{6}$/i.test(color) ? color + '18' : color;

    window.destroyDashboardCharts = () => {
        charts.forEach(({ chart }) => { if (chart.canvas) chart.destroy(); });
        charts = [];
    };

    window.applyDashboardTheme = () => {
        const colors = palette();
        charts.forEach(({ chart, kind }) => {
            if (!chart.canvas) return;
            chart.options.color = colors.text;
            chart.options.plugins.legend.labels.color = colors.text;
            Object.assign(chart.options.plugins.tooltip, {
                backgroundColor: colors.surface,
                titleColor: colors.ink,
                bodyColor: colors.text,
                borderColor: colors.grid,
            });
            Object.values(chart.options.scales || {}).forEach(scale => {
                scale.ticks.color = colors.text;
                scale.grid.color = colors.grid;
            });
            const datasets = chart.data.datasets;
            if (kind === 'mix') {
                datasets[0].backgroundColor = [colors.positive, colors.negative];
                datasets[0].borderColor = colors.surface;
            } else if (kind === 'channel') {
                datasets[0].backgroundColor = colors.positive;
                datasets[1].backgroundColor = colors.negative;
            } else if (kind === 'trend') {
                Object.assign(datasets[0], { borderColor: colors.accent, pointBackgroundColor: colors.accent, backgroundColor: fillColor(colors.accent) });
            } else datasets[0].backgroundColor = colors.accent;
            chart.update('none');
        });
    };

    window.renderDashboard = () => {
        window.destroyDashboardCharts();
        const data = document.getElementById('report-data');
        const error = document.getElementById('chart-error');
        if (!data || !error) return;
        if (!window.Chart) { error.hidden = false; return; }
        try {
            const { summary, groups, period = 'daily' } = JSON.parse(data.textContent);
            const periodName = { daily: 'Daily', weekly: 'Weekly', monthly: 'Monthly' }[period] || 'Daily';
            const periodUnit = { daily: 'days', weekly: 'weeks beginning Monday', monthly: 'calendar months' }[period] || 'days';
            const ratedTotal = Number(summary.good) + Number(summary.bad);
            const colors = palette();
            Chart.defaults.font.family = 'Segoe UI, Arial, sans-serif';
            const base = (callbacks = {}, legend = false) => ({
                responsive: true,
                maintainAspectRatio: false,
                color: colors.text,
                animation: reducedMotion?.matches ? false : { duration: 300 },
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: legend, position: 'bottom', labels: { color: colors.text, usePointStyle: true, boxWidth: 8, padding: 20 } },
                    tooltip: { backgroundColor: colors.surface, titleColor: colors.ink, bodyColor: colors.text, borderColor: colors.grid, borderWidth: 1, padding: 12, cornerRadius: 8, callbacks },
                },
            });
            const labelsAxis = () => ({ ticks: { color: colors.text }, grid: { display: false }, border: { display: false } });
            const scoreAxis = () => ({ min: 0, max: 100, ticks: { color: colors.text, callback: value => value + '%' }, grid: { color: colors.grid }, border: { display: false } });
            const create = (id, kind, config, description) => {
                const canvas = document.getElementById(id);
                canvas.setAttribute('aria-label', description);
                charts.push({ kind, chart: new Chart(canvas, config) });
            };

            create('trend-chart', 'trend', {
                type: 'line',
                data: { labels: groups.trend.map(row => row.label), datasets: [{ label: 'CSAT', data: groups.trend.map(row => row.score), borderColor: colors.accent, backgroundColor: fillColor(colors.accent), fill: true, tension: 0.25, pointRadius: 4, pointHitRadius: 12, pointHoverRadius: 6, pointBackgroundColor: colors.accent, borderWidth: 2 }] },
                options: { ...base({ label: context => `${percent(context.parsed.y)} CSAT · ${count(groups.trend[context.dataIndex].total)} responses` }), scales: { x: labelsAxis(), y: scoreAxis() } },
            }, `${periodName} CSAT trend across ${count(groups.trend.length)} ${periodUnit}. Overall CSAT: ${percent(summary.score)}.`);

            create('mix-chart', 'mix', {
                type: 'doughnut',
                data: { labels: ['Good', 'Bad'], datasets: [{ data: [Number(summary.good), Number(summary.bad)], backgroundColor: [colors.positive, colors.negative], borderWidth: 4, borderColor: colors.surface, borderRadius: 5 }] },
                options: { ...base({ label: context => `${context.label}: ${count(context.parsed)} responses (${percent(ratedTotal > 0 ? context.parsed / ratedTotal * 100 : null)})` }, true), cutout: '73%', interaction: { mode: 'nearest', intersect: true } },
            }, `Feedback mix: ${count(summary.good)} Good and ${count(summary.bad)} Bad ratings.`);

            create('team-chart', 'team', {
                type: 'bar',
                data: { labels: groups.teams.map(row => row.label), datasets: [{ label: 'CSAT', data: groups.teams.map(row => row.score), backgroundColor: colors.accent, borderRadius: 4, maxBarThickness: 22 }] },
                options: { ...base({ label: context => `${percent(context.parsed.x)} CSAT · ${count(groups.teams[context.dataIndex].total)} responses` }), indexAxis: 'y', interaction: { mode: 'index', axis: 'y', intersect: false }, scales: { x: scoreAxis(), y: labelsAxis() } },
            }, `CSAT percentages for ${count(groups.teams.length)} teams, ranked by response volume.`);

            create('channel-chart', 'channel', {
                type: 'bar',
                data: { labels: groups.channels.map(row => row.label), datasets: [{ label: 'Good', data: groups.channels.map(row => Number(row.good)), backgroundColor: colors.positive, borderRadius: 4, maxBarThickness: 50 }, { label: 'Bad', data: groups.channels.map(row => Number(row.bad)), backgroundColor: colors.negative, borderRadius: 4, maxBarThickness: 50 }] },
                options: { ...base({ label: context => `${context.dataset.label}: ${count(context.parsed.y)} responses` }, true), scales: { x: { ...labelsAxis(), stacked: true }, y: { beginAtZero: true, stacked: true, grid: { color: colors.grid }, border: { display: false }, ticks: { color: colors.text, precision: 0 } } } },
            }, `Good and Bad response totals across ${count(groups.channels.length)} submission channels.`);
            error.hidden = true;
        } catch (_) {
            window.destroyDashboardCharts();
            error.hidden = false;
        }
    };

    document.addEventListener('themechange', () => window.applyDashboardTheme());
    window.renderDashboard();
})();
