<?php
$dsat = $dsat ?? ['total' => 0, 'page' => 1, 'pages' => 1, 'page_size' => 50, 'missing_reason' => 0, 'reasons' => [], 'rows' => []];
$dsatPeriod = $filters['period'] ?? 'daily';
$dsatPeriodLabel = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'][$dsatPeriod] ?? 'Daily';
$dsatDateLabel = ['daily' => 'Date', 'weekly' => 'Week of (Monday)', 'monthly' => 'Month'][$dsatPeriod] ?? 'Date';
$showDsatAgents = $showDsatAgents ?? false;
$dsatQuery = $dsatQuery ?? $filters;
$dsatPageSize = $dsat['page_size'] ?? 50;
?>
<section id="dsat-feedback" class="dsat-section" aria-labelledby="dsat-heading" data-dsat-total="<?= (int) $dsat['total'] ?>">
    <div class="section-heading"><h2 id="dsat-heading"><?= !empty($personal) ? 'My DSAT feedback' : 'DSAT feedback' ?></h2><span class="small muted">Same report and filters as the performance overview</span></div>
    <div class="dsat-kpis" aria-label="DSAT indicators">
        <article class="kpi"><div>DSAT responses</div><strong><?= number_format($dsat['total']) ?></strong><p>Responses with a Bad rating</p></article>
        <article class="kpi"><div>DSAT rate</div><strong><?= $summary['dsat_rate'] !== null ? number_format($summary['dsat_rate'], 2) . '<small>%</small>' : '—' ?></strong><p>Bad ÷ (Good + Bad)</p></article>
        <article class="kpi"><div>Reason not provided</div><strong><?= number_format($dsat['missing_reason']) ?></strong><p>DSAT responses without a recorded reason</p></article>
    </div>
    <?php if ($dsat['total'] === 0): ?>
        <div class="panel dsat-empty"><h3>No DSAT responses in this view</h3><p><?= $summary['total'] > 0 ? 'The matching responses contain no Bad ratings.' : 'Try another date range or filter to see customer feedback.' ?></p></div>
    <?php else: ?>
        <div class="dsat-breakdown">
            <section class="panel" aria-labelledby="dsat-reasons-heading">
                <div class="panel-heading"><div><h2 id="dsat-reasons-heading">Top DSAT reasons</h2><p><?= count($dsat['reasons']) > 10 ? 'Top 10 by count' : 'Ranked by count' ?> · percentage of all <?= number_format($dsat['total']) ?> DSAT responses</p></div></div>
                <div class="table-scroll" role="region" aria-labelledby="dsat-reasons-heading" tabindex="0"><table class="dsat-reasons-table"><thead><tr><th scope="col">Rank</th><th scope="col">Recorded reason</th><th scope="col" class="number">DSATs</th><th scope="col" class="number">Share</th></tr></thead><tbody>
                    <?php foreach (array_slice($dsat['reasons'], 0, 10) as $rank => $reason): ?><tr><td><?= $rank + 1 ?></td><th scope="row" class="dsat-reason"><?= esc(trim((string) $reason['reason']) ?: 'Reason not provided') ?></th><td class="number"><?= number_format($reason['count']) ?></td><td class="number"><?= number_format($reason['share'], 1) ?>%</td></tr><?php endforeach ?>
                </tbody></table></div>
                <p class="dsat-note">One DSAT can have multiple reasons; shares may total more than 100%. <?= number_format($dsat['missing_reason']) ?> DSAT responses have no recorded reason. Reasons are taken from the source; missing reasons are never inferred.</p>
            </section>
            <section class="panel" aria-labelledby="dsat-trend-heading">
                <div class="panel-heading"><div><h2 id="dsat-trend-heading"><?= $dsatPeriodLabel ?> DSAT view</h2><p><?= $dsatPeriod === 'weekly' ? 'Monday–Sunday weeks' : ($dsatPeriod === 'monthly' ? 'Calendar months' : 'Calendar days') ?> · selected dates only</p></div></div>
                <div class="table-scroll dsat-period-scroll" role="region" aria-labelledby="dsat-trend-heading" tabindex="0"><table><thead><tr><th scope="col"><?= $dsatDateLabel ?></th><th scope="col" class="number">DSATs</th><th scope="col" class="number">DSAT rate</th></tr></thead><tbody>
                    <?php foreach ($groups['trend'] as $periodRow): ?><?php $periodRatings = (int) $periodRow['good'] + (int) $periodRow['bad']; ?><tr><th scope="row"><?= esc($periodRow['label']) ?></th><td class="number"><?= number_format($periodRow['bad']) ?></td><td class="number"><?= $periodRatings > 0 ? number_format($periodRow['bad'] / $periodRatings * 100, 2) . '%' : '—' ?></td></tr><?php endforeach ?>
                </tbody></table></div>
                <p class="dsat-note">Rate = Bad ÷ (Good + Bad) in each period. A partial week or month includes only the selected dates.</p>
            </section>
        </div>
        <section class="panel dsat-details" aria-labelledby="dsat-details-heading">
            <div class="panel-heading"><div><h2 id="dsat-details-heading">Why the customer gave a DSAT</h2><p>Recorded reasons and the original customer feedback for each Bad rating</p></div><span class="tag"><?= number_format($dsat['total']) ?> DSATs</span></div>
            <div class="table-scroll report-detail-scroll" role="region" aria-labelledby="dsat-details-heading" tabindex="0"><table class="dsat-detail-table"><thead><tr><th scope="col">Date</th><?php if ($showDsatAgents): ?><th scope="col">Agent</th><?php endif ?><th scope="col">CSAT / case ID</th><th scope="col">Recorded reason</th><th scope="col">Customer comment</th></tr></thead><tbody>
                <?php foreach ($dsat['rows'] as $feedback): ?><tr><td class="dsat-date"><?= esc($feedback['response_date']) ?><small><?= esc($feedback['channel'] ?: 'Channel not provided') ?></small></td><?php if ($showDsatAgents): ?><td><?= esc($feedback['agent_name']) ?><small><?= esc($feedback['team']) ?></small></td><?php endif ?><td class="dsat-identifiers"><?= esc($feedback['csat_id'] ?: 'No CSAT ID') ?><?php if ($feedback['case_id'] !== ''): ?><small>Case: <?= esc($feedback['case_id']) ?></small><?php endif ?></td><td class="dsat-reason"><?= esc(trim((string) $feedback['reason']) ?: 'Reason not provided') ?></td><td class="dsat-comment"><?= esc(trim((string) $feedback['comment']) ?: 'No customer comment') ?></td></tr><?php endforeach ?>
            </tbody></table></div>
            <nav class="pagination" aria-label="DSAT feedback pages"><span><?= number_format(($dsat['page'] - 1) * $dsatPageSize + 1) ?>–<?= number_format(min(($dsat['page'] - 1) * $dsatPageSize + count($dsat['rows']), $dsat['total'])) ?> of <?= number_format($dsat['total']) ?> <span class="pagination-page">· Page <?= (int) $dsat['page'] ?> of <?= (int) $dsat['pages'] ?></span></span><div><?php if ($dsat['page'] > 1): ?><a class="button secondary small-button" rel="prev" href="<?= esc($dsatBaseUrl . '?' . http_build_query(array_merge($dsatQuery, ['dsat_page' => $dsat['page'] - 1]))) ?>#dsat-feedback">← Previous</a><?php endif ?><?php if ($dsat['page'] < $dsat['pages']): ?><a class="button secondary small-button" rel="next" href="<?= esc($dsatBaseUrl . '?' . http_build_query(array_merge($dsatQuery, ['dsat_page' => $dsat['page'] + 1]))) ?>#dsat-feedback">Next →</a><?php endif ?></div></nav>
        </section>
    <?php endif ?>
</section>
