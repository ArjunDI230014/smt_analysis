<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<?php $period = $filters['period'] ?? 'daily'; $periodLabel = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'][$period] ?? 'Daily'; $tab = $filters['tab'] ?? 'statistics'; ?>
<div class="page-heading"><div><p class="eyebrow">CUSTOMER SATISFACTION</p><h1>Your service, at a glance.</h1><p class="muted">Explore the feedback behind every customer interaction.</p></div><?php if (session('user')['role'] === 'admin'): ?><a class="button" href="<?= \App\Libraries\Modules::url('admin/uploads') ?>">＋ Upload report</a><?php endif ?></div>
<?php if (isset($googleSheets)): ?><?= view('sheets/status', ['googleSheets' => $googleSheets, 'personal' => $personal]) ?><?php endif ?>
<?php if (!$import): ?>
<section class="panel empty"><span class="empty-icon">▥</span><h2>Your first insight starts here</h2><p>Connect Google Sheets or upload a workbook to see scores, DSAT reasons, and daily, weekly, or monthly performance.</p><?php if (session('user')['role'] === 'admin'): ?><a class="button" href="<?= \App\Libraries\Modules::url('admin/uploads') ?>">Upload a report →</a><?php else: ?><p class="muted">Your administrator can connect the sheet or upload the first report.</p><?php endif ?></section>
<?php else: ?>
<form class="panel filters" id="dashboard-filters" method="get" action="<?= \App\Libraries\Modules::url('dashboard') ?>" data-report-from="<?= esc($import['date_from']) ?>" data-report-to="<?= esc($import['date_to']) ?>" data-report-id="<?= $import['id'] ?>">
    <input type="hidden" name="tab" value="<?= esc($tab) ?>">
    <?= view('dashboard/filter-fields', ['values' => $filters, 'omit' => ['from', 'to', 'team', 'channel', 'agent', 'scope', 'period', 'tab', 'dsat_page']]) ?>
    <div class="report-picker"><label>Report snapshot<select name="import"><?php foreach ($imports as $item): ?><option value="<?= $item['id'] ?>" <?= $item['id'] === $import['id'] ? 'selected' : '' ?>><?= esc($item['filename']) ?> · #<?= $item['id'] ?></option><?php endforeach ?></select></label><span class="sheet-badge"><span class="status-dot"></span> CSAT source data</span></div>
    <div class="filter-grid">
        <label class="period-field">View<select name="period" aria-label="Report period"><?php foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'] as $key => $label): ?><option value="<?= $key ?>" <?= $period === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach ?></select></label>
        <label>From<input type="date" name="from" value="<?= esc($filters['from']) ?>"></label>
        <label>To<input type="date" name="to" value="<?= esc($filters['to']) ?>"></label>
        <?php foreach (['team' => 'Team', 'channel' => 'Channel'] as $key => $label): ?><label><?= $label ?><select name="<?= $key ?>"><option value="">All <?= strtolower($label) ?>s</option><?php foreach ($options[$key] as $option): ?><option value="<?= esc($option) ?>" <?= $filters[$key] === $option ? 'selected' : '' ?>><?= esc($option) ?></option><?php endforeach ?></select></label><?php endforeach ?>
        <label>Row inclusion<select name="scope"><?php foreach (['included' => 'Included in CSAT (default)', 'all' => 'All rows', 'excluded' => 'Excluded from CSAT'] as $key => $label): ?><option value="<?= $key ?>" <?= $filters['scope'] === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach ?></select></label>
        <?php if (!$personal): ?><div class="agent-search-field">
            <label for="agent-search">Agent name or email</label>
            <div class="search-input-wrap"><input id="agent-search" type="search" name="agent" value="<?= esc($filters['agent']) ?>" placeholder="Name or email…" maxlength="255" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="agent-suggestions" aria-describedby="agent-search-hint" aria-keyshortcuts="/"><button class="clear-search" type="button" aria-label="Clear agent search" hidden><span aria-hidden="true">×</span></button></div>
            <span class="agent-search-hint" id="agent-search-hint" role="status">Search by name or email. Small name typos are supported.</span>
            <ul class="agent-suggestions" id="agent-suggestions" role="listbox" aria-label="Matching agents" hidden></ul>
        </div><?php endif ?>
    </div>
    <div class="quick-ranges" role="group" aria-label="Quick date ranges" hidden><span class="small muted">Date range</span><button class="range-button" type="button" data-range="all">Entire report</button><button class="range-button" type="button" data-range="7">Last 7 days</button><button class="range-button" type="button" data-range="30">Last 30 days</button><button class="range-button" type="button" data-range="90">Last 90 days</button><span class="small muted range-hint">Ends on the report’s latest date.</span></div>
    <div class="filter-footer"><span class="small muted">Report dates: <?= esc($import['date_from'] ?: 'No responses') ?><?= $import['date_to'] ? ' – ' . esc($import['date_to']) : '' ?></span><div><a class="text-link" href="<?= esc(\App\Libraries\Modules::url('dashboard') . '?' . http_build_query(['import' => $import['id'], 'tab' => $tab])) ?>">Reset filters</a><button class="button small-button" type="submit">Apply filters</button></div></div>
    <p class="filter-status small muted" id="filter-status" role="status" aria-live="polite" aria-atomic="true">Showing the selected report. <?= $personal ? 'Choose filters to explore your responses.' : 'Search updates as you type. Press / to focus search.' ?></p>
    <div class="report-link-tools"><button class="button secondary small-button" type="button" id="copy-report-link">Copy view link</button><span class="small muted" data-copy-status role="status" aria-live="polite">Copy the applied report, filters, and sorting.</span></div>
