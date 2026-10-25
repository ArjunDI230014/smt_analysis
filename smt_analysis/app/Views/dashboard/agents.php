<?php
$agentPageSize = $agentPageSize ?? 25;
$agentTotalCount = $agentTotalCount ?? $agentCount;
$sortLabels = ['responses_desc' => 'Most responses', 'responses_asc' => 'Fewest responses', 'csat_asc' => 'Lowest CSAT', 'csat_desc' => 'Highest CSAT', 'dsat_desc' => 'Most DSATs', 'dsat_asc' => 'Fewest DSATs', 'name_asc' => 'Agent A–Z', 'name_desc' => 'Agent Z–A'];
$tableKeys = ['agent_sort', 'min_responses', 'max_csat', 'agent_page_size'];
$baseQuery = array_merge($filters, ['import' => $import['id'], 'tab' => 'agents']);
$resetQuery = array_diff_key($baseQuery, array_flip(array_merge($tableKeys, ['page', 'dsat_page'])));
$sortUrl = static fn (string $sort): string => \App\Libraries\Modules::url('dashboard') . '?' . http_build_query(array_merge($baseQuery, ['agent_sort' => $sort, 'page' => 1])) . '#agent-performance';
$sort = $filters['agent_sort'];
?>
<section class="panel agent-panel" id="agent-performance">
<div class="panel-heading"><div><h2 id="agent-performance-heading">Agent performance</h2><p><?= number_format($agentCount) ?> matching of <?= number_format($agentTotalCount) ?> agent/team combinations<?= !$personal ? ' · Select a name for details' : '' ?></p></div><a class="text-link" href="#dashboard-filters">Report filters ↑</a></div>
<form class="table-filter-form" id="agent-table-filters" method="get" action="<?= \App\Libraries\Modules::url('dashboard') ?>#agent-performance">
    <?= view('dashboard/filter-fields', ['values' => $baseQuery, 'omit' => array_merge($tableKeys, ['page', 'dsat_page'])]) ?>
    <p>Refine this agent table using the report and date filters above.</p>
    <div class="table-filter-grid">
        <label>Sort agents<select name="agent_sort"><?php foreach ($sortLabels as $key => $label): ?><option value="<?= $key ?>" <?= $sort === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach ?></select></label>
        <label>Minimum responses<input type="number" name="min_responses" min="0" max="100000" step="1" value="<?= (int) $filters['min_responses'] ?>"></label>
        <label>Maximum CSAT (%)<input type="number" name="max_csat" min="0" max="100" step="0.01" placeholder="Any score" value="<?= esc((string) $filters['max_csat']) ?>"></label>
        <label>Agents per page<select name="agent_page_size"><?php foreach ([10,25,50,100] as $size): ?><option value="<?= $size ?>" <?= $agentPageSize === $size ? 'selected' : '' ?>><?= $size ?></option><?php endforeach ?></select></label>
    </div>
    <div class="table-filter-actions"><button class="button small-button" type="submit">Apply table filters</button><a class="text-link" href="<?= esc(\App\Libraries\Modules::url('dashboard') . '?' . http_build_query($resetQuery)) ?>#agent-performance">Reset agent table</a></div>
