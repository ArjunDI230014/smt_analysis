<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-heading"><div><p class="eyebrow">REPORT LIBRARY</p><h1>From spreadsheet to insight.</h1><p class="muted">Upload a daily report to create a new dashboard snapshot.</p></div><span class="sheet-badge">Admin workspace</span></div>
<?php if ($uploadLimit['limited']): ?><div class="notice error">This server currently allows files up to <strong><?= esc($uploadLimit['label']) ?></strong>. For 20 MB uploads, stop the local server with Ctrl+C and run <code>.\start.ps1</code> from the folder containing <code>spark</code>, then refresh this page.</div><?php endif ?>
<section class="upload-grid"><form class="panel upload-panel" method="post" action="<?= site_url('admin/uploads') ?>" enctype="multipart/form-data" id="upload-form" data-max-bytes="<?= $uploadLimit['bytes'] ?>" data-max-label="<?= esc($uploadLimit['label']) ?>"><?= csrf_field() ?><div class="upload-art" aria-hidden="true">↥</div><h2>Upload your Excel workbook</h2><p>Only the <strong>Daily CSAT</strong> worksheet will be imported.</p><label class="file-label">Choose an .xlsx workbook<input type="file" name="workbook" accept=".xlsx" required></label><p class="small muted">Up to <?= esc($uploadLimit['label']) ?> on this server · 15,000 response rows · Headers in row 1</p><button class="button" type="submit" id="upload-button">Import Daily CSAT →</button><p class="small" id="upload-status" role="status"></p></form><aside class="panel upload-guide"><p class="eyebrow">HOW IT WORKS</p><h2>A focused import.</h2><ol><li><strong>Choose your workbook</strong><p>Keep the sheet name exactly “Daily CSAT”. All other worksheets are ignored.</p></li><li><strong>Validate the responses</strong><p>Dates, Good/Bad ratings, CSAT IDs, and exclusion flags are checked before anything is saved.</p></li><li><strong>Explore your dashboard</strong><p>Flagged rows are excluded by default. Choose All rows to include them when needed.</p></li></ol><div class="inset-note">Each upload is a separate snapshot. Reports are never added together automatically, and an identical file cannot be imported twice.</div></aside></section>
<section class="panel">
    <div class="panel-heading"><div><h2>Import history</h2><p><?= count($imports) ?> report snapshots · Archived reports stay here until restored or deleted.</p></div></div>
    <?php if (!$imports): ?>
    <div class="empty compact"><p>No reports uploaded yet. Your first import will appear here.</p></div>
    <?php else: ?>
    <div class="table-scroll"><table><thead><tr><th>Workbook</th><th>Report dates</th><th class="number">Rows</th><th>Uploaded by</th><th>Imported</th><th>Status</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($imports as $item): ?>
    <tr>
        <td class="filename"><?= esc($item['filename']) ?><small>Daily CSAT</small></td>
        <td><?= esc($item['date_from']) ?><small>to <?= esc($item['date_to']) ?></small></td>
        <td class="number"><?= number_format($item['row_count']) ?></td>
        <td><?= esc($item['uploader']) ?></td><td><?= esc($item['created_at']) ?></td>
        <td><span class="tag"><?= $item['archived'] ? 'Archived' : 'Active' ?></span></td>
        <td><div class="import-actions">
            <?php if (!$item['archived']): ?><a class="button secondary small-button" href="<?= site_url('dashboard') . '?import=' . (int) $item['id'] ?>">View</a><?php endif ?>
            <form method="post" action="<?= site_url('admin/uploads/' . (int) $item['id'] . ($item['archived'] ? '/restore' : '/archive')) ?>">
                <?= csrf_field() ?><button class="button secondary small-button" type="submit" aria-label="<?= esc(($item['archived'] ? 'Restore ' : 'Archive ') . $item['filename']) ?>"><?= $item['archived'] ? 'Restore' : 'Archive' ?></button>
            </form>
            <form method="post" action="<?= site_url('admin/uploads/' . (int) $item['id'] . '/delete') ?>" class="delete-import-form" data-filename="<?= esc($item['filename']) ?>">
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
