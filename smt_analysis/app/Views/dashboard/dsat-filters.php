<?php
$detailKeys = ['dsat_search', 'dsat_reason', 'dsat_comments', 'dsat_sort', 'dsat_page_size'];
$resetQuery = array_diff_key($dsatQuery, array_flip(array_merge($detailKeys, ['page', 'dsat_page'])));
$reasonOptions = array_values(array_filter(array_column($dsat['reasons'], 'reason'), static fn ($reason) => mb_strlen($reason) <= 255));
if ($filters['dsat_reason'] !== '' && !in_array($filters['dsat_reason'], $reasonOptions, true)) { $reasonOptions[] = $filters['dsat_reason']; }
?>
<form class="table-filter-form" id="dsat-table-filters" method="get" action="<?= esc($dsatBaseUrl) ?>#dsat-details">
    <?= view('dashboard/filter-fields', ['values' => $dsatQuery, 'omit' => array_merge($detailKeys, ['page', 'dsat_page'])]) ?>
    <p>Refine the feedback table. Summary cards and reason rankings use the report filters above.</p>
    <div class="table-filter-grid">
        <label class="table-search-field">Search feedback or case ID<input type="search" name="dsat_search" maxlength="255" value="<?= esc($filters['dsat_search']) ?>" placeholder="Case ID, survey ID, agent, reason, or comment…"></label>
        <label>Recorded reason<select name="dsat_reason"><option value="">All reasons</option><?php foreach ($reasonOptions as $reason): ?><option value="<?= esc($reason) ?>" <?= $filters['dsat_reason'] === $reason ? 'selected' : '' ?>><?= esc($reason) ?></option><?php endforeach ?></select></label>
        <label>Customer comments<select name="dsat_comments"><?php foreach (['all' => 'All feedback', 'with' => 'With a comment', 'without' => 'Without a comment'] as $key => $label): ?><option value="<?= $key ?>" <?= $filters['dsat_comments'] === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach ?></select></label>
        <label>Sort feedback<select name="dsat_sort"><?php foreach (['newest' => 'Newest first', 'oldest' => 'Oldest first', 'agent_asc' => 'Agent A–Z', 'reason_asc' => 'Reason A–Z'] as $key => $label): ?><option value="<?= $key ?>" <?= $filters['dsat_sort'] === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach ?></select></label>
        <label>Feedback per page<select name="dsat_page_size"><?php foreach ([10,25,50,100] as $size): ?><option value="<?= $size ?>" <?= (int) $filters['dsat_page_size'] === $size ? 'selected' : '' ?>><?= $size ?></option><?php endforeach ?></select></label>
    </div>
    <div class="table-filter-actions"><button class="button small-button" type="submit">Apply feedback filters</button><a class="text-link" href="<?= esc($dsatBaseUrl . '?' . http_build_query($resetQuery)) ?>#dsat-details">Reset feedback table</a></div>
</form>