</form>
<p class="table-filter-status"><?= esc($sortLabels[$sort]) ?><?= (int) $filters['min_responses'] > 0 ? ' · ' . number_format((int) $filters['min_responses']) . '+ responses' : '' ?><?= $filters['max_csat'] !== '' ? ' · CSAT ≤' . esc((string) $filters['max_csat']) . '%' : '' ?> · Table filters apply to this list.</p>
<?php if ($agentCount === 0): ?>
<div class="table-empty"><h3>No agents match these table filters</h3><p>Lower the minimum responses or broaden the maximum CSAT score.</p></div>
<?php else: ?>
<?php if (!$personal): ?><div class="agent-comparison-wrap"><?= view('dashboard/agent-comparison', compact('agents')) ?></div><?php endif ?>
<div class="table-scroll report-detail-scroll" role="region" aria-labelledby="agent-performance-heading" tabindex="0"><table class="agent-performance-table"><thead><tr>
<th scope="col" <?= str_starts_with($sort, 'name_') ? 'aria-sort="' . ($sort === 'name_asc' ? 'ascending' : 'descending') . '"' : '' ?>><a class="sort-link" href="<?= esc($sortUrl($sort === 'name_asc' ? 'name_desc' : 'name_asc')) ?>">Agent <span aria-hidden="true"><?= $sort === 'name_asc' ? '↑' : ($sort === 'name_desc' ? '↓' : '↕') ?></span></a></th>
<th scope="col">Team</th>
<th scope="col" class="number" <?= str_starts_with($sort, 'responses_') ? 'aria-sort="' . ($sort === 'responses_asc' ? 'ascending' : 'descending') . '"' : '' ?>><a class="sort-link" href="<?= esc($sortUrl($sort === 'responses_desc' ? 'responses_asc' : 'responses_desc')) ?>">Responses <span aria-hidden="true"><?= $sort === 'responses_asc' ? '↑' : ($sort === 'responses_desc' ? '↓' : '↕') ?></span></a></th>
<th scope="col" class="number">Good</th>
<th scope="col" class="number" <?= str_starts_with($sort, 'dsat_') ? 'aria-sort="' . ($sort === 'dsat_asc' ? 'ascending' : 'descending') . '"' : '' ?>><a class="sort-link" href="<?= esc($sortUrl($sort === 'dsat_desc' ? 'dsat_asc' : 'dsat_desc')) ?>">Bad / DSAT <span aria-hidden="true"><?= $sort === 'dsat_asc' ? '↑' : ($sort === 'dsat_desc' ? '↓' : '↕') ?></span></a></th>
<th scope="col" class="number" <?= str_starts_with($sort, 'csat_') ? 'aria-sort="' . ($sort === 'csat_asc' ? 'ascending' : 'descending') . '"' : '' ?>><a class="sort-link" href="<?= esc($sortUrl($sort === 'csat_asc' ? 'csat_desc' : 'csat_asc')) ?>">CSAT <span aria-hidden="true"><?= $sort === 'csat_asc' ? '↑' : ($sort === 'csat_desc' ? '↓' : '↕') ?></span></a></th>
<th scope="col" class="agent-reasons-cell">Top 4 DSAT reasons <span class="small muted">· count</span></th>
</tr></thead><tbody>
<?php foreach ($agents as $agent): ?><tr><td class="agent-name"><?php if (!$personal): ?><a class="text-link" href="<?= esc(\App\Libraries\Modules::url('admin/agents/' . $agent['response_id']) . '?' . http_build_query($filters)) ?>"><?= esc($agent['agent_name']) ?></a><?php else: ?><?= esc($agent['agent_name']) ?><?php endif ?></td><td><span class="tag"><?= esc($agent['team']) ?></span></td><td class="number"><?= number_format($agent['total']) ?></td><td class="number"><?= number_format($agent['good']) ?></td><td class="number"><?= number_format($agent['bad']) ?></td><td class="number score agent-score-cell"><?= $agent['score'] !== null ? number_format($agent['score'], 2) . '%' : '—' ?><?php if ($agent['score'] !== null): ?><div class="agent-score-meter" aria-hidden="true"><span style="width: <?= number_format(min(100, max(0, $agent['score'])), 2, '.', '') ?>%"></span></div><?php endif ?></td><td class="agent-reasons-cell"><?php if (!empty($agent['top_dsat_reasons'])): ?><ol class="agent-inline-reasons"><?php foreach ($agent['top_dsat_reasons'] as $reason): ?><li><span class="inline-reason-label"><?= esc($reason['reason']) ?></span><strong aria-label="<?= (int) $reason['count'] ?> DSAT responses"><?= number_format($reason['count']) ?></strong></li><?php endforeach ?></ol><?php else: ?><span class="small muted">No DSAT responses</span><?php endif ?></td></tr><?php endforeach ?>
</tbody></table></div>
<p class="agent-insight-note agent-table-note">Reason counts are per agent/team combination within the report filters. A DSAT can include multiple reasons. Missing reasons appear as “Reason not provided”.</p>
<?php endif ?>
<nav class="pagination" aria-label="Agent performance pages"><span><?= number_format($agentCount > 0 ? ($page - 1) * $agentPageSize + 1 : 0) ?>–<?= number_format(min(($page - 1) * $agentPageSize + count($agents), $agentCount)) ?> of <?= number_format($agentCount) ?> <span class="pagination-page">· Page <?= $page ?> of <?= $pages ?></span></span><div><?php if ($page > 1): ?><a class="button secondary small-button" rel="prev" href="<?= esc(\App\Libraries\Modules::url('dashboard') . '?' . http_build_query(array_merge($baseQuery, ['page' => $page - 1]))) ?>#agent-performance">← Previous</a><?php endif ?><?php if ($page < $pages): ?><a class="button secondary small-button" rel="next" href="<?= esc(\App\Libraries\Modules::url('dashboard') . '?' . http_build_query(array_merge($baseQuery, ['page' => $page + 1]))) ?>#agent-performance">Next →</a><?php endif ?></div></nav>
</section>
