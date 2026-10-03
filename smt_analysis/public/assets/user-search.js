(() => {
    const form = document.querySelector('.user-directory-filters');
    if (!form) return;
    const input = form.querySelector('[name="q"]');
    const status = form.querySelector('[name="status"]');
    const results = document.getElementById('user-results');
    const count = document.getElementById('user-match-count');
    const feedback = document.getElementById('user-search-feedback');
    let timer;
    let controller;
    let generation = 0;
    let composing = false;

    const cancel = () => {
        clearTimeout(timer);
        controller?.abort();
        generation++;
    };
    const urlForFilters = () => {
        const url = new URL(form.action);
        url.search = new URLSearchParams(new FormData(form)).toString();
        return url;
    };
    const refresh = async url => {
        cancel();
        const current = generation;
        controller = new AbortController();
        results.setAttribute('aria-busy', 'true');
        feedback.textContent = 'Finding matching accounts…';
        try {
            const response = await fetch(url, {signal: controller.signal, credentials: 'same-origin'});
            if (current !== generation) return;
            if (response.redirected) {
                window.location.assign(response.url);
                return;
            }
            if (!response.ok) throw new Error('Search failed');
            const document = new DOMParser().parseFromString(await response.text(), 'text/html');
            if (current !== generation) return;
            const nextResults = document.getElementById('user-results');
            const nextCount = document.getElementById('user-match-count');
            if (!nextResults || !nextCount) throw new Error('Missing search results');
            results.replaceChildren(...nextResults.childNodes);
            count.textContent = nextCount.textContent;
            feedback.textContent = `${nextCount.textContent}. Matches update as you type.`;
            history.replaceState(null, '', url);
        } catch (error) {
            if (current !== generation || error.name === 'AbortError') return;
            feedback.textContent = 'Could not update matches. Press Search to try again.';
        } finally {
            if (current === generation) results.removeAttribute('aria-busy');
        }
    };
    const schedule = () => {
        cancel();
        results.setAttribute('aria-busy', 'true');
        feedback.textContent = 'Finding matching accounts…';
        timer = setTimeout(() => refresh(urlForFilters()), 180);
    };
    input.addEventListener('input', () => { if (!composing) schedule(); });
    input.addEventListener('compositionstart', () => { composing = true; cancel(); });
    input.addEventListener('compositionend', () => { composing = false; schedule(); });
    status.addEventListener('change', () => refresh(urlForFilters()));
    form.addEventListener('submit', event => {
        event.preventDefault();
        refresh(urlForFilters());
    });
    results.addEventListener('click', event => {
        const link = event.target.closest('.user-directory-pagination a');
        if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        refresh(new URL(link.href));
    });
})();
