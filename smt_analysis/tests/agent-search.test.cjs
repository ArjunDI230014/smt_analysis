const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../public/assets/agent-search.js'), 'utf8');

function node(properties = {}) {
    const callbacks = new Map();
    const attributes = new Map();
    return Object.assign({
        children: [],
        addEventListener(type, callback) {
            callbacks.set(type, [...(callbacks.get(type) || []), callback]);
        },
        emit(type, event = {}) { (callbacks.get(type) || []).forEach(callback => callback(event)); },
        setAttribute(name, value) { attributes.set(name, value); },
        removeAttribute(name) { attributes.delete(name); },
        getAttribute(name) { return attributes.get(name); },
        replaceChildren() { this.children = []; },
        append(child) { this.children.push(child); },
    }, properties);
}

test('email queries close name suggestions while returning to names restores keyboard suggestions', () => {
    let submitted = 0;
    const input = node({ value: 'Alice', form: { requestSubmit: () => { submitted++; } } });
    const list = node({ hidden: true });
    const hint = node({ textContent: '' });
    const names = node({ textContent: JSON.stringify(['Alice Smith', 'Bob Jones']) });
    const nodes = { 'agent-search': input, 'agent-suggestions': list, 'agent-search-hint': hint, 'agent-names': names };
    const document = node({
        activeElement: input,
        getElementById: id => nodes[id],
        createElement: () => node({ scrollIntoView() {} }),
    });
    vm.runInNewContext(source, { document });
    input.emit('focus');
    assert.equal(list.hidden, false);
    assert.equal(list.children[0].textContent, 'Alice Smith');
    input.value = 'alice@example.test';
    input.emit('input');
    assert.equal(list.hidden, true);
    assert.equal(list.children.length, 0);
    assert.equal(input.getAttribute('aria-expanded'), 'false');
    assert.equal(hint.textContent, 'Searching agent emails…');
    assert.equal(submitted, 0);
    input.value = 'Alice';
    input.emit('input');
    input.emit('keydown', { key: 'ArrowDown', preventDefault() {} });
    input.emit('keydown', { key: 'Enter', preventDefault() {} });
    assert.equal(input.value, 'Alice Smith');
    assert.equal(submitted, 1);
    assert.equal(list.hidden, true);
    input.value = '';
    input.emit('input');
    assert.match(hint.textContent, /name or email/);
    input.value = 'Unmatched';
    input.emit('input');
    assert.equal(hint.textContent, 'No name suggestions. Email search is also supported.');
});
