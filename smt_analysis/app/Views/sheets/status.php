<?php
$source = $googleSheets['source'] ?? null;
$admin = !$personal;
$connected = $googleSheets['configured'] && $googleSheets['credentials_ready'];
$settingsPage = $settingsPage ?? false;
$lastSync = $source['last_synced_at'] ?? null;
$connectionTitle = match (true) {
    !$googleSheets['configured'] => 'Connect Google Sheets',
    !$source['enabled'] => 'Google Sheets paused',
    !$googleSheets['credentials_ready'] => 'Google Sheets setup incomplete',
    !empty($source['last_error']) => 'Google Sheets connection needs attention',
    !$lastSync => 'Google Sheets awaiting first refresh',
    default => 'Google Sheets connected',
};
$lastDisplay = $lastSync ? (new DateTimeImmutable($lastSync, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Kuala_Lumpur'))->format('d M Y, H:i') . ' MYT' : 'No successful refresh yet';
?>
<section class="panel sheets-status" aria-label="Google Sheets connection"
    <?php if ($connected && $source['enabled'] && !$settingsPage): ?>data-sheet-sync="<?= site_url('sheets/refresh') ?>" data-sheet-import="<?= (int) ($source['import_id'] ?? 0) ?>" data-sheet-updated="<?= esc($lastSync ?? '') ?>"<?php endif ?>>
    <div><h2><?= esc($connectionTitle) ?></h2>
    <p class="small muted" data-sheet-sync-message aria-live="polite"><?= esc($lastDisplay) ?><?= $connected && $source['enabled'] ? ' · Checks every ' . (int) $source['refresh_minutes'] . ' minutes while this dashboard is open' : '' ?></p>
    <?php if ($admin && $source): ?><p class="small muted"><?= number_format((int) $source['row_count']) ?> unique responses · <?= number_format((int) $source['duplicate_count']) ?> identical duplicates skipped<?= $source['sheet_title'] !== '' ? ' · ' . esc($source['sheet_title']) : '' ?></p><?php endif ?>
    <?php if ($admin && !$googleSheets['credentials_ready']): ?><p class="small sheets-status-error"><?= esc($googleSheets['credentials_error'] ?? 'Add the service-account JSON key to finish connecting.') ?></p><?php endif ?>
    <?php if ($source && $source['last_error']): ?><p class="small sheets-status-error"><?= $admin ? esc($source['last_error']) : 'The last refresh could not finish. Your administrator can check the connection.' ?></p><?php endif ?>
    <?php if (!$googleSheets['configured'] && !$admin): ?><p class="small muted">Your administrator can connect the source spreadsheet.</p><?php endif ?>
    </div>
    <?php if ($admin): ?><div class="sheets-status-actions"><?php if (!$settingsPage): ?><a class="button secondary small-button" href="<?= site_url('admin/sheets') ?>"><?= $googleSheets['configured'] ? 'Edit connection' : 'Connect sheet' ?></a><?php endif ?>
    <?php if ($connected && $source['enabled']): ?><form method="post" action="<?= site_url('admin/sheets/refresh') ?>"><?= csrf_field() ?><button class="button small-button" type="submit">Refresh now</button></form><?php endif ?></div><?php endif ?>
    <?php if ($connected && $source['enabled'] && !$settingsPage): ?><form hidden data-sheet-sync-token><?= csrf_field() ?></form><script src="<?= base_url('assets/sheets-sync.js') ?>?v=<?= filemtime(FCPATH . 'assets/sheets-sync.js') ?>" defer></script><?php endif ?>
</section>