</form>
<div id="dashboard-results" data-response-count="<?= $summary['total'] ?>" data-agent-count="<?= $matchedNameCount ?>" data-dsat-total="<?= (int) $dsat['total'] ?>" data-period="<?= esc($period) ?>">
<div class="active-filters" aria-label="Applied filters"><span class="small muted">Showing</span><?php if ($filters['scope'] !== 'included'): ?><a class="filter-chip removable" href="<?= esc(\App\Libraries\Modules::url('dashboard') . '?' . http_build_query(array_merge($filters, ['import' => $import['id'], 'scope' => 'included']))) ?>" aria-label="Reset row inclusion to included CSAT responses"><?= $filters['scope'] === 'all' ? 'All rows' : 'Excluded from CSAT' ?> <span aria-hidden="true">×</span></a><?php else: ?><span class="filter-chip">Included in CSAT</span><?php endif ?>
<span class="filter-chip dsat-view-chip"><?= $periodLabel ?> view</span>
<?php if ($filters['from'] !== '' || $filters['to'] !== ''): ?><a class="filter-chip removable" href="<?= esc(\App\Libraries\Modules::url('dashboard') . '?' . http_build_query(array_merge($filters, ['import' => $import['id'], 'from' => '', 'to' => '']))) ?>" aria-label="Remove date filter"><?= esc($filters['from'] ?: 'Start') ?> – <?= esc($filters['to'] ?: 'Latest') ?> <span aria-hidden="true">×</span></a><?php else: ?><span class="filter-chip">Entire report period</span><?php endif ?>
<?php foreach (['team' => 'Team', 'channel' => 'Channel', 'agent' => 'Agent'] as $key => $label): ?><?php if ($filters[$key] !== ''): ?><a class="filter-chip removable" href="<?= esc(\App\Libraries\Modules::url('dashboard') . '?' . http_build_query(array_merge($filters, ['import' => $import['id'], $key => '']))) ?>" aria-label="<?= esc('Remove ' . strtolower($label) . ' filter: ' . $filters[$key]) ?>"><?= $label ?>: <?= esc($filters[$key]) ?> <span aria-hidden="true">×</span></a><?php endif ?><?php endforeach ?>
</div>
<?php if ($filters['agent'] !== ''): ?><p class="search-summary">Search: <strong><?= esc($filters['agent']) ?></strong> · <?= $matchedNameCount ?> matching agent names within these filters. Scores and charts include all matching responses.</p><?php endif ?>
<nav class="report-sections" id="report-sections" aria-label="Report sections">
<?php foreach (['statistics' => 'Statistics', 'dsat' => 'DSAT feedback', 'agents' => 'Agent performance'] as $key => $label): ?>
    <a class="<?= $tab === $key ? 'active' : '' ?>" <?= $tab === $key ? 'aria-current="page"' : '' ?> href="<?= esc(\App\Libraries\Modules::url('dashboard') . '?' . http_build_query(array_merge($filters, ['import' => $import['id'], 'page' => $page, 'tab' => $key]))) ?>#report-sections"><?= $label ?></a>
