<?php

namespace Tests\Database;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

final class AgentInsightsViewTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh = true;
    private int $adminId;
    private int $viewerId;
    private array $reports = [];
    private array $identities = [];
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['admin', 'user'] as $role) {
            $this->db->table('users')->insert(['name' => ucfirst($role), 'email' => $role . '@example.test',
                'password_hash' => 'unused', 'role' => $role, 'active' => 1, 'created_at' => '2026-10-25 00:00:00']);
            if ($role === 'admin') { $this->adminId = (int) $this->db->insertID(); }
            else { $this->viewerId = (int) $this->db->insertID(); }
        }
        foreach (['shopee-food', 'marketplace'] as $module) {
            $this->db->table('imports')->insert(['module' => $module, 'filename' => $module . '.xlsx',
                'file_hash' => hash('sha256', $module), 'uploaded_by' => $this->adminId, 'row_count' => 11,
                'date_from' => '2026-10-01', 'date_to' => '2026-10-24', 'created_at' => '2026-10-25 00:00:00']);
            $this->reports[$module] = (int) $this->db->insertID();
            foreach (['A Speed', 'A Speed', 'A Speed', 'B Accuracy', 'B Accuracy', 'C Policy', 'D Resolution', 'E Other'] as $reason) {
                $this->identities[$module] = $this->row($module, $reason);
            }
            $this->row($module, '', ['good' => 1, 'bad' => 0]);
            $this->row($module, 'Outside date', ['response_date' => '2026-10-01']);
            $this->row($module, 'Private reason', ['agent_name' => 'Private Other', 'agent_email' => 'other@example.test']);
        }
    }

    public function testPersonalStatisticsShowFourRankedReasonsAndOnlyOwnedChartData(): void
    {
        foreach (['shopee-food' => '/dashboard', 'marketplace' => '/marketplace/dashboard'] as $module => $path) {
            $result = $this->withSession(['user_id' => $this->viewerId])->get($path);
            $result->assertOK();
            $body = $result->getBody();
            $xpath = $this->document($body);
            self::assertStringContainsString('My performance insights', $body);
            self::assertStringNotContainsString('Private Other', $body);
            self::assertStringNotContainsString('Private reason', $body);
            $reasons = $xpath->query('//ol[@class="agent-reason-ranking"]/li');
            self::assertCount(4, $reasons);
            foreach (['A Speed' => 3, 'B Accuracy' => 2, 'C Policy' => 1, 'D Resolution' => 1] as $reason => $count) {
                $row = $reasons->item(array_search($reason, ['A Speed', 'B Accuracy', 'C Policy', 'D Resolution'], true));
                self::assertStringContainsString($reason, $row->textContent);
                self::assertSame((string) $count, trim($xpath->query('.//strong/text()', $row)->item(0)->textContent));
            }
            foreach (['agent-csat-trend', 'agent-feedback-mix', 'agent-response-trend', 'agent-channel-chart'] as $id) {
                self::assertCount(1, $xpath->query('//canvas[@id="' . $id . '"]'));
            }
            $data = $this->chartData($xpath);
            self::assertSame(10, $data['summary']['total']);
            self::assertSame(9, $data['summary']['bad']);
            self::assertSame(1, $data['summary']['good']);
            self::assertSame('daily', $data['period']);
            self::assertSame(10, array_sum(array_column($data['groups']['trend'], 'total')));
            $link = $xpath->query('//ol[@class="agent-reason-ranking"]//a')->item(0);
            parse_str((string) parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
            self::assertSame('dsat', $query['tab']);
            self::assertSame((string) $this->reports[$module], $query['import']);
            self::assertSame('A Speed', $query['dsat_reason']);
            self::assertSame(parse_url(site_url(ltrim($path, '/')), PHP_URL_PATH), parse_url($link->getAttribute('href'), PHP_URL_PATH));
        }
    }

    public function testIndividualAgentChartsAndReasonLinksKeepReportFiltersAndModule(): void
    {
        foreach (['shopee-food' => '', 'marketplace' => '/marketplace'] as $module => $prefix) {
            $path = $prefix . '/admin/agents/' . $this->identities[$module];
            $filters = ['from' => '2026-10-24', 'to' => '2026-10-24', 'team' => 'Support', 'period' => 'weekly'];
            $result = $this->withSession(['user_id' => $this->adminId])->get($path . '?' . http_build_query($filters));
            $result->assertOK();
            $body = $result->getBody();
            $xpath = $this->document($body);
            $data = $this->chartData($xpath);
            self::assertSame(9, $data['summary']['total']);
            self::assertSame(8, $data['summary']['bad']);
            self::assertSame('weekly', $data['period']);
            self::assertCount(4, $xpath->query('//ol[@class="agent-reason-ranking"]/li'));
            self::assertStringContainsString('assets/agent-performance.js', $body);
            self::assertStringContainsString('assets/vendor/chart.umd.min.js', $body);
            self::assertStringNotContainsString('Outside date', $body);
            self::assertStringNotContainsString('Private reason', $body);
            foreach ($xpath->query('//ol[@class="agent-reason-ranking"]//a') as $link) {
                self::assertSame(parse_url(site_url(ltrim($path, '/')), PHP_URL_PATH), parse_url($link->getAttribute('href'), PHP_URL_PATH));
                parse_str((string) parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
                foreach ($filters as $key => $value) { self::assertSame($value, $query[$key]); }
                self::assertSame('dsat-details', parse_url($link->getAttribute('href'), PHP_URL_FRAGMENT));
            }
        }
    }

    public function testAgentTableShowsFourReasonsPerGroupAndMatchingComparisonCounts(): void
    {
        foreach (['shopee-food' => '/dashboard', 'marketplace' => '/marketplace/dashboard'] as $module => $path) {
            $result = $this->withSession(['user_id' => $this->adminId])->get($path . '?tab=agents&from=2026-10-24&to=2026-10-24&agent_sort=name_asc');
            $result->assertOK();
            $xpath = $this->document($result->getBody());
            $rows = $xpath->query('//table[@class="agent-performance-table"]/tbody/tr');
            self::assertCount(2, $rows);
            self::assertCount(4, $xpath->query('.//ol[@class="agent-inline-reasons"]/li', $rows->item(0)));
            self::assertCount(1, $xpath->query('.//ol[@class="agent-inline-reasons"]/li', $rows->item(1)));
            self::assertStringContainsString('A Speed', $rows->item(0)->textContent);
            self::assertStringNotContainsString('E Other', $rows->item(0)->textContent);
            self::assertStringContainsString('Private reason', $rows->item(1)->textContent);
            $data = $this->chartData($xpath);
            self::assertSame(['Alice', 'Private Other'], array_column($data['agents'], 'agent_name'));
            self::assertSame([9, 1], array_map('intval', array_column($data['agents'], 'total')));
            self::assertSame([8, 1], array_map('intval', array_column($data['agents'], 'bad')));
            self::assertCount(1, $xpath->query('//canvas[@id="agent-comparison-score"]'));
            self::assertCount(1, $xpath->query('//canvas[@id="agent-comparison-volume"]'));
        }
    }

    public function testReasonLabelsCannotCreateMarkupInEitherAgentView(): void
    {
        $reason = '<img src=x onerror=alert(1)>';
        for ($i = 0; $i < 4; $i++) { $this->row('shopee-food', $reason); }
        foreach ([$this->viewerId => '/dashboard', $this->adminId => '/dashboard?tab=agents'] as $user => $path) {
            $result = $this->withSession(['user_id' => $user])->get($path);
            $result->assertOK();
            self::assertStringNotContainsString($reason, $result->getBody());
            self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $result->getBody());
            self::assertCount(0, $this->document($result->getBody())->query('//img[@onerror]'));
        }
    }

    public function testNoDsatAndEmptyFiltersDoNotInventReasonsOrScores(): void
    {
        $this->db->table('responses')->where('agent_email', 'user@example.test')->update(['good' => 1, 'bad' => 0]);
        $result = $this->withSession(['user_id' => $this->viewerId])->get('/dashboard');
        $result->assertOK();
        $xpath = $this->document($result->getBody());
        self::assertCount(0, $xpath->query('//ol[@class="agent-reason-ranking"]/li'));
        self::assertStringContainsString('No DSAT responses', $result->getBody());
        self::assertSame(100, $this->chartData($xpath)['summary']['score']);
        self::assertStringNotContainsString('Private reason', $result->getBody());
        $empty = $this->withSession(['user_id' => $this->viewerId])->get('/dashboard?from=2099-01-01');
        $empty->assertOK();
        self::assertStringContainsString('No responses match these filters', $empty->getBody());
        self::assertCount(0, $this->document($empty->getBody())->query('//canvas'));
    }

    private function chartData(\DOMXPath $xpath): array
    {
        $scripts = $xpath->query('//script[@id="agent-performance-data"]');
        self::assertCount(1, $scripts);
        return json_decode($scripts->item(0)->textContent, true, 512, JSON_THROW_ON_ERROR);
    }

    private function document(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        @$document->loadHTML($html);
        return new \DOMXPath($document);
    }

    private function row(string $module, string $reason, array $changes = []): int
    {
        $id = ++$this->sequence;
        $this->db->table('responses')->insert(array_replace([
            'import_id' => $this->reports[$module], 'source_row' => $id + 1, 'response_date' => '2026-10-24',
            'team' => 'Support', 'agent_name' => 'Alice', 'agent_email' => 'user@example.test',
            'channel' => 'Chat', 'category' => '', 'csat_id' => 'survey-' . $id, 'good' => 0, 'bad' => 1,
            'is_excluded' => 0, 'raw_json' => json_encode(['dsat_reason' => $reason], JSON_THROW_ON_ERROR),
        ], $changes));
        return (int) $this->db->insertID();
    }
}
