(() => {
    document.querySelectorAll('.password-toggle').forEach((button) => {
        const input = document.getElementById(button.getAttribute('aria-controls'));
        if (!input) return;
        button.addEventListener('click', () => {
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.textContent = show ? 'Hide' : 'Show';
            button.setAttribute('aria-pressed', String(show));
            button.setAttribute('aria-label', `${show ? 'Hide' : 'Show'} ${input.name.replaceAll('_', ' ')}`);
        });
    });
})();
