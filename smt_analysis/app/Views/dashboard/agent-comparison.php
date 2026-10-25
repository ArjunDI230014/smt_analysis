<?php $comparisonAgents = array_slice($agents, 0, 12); ?>
<section class="agent-insights" aria-labelledby="agent-comparison-heading">
    <div class="section-heading"><h2 id="agent-comparison-heading">Agent performance at a glance</h2><span class="small muted">First <?= count($comparisonAgents) ?> agent/team combinations on this page · current table sort</span></div>
    <div id="agent-chart-error" class="notice error" hidden>Comparison charts could not load. Agent scores and reason counts remain available in the table.</div>
    <noscript><p class="notice">Enable JavaScript for agent comparison charts. All counts are available in the table below.</p></noscript>
    <div class="agent-insight-grid agent-comparison-grid">
        <article class="panel"><div class="panel-heading"><div><h2>CSAT by agent</h2><p>Compare scores alongside response counts in the tooltip</p></div><span class="tag">CSAT %</span></div><div class="chart" style="height: <?= max(240, count($comparisonAgents) * 40 + 55) ?>px"><canvas id="agent-comparison-score" role="img" aria-label="CSAT scores for agents on this page"></canvas></div></article>
        <article class="panel"><div class="panel-heading"><div><h2>Feedback by agent</h2><p>Good and Bad counts show the volume behind each score</p></div></div><div class="chart" style="height: <?= max(240, count($comparisonAgents) * 40 + 55) ?>px"><canvas id="agent-comparison-volume" role="img" aria-label="Good and Bad response counts for agents on this page"></canvas></div></article>
    </div>
    <script id="agent-performance-data" type="application/json"><?= json_encode(['agents' => array_map(static fn (array $agent): array => array_intersect_key($agent, array_flip(['agent_name', 'team', 'score', 'total', 'good', 'bad'])), $comparisonAgents)], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
</section>
