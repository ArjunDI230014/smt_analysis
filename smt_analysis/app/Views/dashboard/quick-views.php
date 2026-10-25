<?php
$presets = [
    ['label' => 'Lowest CSAT', 'changes' => ['tab' => 'agents', 'agent_sort' => 'csat_asc', 'min_responses' => 0, 'max_csat' => '']],
    ['label' => 'CSAT ≤80% · 10+ responses', 'changes' => ['tab' => 'agents', 'agent_sort' => 'csat_asc', 'min_responses' => 10, 'max_csat' => '80']],
    ['label' => 'Most DSATs', 'changes' => ['tab' => 'agents', 'agent_sort' => 'dsat_desc', 'min_responses' => 0, 'max_csat' => '']],
    ['label' => 'Latest feedback', 'changes' => ['tab' => 'dsat', 'dsat_sort' => 'newest', 'dsat_search' => '', 'dsat_reason' => '', 'dsat_comments' => 'all']],
];
?>
<nav class="executive-quick-views" aria-label="Quick report views"><span>Quick views</span>
<?php foreach ($presets as $preset): ?>
<a href="<?= esc(\App\Libraries\Modules::url('dashboard') . '?' . http_build_query(array_merge($filters, ['import' => $import['id'], 'page' => 1, 'dsat_page' => 1], $preset['changes']))) ?>#report-sections"><?= esc($preset['label']) ?></a>
<?php endforeach ?>
</nav>