<?php endforeach ?>
</nav>
<?= view('dashboard/quick-views', compact('filters', 'import')) ?>
<div class="section-heading"><h2>Performance overview</h2><span class="small muted"><?= number_format($summary['total']) ?> of <?= number_format($import['row_count']) ?> responses</span></div>
<section class="kpi-grid" aria-label="Key performance indicators">
    <article class="kpi primary"><div>CSAT score <span>↗</span></div><strong><?= $summary['score'] !== null ? number_format($summary['score'], 2) . '<small>%</small>' : '—' ?></strong><p>Good ÷ (Good + Bad)</p></article>
    <article class="kpi"><div>Total responses <span class="metric-symbol">▤</span></div><strong><?= number_format($summary['total']) ?></strong><p>Within the selected filters</p></article>
    <article class="kpi"><div>Good ratings <span class="metric-symbol positive">＋</span></div><strong><?= number_format($summary['good']) ?></strong><p><span class="legend-dot green"></span> Positive customer feedback</p></article>
    <article class="kpi"><div>Bad ratings <span class="metric-symbol negative">−</span></div><strong><?= number_format($summary['bad']) ?></strong><p><span class="legend-dot orange"></span> Opportunities to improve</p></article>
</section>
<p class="method-note">Inclusion: <strong><?= ['all' => 'All rows', 'included' => 'Included in CSAT', 'excluded' => 'Excluded from CSAT'][$filters['scope']] ?></strong>. <?= number_format($summary['flagged']) ?> matching rows are excluded from CSAT. Scores use response totals, not averages of team percentages.</p>
<?php if ($tab === 'dsat'): ?>
<?= view('dashboard/dsat', ['dsat' => $dsat, 'summary' => $summary, 'groups' => $groups, 'filters' => $filters, 'personal' => $personal, 'showDsatAgents' => !$personal, 'dsatBaseUrl' => \App\Libraries\Modules::url('dashboard'), 'dsatQuery' => array_merge($filters, ['import' => $import['id'], 'page' => $page])]) ?>
<?php elseif ($summary['total'] === 0): ?>
<section class="panel empty"><span class="empty-icon" aria-hidden="true">⌕</span><h2>No responses match these filters</h2><p>Try another date range, team, channel, or agent name.</p><a class="button secondary" href="<?= esc(\App\Libraries\Modules::url('dashboard') . '?' . http_build_query(['import' => $import['id'], 'tab' => $tab])) ?>">Clear filters and view report</a></section>
<?php else: ?>
<?php if ($tab === 'statistics'): ?>
<?php if ($personal): ?>
<?= view('dashboard/agent-insights', ['summary' => $summary, 'groups' => $groups, 'dsat' => $dsat, 'filters' => $filters, 'personal' => true, 'reasonBaseUrl' => \App\Libraries\Modules::url('dashboard'), 'reasonQuery' => array_merge($filters, ['import' => $import['id'], 'tab' => 'dsat'])]) ?>
<?php else: ?>
<?= view('dashboard/charts', compact('period', 'periodLabel', 'groups')) ?>
<?php endif ?>
<?php else: ?>
<?php if ($personal): ?>
<?= view('dashboard/agent-insights', ['summary' => $summary, 'groups' => $groups, 'dsat' => $dsat, 'filters' => $filters, 'personal' => true, 'reasonBaseUrl' => \App\Libraries\Modules::url('dashboard'), 'reasonQuery' => array_merge($filters, ['import' => $import['id'], 'tab' => 'dsat'])]) ?>
<?php endif ?>
<?= view('dashboard/agents', compact('agents', 'agentCount', 'agentTotalCount', 'agentPageSize', 'personal', 'filters', 'page', 'pages', 'import')) ?>
<?php endif ?>
<?php endif ?>
</div><footer class="report-footer">Source: <?= esc($import['filename']) ?> · Report snapshot · Imported <?= esc($import['created_at']) ?> (server time)</footer>
<?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<?php if ($import && !$personal): ?>
<script id="agent-names" type="application/json"><?= json_encode($agentNames, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
<script src="<?= base_url('assets/agent-search.js') ?>" defer></script><script src="<?= base_url('assets/agent-live-search.js') ?>?v=<?= filemtime(FCPATH . 'assets/agent-live-search.js') ?>" defer></script>
<?php endif ?>
<?php if ($import): ?>
<script src="<?= base_url('assets/dashboard-qol.js') ?>" defer></script>
<script id="report-data" type="application/json"><?= json_encode(['summary' => $summary, 'groups' => $groups, 'period' => $period], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
<script src="<?= base_url('assets/vendor/chart.umd.min.js') ?>" defer></script>
<script src="<?= base_url('assets/dashboard.js') ?>" defer></script>
<script src="<?= base_url('assets/agent-performance.js') ?>?v=<?= filemtime(FCPATH . 'assets/agent-performance.js') ?>" defer></script>
<?php endif ?>
<?= $this->endSection() ?>
