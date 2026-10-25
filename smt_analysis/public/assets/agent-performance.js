(() => {
    let charts = [];
    const canvasIds = ['agent-csat-trend', 'agent-feedback-mix', 'agent-response-trend', 'agent-channel-chart', 'agent-comparison-score', 'agent-comparison-volume'];
    const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)');
    const count = value => Number(value).toLocaleString();
    const percent = value => value === null || value === undefined ? '—' : Number(value).toFixed(2) + '%';
    const palette = () => {
        const styles = getComputedStyle(document.documentElement);
        const color = (name, fallback) => styles.getPropertyValue(name).trim() || fallback;
        return {
            text: color('--chart-text', '#60706a'), grid: color('--chart-grid', '#e3eae5'),
            accent: color('--chart-accent', '#18796a'),
            positive: color('--chart-positive', color('--chart-green', '#18796a')),
            negative: color('--chart-negative', '#c45f4b'),
            surface: color('--surface', '#ffffff'), ink: color('--ink', '#203b35'),
        };
    };
    const fillColor = color => /^#[a-f\d]{6}$/i.test(color) ? color + '18' : color;

    window.destroyAgentPerformanceCharts = () => {
        charts.forEach(({ chart }) => { if (chart.canvas) chart.destroy(); });
        charts = [];
    };

    const applyTheme = () => {
        const colors = palette();
        charts.forEach(({ chart, kind }) => {
            if (!chart.canvas) return;
            chart.options.color = colors.text;
            chart.options.plugins.legend.labels.color = colors.text;
            Object.assign(chart.options.plugins.tooltip, { backgroundColor: colors.surface, titleColor: colors.ink, bodyColor: colors.text, borderColor: colors.grid });
            Object.values(chart.options.scales || {}).forEach(scale => {
                scale.ticks.color = colors.text;
                scale.grid.color = colors.grid;
            });
            const datasets = chart.data.datasets;
            if (kind === 'mix') {
                datasets[0].backgroundColor = [colors.positive, colors.negative];
                datasets[0].borderColor = colors.surface;
            } else if (kind === 'feedback') {
                datasets[0].backgroundColor = colors.positive;
                datasets[1].backgroundColor = colors.negative;
            } else if (kind === 'trend') {
                Object.assign(datasets[0], { borderColor: colors.accent, pointBackgroundColor: colors.accent, backgroundColor: fillColor(colors.accent) });
            } else datasets[0].backgroundColor = colors.accent;
            chart.update('none');
        });
    };

    window.renderAgentPerformance = () => {
        window.destroyAgentPerformanceCharts();
        const data = document.getElementById('agent-performance-data');
        const error = document.getElementById('agent-chart-error');
        const visibleCanvases = canvasIds.filter(id => document.getElementById(id));
        if (!data || visibleCanvases.length === 0) return;
        if (!window.Chart) { if (error) error.hidden = false; return; }
        try {
            const { summary = {}, groups = {}, period = 'daily', agents = [] } = JSON.parse(data.textContent);
            const trend = groups.trend || [];
            const channels = groups.channels || [];
            const comparisons = agents.slice(0, 12);
            const duplicateNames = new Set(comparisons.filter((row, index, rows) => rows.some((other, otherIndex) => index !== otherIndex && row.agent_name === other.agent_name)).map(row => row.agent_name));
            const agentLabels = comparisons.map(row => duplicateNames.has(row.agent_name) ? `${row.agent_name} · ${row.team}` : row.agent_name);
            const periodName = { daily: 'Daily', weekly: 'Weekly', monthly: 'Monthly' }[period] || 'Daily';
            const periodUnit = { daily: 'days', weekly: 'weeks beginning Monday', monthly: 'calendar months' }[period] || 'days';
            const colors = palette();
            const Chart = window.Chart;
            Chart.defaults.font.family = 'Segoe UI, Arial, sans-serif';
            const base = (callbacks = {}, legend = false) => ({
                responsive: true, maintainAspectRatio: false, color: colors.text,
                animation: reducedMotion?.matches ? false : { duration: 300 },
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: legend, position: 'bottom', labels: { color: colors.text, usePointStyle: true, boxWidth: 8, padding: 20 } },
                    tooltip: { backgroundColor: colors.surface, titleColor: colors.ink, bodyColor: colors.text, borderColor: colors.grid, borderWidth: 1, padding: 12, cornerRadius: 8, callbacks },
                },
            });
            const labelAxis = () => ({ ticks: { color: colors.text }, grid: { display: false }, border: { display: false } });
            const scoreAxis = () => ({ min: 0, max: 100, ticks: { color: colors.text, callback: value => value + '%' }, grid: { color: colors.grid }, border: { display: false } });
            const countAxis = () => ({ beginAtZero: true, stacked: true, ticks: { color: colors.text, precision: 0 }, grid: { color: colors.grid }, border: { display: false } });
            const feedbackDatasets = rows => [
                { label: 'Good', data: rows.map(row => Number(row.good)), backgroundColor: colors.positive, borderRadius: 4, maxBarThickness: 36 },
                { label: 'Bad / DSAT', data: rows.map(row => Number(row.bad)), backgroundColor: colors.negative, borderRadius: 4, maxBarThickness: 36 },
            ];
            const create = (id, kind, config, description) => {
                const canvas = document.getElementById(id);
                if (!canvas) return;
                canvas.setAttribute('aria-label', description);
                charts.push({ kind, chart: new Chart(canvas, config) });
            };
            const stackedFeedback = (rows, labels, horizontal) => ({
                type: 'bar', data: { labels, datasets: feedbackDatasets(rows) },
                options: {
                    ...base({
                        label: context => `${context.dataset.label}: ${count(horizontal ? context.parsed.x : context.parsed.y)} responses`,
                        footer: contexts => {
                            const row = rows[contexts[0]?.dataIndex];
                            return row ? `CSAT: ${percent(row.score)} · ${count(row.total)} responses` : '';
                        },
                    }, true),
                    indexAxis: horizontal ? 'y' : 'x',
                    interaction: { mode: 'index', axis: horizontal ? 'y' : 'x', intersect: false },
                    scales: horizontal ? { x: countAxis(), y: { ...labelAxis(), stacked: true } } : { x: { ...labelAxis(), stacked: true }, y: countAxis() },
                },
            });

            create('agent-csat-trend', 'trend', {
                type: 'line',
                data: { labels: trend.map(row => row.label), datasets: [{ label: 'CSAT', data: trend.map(row => row.score), borderColor: colors.accent, pointBackgroundColor: colors.accent, backgroundColor: fillColor(colors.accent), fill: true, tension: 0.2, spanGaps: false, pointRadius: 4, pointHitRadius: 12, pointHoverRadius: 6, borderWidth: 2 }] },
                options: {
                    ...base({ label: context => `${percent(context.parsed.y)} CSAT · ${count(trend[context.dataIndex].total)} responses`, afterLabel: context => `${count(trend[context.dataIndex].good)} Good · ${count(trend[context.dataIndex].bad)} Bad / DSAT` }),
                    scales: { x: labelAxis(), y: scoreAxis() },
                },
            }, `${periodName} CSAT across ${count(trend.length)} ${periodUnit}. Overall CSAT: ${percent(summary.score)}.`);

            const ratedTotal = Number(summary.good) + Number(summary.bad);
            create('agent-feedback-mix', 'mix', {
                type: 'doughnut',
                data: { labels: ['Good', 'Bad / DSAT'], datasets: [{ data: [Number(summary.good), Number(summary.bad)], backgroundColor: [colors.positive, colors.negative], borderWidth: 4, borderColor: colors.surface, borderRadius: 5 }] },
                options: { ...base({ label: context => `${context.label}: ${count(context.parsed)} responses (${percent(ratedTotal > 0 ? context.parsed / ratedTotal * 100 : null)})` }), cutout: '74%', interaction: { mode: 'nearest', intersect: true } },
            }, `Feedback mix: ${count(summary.good)} Good and ${count(summary.bad)} Bad / DSAT ratings.`);

            create('agent-response-trend', 'feedback', stackedFeedback(trend, trend.map(row => row.label), false), `${periodName} response volume across ${count(trend.length)} ${periodUnit}, split into Good and Bad / DSAT ratings.`);
            create('agent-channel-chart', 'feedback', stackedFeedback(channels, channels.map(row => row.label), true), `Good and Bad / DSAT response counts across ${count(channels.length)} submission channels.`);

            create('agent-comparison-score', 'score', {
                type: 'bar',
                data: { labels: agentLabels, datasets: [{ label: 'CSAT', data: comparisons.map(row => row.score), backgroundColor: colors.accent, borderRadius: 4, maxBarThickness: 24 }] },
                options: {
                    ...base({ label: context => `${percent(context.parsed.x)} CSAT · ${count(comparisons[context.dataIndex].total)} responses`, afterLabel: context => `${count(comparisons[context.dataIndex].good)} Good · ${count(comparisons[context.dataIndex].bad)} Bad / DSAT` }),
                    indexAxis: 'y', interaction: { mode: 'index', axis: 'y', intersect: false }, scales: { x: scoreAxis(), y: labelAxis() },
                },
            }, `CSAT percentages for the first ${count(comparisons.length)} agent/team combinations on this page, in the selected table order.`);
            create('agent-comparison-volume', 'feedback', stackedFeedback(comparisons, agentLabels, true), `Good and Bad / DSAT counts for the first ${count(comparisons.length)} agent/team combinations on this page, in the selected table order.`);
            if (error) error.hidden = true;
        } catch (_) {
            window.destroyAgentPerformanceCharts();
            if (error) error.hidden = false;
        }
    };

    document.addEventListener('themechange', applyTheme);
    window.renderAgentPerformance();
})();
