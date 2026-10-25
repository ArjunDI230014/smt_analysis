<?= $this->extend('layout') ?>
<?= $this->section('content') ?>
<div class="page-heading"><div><p class="eyebrow">SUMMIT ANALYTICS</p><h1>Choose your module.</h1><p class="muted">Select Shopee Food or Marketplace to view its statistics.</p></div><span class="sheet-badge">Admin workspace</span></div>
<div class="module-grid">
<?php foreach ($modules as $module): ?>
<?php $key = $module['key']; ?>
<section class="panel module-card" aria-labelledby="<?= esc($key) ?>-heading">
    <div class="module-card-heading"><span class="module-icon" aria-hidden="true"><?php if ($key === \App\Libraries\Modules::SHOPEE_FOOD): ?><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 3v5a3 3 0 0 0 6 0V3M7 3v18M20 21V3c-4 2-5 6-5 10h5"/></svg><?php else: ?><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10h18l-2-7H5l-2 7ZM5 10v11h14V10M9 21v-7h6v7M3 10a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/></svg><?php endif ?></span><h2 id="<?= esc($key) ?>-heading"><?= esc($module['label']) ?></h2></div>
    <p class="module-description"><?= $key === \App\Libraries\Modules::SHOPEE_FOOD ? 'Track Shopee Food customer feedback, CSAT scores, and DSAT details.' : 'Track Marketplace customer feedback in its own reporting workspace.' ?></p>
    <a class="button module-open" href="<?= \App\Libraries\Modules::url('dashboard', $key) ?>">Open <?= esc($module['label']) ?> <span aria-hidden="true">→</span></a>
</section>
<?php endforeach ?>
</div>
<?= $this->endSection() ?>
