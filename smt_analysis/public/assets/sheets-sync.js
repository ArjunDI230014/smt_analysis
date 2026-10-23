(() => {
    'use strict';
    const panel = document.querySelector('[data-sheet-sync]');
    if (!panel) return;
    const message = panel.querySelector('[data-sheet-sync-message]');
    let running = false;
    let stopped = false;
    const sync = async () => {
        if (running || stopped || document.hidden) return;
        const token = panel.querySelector('[data-sheet-sync-token] input');
        if (!token) return;
        running = true;
        try {
            const body = new URLSearchParams({[token.name]: token.value});
            const response = await fetch(panel.dataset.sheetSync, {
                method: 'POST', body, credentials: 'same-origin', headers: {'Accept': 'application/json'},
            });
            if (response.redirected || response.status === 403) {
                stopped = true;
                message.textContent = 'Reload the page to resume automatic refresh.';
                return;
            }
            const result = await response.json();
            if (result.csrf_name && result.csrf_hash) {
                document.querySelectorAll('input[type="hidden"]').forEach(input => {
                    if (input.name === result.csrf_name) input.value = result.csrf_hash;
                });
            }
            if (!response.ok) throw new Error(result.message || 'Automatic refresh could not finish. The last successful report is still available.');
            if (result.state === 'disabled' || result.state === 'not_configured') {
                stopped = true;
                message.textContent = 'Automatic refresh is paused. Your administrator can update the connection.';
                return;
            }
            const currentReport = new URL(window.location.href).searchParams.get('import');
            const showingConnected = !currentReport || currentReport === panel.dataset.sheetImport;
            const changed = ['synced', 'unchanged'].includes(result.state)
                && (String(result.import_id || '') !== panel.dataset.sheetImport || result.state === 'synced');
            if (changed && showingConnected) {
                const url = new URL(window.location.href);
                url.searchParams.set('import', result.import_id);
                window.location.replace(url.href);
                return;
            }
            if (result.state === 'unchanged' || result.state === 'synced') {
                panel.dataset.sheetUpdated = result.updated_at || '';
                const updated = result.updated_at ? new Date(result.updated_at.replace(' ', 'T') + 'Z') : null;
                const formatted = updated && !Number.isNaN(updated.getTime())
                    ? updated.toLocaleString('en-MY', {timeZone: 'Asia/Kuala_Lumpur'}) + ' MYT' : 'Up to date';
                message.textContent = 'Last checked: ' + formatted;
            }
        } catch (error) {
            message.textContent = error.message || 'Unable to check Google Sheets. Retrying automatically.';
        } finally {
            running = false;
        }
    };
    sync();
    window.setInterval(sync, 60000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) sync(); });
})();
