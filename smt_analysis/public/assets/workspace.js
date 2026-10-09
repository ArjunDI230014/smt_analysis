(() => {
    const button = document.getElementById('nav-toggle');
    const sidebar = document.querySelector('.sidebar');
    const navigation = document.getElementById('workspace-navigation');
    if (!button || !sidebar || !navigation) return;

    const compact = matchMedia('(max-width: 850px)');
    const setOpen = open => {
        sidebar.classList.toggle('is-open', open);
        button.setAttribute('aria-expanded', String(open));
        button.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
    };
    document.documentElement.classList.add('workspace-ready');
    button.hidden = false;
    button.addEventListener('click', () => setOpen(button.getAttribute('aria-expanded') !== 'true'));
    navigation.addEventListener('click', event => {
        if (event.target.closest('a') && compact.matches) setOpen(false);
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && button.getAttribute('aria-expanded') === 'true') {
            setOpen(false);
            button.focus();
        }
    });
    compact.addEventListener('change', () => setOpen(false));
})();
