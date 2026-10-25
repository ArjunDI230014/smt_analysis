(() => {
    const enhanced = new WeakMap();
    const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const monthFormat = new Intl.DateTimeFormat(undefined, { month: 'long', year: 'numeric', timeZone: 'UTC' });
    const dateFormat = new Intl.DateTimeFormat(undefined, { dateStyle: 'full', timeZone: 'UTC' });
    let active = null;
    let sequence = 0;

    const makeDate = (year, month, day) => {
        const date = new Date(0);
        date.setUTCFullYear(year, month, day);
        return date;
    };
    const iso = date => date.toISOString().slice(0, 10);
    const parse = value => {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) return null;
        const [year, month, day] = value.split('-').map(Number);
        const date = makeDate(year, month - 1, day);
        return iso(date) === value && year > 0 ? date : null;
    };
    const today = () => {
        const now = new Date();
        return makeDate(now.getFullYear(), now.getMonth(), now.getDate());
    };
    const addDays = (date, days) => makeDate(date.getUTCFullYear(), date.getUTCMonth(), date.getUTCDate() + days);
    const addMonths = (date, months) => {
        const last = makeDate(date.getUTCFullYear(), date.getUTCMonth() + months + 1, 0);
        return makeDate(last.getUTCFullYear(), last.getUTCMonth(), Math.min(date.getUTCDate(), last.getUTCDate()));
    };
    const visible = element => !!element.getClientRects().length && !element.closest('[hidden], [inert]');

    const enhance = input => {
        if (enhanced.has(input) || input.dataset.nativeControl !== undefined) return;
        const label = input.getAttribute('aria-label') || [...input.labels || []].map(item => item.textContent.trim()).join(' ') || input.name || 'date';
        const wrapper = document.createElement('span');
        wrapper.className = 'date-control';
        input.before(wrapper);
        wrapper.append(input);
        input.classList.add('enhanced-date');

        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'date-trigger';
        trigger.setAttribute('aria-label', `Choose ${label} date`);
        trigger.title = `Choose ${label} date`;
        trigger.setAttribute('aria-haspopup', 'dialog');
        trigger.setAttribute('aria-expanded', 'false');
        trigger.innerHTML = '<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/></svg>';
        wrapper.append(trigger);

        const popup = document.createElement('div');
        const id = `date-picker-${++sequence}`;
        popup.id = id;
        popup.className = 'date-popover';
        popup.setAttribute('role', 'dialog');
        popup.setAttribute('aria-labelledby', `${id}-label ${id}-title`);
        popup.setAttribute('aria-describedby', `${id}-hint`);
        trigger.setAttribute('aria-controls', id);
        const name = document.createElement('span');
        name.className = 'sr-only';
        name.id = `${id}-label`;
        name.textContent = label;
        const hint = document.createElement('p');
        hint.id = `${id}-hint`;
        hint.className = 'sr-only';
        hint.textContent = 'Use arrow keys to move by day or week. Page Up and Page Down change month. Enter selects a date. Escape closes the calendar.';
        const header = document.createElement('div');
        header.className = 'date-header';
        const previous = document.createElement('button');
        previous.type = 'button';
        previous.className = 'date-nav';
        previous.setAttribute('aria-label', 'Previous month');
        previous.textContent = '‹';
        const title = document.createElement('span');
        title.className = 'date-title';
        title.id = `${id}-title`;
        title.setAttribute('aria-live', 'polite');
        const next = document.createElement('button');
        next.type = 'button';
        next.className = 'date-nav';
        next.setAttribute('aria-label', 'Next month');
        next.textContent = '›';
        header.append(previous, title, next);
        const weekdays = document.createElement('div');
        weekdays.className = 'date-weekdays';
        weekdays.setAttribute('aria-hidden', 'true');
        dayNames.forEach(day => {
            const element = document.createElement('span');
            element.textContent = day;
            weekdays.append(element);
        });
        const grid = document.createElement('div');
        grid.className = 'date-grid';
        grid.setAttribute('role', 'grid');
        grid.setAttribute('aria-labelledby', title.id);
        const footer = document.createElement('div');
        footer.className = 'date-footer';
        const current = document.createElement('button');
        current.type = 'button';
        current.className = 'date-action';
        current.textContent = 'Today';
        const clear = document.createElement('button');
        clear.type = 'button';
        clear.className = 'date-action';
        clear.textContent = 'Clear';
        footer.append(current, clear);
        popup.append(name, hint, header, weekdays, grid, footer);
        let focused;
        let view;
        let state = '';

        const minimum = () => parse(input.min) || makeDate(1, 0, 1);
        const maximum = () => parse(input.max) || makeDate(9999, 11, 31);
        const allowed = date => date >= minimum() && date <= maximum();
        const clamp = date => date < minimum() ? minimum() : date > maximum() ? maximum() : date;
        const sync = () => {
            const disabled = input.matches(':disabled') || input.readOnly;
            if (trigger.disabled !== disabled) trigger.disabled = disabled;
            if (active === control && disabled) close();
            const nextState = [input.value, input.min, input.max, input.required].join('|');
            if (active === control && state !== nextState) {
                focused = clamp(parse(input.value) || focused);
                view = makeDate(focused.getUTCFullYear(), focused.getUTCMonth(), 1);
                render();
            }
            state = nextState;
        };
        const position = () => {
            if (active !== control) return;
            const rect = wrapper.getBoundingClientRect();
            const viewport = window.visualViewport;
            const left = viewport?.offsetLeft || 0;
            const top = viewport?.offsetTop || 0;
            const width = viewport?.width || document.documentElement.clientWidth;
            const height = viewport?.height || window.innerHeight;
            popup.style.maxWidth = `${Math.max(0, width - 16)}px`;
            popup.style.maxHeight = `${Math.max(0, height - 16)}px`;
            const size = popup.getBoundingClientRect();
            popup.style.left = `${Math.max(left + 8, Math.min(rect.left, left + width - size.width - 8))}px`;
            const below = rect.bottom + 6;
            const above = rect.top - size.height - 6;
            popup.style.top = `${Math.max(top + 8, Math.min(below + size.height <= top + height - 8 || above < top + 8 ? below : above, top + height - size.height - 8))}px`;
        };
        const render = (focusDay = false) => {
            title.textContent = monthFormat.format(view);
            grid.replaceChildren();
            const start = addDays(view, -view.getUTCDay());
            const selected = input.value;
            const now = iso(today());
            for (let week = 0; week < 6; week++) {
                const row = document.createElement('div');
                row.className = 'date-week';
                row.setAttribute('role', 'row');
                for (let day = 0; day < 7; day++) {
                    const date = addDays(start, week * 7 + day);
                    const value = iso(date);
                    const cell = document.createElement('span');
                    cell.setAttribute('role', 'gridcell');
                    cell.setAttribute('aria-selected', String(value === selected));
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'date-day';
                    button.classList.toggle('is-outside', date.getUTCMonth() !== view.getUTCMonth());
                    button.classList.toggle('is-today', value === now);
                    button.classList.toggle('is-selected', value === selected);
                    button.setAttribute('aria-label', dateFormat.format(date));
                    if (value === now) button.setAttribute('aria-current', 'date');
                    button.dataset.date = value;
                    button.textContent = date.getUTCDate();
                    button.disabled = !allowed(date);
                    button.tabIndex = value === iso(focused) && !button.disabled ? 0 : -1;
                    cell.append(button);
                    row.append(cell);
                }
                grid.append(row);
            }
            previous.disabled = makeDate(view.getUTCFullYear(), view.getUTCMonth(), 0) < minimum();
            next.disabled = makeDate(view.getUTCFullYear(), view.getUTCMonth() + 1, 1) > maximum();
            current.disabled = !allowed(today());
            clear.hidden = input.required;
            position();
            if (focusDay) grid.querySelector('.date-day[tabindex="0"]')?.focus({ preventScroll: true });
        };
        const close = (restore = false) => {
            if (active !== control) return;
            active = null;
            popup.remove();
            trigger.setAttribute('aria-expanded', 'false');
            wrapper.classList.remove('is-open');
            if (restore && trigger.isConnected) trigger.focus({ preventScroll: true });
        };
        const open = () => {
            sync();
            if (trigger.disabled) return;
            if (active === control) { close(true); return; }
            active?.close();
            document.dispatchEvent(new CustomEvent('control:open', { detail: { control: trigger } }));
            const pair = input.form?.elements.namedItem(input.name === 'from' ? 'to' : 'from');
            focused = clamp(parse(input.value) || parse(pair?.value) || parse(input.form?.dataset.reportTo) || parse(input.form?.dataset.reportFrom) || today());
            view = makeDate(focused.getUTCFullYear(), focused.getUTCMonth(), 1);
            active = control;
            document.body.append(popup);
            trigger.setAttribute('aria-expanded', 'true');
            wrapper.classList.add('is-open');
            render(true);
        };
        const choose = value => {
            if (trigger.disabled || value && !allowed(parse(value))) return;
            const changed = input.value !== value;
            input.value = value;
            close(true);
            if (changed) {
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
        };
        const control = { input, wrapper, trigger, popup, close, position, sync, render };
        enhanced.set(input, control);
        sync();
        trigger.addEventListener('click', event => { event.preventDefault(); open(); });
        trigger.addEventListener('keydown', event => {
            if (event.key === 'ArrowDown') { event.preventDefault(); if (active !== control) open(); }
        });
        input.addEventListener('keydown', event => {
            if (event.key === ' ' || event.key === 'ArrowDown' && event.altKey) {
                event.preventDefault();
                if (active !== control) open();
            }
        });
        const changed = () => {
            if (active === control) {
                focused = clamp(parse(input.value) || focused);
                view = makeDate(focused.getUTCFullYear(), focused.getUTCMonth(), 1);
                render();
            }
        };
        input.addEventListener('input', changed);
        input.addEventListener('change', changed);
        previous.addEventListener('click', () => {
            focused = clamp(addMonths(focused, -1));
            view = makeDate(focused.getUTCFullYear(), focused.getUTCMonth(), 1);
            render();
        });
        next.addEventListener('click', () => {
            focused = clamp(addMonths(focused, 1));
            view = makeDate(focused.getUTCFullYear(), focused.getUTCMonth(), 1);
            render();
        });
        grid.addEventListener('click', event => {
            const day = event.target.closest('.date-day');
            if (day && !day.disabled) choose(day.dataset.date);
        });
        current.addEventListener('click', () => choose(iso(today())));
        clear.addEventListener('click', () => { if (!input.required) choose(''); });
        popup.addEventListener('keydown', event => {
            if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(true); return; }
            if (event.key === 'Tab') {
                const elements = [...popup.querySelectorAll('button:not(:disabled)')].filter(element => element.tabIndex >= 0 && visible(element));
                const at = elements.indexOf(document.activeElement);
                if (event.shiftKey ? at > 0 : at >= 0 && at < elements.length - 1) return;
                event.preventDefault();
                close();
                const outside = [...document.querySelectorAll('a[href], button:not(:disabled), input:not(:disabled):not([type="hidden"]), select:not(:disabled), textarea:not(:disabled), [tabindex="0"]')].filter(element => element.tabIndex >= 0 && visible(element));
                const index = outside.indexOf(trigger);
                (outside[index + (event.shiftKey ? -1 : 1)] || trigger).focus();
                return;
            }
            const day = event.target.closest('.date-day');
            if (!day) return;
            const date = parse(day.dataset.date);
            let destination;
            if (event.key === 'ArrowLeft') destination = addDays(date, -1);
            if (event.key === 'ArrowRight') destination = addDays(date, 1);
            if (event.key === 'ArrowUp') destination = addDays(date, -7);
            if (event.key === 'ArrowDown') destination = addDays(date, 7);
            if (event.key === 'Home') destination = addDays(date, -date.getUTCDay());
            if (event.key === 'End') destination = addDays(date, 6 - date.getUTCDay());
            if (event.key === 'PageUp') destination = addMonths(date, event.shiftKey ? -12 : -1);
            if (event.key === 'PageDown') destination = addMonths(date, event.shiftKey ? 12 : 1);
            if (destination) {
                event.preventDefault();
                focused = clamp(destination);
                view = makeDate(focused.getUTCFullYear(), focused.getUTCMonth(), 1);
                render(true);
            }
        });
        input.form?.addEventListener('reset', () => setTimeout(() => { close(); sync(); }, 0));
    };

    const scan = () => {
        if (active && !active.input.isConnected) active.close();
        document.querySelectorAll('input[type="date"]').forEach(input => {
            enhance(input);
            enhanced.get(input)?.sync();
        });
    };
    document.addEventListener('pointerdown', event => {
        if (active && !active.popup.contains(event.target) && !active.wrapper.contains(event.target)) active.close();
    });
    document.addEventListener('focusin', event => {
        if (active && !active.popup.contains(event.target) && !active.wrapper.contains(event.target)) active.close();
    });
    document.addEventListener('control:open', event => {
        if (active && event.detail?.control !== active.trigger) active.close();
    });
    window.addEventListener('resize', () => active?.position());
    window.addEventListener('scroll', () => active?.position(), true);
    window.visualViewport?.addEventListener('resize', () => active?.position());
    window.visualViewport?.addEventListener('scroll', () => active?.position());
    window.addEventListener('pageshow', () => { active?.close(); scan(); });
    document.addEventListener('dashboard:updated', scan);
    let pending = false;
    const observer = new MutationObserver(records => {
        if (pending || !records.some(record => !record.target.closest?.('.date-popover') && (record.type === 'childList' || record.target.matches?.('input[type="date"], fieldset')))) return;
        pending = true;
        queueMicrotask(() => { pending = false; scan(); });
    });
    scan();
    observer.observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['disabled', 'readonly', 'required', 'min', 'max', 'value'] });
})();
