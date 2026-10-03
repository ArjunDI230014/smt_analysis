<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-heading"><div><p class="eyebrow">CUSTOMER SATISFACTION</p><h1>Your service, at a glance.</h1><p class="muted">Explore the feedback behind every customer interaction.</p></div><?php if (session('user')['role'] === 'admin'): ?><a class="button" href="<?= site_url('admin/uploads') ?>">＋ Upload report</a><?php endif ?></div>
<?php if (!$import): ?>
<section class="panel empty"><span class="empty-icon">▥</span><h2>Your first insight starts here</h2><p>Upload an Excel workbook containing the <strong>Daily CSAT</strong> worksheet to see scores, team performance, and channel breakdowns.</p><?php if (session('user')['role'] === 'admin'): ?><a class="button" href="<?= site_url('admin/uploads') ?>">Upload your first report →</a><?php else: ?><p class="muted">Your administrator can upload the first report.</p><?php endif ?></section>
<?php else: ?>
<form class="panel filters" method="get" action="<?= site_url('dashboard') ?>">
    <div class="report-picker"><label>Report snapshot<select name="import"><?php foreach ($imports as $item): ?><option value="<?= $item['id'] ?>" <?= $item['id'] === $import['id'] ? 'selected' : '' ?>><?= esc($item['filename']) ?> · #<?= $item['id'] ?></option><?php endforeach ?></select></label><span class="sheet-badge"><span class="status-dot"></span> Daily CSAT only</span></div>
    <div class="filter-grid">
        <label>From<input type="date" name="from" value="<?= esc($filters['from']) ?>"></label>
        <label>To<input type="date" name="to" value="<?= esc($filters['to']) ?>"></label>
        <?php foreach (['team' => 'Team', 'channel' => 'Channel'] as $key => $label): ?><label><?= $label ?><select name="<?= $key ?>"><option value="">All <?= strtolower($label) ?>s</option><?php foreach ($options[$key] as $option): ?><option value="<?= esc($option) ?>" <?= $filters[$key] === $option ? 'selected' : '' ?>><?= esc($option) ?></option><?php endforeach ?></select></label><?php endforeach ?>
        <label>Row inclusion<select name="scope"><?php foreach (['included' => 'Unflagged rows only (default)', 'all' => 'All rows', 'excluded' => 'Flagged rows only'] as $key => $label): ?><option value="<?= $key ?>" <?= $filters['scope'] === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach ?></select></label>
        <?php if (!$personal): ?><div class="agent-search-field">
            <label for="agent-search">Agent search</label>
            <input id="agent-search" type="search" name="agent" value="<?= esc($filters['agent']) ?>" placeholder="Part of a name…" maxlength="255" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="agent-suggestions" aria-describedby="agent-search-hint">
            <span class="agent-search-hint" id="agent-search-hint" role="status">Partial names, any word order, or small typos.</span>
            <ul class="agent-suggestions" id="agent-suggestions" role="listbox" aria-label="Matching agents" hidden></ul>
        </div><?php endif ?>
    </div>
    <div class="filter-footer"><span class="small muted">Report dates: <?= esc($import['date_from']) ?> – <?= esc($import['date_to']) ?></span><div><a class="text-link" href="<?= site_url('dashboard') . '?import=' . $import['id'] ?>">Reset</a><button class="button small-button" type="submit">Apply filters</button></div></div>
</form>
<div id="dashboard-results">
<?php if ($filters['agent'] !== ''): ?><p class="search-summary">Search: <strong><?= esc($filters['agent']) ?></strong> · <?= $matchedNameCount ?> matching agent names within these filters. Scores and charts include all matching responses.</p><?php endif ?>
<div class="section-heading"><h2>Performance overview</h2><span class="small muted"><?= number_format($summary['total']) ?> of <?= number_format($import['row_count']) ?> responses</span></div>
<section class="kpi-grid" aria-label="Key performance indicators">
    <article class="kpi primary"><div>CSAT score <span>↗</span></div><strong><?= $summary['score'] !== null ? number_format($summary['score'], 2) . '<small>%</small>' : '—' ?></strong><p>Good ÷ (Good + Bad)</p></article>
    <article class="kpi"><div>Total responses <span class="metric-symbol">▤</span></div><strong><?= number_format($summary['total']) ?></strong><p>Within the selected filters</p></article>
    <article class="kpi"><div>Good ratings <span class="metric-symbol positive">＋</span></div><strong><?= number_format($summary['good']) ?></strong><p><span class="legend-dot green"></span> Positive customer feedback</p></article>
    <article class="kpi"><div>Bad ratings <span class="metric-symbol negative">−</span></div><strong><?= number_format($summary['bad']) ?></strong><p><span class="legend-dot orange"></span> Opportunities to improve</p></article>
