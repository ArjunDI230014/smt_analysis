(() => {
    const form = document.querySelector('.user-directory-filters');
    if (!form) return;
    const input = form.querySelector('[name="q"]');
    const results = document.getElementById('user-results');
    const count = document.getElementById('user-match-count');
    const feedback = document.getElementById('user-search-feedback');
    if (!input || !results || !count || !feedback) return;

    const editor = document.getElementById('user-editor');
    const editorContent = document.getElementById('user-editor-content');
    const canShowDialog = editor && editorContent && typeof editor.showModal === 'function';
    let activeEditor;
    let timer;
    let controller;
    let generation = 0;
    let composing = false;

    const resetPasswords = root => {
        root.querySelectorAll('input[type="password"], input[data-user-password-visible]').forEach(field => {
            field.type = 'password';
            field.value = '';
            field.removeAttribute('data-user-password-visible');
            field.setCustomValidity('');
        });
        root.querySelectorAll('[data-password-confirm]').forEach(field => field.setCustomValidity(''));
        root.querySelectorAll('[data-user-password-toggle]').forEach(button => {
            button.textContent = 'Show';
            button.setAttribute('aria-pressed', 'false');
        });
    };
    const restoreEditor = () => {
        if (!activeEditor) return;
        const { source, content, placeholder, trigger } = activeEditor;
        activeEditor = null;
        content.querySelectorAll('form').forEach(accountForm => accountForm.reset());
        resetPasswords(content);
        content.querySelectorAll('details').forEach(details => { details.open = false; });
        placeholder.replaceWith(content);
        source.open = false;
        document.body.classList.remove('user-dialog-open');
        editor.removeAttribute('aria-labelledby');
        if (trigger.isConnected) trigger.focus({ preventScroll: true });
    };
    const closeEditor = () => {
        if (!canShowDialog) return;
        if (editor.open) editor.close();
        // Restore synchronously before a search can replace the originating row.
        restoreEditor();
    };
    const openEditor = source => {
        if (!canShowDialog) return false;
        const content = source.querySelector(':scope > .user-management-content');
        const trigger = source.querySelector(':scope > summary');
        if (!content || !trigger) return false;
        closeEditor();
        const placeholder = document.createComment('Account editor');
        content.before(placeholder);
        editorContent.replaceChildren(content);
        activeEditor = { source, content, placeholder, trigger };
        source.open = false;
        content.querySelectorAll('[data-user-close]').forEach(button => { button.hidden = false; });
        const title = content.querySelector('h2[id]');
        if (title) editor.setAttribute('aria-labelledby', title.id);
        document.body.classList.add('user-dialog-open');
        editor.showModal();
        const field = content.querySelector('[aria-invalid="true"]')
            || content.querySelector('input:not([type="hidden"]):not([type="radio"]):not([type="checkbox"])');
        field?.focus({ preventScroll: true });
        return true;
    };
    const outsideDialog = event => {
        const bounds = editor.getBoundingClientRect();
        return event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom;
    };
    if (canShowDialog) {
        editor.addEventListener('close', () => {
            // A queued close event may arrive after another account was opened.
            if (!editor.open) restoreEditor();
        });
        editor.addEventListener('cancel', event => {
            event.preventDefault();
            closeEditor();
        });
        let backdropPressed = false;
        editor.addEventListener('pointerdown', event => {
            backdropPressed = event.target === editor && outsideDialog(event);
        });
        editor.addEventListener('click', event => {
            if (event.target === editor && backdropPressed && outsideDialog(event)) closeEditor();
            backdropPressed = false;
        });
    }

    const cancel = () => {
        clearTimeout(timer);
        controller?.abort();
        generation++;
    };
    const setBusy = busy => {
        if (busy) results.setAttribute('aria-busy', 'true');
        else results.removeAttribute('aria-busy');
    };
    const urlForFilters = () => {
        const url = new URL(form.action, window.location.href);
        const params = new URLSearchParams(new FormData(form));
        if (!params.get('q')?.trim()) params.delete('q');
        ['status', 'role'].forEach(name => { if (params.get(name) === 'all') params.delete(name); });
        url.search = params.toString();
        return url;
    };
    const syncFilters = url => {
        input.value = url.searchParams.get('q') || '';
        ['status', 'role'].forEach(name => {
            const select = form.querySelector(`[name="${name}"]`);
            if (!select) return;
            const value = url.searchParams.get(name) || 'all';
            select.value = [...select.options].some(option => option.value === value) ? value : 'all';
            // Refresh the app's enhanced select without triggering another search.
            select.dispatchEvent(new Event('input', { bubbles: true }));
        });
        const selectedStatus = form.querySelector('[name="status"]')?.value || 'all';
        document.querySelectorAll('[data-user-filter-link]').forEach(link => {
            const linkStatus = new URL(link.href, window.location.href).searchParams.get('status') || 'all';
            if (selectedStatus === linkStatus) link.setAttribute('aria-current', 'true');
            else link.removeAttribute('aria-current');
        });
    };
    const refresh = async (url, historyMode = 'push') => {
        cancel();
        closeEditor();
        const current = generation;
        controller = new AbortController();
        setBusy(true);
        feedback.textContent = 'Finding matching accounts…';
        try {
            const response = await fetch(url, { signal: controller.signal, credentials: 'same-origin' });
            if (current !== generation) return;
            if (response.redirected) {
                window.location.assign(response.url);
                return;
            }
            if (!response.ok) throw new Error('Search failed');
            const nextDocument = new DOMParser().parseFromString(await response.text(), 'text/html');
            if (current !== generation) return;
            const nextResults = nextDocument.getElementById('user-results');
            const nextCount = nextDocument.getElementById('user-match-count');
            if (!nextResults || !nextCount) throw new Error('Missing search results');
            closeEditor();
            results.replaceChildren(...nextResults.childNodes);
            count.textContent = nextCount.textContent;
            syncFilters(url);
            feedback.textContent = `${nextCount.textContent}. Matches update as you type.`;
            if (historyMode === 'replace') history.replaceState(null, '', url);
            else if (historyMode === 'push' && window.location.href !== url.href) history.pushState(null, '', url);
        } catch (error) {
            if (current !== generation || error.name === 'AbortError') return;
            feedback.textContent = 'Could not update matches. Press Search to try again.';
        } finally {
            if (current === generation) setBusy(false);
        }
    };
    const schedule = () => {
        cancel();
        setBusy(true);
        feedback.textContent = 'Finding matching accounts…';
        timer = setTimeout(() => refresh(urlForFilters(), 'replace'), 180);
    };
    input.addEventListener('input', () => { if (!composing) schedule(); });
    input.addEventListener('compositionstart', () => {
        composing = true;
        cancel();
        setBusy(false);
    });
    input.addEventListener('compositionend', () => { composing = false; schedule(); });
    ['status', 'role'].forEach(name => {
        form.querySelector(`[name="${name}"]`)?.addEventListener('change', () => refresh(urlForFilters()));
    });
    form.addEventListener('submit', event => {
        event.preventDefault();
        refresh(urlForFilters());
    });
    document.addEventListener('click', event => {
        const target = event.target instanceof Element ? event.target : null;
        if (!target) return;
        const summary = target.closest('.user-create > summary, .user-member-actions > summary');
        if (summary && canShowDialog) {
            event.preventDefault();
            openEditor(summary.parentElement);
            return;
        }
        if (target.closest('[data-user-close]')) {
            event.preventDefault();
            closeEditor();
            return;
        }
        const passwordToggle = target.closest('[data-user-password-toggle]');
        if (passwordToggle) {
            const field = document.getElementById(passwordToggle.getAttribute('aria-controls'));
            if (!field) return;
            const visible = field.type === 'password';
            field.type = visible ? 'text' : 'password';
            field.toggleAttribute('data-user-password-visible', visible);
            passwordToggle.textContent = visible ? 'Hide' : 'Show';
            passwordToggle.setAttribute('aria-pressed', String(visible));
            return;
        }
        const link = target.closest('.user-directory-pagination a, [data-user-filter-link], [data-user-reset]');
        if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const url = new URL(link.href, window.location.href);
        if (url.origin !== window.location.origin) return;
        event.preventDefault();
        syncFilters(url);
        refresh(url);
    });
    document.addEventListener('input', event => {
        const accountForm = event.target.form;
        if (!accountForm) return;
        accountForm.querySelectorAll('[data-password-confirm]').forEach(confirmation => {
            const password = document.getElementById(confirmation.dataset.passwordConfirm);
            confirmation.setCustomValidity(confirmation.value && password && confirmation.value !== password.value ? 'Passwords must match.' : '');
        });
    });
    window.addEventListener('popstate', () => {
        const url = new URL(window.location.href);
        syncFilters(url);
        refresh(url, 'none');
    });
    window.addEventListener('pagehide', () => { cancel(); closeEditor(); });
    syncFilters(new URL(window.location.href));
    const reopen = document.getElementById('user-management')?.dataset.userReopen;
    if (reopen) {
        const source = document.getElementById(reopen);
        if (source) openEditor(source);
    }
})();
