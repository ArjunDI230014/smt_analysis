(() => {
    const form = document.getElementById('upload-form');
    if (!form) return;

    const input = form.elements.workbook;
    const button = document.getElementById('upload-button');
    const clearButton = document.getElementById('upload-clear');
    const dropzone = document.getElementById('upload-dropzone');
    const selection = document.getElementById('upload-selection');
    const fileName = document.getElementById('upload-file-name');
    const fileSize = document.getElementById('upload-file-size');
    const status = document.getElementById('upload-status');
    const buttonLabel = button.textContent;
    const maxBytes = Number(form.dataset.maxBytes);
    let importing = false;
    let dragDepth = 0;

    const announce = (message, kind = '') => {
        status.textContent = message;
        status.classList.toggle('is-error', kind === 'error');
        status.classList.toggle('is-success', kind === 'success');
    };

    const formatSize = bytes => {
        if (bytes < 1024) return `${bytes.toLocaleString()} bytes`;
        const unit = bytes < 1024 * 1024 ? 'KB' : 'MB';
        const divisor = unit === 'KB' ? 1024 : 1024 * 1024;
        return `${(bytes / divisor).toLocaleString(undefined, { maximumFractionDigits: 1 })} ${unit}`;
    };

    const validate = file => {
        if (!file) return '';
        if (!/\.xlsx$/i.test(file.name)) return 'Choose an Excel workbook with the .xlsx extension.';
        if (Number.isFinite(maxBytes) && maxBytes > 0 && file.size > maxBytes) {
            return `This workbook is too large. Choose a file up to ${form.dataset.maxLabel}.`;
        }
        return '';
    };

    const updateSelection = (emptyMessage = '') => {
        const file = input.files[0];
        const error = validate(file);
        input.setCustomValidity(error);
        input.setAttribute('aria-invalid', error ? 'true' : 'false');
        dropzone.classList.toggle('is-invalid', Boolean(error));
        dropzone.classList.toggle('has-file', Boolean(file));
        selection.hidden = !file;
        fileName.textContent = file?.name || '';
        fileSize.textContent = file ? formatSize(file.size) : '';
        clearButton.disabled = importing;
        announce(error || (file ? 'Workbook selected. Ready to import Daily CSAT.' : emptyMessage), error ? 'error' : file ? 'success' : '');
        return error;
    };

    input.addEventListener('change', () => updateSelection());
    input.addEventListener('invalid', () => {
        if (!input.files.length) announce('Choose a workbook before importing.', 'error');
    });

    clearButton.addEventListener('click', () => {
        input.value = '';
        updateSelection('Selection cleared. Choose another workbook when you are ready.');
        input.focus();
    });

    // Keep the native picker available when a browser cannot accept dropped files.
    const canDropFiles = typeof DataTransfer !== 'undefined'
        && Boolean(Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'files')?.set);
    if (canDropFiles) {
        document.getElementById('upload-drop-hint').hidden = false;
        const isFileDrag = event => Array.from(event.dataTransfer?.types || []).includes('Files');
        const resetDrag = () => {
            dragDepth = 0;
            dropzone.classList.remove('is-dragover');
        };
        dropzone.addEventListener('dragenter', event => {
            if (!isFileDrag(event) || importing) return;
            event.preventDefault();
            dragDepth += 1;
            dropzone.classList.add('is-dragover');
        });
        dropzone.addEventListener('dragover', event => {
            if (!isFileDrag(event)) return;
            event.preventDefault();
            event.dataTransfer.dropEffect = importing ? 'none' : 'copy';
        });
        dropzone.addEventListener('dragleave', () => {
            dragDepth = Math.max(0, dragDepth - 1);
            if (!dragDepth) resetDrag();
        });
        dropzone.addEventListener('drop', event => {
            if (!isFileDrag(event)) return;
            event.preventDefault();
            resetDrag();
            if (importing) return;
            if (event.dataTransfer.files.length !== 1) {
                announce('Drop one workbook at a time.', 'error');
                return;
            }
            try {
                input.files = event.dataTransfer.files;
                updateSelection();
            } catch {
                announce('Use the file picker to choose your workbook in this browser.', 'error');
                input.focus();
            }
        });
        window.addEventListener('dragend', resetDrag);
    }

    form.addEventListener('submit', event => {
        if (importing) {
            event.preventDefault();
            return;
        }
        const error = updateSelection();
        if (!input.files.length || error) {
            event.preventDefault();
            input.reportValidity();
            return;
        }
        importing = true;
        button.disabled = true;
        clearButton.disabled = true;
        button.textContent = 'Importing…';
        form.classList.add('is-busy');
        button.setAttribute('aria-busy', 'true');
        announce('Reading and validating Daily CSAT. Please keep this page open.');
    });

    window.addEventListener('pageshow', () => {
        importing = false;
        button.disabled = false;
        button.textContent = buttonLabel;
        form.classList.remove('is-busy');
        button.removeAttribute('aria-busy');
        dropzone.classList.remove('is-dragover');
        dragDepth = 0;
        updateSelection();
    });

    updateSelection();
})();
