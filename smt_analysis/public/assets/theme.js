(() => {
    const root = document.documentElement;
    const storageKey = 'smt-theme';
    const systemTheme = window.matchMedia?.('(prefers-color-scheme: dark)');
    const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)');
    const normalize = value => value === 'light' || value === 'dark' ? value : 'system';
    let preference = 'system';
    let transitionTimer;
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
        clearTimeout(transitionTimer);
        root.classList.remove('theme-transition');
        if (animate && changed && !reducedMotion?.matches) {
            root.classList.add('theme-transition');
            transitionTimer = setTimeout(() => root.classList.remove('theme-transition'), 450);
        }
        root.dataset.theme = theme;
        root.dataset.themeChoice = preference;
        syncControls();
        if (changed) document.dispatchEvent(new CustomEvent('themechange', { detail: { theme, preference } }));
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
        button?.addEventListener('click', () => chooseTheme(root.dataset.theme === 'dark' ? 'light' : 'dark'));
        selector?.addEventListener('change', () => chooseTheme(selector.value));
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bindControls, { once: true });
    else bindControls();

    const followSystem = () => { if (preference === 'system') applyTheme(true); };
    if (systemTheme?.addEventListener) systemTheme.addEventListener('change', followSystem);
    else systemTheme?.addListener?.(followSystem);

    window.addEventListener('storage', event => {
        if (event.key === storageKey || event.key === null) {
            preference = normalize(event.newValue);
            applyTheme(true);
        }
    });
})();
