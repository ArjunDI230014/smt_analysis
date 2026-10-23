(() => {
    const input = document.getElementById('agent-search');
    if (!input) return;
    const form = input.form;
    const results = document.getElementById('dashboard-results');
    const status = document.getElementById('filter-status');
    const apply = form.querySelector('button[type="submit"]');
    let timer, controller;
    let generation = 0;
    let composing = false;
    const announce = message => { if (status) status.textContent = message; };
    const busy = value => {
        results.setAttribute('aria-busy', String(value));
        if (apply) {
            apply.disabled = value;
            apply.setAttribute('aria-busy', String(value));
            apply.textContent = value ? 'Updating…' : 'Apply filters';
        }
    };
    const cancel = () => { clearTimeout(timer); controller?.abort(); generation++; busy(false); };
    const snapshotChanged = () => form.elements.namedItem('import').value !== form.dataset.reportId;
    const refresh = async ({push = false} = {}) => {
        cancel();
        if (snapshotChanged()) { announce('Apply filters to open the selected report.'); return; }
        if (!form.checkValidity()) { announce('Check the date range before applying filters.'); return; }
        const current = generation;
        controller = new AbortController();
        const url = new URL(form.action);
        url.search = new URLSearchParams(new FormData(form)).toString();
        busy(true);
        announce('Updating scores, charts, DSAT feedback, and agent results…');
        try {
            const response = await fetch(url, {signal: controller.signal, credentials: 'same-origin'});
            if (current !== generation) return;
            if (response.redirected) { location.assign(response.url); return; }
            if (!response.ok) throw new Error('Search failed');
            const next = new DOMParser().parseFromString(await response.text(), 'text/html');
            if (current !== generation) return;
            const content = next.getElementById('dashboard-results');
            if (!content) throw new Error('Missing results');
            const names = next.getElementById('agent-names');
            const agentNames = names ? JSON.parse(names.textContent) : [];
            window.destroyDashboardCharts?.();
            results.replaceChildren(...content.childNodes);
            results.dataset.responseCount = content.dataset.responseCount;
            results.dataset.agentCount = content.dataset.agentCount;
            results.dataset.period = content.dataset.period;
            document.getElementById('report-data')?.remove();
            const data = next.getElementById('report-data');
            if (data) document.body.append(data);
            window.renderDashboard?.();
            if (url.href !== location.href) history[push ? 'pushState' : 'replaceState'](null, '', url);
            document.dispatchEvent(new CustomEvent('dashboard:updated', {detail: {agentNames}}));
            const count = Number(content.dataset.responseCount).toLocaleString();
            const dsatCount = Number(results.querySelector('[data-dsat-total]')?.dataset.dsatTotal || 0).toLocaleString();
            announce(input.value.trim()
                ? `Updated: ${count} responses, ${dsatCount} DSATs across ${Number(content.dataset.agentCount).toLocaleString()} matching agent names.`
                : `Updated: ${count} responses, ${dsatCount} DSATs. All matching agents are shown.`);
        } catch (error) {
            if (current === generation && error.name !== 'AbortError') announce('Could not update results. Your previous results are still shown. Use Apply filters to retry.');
        } finally {
            if (current === generation) busy(false);
        }
    };
    input.addEventListener('input', () => {
        cancel();
        if (!composing) timer = setTimeout(refresh, 250);
    });
    input.addEventListener('compositionstart', () => { composing = true; cancel(); });
    input.addEventListener('compositionend', () => { composing = false; timer = setTimeout(refresh, 250); });
    const markPending = event => {
        if (event.target === input) return;
        cancel();
        announce('Filters changed. Apply filters to update the results.');
    };
    form.addEventListener('input', markPending);
    form.addEventListener('change', markPending);
    // A new snapshot needs a full page to refresh its dates, options, and source metadata together.
    form.addEventListener('submit', event => {
        if (snapshotChanged()) { cancel(); return; }
        event.preventDefault();
        refresh({push: true});
    });
    window.addEventListener('popstate', () => { cancel(); location.reload(); });
    window.addEventListener('pageshow', event => { if (event.persisted) cancel(); });
})();