</section>
<p class="method-note">Inclusion: <strong><?= ['all' => 'All rows', 'included' => 'Unflagged rows only', 'excluded' => 'Flagged rows only'][$filters['scope']] ?></strong>. <?= number_format($summary['flagged']) ?> matching rows have is_excluded = 1. Scores use response totals, not averages of team percentages.</p>
<?php if ($summary['total'] === 0): ?>
<section class="panel empty"><h2>No responses match these filters</h2><p>Try another date range, team, channel, or agent name.</p></section>
<?php else: ?>
<div id="chart-error" class="notice error" hidden>Charts could not load. The score cards and tables remain available.</div>
<noscript><div class="notice">Enable JavaScript to display the charts. Scores and agent totals are available below.</div></noscript>
<section class="chart-grid">
    <article class="panel"><div class="panel-heading"><div><h2>Daily CSAT trend</h2><p>Customer satisfaction over the report period</p></div><span class="tag">CSAT %</span></div><div class="chart"><canvas id="trend-chart" role="img" aria-label="Daily CSAT percentages"></canvas></div><?php if (count($groups['trend']) === 1): ?><p class="chart-note">This snapshot contains one day. Reports with multiple dates will show a trend.</p><?php endif ?></article>
    <article class="panel"><div class="panel-heading"><div><h2>Feedback mix</h2><p>Good and Bad ratings</p></div></div><div class="chart"><canvas id="mix-chart" role="img" aria-label="Good versus Bad ratings"></canvas></div></article>
    <article class="panel"><div class="panel-heading"><div><h2>CSAT by team</h2><p>Top 12 teams by response volume · hover for counts</p></div><span class="tag">CSAT %</span></div><div class="chart tall"><canvas id="team-chart" role="img" aria-label="CSAT by team"></canvas></div></article>
    <article class="panel"><div class="panel-heading"><div><h2>Feedback by channel</h2><p>Using the CSAT submission channel</p></div></div><div class="chart tall"><canvas id="channel-chart" role="img" aria-label="Good and Bad responses by submission channel"></canvas></div></article>
</section>
<section class="panel agent-panel" id="agent-performance"><div class="panel-heading"><div><h2>Agent performance</h2><p>Sorted by response volume · <?= number_format($agentCount) ?> agent/team combinations</p></div><span class="tag">Daily CSAT</span></div><div class="table-scroll"><table><thead><tr><th>Agent</th><th>Team</th><th class="number">Responses</th><th class="number">Good</th><th class="number">Bad</th><th class="number">CSAT</th></tr></thead><tbody><?php foreach ($agents as $agent): ?><tr><td class="agent-name"><?php if (!$personal): ?><a class="text-link" href="<?= esc(site_url('admin/agents/' . $agent['response_id']) . '?' . http_build_query($filters)) ?>"><?= esc($agent['agent_name']) ?></a><?php else: ?><?= esc($agent['agent_name']) ?><?php endif ?></td><td><span class="tag"><?= esc($agent['team']) ?></span></td><td class="number"><?= number_format($agent['total']) ?></td><td class="number"><?= number_format($agent['good']) ?></td><td class="number"><?= number_format($agent['bad']) ?></td><td class="number score"><?= number_format($agent['score'], 2) ?>%</td></tr><?php endforeach ?></tbody></table></div><div class="pagination"><span>Page <?= $page ?> of <?= $pages ?></span><div><?php if ($page > 1): ?><a class="button secondary small-button" href="<?= esc(site_url('dashboard') . '?' . http_build_query(array_merge($filters, ['import' => $import['id'], 'page' => $page - 1]))) ?>">← Previous</a><?php endif ?><?php if ($page < $pages): ?><a class="button secondary small-button" href="<?= esc(site_url('dashboard') . '?' . http_build_query(array_merge($filters, ['import' => $import['id'], 'page' => $page + 1]))) ?>">Next →</a><?php endif ?></div></div></section>
<?php endif ?>
</div><footer class="report-footer">Source: <?= esc($import['filename']) ?> · Daily CSAT · Imported <?= esc($import['created_at']) ?> (server time)</footer>
<?php endif ?>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<?php if ($import && !$personal): ?>
<script id="agent-names" type="application/json"><?= json_encode($agentNames, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
<script src="<?= base_url('assets/agent-search.js') ?>" defer></script><script src="<?= base_url('assets/agent-live-search.js') ?>" defer></script>
<?php endif ?>
<?php if ($import): ?>
<script id="report-data" type="application/json"><?= json_encode(['summary' => $summary, 'groups' => $groups], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
<script src="<?= base_url('assets/vendor/chart.umd.min.js') ?>" defer></script>
<script src="<?= base_url('assets/dashboard.js') ?>" defer></script>
<?php endif ?>
<?= $this->endSection() ?>
