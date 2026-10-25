(() => {
    const root = document.documentElement;
    const storageKey = 'smt-theme';
    const systemTheme = window.matchMedia?.('(prefers-color-scheme: dark)');
    const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)');
    const normalize = value => value === 'light' || value === 'dark' ? value : 'system';
    let preference = 'system';
    let transitionTimer;
    let activeTransition;
    let themeGeneration = 0;
    let button;
    let selector;

    try { preference = normalize(localStorage.getItem(storageKey)); } catch (_) { /* Appearance remains usable when storage is blocked. */ }

    const syncControls = () => {
        const dark = root.dataset.theme === 'dark';
        const nextTheme = dark ? 'light' : 'dark';
        if (button) {
            const icon = button.querySelector('.theme-icon');
            const label = button.querySelector('.theme-label');
            if (icon) icon.textContent = dark ? '☀' : '☾';
            if (label) label.textContent = dark ? 'Light mode' : 'Dark mode';
            button.removeAttribute('aria-pressed');
            button.setAttribute('aria-label', `Switch to ${nextTheme} mode`);
            button.title = `Switch to ${nextTheme} mode${preference === 'system' ? ' · Currently following your system' : ''}`;
        }
        if (selector) selector.value = preference;
    };

    const applyTheme = (animate = false) => {
        const theme = preference === 'system' ? (systemTheme?.matches ? 'dark' : 'light') : preference;
        const changed = root.dataset.theme !== theme;
        const generation = ++themeGeneration;
        activeTransition?.skipTransition();
        activeTransition = null;
        clearTimeout(transitionTimer);
        root.classList.remove('theme-transition');
        const update = () => {
            // Skipping a view transition still runs its callback. Only commit the latest choice.
            if (generation !== themeGeneration) return;
            const didChange = root.dataset.theme !== theme;
            root.dataset.theme = theme;
            root.dataset.themeChoice = preference;
            syncControls();
            if (didChange) document.dispatchEvent(new CustomEvent('themechange', { detail: { theme, preference } }));
        };
        if (!animate || !changed || reducedMotion?.matches) {
            update();
            return;
        }
        // Blend the whole page, including canvas charts, in one synchronized fade.
        if (document.startViewTransition) {
            try {
                const transition = document.startViewTransition(update);
                activeTransition = transition;
                transition.ready.catch(() => {}); // An interrupted animation may never become ready.
                const finish = () => { if (activeTransition === transition) activeTransition = null; };
                transition.finished.then(finish, finish);
                return;
            } catch (_) { /* Use color transitions when page snapshots are unavailable. */ }
        }
        root.classList.add('theme-transition');
        update();
        transitionTimer = setTimeout(() => root.classList.remove('theme-transition'), 420);
    };

    const chooseTheme = value => {
        preference = normalize(value);
        try {
            if (preference === 'system') localStorage.removeItem(storageKey);
            else localStorage.setItem(storageKey, preference);
        } catch (_) { /* Keep the current choice in memory if persistence is unavailable. */ }
        applyTheme(true);
    };

    // This script runs in the head so the initial theme is set before the page is painted.
    applyTheme();

    const bindControls = () => {
        button = document.getElementById('theme-toggle');
        selector = document.getElementById('theme-preference');
        syncControls();
        button?.addEventListener('click', () => chooseTheme((preference === 'system' ? root.dataset.theme : preference) === 'dark' ? 'light' : 'dark'));
        selector?.addEventListener('change', () => chooseTheme(selector.value));
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bindControls, { once: true });
    else bindControls();

    const followSystem = () => { if (preference === 'system') applyTheme(true); };
    if (systemTheme?.addEventListener) systemTheme.addEventListener('change', followSystem);
    else systemTheme?.addListener?.(followSystem);

    reducedMotion?.addEventListener?.('change', () => {
        if (!reducedMotion.matches) return;
        activeTransition?.skipTransition();
        clearTimeout(transitionTimer);
        root.classList.remove('theme-transition');
    });

    window.addEventListener('storage', event => {
        if (event.key === storageKey || event.key === null) {
            preference = normalize(event.newValue);
            applyTheme(true);
        }
    });
})();
