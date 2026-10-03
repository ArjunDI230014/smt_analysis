(() => {
    const root = document.documentElement;
    let saved;
    try { saved = localStorage.getItem('smt-theme'); } catch (_) { /* Private browsing may block storage. */ }
    root.dataset.theme = saved === 'dark' || saved === 'light' ? saved : (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    document.addEventListener('DOMContentLoaded', () => {
        const button = document.getElementById('theme-toggle');
        if (!button) return;
        let transitionTimer;
        const sync = () => {
            const dark = root.dataset.theme === 'dark';
            button.querySelector('.theme-icon').textContent = dark ? '☀' : '☾';
            button.querySelector('.theme-label').textContent = dark ? 'Light mode' : 'Dark mode';
            button.setAttribute('aria-pressed', String(dark));
            button.setAttribute('aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode');
        };
        sync();
        button.addEventListener('click', () => {
            clearTimeout(transitionTimer);
            root.classList.add('theme-transition');
            root.dataset.theme = root.dataset.theme === 'dark' ? 'light' : 'dark';
            try { localStorage.setItem('smt-theme', root.dataset.theme); } catch (_) { /* Theme still works without persistence. */ }
            sync();
            document.dispatchEvent(new Event('themechange'));
            transitionTimer = setTimeout(() => root.classList.remove('theme-transition'), 450);
        });
    });
})();
