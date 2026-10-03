document.querySelectorAll('.delete-import-form').forEach(form => {
    form.addEventListener('submit', event => {
        if (!window.confirm(`Permanently delete "${form.dataset.filename}" and all its imported responses? This cannot be undone.`)) {
            event.preventDefault();
        }
    });
});
