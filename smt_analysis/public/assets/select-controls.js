(() => {
    const controls = new Map();
    let nextId = 0;
    let invalidFocusPending = false;
    const eligible = select => !select.multiple && select.size <= 1 && !select.hasAttribute('data-native-control');

    const enhance = select => {
        if (controls.has(select) || !eligible(select)) return;
        const id = `select-control-${++nextId}`;
        const labels = [...select.labels];
        const wrapper = document.createElement('div');
        wrapper.className = 'select-control';
        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.id = `${id}-trigger`;
        trigger.className = 'select-trigger';
        trigger.setAttribute('role', 'combobox');
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        trigger.setAttribute('aria-controls', `${id}-options`);
        const value = document.createElement('span');
        value.className = 'select-value';
        trigger.append(value);
        const icon = document.createElement('span');
        icon.className = 'select-chevron';
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = '⌄';
        trigger.append(icon);
        const error = document.createElement('span');
        error.id = `${id}-error`;
        error.className = 'select-error';
        error.setAttribute('role', 'alert');
        error.hidden = true;

        // Keep the actual label, but place the two controls beside it rather than
        // nesting multiple labelable elements inside an implicit label.
        const enclosingLabel = select.closest('label');
        if (enclosingLabel) {
            const field = document.createElement('div');
            field.className = `${enclosingLabel.className} control-field`.trim();
            if (enclosingLabel.hasAttribute('style')) {
                field.setAttribute('style', enclosingLabel.getAttribute('style'));
                enclosingLabel.removeAttribute('style');
            }
            enclosingLabel.className = '';
            enclosingLabel.replaceWith(field);
            field.append(enclosingLabel, wrapper);
        } else select.before(wrapper);
        wrapper.append(select, trigger, error);
        labels.forEach((label, index) => {
            if (!label.id) label.id = `${id}-label-${index}`;
            label.htmlFor = trigger.id;
        });
        const labelIds = labels.map(label => label.id).join(' ');
        if (!select.hasAttribute('aria-label') && !select.hasAttribute('aria-labelledby') && labelIds) {
            select.setAttribute('aria-labelledby', labelIds);
        }
        select.classList.add('enhanced-select');
        select.tabIndex = -1;
        select.setAttribute('aria-hidden', 'true');

        const popup = document.createElement('div');
        popup.className = 'select-popover';
        popup.hidden = true;
        const search = document.createElement('input');
        search.type = 'search';
        search.className = 'select-search';
        search.placeholder = 'Search options…';
        search.autocomplete = 'off';
        search.setAttribute('aria-label', 'Search options');
        search.setAttribute('role', 'combobox');
        search.setAttribute('aria-controls', `${id}-options`);
        search.setAttribute('aria-expanded', 'false');
        search.setAttribute('aria-autocomplete', 'list');
        const list = document.createElement('div');
        list.id = `${id}-options`;
        list.className = 'select-options';
        list.setAttribute('role', 'listbox');
        const empty = document.createElement('p');
        empty.className = 'select-empty';
        empty.setAttribute('role', 'status');
        empty.textContent = 'No matching options';
        empty.hidden = true;
        popup.append(search, list, empty);
        document.body.append(popup);
        let opened = false;
        let active = -1;
        let typeahead = '';
        let typeaheadTimer;
        let entries = [];

        const enabled = () => entries.filter(entry => !entry.disabled);
        const setActive = index => {
            active = index;
            entries.forEach(entry => entry.node.classList.toggle('is-active', entry.index === active));
            const entry = entries.find(item => item.index === active);
            [trigger, search].forEach(control => {
                if (entry && opened) control.setAttribute('aria-activedescendant', entry.node.id);
                else control.removeAttribute('aria-activedescendant');
            });
            if (entry) {
                const bounds = list.getBoundingClientRect();
                const item = entry.node.getBoundingClientRect();
                if (item.top < bounds.top) list.scrollTop -= bounds.top - item.top;
                else if (item.bottom > bounds.bottom) list.scrollTop += item.bottom - bounds.bottom;
            }
        };
        const render = () => {
            const query = search.value.trim().toLocaleLowerCase();
            list.replaceChildren();
            entries = [];
            let previousGroup;
            [...select.options].forEach((option, index) => {
                if (option.hidden || option.parentElement.hidden || (query && !option.label.toLocaleLowerCase().includes(query))) return;
                const group = option.parentElement instanceof HTMLOptGroupElement ? option.parentElement : null;
                if (group && group !== previousGroup) {
                    const heading = document.createElement('div');
                    heading.className = 'select-group';
                    heading.textContent = group.label;
                    list.append(heading);
                }
                previousGroup = group;
                const node = document.createElement('div');
                node.className = 'select-option';
                node.id = `${id}-option-${index}`;
                node.setAttribute('role', 'option');
                node.setAttribute('aria-selected', String(index === select.selectedIndex));
                node.textContent = option.label;
                const disabled = option.disabled || Boolean(group?.disabled);
                if (disabled) node.setAttribute('aria-disabled', 'true');
                node.dataset.index = String(index);
                entries.push({index, node, disabled});
                list.append(node);
            });
            empty.hidden = entries.length > 0;
            const next = enabled();
            setActive(next.some(entry => entry.index === active) ? active
                : next.find(entry => entry.index === select.selectedIndex)?.index ?? next[0]?.index ?? -1);
        };
        const position = () => {
            if (!opened) return;
            const rect = trigger.getBoundingClientRect();
            const viewport = window.visualViewport;
            const leftEdge = viewport?.offsetLeft || 0;
            const topEdge = viewport?.offsetTop || 0;
            const width = viewport?.width || document.documentElement.clientWidth;
            const height = viewport?.height || window.innerHeight;
            if (rect.bottom < topEdge || rect.top > topEdge + height || rect.width === 0) { close(); return; }
            const popupWidth = Math.min(Math.max(rect.width, 220), width - 16);
            const below = topEdge + height - rect.bottom - 14;
            const above = rect.top - topEdge - 14;
            const upwards = below < 220 && above > below;
            const available = Math.max(80, Math.min(360, upwards ? above : below));
            popup.style.position = 'fixed';
            popup.style.width = `${popupWidth}px`;
            popup.style.maxHeight = `${available}px`;
            popup.style.left = `${Math.max(leftEdge + 8, Math.min(rect.left, leftEdge + width - popupWidth - 8))}px`;
            popup.style.top = `${upwards ? Math.max(topEdge + 8, rect.top - Math.min(popup.scrollHeight, available) - 6) : rect.bottom + 6}px`;
        };
        const sync = () => {
            value.textContent = select.selectedOptions[0]?.label || 'Choose an option';
            trigger.title = value.textContent;
            trigger.disabled = select.matches(':disabled');
            trigger.setAttribute('aria-required', String(select.required));
            for (const attribute of ['aria-label', 'aria-labelledby', 'aria-describedby']) {
                const content = select.getAttribute(attribute) || (attribute === 'aria-labelledby' ? labelIds : '');
                if (content) trigger.setAttribute(attribute, content);
                else trigger.removeAttribute(attribute);
            }
            if (select.validity.valid) {
                error.hidden = true;
                error.textContent = '';
                trigger.removeAttribute('aria-invalid');
            }
            const describedBy = trigger.getAttribute('aria-describedby') || '';
            if (!error.hidden) trigger.setAttribute('aria-describedby', `${describedBy} ${error.id}`.trim());
            list.setAttribute('aria-label', select.getAttribute('aria-label') || labels.map(label => label.textContent.trim()).join(' ') || select.name || 'Options');
            search.hidden = select.options.length < 8;
            if (trigger.disabled) close();
            else if (opened) { render(); position(); }
        };
        const close = (restoreFocus = false) => {
            if (!opened) return;
            opened = false;
            popup.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
            search.setAttribute('aria-expanded', 'false');
            trigger.removeAttribute('aria-activedescendant');
            search.removeAttribute('aria-activedescendant');
            wrapper.classList.remove('is-open');
            if (restoreFocus && trigger.isConnected && !trigger.disabled) trigger.focus();
        };
        const open = () => {
            if (select.matches(':disabled')) return;
            sync();
            document.dispatchEvent(new CustomEvent('control:open', {detail: {control: trigger}}));
            opened = true;
            search.value = '';
            popup.hidden = false;
            wrapper.classList.add('is-open');
            trigger.setAttribute('aria-expanded', 'true');
            search.setAttribute('aria-expanded', 'true');
            active = select.selectedIndex;
            render();
            position();
            if (!search.hidden) search.focus({preventScroll: true});
        };
        const choose = index => {
            const option = select.options[index];
            if (select.matches(':disabled') || !option || option.disabled || option.parentElement.disabled) return;
            const changed = select.selectedIndex !== index;
            select.selectedIndex = index;
            close(true);
            sync();
            if (changed) {
                select.dispatchEvent(new Event('input', {bubbles: true}));
                select.dispatchEvent(new Event('change', {bubbles: true}));
            }
        };
        const move = delta => {
            const options = enabled();
            const current = options.findIndex(entry => entry.index === active);
            if (options.length) setActive(options[(current + delta + options.length) % options.length].index);
        };
        const keydown = event => {
            if (event.isComposing) return;
            if (event.key === 'Escape' && opened) { event.preventDefault(); event.stopPropagation(); close(true); return; }
            if (event.key === 'Tab' && opened) {
                // The search lives in a portal; resume tab order at the original field.
                if (event.target === search) trigger.focus({preventScroll: true});
                close();
                return;
            }
            if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
                event.preventDefault();
                if (!opened) { open(); if (event.key === 'ArrowUp') setActive(enabled().at(-1)?.index ?? -1); }
                else if (event.key === 'ArrowDown') move(1);
                else if (event.key === 'ArrowUp') move(-1);
                if (event.key === 'Home') setActive(enabled()[0]?.index ?? -1);
                if (event.key === 'End') setActive(enabled().at(-1)?.index ?? -1);
                return;
            }
            if (event.key === 'Enter' || (event.key === ' ' && event.target === trigger)) {
                event.preventDefault();
                if (!opened) open();
                else if (active >= 0) choose(active);
                return;
            }
            if (event.target === trigger && event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
                event.preventDefault();
                if (!opened) open();
                if (!search.hidden) {
                    search.value += event.key;
                    render();
                    return;
                }
                clearTimeout(typeaheadTimer);
                typeahead += event.key.toLocaleLowerCase();
                typeaheadTimer = setTimeout(() => { typeahead = ''; }, 700);
                const match = enabled().find(entry => select.options[entry.index].label.toLocaleLowerCase().startsWith(typeahead));
                if (match) setActive(match.index);
            }
        };

        trigger.addEventListener('click', event => { event.preventDefault(); opened ? close() : open(); });
        trigger.addEventListener('keydown', keydown);
        search.addEventListener('keydown', keydown);
        search.addEventListener('input', () => { active = -1; render(); position(); });
        list.addEventListener('pointerdown', event => event.preventDefault());
        list.addEventListener('click', event => {
            const option = event.target.closest('.select-option');
            if (option) choose(Number(option.dataset.index));
        });
        list.addEventListener('pointermove', event => {
            const option = event.target.closest('.select-option');
            if (option && option.getAttribute('aria-disabled') !== 'true' && Number(option.dataset.index) !== active) setActive(Number(option.dataset.index));
        });
        select.addEventListener('change', sync);
        select.addEventListener('input', sync);
        select.addEventListener('focus', () => trigger.focus());
        select.addEventListener('invalid', event => {
            event.preventDefault();
            error.textContent = select.validationMessage;
            error.hidden = false;
            trigger.setAttribute('aria-invalid', 'true');
            trigger.setAttribute('aria-describedby', `${select.getAttribute('aria-describedby') || ''} ${error.id}`.trim());
            if (!invalidFocusPending) {
                invalidFocusPending = true;
                trigger.focus();
                queueMicrotask(() => { invalidFocusPending = false; });
            }
        });
        const observer = new MutationObserver(sync);
        observer.observe(select, {subtree: true, childList: true, characterData: true, attributes: true,
            attributeFilter: ['disabled', 'required', 'label', 'selected', 'value', 'hidden', 'aria-label', 'aria-labelledby', 'aria-describedby']});
        const reset = () => {
            close();
            error.hidden = true;
            error.textContent = '';
            trigger.removeAttribute('aria-invalid');
            sync();
        };
        controls.set(select, {trigger, popup, sync, close, reset, position, observer, get opened() { return opened; }});
        sync();
    };

    const scan = root => {
        if (root instanceof HTMLSelectElement) enhance(root);
        root.querySelectorAll?.('select').forEach(enhance);
    };
    scan(document);
    const observer = new MutationObserver(records => {
        records.forEach(record => {
            if (record.type === 'attributes' && record.target instanceof HTMLFieldSetElement) {
                record.target.querySelectorAll('select').forEach(select => controls.get(select)?.sync());
            }
            record.addedNodes.forEach(node => { if (node instanceof Element) scan(node); });
        });
        controls.forEach((control, select) => {
            if (select.isConnected) return;
            control.observer.disconnect();
            control.popup.remove();
            controls.delete(select);
        });
    });
    observer.observe(document.body, {childList: true, subtree: true, attributes: true, attributeFilter: ['disabled']});
    document.addEventListener('pointerdown', event => controls.forEach(control => {
        if (control.opened && !control.trigger.contains(event.target) && !control.popup.contains(event.target)) control.close();
    }));
    document.addEventListener('focusin', event => controls.forEach(control => {
        if (control.opened && !control.trigger.contains(event.target) && !control.popup.contains(event.target)) control.close();
    }));
    document.addEventListener('control:open', event => controls.forEach(control => {
        if (event.detail?.control !== control.trigger) control.close();
    }));
    document.addEventListener('reset', event => setTimeout(() => controls.forEach((control, select) => {
        if (select.form !== event.target) return;
        control.reset();
    }), 0));
    window.addEventListener('pageshow', () => controls.forEach(control => { control.close(); control.sync(); }));
    document.addEventListener('themechange', () => controls.forEach(control => control.sync()));
    window.addEventListener('resize', () => controls.forEach(control => control.position()));
    window.visualViewport?.addEventListener('resize', () => controls.forEach(control => control.position()));
    document.addEventListener('scroll', event => {
        if (event.target instanceof Element && event.target.closest('.select-popover')) return;
        controls.forEach(control => control.position());
    }, true);
})();
