<?php
$insightPeriod = $filters['period'] ?? 'daily';
$insightPeriodLabel = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'][$insightPeriod] ?? 'Daily';
$topReasons = array_slice($dsat['reasons'], 0, 4);
$reasonQuery = $reasonQuery ?? $filters;
$latestPeriod = $groups['trend'] ? $groups['trend'][array_key_last($groups['trend'])] : null;
$previousPeriod = count($groups['trend']) > 1 ? $groups['trend'][count($groups['trend']) - 2] : null;
$scoreChange = $latestPeriod && $previousPeriod && $latestPeriod['score'] !== null && $previousPeriod['score'] !== null
    ? $latestPeriod['score'] - $previousPeriod['score'] : null;
?>
<section class="agent-insights" aria-labelledby="agent-insights-heading">
    <div class="section-heading"><h2 id="agent-insights-heading"><?= !empty($personal) ? 'My performance insights' : 'Performance insights' ?></h2><span class="small muted">Selected report and filters · <?= esc(strtolower($insightPeriodLabel)) ?> view</span></div>
    <div id="agent-chart-error" class="notice error" hidden>Performance charts could not load. Reason counts and performance tables remain available.</div>
    <noscript><p class="notice">Enable JavaScript to explore the performance charts. Reason counts and score tables are available below.</p></noscript>
    <div class="agent-insight-grid">
        <article class="panel agent-top-reasons" aria-labelledby="agent-top-reasons-heading">
            <div class="panel-heading"><div><p class="insight-eyebrow">CUSTOMER FEEDBACK</p><h2 id="agent-top-reasons-heading">Top 4 DSAT reasons</h2><p>Most frequent reasons across <?= number_format($dsat['total']) ?> DSAT responses</p></div><span class="tag">By count</span></div>
            <?php if ($topReasons): ?>
            <ol class="agent-reason-ranking">
                <?php foreach ($topReasons as $rank => $reason): ?>
                <li>
                    <span class="reason-rank" aria-hidden="true"><?= $rank + 1 ?></span>
                    <div class="reason-content"><div class="reason-label-row">
                        <?php if (mb_strlen($reason['reason']) <= 255): ?><a href="<?= esc($reasonBaseUrl . '?' . http_build_query(array_merge($reasonQuery, ['dsat_reason' => $reason['reason'], 'dsat_page' => 1, 'dsat_search' => '', 'dsat_comments' => 'all']))) ?>#dsat-details"><?= esc($reason['reason']) ?></a><?php else: ?><span><?= esc($reason['reason']) ?></span><?php endif ?>
                        <strong><?= number_format($reason['count']) ?><small>DSAT<?= (int) $reason['count'] === 1 ? '' : 's' ?></small></strong>
                    </div><div class="reason-bar" aria-hidden="true"><span style="width: <?= number_format(min(100, max(0, $reason['share'])), 2, '.', '') ?>%"></span></div><span class="reason-share"><?= number_format($reason['share'], 1) ?>% of DSAT responses</span></div>
                </li>
                <?php endforeach ?>
            </ol>
            <p class="agent-insight-note"><?= count($topReasons) < 4 ? 'Only ' . count($topReasons) . ' reason ' . (count($topReasons) === 1 ? 'category is' : 'categories are') . ' recorded in this view. ' : '' ?>One response can include multiple reasons. <?= number_format($dsat['missing_reason']) ?> DSAT responses have no recorded reason.</p>
            <?php else: ?>
            <div class="agent-insight-empty"><span class="insight-empty-symbol" aria-hidden="true">✓</span><h3>No DSAT responses</h3><p><?= $summary['total'] > 0 ? 'There are no Bad ratings in the selected view.' : 'No responses match these filters.' ?></p></div>
            <?php endif ?>
        </article>
        <article class="panel agent-mix-panel"><div class="panel-heading"><div><h2>Feedback mix</h2><p>Your Good and Bad ratings at a glance</p></div></div>
            <?php if ((int) $summary['good'] + (int) $summary['bad'] > 0): ?>
            <div class="agent-mix-visual"><div class="chart"><canvas id="agent-feedback-mix" role="img" aria-label="Good and Bad rating distribution"></canvas></div><div class="agent-mix-center" aria-hidden="true"><strong><?= $summary['score'] !== null ? number_format($summary['score'], 2) . '%' : '—' ?></strong><span>CSAT score</span></div></div>
            <?php endif ?>
            <div class="agent-mix-totals"><span><i class="legend-dot green" aria-hidden="true"></i>Good <strong><?= number_format($summary['good']) ?></strong></span><span><i class="legend-dot orange" aria-hidden="true"></i>Bad <strong><?= number_format($summary['bad']) ?></strong></span></div>
            <p class="agent-insight-note">DSAT rate: <strong><?= $summary['dsat_rate'] !== null ? number_format($summary['dsat_rate'], 2) . '%' : '—' ?></strong> · Bad ÷ (Good + Bad)</p>
        </article>
        <article class="panel"><div class="panel-heading"><div><h2><?= $insightPeriodLabel ?> CSAT trend</h2><p><?= $insightPeriod === 'weekly' ? 'Weeks beginning Monday' : ($insightPeriod === 'monthly' ? 'Calendar months' : 'Calendar days') ?> · selected dates only</p></div><span class="tag">CSAT %</span></div>
            <?php if ($groups['trend']): ?><div class="chart"><canvas id="agent-csat-trend" role="img" aria-label="<?= $insightPeriodLabel ?> agent CSAT trend"></canvas></div><?php endif ?>
            <p class="agent-insight-note"><?php if ($scoreChange !== null): ?><strong><?= $scoreChange > 0 ? '+' : '' ?><?= number_format($scoreChange, 2) ?> percentage points</strong> in <?= esc($latestPeriod['label']) ?> compared with <?= esc($previousPeriod['label']) ?>.<?php elseif (count($groups['trend']) === 1): ?>One period is available. Widen the date range to see how your score changes.<?php else: ?>CSAT = Good ÷ (Good + Bad). No score is shown for periods without ratings.<?php endif ?></p>
        </article>
        <article class="panel"><div class="panel-heading"><div><h2><?= $insightPeriodLabel ?> response volume</h2><p>See the Good and Bad counts behind each score</p></div></div>
            <?php if ($groups['trend']): ?><div class="chart"><canvas id="agent-response-trend" role="img" aria-label="<?= $insightPeriodLabel ?> Good and Bad response counts"></canvas></div><?php endif ?>
            <p class="agent-insight-note"><?= number_format($summary['good']) ?> Good · <?= number_format($summary['bad']) ?> Bad across the selected period.</p>
        </article>
        <article class="panel agent-channel-panel"><div class="panel-heading"><div><h2>Performance by channel</h2><p>Good and Bad response counts by CSAT submission channel</p></div></div>
            <?php if ($groups['channels']): ?><div class="chart"><canvas id="agent-channel-chart" role="img" aria-label="Agent feedback by submission channel"></canvas></div><?php else: ?><p class="agent-insight-note">No channel data in this view.</p><?php endif ?>
        </article>
    </div>
    <script id="agent-performance-data" type="application/json"><?= json_encode(['summary' => $summary, 'groups' => $groups, 'period' => $insightPeriod], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
</section>
