const uploadForm = document.getElementById('upload-form');
uploadForm?.addEventListener('submit', event => {
    const file = uploadForm.elements.workbook.files[0];
    const status = document.getElementById('upload-status');
    if (file && file.size > Number(uploadForm.dataset.maxBytes)) {
        event.preventDefault();
        status.textContent = `This file exceeds the server's ${uploadForm.dataset.maxLabel} limit. Start the local server using start.ps1, refresh this page, and try again.`;
        return;
    }
    const button = document.getElementById('upload-button');
    button.disabled = true;
    button.textContent = 'Importing…';
    status.textContent = 'Reading and validating Daily CSAT. Please keep this page open.';
});
