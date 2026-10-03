(() => {
    const input = document.getElementById('agent-search');
    if (!input) return;
    const form = input.form;
    const results = document.getElementById('dashboard-results');
    const hint = document.getElementById('agent-search-hint');
    let timer, controller;
    let generation = 0;
    let composing = false;
    const cancel = () => { clearTimeout(timer); controller?.abort(); generation++; };
    const refresh = async () => {
        cancel();
        const current = generation;
        controller = new AbortController();
        const url = new URL(form.action);
        url.search = new URLSearchParams(new FormData(form)).toString();
        results.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(url, {signal: controller.signal, credentials: 'same-origin'});
            if (current !== generation) return;
            if (response.redirected) { location.assign(response.url); return; }
            if (!response.ok) throw new Error('Search failed');
            const next = new DOMParser().parseFromString(await response.text(), 'text/html');
            if (current !== generation) return;
            const content = next.getElementById('dashboard-results');
            if (!content) throw new Error('Missing results');
            if (window.Chart) Object.values(Chart.instances).forEach(chart => chart.destroy());
            results.replaceChildren(...content.childNodes);
            document.getElementById('report-data')?.remove();
            const data = next.getElementById('report-data');
            if (data) document.body.append(data);
            window.renderDashboard?.();
            history.replaceState(null, '', url);
        } catch (error) {
            if (current === generation && error.name !== 'AbortError') hint.textContent = 'Could not update results. Use Apply filters to try again.';
        } finally {
            if (current === generation) results.removeAttribute('aria-busy');
        }
    };
    input.addEventListener('input', () => {
        cancel();
        if (!composing) timer = setTimeout(refresh, 180);
    });
    input.addEventListener('compositionstart', () => { composing = true; cancel(); });
    input.addEventListener('compositionend', () => { composing = false; timer = setTimeout(refresh, 180); });
    // Suggestions submit this same form when an agent is chosen.
    form.addEventListener('submit', event => { event.preventDefault(); refresh(); });
})();
