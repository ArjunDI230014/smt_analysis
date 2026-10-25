<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Upload exceeds server limit</title></head>
<body style="font-family:system-ui,sans-serif;max-width:680px;margin:60px auto;padding:24px;line-height:1.6">
<h1>Upload exceeds the server limit</h1>
<p>This PHP server accepts requests up to <strong><?= esc($limit) ?></strong>. PHP discarded the uploaded file and form fields. No report was imported.</p>
<p>For local development, stop the server with Ctrl+C and run <code>.\start.ps1</code> from the folder containing <code>spark</code>. This enables 128 MB file uploads and 130 MB requests.</p>
<p>Then refresh the upload page and select your workbook again.</p>
<p><a href="<?= \App\Libraries\Modules::url('admin/uploads') ?>">Return to Upload reports</a></p>
</body></html>
