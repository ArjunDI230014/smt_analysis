<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<?php $workbookLimit = \App\Libraries\UploadLimits::label(\App\Libraries\DailyCsatImporter::MAX_BYTES); ?>
<div class="page-heading"><div><p class="eyebrow">REPORT LIBRARY</p><h1>From spreadsheet to insight.</h1><p class="muted">Upload the master workbook to create a new dashboard snapshot.</p></div><span class="sheet-badge">Admin workspace</span></div>
<?php if ($uploadLimit['limited']): ?><div class="notice error">This server currently allows files up to <strong><?= esc($uploadLimit['label']) ?></strong>. The app supports <?= esc($workbookLimit) ?> workbooks. Ask your administrator to update this server’s PHP upload settings; for the development server, restart it using <code>.\start.ps1</code>.</div><?php endif ?>
<section class="upload-grid">
    <form class="panel upload-panel" method="post" action="<?= \App\Libraries\Modules::url('admin/uploads') ?>" enctype="multipart/form-data" id="upload-form" data-max-bytes="<?= $uploadLimit['bytes'] ?>" data-max-label="<?= esc($uploadLimit['label']) ?>" data-sheet-name="Raw Data CSAT">
        <?= csrf_field() ?>
        <div class="upload-art" aria-hidden="true">↥</div>
        <h2>Upload your Excel workbook</h2>
        <p>Only the <strong>Raw Data CSAT</strong> worksheet will be imported. All other worksheets are ignored.</p>
        <div class="file-dropzone" id="upload-dropzone">
            <label class="file-label" for="workbook">Choose an .xlsx workbook</label>
            <input id="workbook" type="file" name="workbook" accept=".xlsx" aria-describedby="upload-requirements upload-status" required>
            <p class="small muted" id="upload-drop-hint" hidden>Or drag and drop one workbook here.</p>
            <div class="file-selection" id="upload-selection" hidden>
                <div class="file-selection-details"><strong class="file-name" id="upload-file-name"></strong><span class="small muted file-size" id="upload-file-size"></span></div>
                <button class="button secondary small-button" id="upload-clear" type="button">Clear</button>
            </div>
        </div>
        <p class="small muted" id="upload-requirements">Up to <?= esc($uploadLimit['label']) ?> on this server · <?= number_format(\App\Libraries\DailyCsatImporter::MAX_ROWS) ?> response rows · Raw Data CSAT only</p>
        <button class="button" type="submit" id="upload-button">Import Raw Data CSAT →</button>
        <p class="small" id="upload-status" role="status" aria-live="polite" aria-atomic="true"></p>
    </form>
    <aside class="panel upload-guide"><p class="eyebrow">HOW IT WORKS</p><h2>A focused import.</h2><ol><li><strong>Choose your master workbook</strong><p>Keep the worksheet name exactly “Raw Data CSAT”. If it is missing, the import stops. Every other worksheet is ignored.</p></li><li><strong>Validate the source</strong><p>Identical duplicate surveys count once. Conflicting entries stop the import. The workbook itself is never changed.</p></li><li><strong>Explore your dashboard</strong><p>See recorded DSAT reasons with daily, weekly, and monthly views. Customer comments appear separately when provided.</p></li></ol><div class="inset-note">Each upload is a separate snapshot. Reports are never added together automatically, and an identical file cannot be imported twice. The master’s “Inclulde in CSAT?” flag controls the default inclusion filter.</div></aside>
</section>
<section class="panel">
    <div class="panel-heading"><div><h2>Import history</h2><p><?= count($imports) ?> report snapshots · Archived reports stay here until restored or deleted.</p></div></div>
    <?php if (!$imports): ?>
    <div class="empty compact"><p>No reports uploaded yet. Your first import will appear here.</p></div>
    <?php else: ?>
    <div class="table-scroll"><table><thead><tr><th>Workbook</th><th>Report dates</th><th class="number">Rows</th><th>Uploaded by</th><th>Imported</th><th>Status</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($imports as $item): ?>
    <tr>
        <td class="filename"><?= esc($item['filename']) ?><small>Report snapshot</small></td>
        <td><?= esc($item['date_from']) ?><small>to <?= esc($item['date_to']) ?></small></td>
        <td class="number"><?= number_format($item['row_count']) ?></td>
        <td><?= esc($item['uploader']) ?></td><td><?= esc($item['created_at']) ?></td>
        <td><span class="tag"><?= $item['archived'] ? 'Archived' : 'Active' ?></span></td>
        <td><div class="import-actions">
            <?php if (!$item['archived']): ?><a class="button secondary small-button" href="<?= \App\Libraries\Modules::url('dashboard') . '?import=' . (int) $item['id'] ?>">View</a><?php endif ?>
            <form method="post" action="<?= \App\Libraries\Modules::url('admin/uploads/' . (int) $item['id'] . ($item['archived'] ? '/restore' : '/archive')) ?>">
                <?= csrf_field() ?><button class="button secondary small-button" type="submit" aria-label="<?= esc(($item['archived'] ? 'Restore ' : 'Archive ') . $item['filename']) ?>"><?= $item['archived'] ? 'Restore' : 'Archive' ?></button>
            </form>
            <form method="post" action="<?= \App\Libraries\Modules::url('admin/uploads/' . (int) $item['id'] . '/delete') ?>" class="delete-import-form" data-filename="<?= esc($item['filename']) ?>">
                <?= csrf_field() ?><button class="button secondary small-button danger-button" type="submit" aria-label="<?= esc('Delete ' . $item['filename']) ?>">Delete</button>
            </form>
        </div></td>
    </tr>
    <?php endforeach ?>
    </tbody></table></div>
    <?php endif ?>
</section>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script src="<?= base_url('assets/upload.js') ?>" defer></script>
<script src="<?= base_url('assets/import-history.js') ?>" defer></script>
<?= $this->endSection() ?>
