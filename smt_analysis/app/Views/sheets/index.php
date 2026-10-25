<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<?php
$source = $googleSheets['source'] ?? [];
$config = config('GoogleSheets');
$module = \App\Libraries\Modules::current();
$spreadsheetId = $source['spreadsheet_id'] ?? ($module === \App\Libraries\Modules::SHOPEE_FOOD ? $config->defaultSpreadsheetId : '');
$sourceUrl = $spreadsheetId !== '' ? 'https://docs.google.com/spreadsheets/d/' . rawurlencode($spreadsheetId) . '/edit#gid=' . (int) ($source['sheet_gid'] ?? 0) : '';
$managedKey = trim((string) env($config->credentialsEnvironment($module), '')) !== '';
?>
<div class="page-heading"><div><p class="eyebrow">CONNECTED DATA</p><h1>Google Sheets</h1><p class="muted">Connect once. Keep your sheet updated and the dashboard follows.</p></div><a class="button secondary" href="<?= \App\Libraries\Modules::url('dashboard') ?>">View dashboard</a></div>
<nav class="sheets-module-navigation" aria-label="Google Sheets modules">
<?php foreach (\App\Libraries\Modules::all() as $key => $label): ?>
    <a class="<?= $module === $key ? 'active' : '' ?>" <?= $module === $key ? 'aria-current="page"' : '' ?> href="<?= \App\Libraries\Modules::url('admin/sheets', $key) ?>"><?= esc($label) ?></a>
<?php endforeach ?>
</nav>
<?php if ($googleSheets['migration_required']): ?><div class="notice error" role="alert">The server needs its latest database migration before this connection can be saved.</div><?php endif ?>
<?= view('sheets/status', ['googleSheets' => $googleSheets, 'personal' => false, 'settingsPage' => true]) ?>
<div class="sheets-settings-grid">
<section class="panel sheets-settings"><div class="panel-heading"><div><h2>Connection settings</h2><p>Change the file URL here whenever you move to another spreadsheet.</p></div></div>
<form action="<?= \App\Libraries\Modules::url('admin/sheets') ?>" method="post" enctype="multipart/form-data">
<?= csrf_field() ?>
<label><?= esc(\App\Libraries\Modules::label()) ?> Google Sheet URL<input type="url" name="spreadsheet_url" value="<?= esc($sourceUrl) ?>" maxlength="2048" required placeholder="https://docs.google.com/spreadsheets/d/…/edit" aria-describedby="sheet-url-help"></label>
<p class="small muted" id="sheet-url-help">Paste the file URL from any tab. The app always imports the worksheet named exactly <strong>Raw Data CSAT</strong> and ignores every other tab. If that worksheet is missing, the refresh stops and keeps the last successful report.</p>
<div class="sheets-form-row"><label>Check for updates every (minutes)<input type="number" name="refresh_minutes" min="1" max="1440" required value="<?= (int) ($source['refresh_minutes'] ?? 15) ?>"></label><label>Connection<select name="enabled"><option value="1" <?= !isset($source['enabled']) || $source['enabled'] ? 'selected' : '' ?>>Enabled</option><option value="0" <?= isset($source['enabled']) && !$source['enabled'] ? 'selected' : '' ?>>Paused</option></select></label></div>
<fieldset class="sheets-mappings"><legend>DSAT details</legend><p class="small muted">Enter exact column headings from Raw Data CSAT, or leave blank to use recognised fields. The master source records reasons in <strong>csat_reason</strong>. Map a customer comment only when your source includes a separate comment field. Issue categories are not treated as DSAT reasons automatically.</p>
<label>Reason column<input name="reason_column" maxlength="255" value="<?= esc($source['reason_column'] ?? '') ?>" placeholder="e.g. csat_reason"></label>
<label>Customer comment column<input name="comment_column" maxlength="255" value="<?= esc($source['comment_column'] ?? '') ?>" placeholder="Optional exact comment heading"></label>
<label>Case or ticket ID column<input name="case_column" maxlength="255" value="<?= esc($source['case_column'] ?? '') ?>" placeholder="e.g. case_id"></label>
</fieldset>
<?php if (!$managedKey): ?>
<label><?= $googleSheets['credentials_ready'] ? 'Replace service-account key (optional)' : 'Service-account JSON key' ?><input type="file" name="credentials" accept=".json,application/json" aria-describedby="key-help"></label>
<p class="small muted" id="key-help">One-time Google connection key, up to 64 KB. Stored privately on this server. Leave blank to keep the current key.</p>
<?php else: ?><p class="method-note">The service-account key is managed by the server administrator.</p><?php endif ?>
<div class="sheets-form-actions"><button class="button" type="submit" <?= $googleSheets['migration_required'] ? 'disabled' : '' ?>>Save connection</button><span class="small muted">Save changes before refreshing.</span></div>
</form></section>
<aside class="panel sheets-guide"><div class="panel-heading"><h2>One-time setup</h2></div>
<ol><li>In <a href="https://console.cloud.google.com/" target="_blank" rel="noopener noreferrer">Google Cloud</a>, create a project and enable <strong>Google Sheets API</strong>.</li><li>Open <strong>IAM &amp; Admin → Service Accounts</strong>. Create an account, then create a JSON key in its <strong>Keys</strong> tab.</li><li>Add that key to the connection settings and save.</li><li>Sign in with the Google account that owns your spreadsheet. Click <strong>Share</strong>, add the service-account email below, and choose <strong>Viewer</strong>.</li><li>Choose <strong>Refresh now</strong>. The app will validate the data and build the report.</li></ol>
<p>Your spreadsheet account can use a different email from this app or GPT. The web app connects directly to Google.</p>
<?php if ($googleSheets['service_email']): ?><label>Share the sheet with<input type="text" readonly value="<?= esc($googleSheets['service_email']) ?>" aria-label="Service-account email to share with" class="service-email"></label><?php endif ?>
<p class="small muted">When you change files, share the new file with this same service-account email and save its URL.</p>
<details><summary>Source worksheet and duplicate handling</summary><p class="small muted">Use the original <strong>Raw Data CSAT</strong> worksheet. The app reads only that exact worksheet name for both Google Sheets and Excel uploads. Its <strong>Inclulde in CSAT?</strong> flag controls inclusion. The recorded csat_reason codes are displayed using the source’s reason labels; a response can contain more than one reason. A missing reason shows “Reason not provided”; comments are displayed separately when provided.</p><p class="small muted">Identical entries with the same CSAT ID count once. Conflicting entries with the same ID stop the refresh and keep the last successful report. Fix them in the source and refresh again. Repeated refreshes update one connected report to match Raw Data CSAT. Keep historical rows in that worksheet for weekly and monthly trends.</p></details>
<details><summary>Updates while the dashboard is closed</summary><p class="small muted">The dashboard checks for due updates while it is open. For unattended updates, run the supplied scheduled sync task on the server. The server must remain awake and connected. See SETUP.md for the one-time scheduler command.</p></details>
</aside></div>
<?= $this->endSection() ?>
