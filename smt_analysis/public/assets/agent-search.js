(() => {
    const input = document.getElementById('agent-search');
    const list = document.getElementById('agent-suggestions');
    const hint = document.getElementById('agent-search-hint');
    const normalize = value => value.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').trim();
    const names = JSON.parse(document.getElementById('agent-names').textContent).map(name => ({name, normalized: normalize(name)}));
    let active = -1;
    const distance = (a, b) => {
        let previous = Array.from({length: b.length + 1}, (_, i) => i);
        for (let i = 1; i <= a.length; i++) {
            const current = [i];
            for (let j = 1; j <= b.length; j++) current[j] = Math.min(current[j - 1] + 1, previous[j] + 1, previous[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
            previous = current;
        }
        return previous[b.length];
    };
    const close = () => {
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        active = -1;
    };
    const choose = item => {
        input.value = item.textContent;
        close();
        input.form.requestSubmit();
    };
    const refresh = () => {
        list.replaceChildren();
        active = -1;
        input.removeAttribute('aria-activedescendant');
        const query = normalize(input.value);
        if (!query) { close(); hint.textContent = 'Partial names, any word order, or small typos.'; return; }
        const terms = query.split(' ');
        const matches = names.filter(({normalized}) => terms.every(term => {
            if (normalized.includes(term)) return true;
            const allowed = term.length >= 7 ? 2 : (term.length >= 4 ? 1 : 0);
            return allowed > 0 && normalized.split(' ').some(word => distance(term, word) <= allowed);
        }));
        hint.textContent = matches.length ? `${matches.length} matching names. Enter searches all; arrows select a suggestion.` : 'No matching names in these filters. Try fewer letters or Reset.';
        matches.slice(0, 10).forEach(({name}, index) => {
            const item = document.createElement('li');
            item.id = `agent-option-${index}`;
            item.role = 'option';
            item.setAttribute('aria-selected', 'false');
            item.textContent = name;
            item.addEventListener('pointerdown', event => event.preventDefault());
            item.addEventListener('click', () => choose(item));
            list.append(item);
        });
        list.hidden = matches.length === 0;
        input.setAttribute('aria-expanded', String(matches.length > 0));
    };
    input.addEventListener('input', refresh);
    input.addEventListener('focus', refresh);
    input.addEventListener('blur', close);
    input.addEventListener('keydown', event => {
        const items = [...list.children];
        if (event.key === 'Escape') { event.preventDefault(); close(); return; }
        if (list.hidden || !items.length) return;
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            active = event.key === 'ArrowDown' ? (active + 1) % items.length : (active <= 0 ? items.length - 1 : active - 1);
            items.forEach((item, index) => item.setAttribute('aria-selected', String(index === active)));
            input.setAttribute('aria-activedescendant', items[active].id);
            items[active].scrollIntoView({block:'nearest'});
        } else if (event.key === 'Enter' && active >= 0) {
            event.preventDefault(); choose(items[active]);
        }
    });
})();
