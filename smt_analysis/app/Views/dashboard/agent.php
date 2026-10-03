<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-heading"><div><p class="eyebrow">AGENT INFORMATION</p><h1><?= esc($identity['agent_name']) ?></h1><p class="muted"><?= esc($identity['agent_email'] ?: 'No agent email in this report') ?></p></div><a class="button secondary" href="<?= esc(site_url('dashboard') . '?' . http_build_query(array_merge($filters, ['import' => $identity['import_id']])) . '#agent-performance') ?>">← Agent performance</a></div>
<section class="panel"><div class="panel-heading"><div><h2>Account information</h2><p><?= $account ? esc(ucfirst($account['role'])) . ' · ' . ($account['archived'] ? 'Archived' : ($account['active'] ? 'Active' : 'Inactive')) : 'No registered account linked to this report identity.' ?></p></div><?php if ($account): ?><a class="text-link" href="<?= esc(site_url('admin/users') . '?' . http_build_query(['q' => $account['email']])) ?>">Manage account →</a><?php endif ?></div></section>
<p class="method-note">Performance in the selected report and filters. <?= $identity['agent_email'] ? 'Matched by agent email and name.' : 'This report has no email for this agent; matching rows share this exact name.' ?></p>
<section class="kpi-grid">
<article class="kpi primary"><div>CSAT score</div><strong><?= $summary['score'] !== null ? number_format($summary['score'], 2) . '<small>%</small>' : '—' ?></strong><p>Good ÷ (Good + Bad)</p></article>
<article class="kpi"><div>Total responses</div><strong><?= $summary['total'] ?></strong><p>Within selected filters</p></article>
<article class="kpi"><div>Good ratings</div><strong><?= $summary['good'] ?></strong><p>Positive feedback</p></article>
<article class="kpi"><div>Bad ratings</div><strong><?= $summary['bad'] ?></strong><p>Opportunities to improve</p></article>
</section>
<?php foreach (['trend' => 'Daily performance', 'teams' => 'Teams', 'channels' => 'Channels'] as $key => $heading): ?>
<section class="panel"><div class="panel-heading"><h2><?= $heading ?></h2></div><div class="table-scroll"><table><thead><tr><th><?= $key === 'trend' ? 'Date' : 'Name' ?></th><th>Responses</th><th>Good</th><th>Bad</th><th>CSAT</th></tr></thead><tbody><?php foreach ($groups[$key] as $row): ?><tr><td><?= esc($row['label']) ?></td><td><?= $row['total'] ?></td><td><?= $row['good'] ?></td><td><?= $row['bad'] ?></td><td><?= $row['score'] !== null ? number_format($row['score'], 2) . '%' : '—' ?></td></tr><?php endforeach ?><?php if (!$groups[$key]): ?><tr><td colspan="5">No responses match these filters.</td></tr><?php endif ?></tbody></table></div></section>
<?php endforeach ?>
<?= $this->endSection() ?>
