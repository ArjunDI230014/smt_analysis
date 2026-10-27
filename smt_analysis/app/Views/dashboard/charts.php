<div id="chart-error" class="notice error" hidden>Charts could not load. The score cards and tables remain available.</div>
<noscript><div class="notice">Enable JavaScript to display the charts. Scores and agent totals are available below.</div></noscript>
<section class="chart-grid dashboard-chart-grid">
    <article class="panel chart-card trend-card"><div class="panel-heading"><div><h2><?= $periodLabel ?> CSAT trend</h2><p><?= $period === 'weekly' ? 'Monday–Sunday weeks · label shows the Monday start' : ($period === 'monthly' ? 'Calendar months · selected dates only' : 'Customer satisfaction across the report period') ?></p></div><span class="tag">CSAT %</span></div><div class="chart"><canvas id="trend-chart" role="img" aria-label="<?= $periodLabel ?> CSAT percentages"></canvas></div><?php if (count($groups['trend']) === 1): ?><p class="chart-note">This view contains one <?= ['daily' => 'day', 'weekly' => 'week', 'monthly' => 'month'][$period] ?>. Select a wider date range to see a trend.</p><?php endif ?></article>
    <article class="panel chart-card mix-card"><div class="panel-heading"><div><h2>Rating mix</h2><p>Good and Bad ratings</p></div></div><div class="chart"><canvas id="mix-chart" role="img" aria-label="Good versus Bad ratings"></canvas></div></article>
    <article class="panel chart-card"><div class="panel-heading"><div><h2>CSAT by team</h2><p>Top 12 teams by response volume</p></div><span class="tag">CSAT %</span></div><div class="chart tall"><canvas id="team-chart" role="img" aria-label="CSAT by team"></canvas></div></article>
    <article class="panel chart-card"><div class="panel-heading"><div><h2>Feedback by channel</h2><p>Good and Bad response totals</p></div></div><div class="chart tall"><canvas id="channel-chart" role="img" aria-label="Good and Bad responses by submission channel"></canvas></div></article>
    <article class="panel chart-card"><div class="panel-heading"><div><h2>Top DSAT reasons</h2><p>Recorded reasons for Bad ratings</p></div></div><?php if (!empty($dsat['reasons'])): ?><div class="chart tall"><canvas id="reason-chart" role="img" aria-label="Top recorded DSAT reasons"></canvas></div><?php else: ?><div class="chart-empty">No DSAT reasons in this view.</div><?php endif ?></article>
</section>
<section class="dashboard-detail-grid" aria-label="Report details">
    <article class="panel dashboard-table-card period-card">
        <div class="panel-heading"><div><h2><?= $periodLabel ?> response details</h2><p>Recent periods from the selected report and filters</p></div><span class="tag"><?= number_format(count($groups['trend'])) ?> periods</span></div>
        <div class="table-scroll"><table><thead><tr><th scope="col"><?= $period === 'daily' ? 'Date' : ($period === 'weekly' ? 'Week of' : 'Month') ?></th><th scope="col" class="number">Responses</th><th scope="col" class="number">Good</th><th scope="col" class="number">Bad</th><th scope="col" class="number">CSAT</th></tr></thead><tbody>
        <?php foreach (array_slice($groups['trend'], -7) as $periodRow): ?><tr><th scope="row"><?= esc($periodRow['label']) ?></th><td class="number"><?= number_format($periodRow['total']) ?></td><td class="number good-value"><?= number_format($periodRow['good']) ?></td><td class="number bad-value"><?= number_format($periodRow['bad']) ?></td><td class="number score-value"><?= $periodRow['score'] !== null ? number_format($periodRow['score'], 1) . '%' : '—' ?></td></tr><?php endforeach ?>
        </tbody></table></div>
    </article>
    <article class="panel dashboard-table-card agent-summary-card">
        <div class="panel-heading"><div><h2>Agent performance</h2><p>Leading agents by response volume</p></div></div>
        <?php if ($agents): ?><div class="table-scroll"><table><thead><tr><th scope="col">Agent</th><th scope="col" class="number">Responses</th><th scope="col" class="number">CSAT</th></tr></thead><tbody>
        <?php foreach (array_slice($agents, 0, 5) as $agent): ?><tr><th scope="row"><?= esc($agent['agent_name'] ?: 'Agent not provided') ?><small><?= esc($agent['team'] ?: 'Team not provided') ?></small></th><td class="number"><?= number_format($agent['total']) ?></td><td class="number score-value"><?= $agent['score'] !== null ? number_format($agent['score'], 1) . '%' : '—' ?></td></tr><?php endforeach ?>
        </tbody></table></div><?php else: ?><div class="chart-empty">No agent data in this view.</div><?php endif ?>
    </article>
</section>
