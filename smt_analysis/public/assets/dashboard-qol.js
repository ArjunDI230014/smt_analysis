(() => {
    const form = document.getElementById('dashboard-filters');
    if (!form) return;
    const from = form.elements.namedItem('from');
    const to = form.elements.namedItem('to');
    const report = form.elements.namedItem('import');
    const ranges = form.querySelector('.quick-ranges');
    const rangeButtons = [...form.querySelectorAll('[data-range]')];
    const status = document.getElementById('filter-status');
    const apply = form.querySelector('button[type="submit"]');
    const initialStatus = status.textContent;
    let preferredRange = null;
    const rangeStart = days => {
        const date = new Date(`${form.dataset.reportTo}T00:00:00Z`);
        date.setUTCDate(date.getUTCDate() - days + 1);
        const start = date.toISOString().slice(0, 10);
        return start < form.dataset.reportFrom ? form.dataset.reportFrom : start;
    };
    const validDates = /^\d{4}-\d{2}-\d{2}$/.test(form.dataset.reportFrom) && /^\d{4}-\d{2}-\d{2}$/.test(form.dataset.reportTo);
    const updateRanges = () => {
        const changedReport = report.value !== form.dataset.reportId;
        const matches = rangeButtons.filter(button => button.dataset.range === 'all'
                ? !from.value && !to.value
                : validDates && from.value === rangeStart(Number(button.dataset.range)) && to.value === form.dataset.reportTo);
        const selected = matches.find(button => button.dataset.range === preferredRange) || matches[0];
        rangeButtons.forEach(button => {
            button.disabled = changedReport;
            button.setAttribute('aria-pressed', String(button === selected && !changedReport));
        });
        form.querySelector('.range-hint').textContent = changedReport
            ? 'Apply the selected report to refresh its date ranges.'
            : 'Ends on the report’s latest date.';
    };
    const validateDates = () => {
        to.setCustomValidity(from.value && to.value && from.value > to.value ? 'Choose an end date on or after the start date.' : '');
        updateRanges();
    };
    if (validDates) {
        ranges.hidden = false;
        rangeButtons.forEach(button => button.addEventListener('click', () => {
            const all = button.dataset.range === 'all';
            preferredRange = button.dataset.range;
            from.value = all ? '' : rangeStart(Number(button.dataset.range));
            to.value = all ? '' : form.dataset.reportTo;
            validateDates();
            form.requestSubmit();
        }));
    }
    const datesEdited = () => { preferredRange = null; validateDates(); };
    from.addEventListener('input', datesEdited);
    to.addEventListener('input', datesEdited);
    from.addEventListener('change', datesEdited);
    to.addEventListener('change', datesEdited);
    report.addEventListener('change', updateRanges);
    form.addEventListener('submit', event => {
        if (event.defaultPrevented) return;
        status.textContent = 'Applying filters…';
        if (apply) {
            apply.disabled = true;
            apply.setAttribute('aria-busy', 'true');
            apply.textContent = 'Applying…';
        }
    });
    window.addEventListener('pageshow', () => {
        if (apply) {
            apply.disabled = false;
            apply.removeAttribute('aria-busy');
            apply.textContent = 'Apply filters';
        }
        status.textContent = initialStatus;
    });
    validateDates();

    const search = document.getElementById('agent-search');
    const clear = form.querySelector('.clear-search');
    if (search && clear) {
        const updateClear = () => { clear.hidden = search.value === ''; };
        clear.addEventListener('click', () => {
            search.value = '';
            search.focus();
            search.dispatchEvent(new Event('input', {bubbles: true}));
        });
        search.addEventListener('input', updateClear);
        document.addEventListener('dashboard:updated', updateClear);
        updateClear();
        document.addEventListener('keydown', event => {
            if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey || event.shiftKey) return;
            const target = event.target;
            if (target instanceof Element && (target.closest('input, textarea, select, button, [role="combobox"]') || target.isContentEditable)) return;
            event.preventDefault();
            search.focus();
            search.select();
        });
    }
})();
