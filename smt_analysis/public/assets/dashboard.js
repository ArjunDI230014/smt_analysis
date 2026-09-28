(() => {
    const error = document.getElementById('chart-error');
    if (!window.Chart) { error.hidden = false; return; }
    try {
        const { summary, groups } = JSON.parse(document.getElementById('report-data').textContent);
        const green = '#18796a', orange = '#ed9777';
        Chart.defaults.font.family = 'Segoe UI, Arial, sans-serif';
        Chart.defaults.color = '#718079';
        Chart.defaults.plugins.legend.labels.usePointStyle = true;
        Chart.defaults.plugins.legend.labels.boxWidth = 8;
        const base = () => ({ responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } });
        const axes = { x: { grid: { display: false }, border: { display: false } }, y: { min: 0, max: 100, ticks: { callback: value => value + '%' }, grid: { color: '#edf1ee' }, border: { display: false } } };
        new Chart(document.getElementById('trend-chart'), {
            type: 'line', data: { labels: groups.trend.map(row => row.label), datasets: [{ label: 'CSAT', data: groups.trend.map(row => row.score), borderColor: green, backgroundColor: '#18796a12', fill: true, tension: 0.25, pointRadius: 5, pointBackgroundColor: green, borderWidth: 2 }] },
            options: { ...base(), scales: axes, plugins: { legend: { display: false }, tooltip: { callbacks: { label: context => `${context.parsed.y.toFixed(2)}% CSAT · ${groups.trend[context.dataIndex].total} responses` } } } }
        });
        new Chart(document.getElementById('mix-chart'), {
            type: 'doughnut', data: { labels: ['Good', 'Bad'], datasets: [{ data: [summary.good, summary.bad], backgroundColor: [green, orange], borderWidth: 5, borderColor: '#ffffff', borderRadius: 5 }] },
            options: { ...base(), cutout: '73%', plugins: { legend: { display: true, position: 'bottom', labels: { padding: 22 } } } }
        });
        new Chart(document.getElementById('team-chart'), {
            type: 'bar', data: { labels: groups.teams.map(row => row.label), datasets: [{ label: 'CSAT', data: groups.teams.map(row => row.score), backgroundColor: green, borderRadius: 4, maxBarThickness: 22 }] },
            options: { ...base(), indexAxis: 'y', scales: { x: { ...axes.y }, y: { ...axes.x } }, plugins: { legend: { display: false }, tooltip: { callbacks: { label: context => `${context.parsed.x.toFixed(2)}% CSAT · ${groups.teams[context.dataIndex].total} responses` } } } }
        });
        new Chart(document.getElementById('channel-chart'), {
            type: 'bar', data: { labels: groups.channels.map(row => row.label), datasets: [{ label: 'Good', data: groups.channels.map(row => Number(row.good)), backgroundColor: green, borderRadius: 4, maxBarThickness: 50 }, { label: 'Bad', data: groups.channels.map(row => Number(row.bad)), backgroundColor: orange, borderRadius: 4, maxBarThickness: 50 }] },
            options: { ...base(), scales: { x: { ...axes.x, stacked: true }, y: { beginAtZero: true, stacked: true, grid: { color: '#edf1ee' }, border: { display: false }, ticks: { precision: 0 } } }, plugins: { legend: { display: true, position: 'bottom' } } }
        });
    } catch (exception) { error.hidden = false; }
})();
