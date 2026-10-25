<?php

namespace Tests\Database;

use App\Libraries\CsatReport;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use DomainException;

final class ExecutiveReportTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $refresh = true;
    private int $importId;
    private int $otherImportId;
    private int $nextId = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->table('users')->insert(['name' => 'Executive Admin', 'email' => 'admin@example.test',
            'password_hash' => 'unused', 'role' => 'admin', 'active' => 1, 'created_at' => '2026-10-25 00:00:00']);
        $admin = (int) $this->db->insertID();
        foreach (['shopee-food', 'marketplace'] as $module) {
            $this->db->table('imports')->insert(['module' => $module, 'filename' => $module . '.xlsx',
                'file_hash' => hash('sha256', $module), 'uploaded_by' => $admin, 'row_count' => 0,
                'date_from' => '2026-10-01', 'date_to' => '2026-10-31', 'created_at' => '2026-10-25 00:00:00']);
            if ($module === 'shopee-food') { $this->importId = (int) $this->db->insertID(); }
            else { $this->otherImportId = (int) $this->db->insertID(); }
        }
    }

    private function row(array $changes = [], array $raw = []): void
    {
        $id = $this->nextId++;
        $this->db->table('responses')->insert(array_replace([
            'import_id' => $this->importId, 'source_row' => $id + 1, 'response_date' => '2026-10-24',
            'agent_name' => 'Alice', 'agent_email' => 'alice@example.test', 'team' => 'Support', 'channel' => 'Chat',
            'category' => '', 'csat_id' => 'survey-' . $id, 'good' => 0, 'bad' => 1, 'is_excluded' => 0,
            'handling_seconds' => null, 'raw_json' => json_encode($raw, JSON_THROW_ON_ERROR),
        ], $changes));
    }

    private function report(array $input = [], int $page = 1, ?string $owner = null): array
    {
        $service = new CsatReport($this->db, $owner);
        return $service->build($this->importId, $service->filters($input), $page);
    }

    public function testNewFilterDefaultsAndValidation(): void
    {
        $service = new CsatReport($this->db);
        $filters = $service->filters([]);
        foreach (['agent_sort' => 'responses_desc', 'min_responses' => 0, 'max_csat' => '', 'agent_page_size' => 25,
            'dsat_search' => '', 'dsat_reason' => '', 'dsat_comments' => 'all', 'dsat_sort' => 'newest', 'dsat_page_size' => 50] as $field => $value) {
            self::assertSame($value, $filters[$field]);
        }
        self::assertSame('80.50', $service->filters(['max_csat' => '80.50'])['max_csat']);
        foreach ([['agent_sort' => 'total; DROP TABLE responses'], ['agent_sort' => ['name_asc']], ['min_responses' => -1],
            ['min_responses' => 100001], ['min_responses' => '1.5'], ['max_csat' => 101], ['max_csat' => '-0.1'],
            ['max_csat' => 'NaN'], ['max_csat' => []], ['agent_page_size' => 20], ['dsat_page_size' => true],
            ['dsat_search' => str_repeat('x', 256)], ['dsat_reason' => []], ['dsat_comments' => 'some'], ['dsat_sort' => 'random']] as $invalid) {
            try {
                $service->filters($invalid);
                self::fail('An invalid table filter must be rejected.');
            } catch (DomainException $e) { self::assertNotSame('', $e->getMessage()); }
        }
    }

    public function testAgentThresholdsApplyBeforePaginationAndKeepReportTotals(): void
    {
        for ($i = 1; $i <= 24; $i++) {
            for ($j = 0; $j < 3; $j++) {
                $good = $j < $i % 4;
                $this->row(['agent_name' => sprintf('Agent %02d', $i), 'good' => $good ? 1 : 0, 'bad' => $good ? 0 : 1]);
            }
        }
        $this->row(['agent_name' => 'Low sample']);
        $this->row(['agent_name' => 'Unrated', 'good' => 0, 'bad' => 0]);
        $all = $this->report();
        $filtered = $this->report(['min_responses' => '3', 'max_csat' => '40', 'agent_sort' => 'csat_asc', 'agent_page_size' => '10'], 2);
        self::assertSame(26, $filtered['agentTotalCount']);
        self::assertSame(12, $filtered['agentCount']);
        self::assertSame(10, $filtered['agentPageSize']);
        self::assertSame(2, $filtered['pages']);
        self::assertSame(2, $filtered['page']);
        self::assertCount(2, $filtered['agents']);
        self::assertSame(['Agent 17', 'Agent 21'], array_column($filtered['agents'], 'agent_name'));
        foreach ($filtered['agents'] as $agent) {
            self::assertSame(3, (int) $agent['total']);
            self::assertLessThanOrEqual(40, $agent['score']);
        }
        foreach (['summary', 'groups', 'dsat'] as $key) { self::assertSame($all[$key], $filtered[$key]); }
        $none = $this->report(['min_responses' => 4], 999);
        self::assertSame(0, $none['agentCount']);
        self::assertSame(1, $none['page']);
        self::assertSame(1, $none['pages']);
        self::assertSame([], $none['agents']);
        self::assertSame($all['summary'], $none['summary']);
        foreach (['csat_asc', 'csat_desc'] as $sort) {
            $agents = $this->report(['agent_sort' => $sort, 'agent_page_size' => 100])['agents'];
            self::assertSame('Unrated', $agents[count($agents) - 1]['agent_name']);
            self::assertNull($agents[count($agents) - 1]['score']);
        }
        foreach (['responses_desc', 'responses_asc', 'dsat_desc', 'dsat_asc', 'name_asc', 'name_desc'] as $sort) {
            $agents = $this->report(['agent_sort' => $sort, 'agent_page_size' => 100])['agents'];
            $column = str_starts_with($sort, 'name_') ? 'agent_name' : (str_starts_with($sort, 'dsat_') ? 'bad' : 'total');
            $values = array_column($agents, $column);
            $expected = $values;
            str_ends_with($sort, '_asc') ? sort($expected) : rsort($expected);
            self::assertSame($expected, $values);
        }
    }

    public function testSharedSearchMatchesNamesOrEmailsWithoutBroadeningDuplicateNames(): void
    {
        $this->row([], ['customer_comment' => 'First owner']);
        $this->row(['agent_email' => 'different@elsewhere.test'], ['customer_comment' => 'Other owner']);
        $this->row(['agent_name' => 'José Ahmad', 'agent_email' => 'jose@example.test']);
        $this->row(['import_id' => $this->otherImportId], ['customer_comment' => 'Other module']);
        $searched = $this->report(['agent' => 'ALICE@EXAMPLE.TEST']);
        self::assertSame(1, $searched['summary']['total']);
        self::assertSame('First owner', $searched['dsat']['rows'][0]['comment']);
        self::assertSame(['Alice', 'José Ahmad'], $searched['agentNames']);
        self::assertSame(1, $searched['matchedNameCount']);
        self::assertSame(1, $this->report(['agent' => 'ahmd jose'])['summary']['total']);
        $owned = $this->report(['agent' => 'different@elsewhere.test'], 1, 'alice@example.test');
        self::assertSame(0, $owned['summary']['total']);
        self::assertSame([], $owned['dsat']['rows']);
        self::assertSame(['Alice'], $owned['agentNames']);
    }

    public function testEmailLookupsMatchLiteralAddressesWithoutFuzzyLocalPartCollisions(): void
    {
        foreach (['sample3@example.test', 'sample4@example.test', 'sample30@example.test'] as $email) {
            $this->row(['agent_name' => 'Same Display Name', 'agent_email' => $email], ['customer_comment' => $email]);
        }
        $this->row(['agent_name' => 'sample3@example.test', 'agent_email' => 'other@elsewhere.test']);
        $this->row(['import_id' => $this->otherImportId, 'agent_email' => 'sample3@example.test']);
        foreach (['sample3@example.test', 'SAMPLE3@EXAMPLE'] as $lookup) {
            $report = $this->report(['agent' => $lookup]);
            self::assertSame(1, $report['summary']['total']);
            self::assertSame('sample3@example.test', $report['dsat']['rows'][0]['agent_email']);
        }
        $domain = $this->report(['agent' => '@EXAMPLE.TEST']);
        self::assertSame(3, $domain['summary']['total']);
        self::assertSame(0, $this->report(['agent' => 'sample5@example.test'])['summary']['total']);
    }

    public function testDsatDetailFiltersCombineWithoutChangingReasonRankingsOrOtherTables(): void
    {
        $this->row(['agent_name' => 'José Ahmad', 'team' => 'Élite', 'channel' => 'Email'],
            ['dsat_reasons' => ['Spéed', 'Accuracy'], 'dsat_reason' => 'Spéed; Accuracy', 'customer_comment' => 'Refund pending 50%', 'case_id' => 'CASE-42']);
        $this->row(['agent_name' => 'José Ahmad'], ['dsat_reasons' => ['Speed'], 'customer_comment' => 'Refund pending']);
        $this->row(['agent_name' => 'Bob'], ['dsat_reason' => 'Accuracy']);
        $this->row(['agent_name' => 'Charlie']);
        $all = $this->report();
        $filtered = $this->report(['dsat_search' => 'JOSE elite CASE-42 REFUND', 'dsat_reason' => 'speed', 'dsat_comments' => 'with']);
        self::assertSame(4, $filtered['dsat']['total']);
        self::assertSame(1, $filtered['dsat']['matched_total']);
        self::assertSame('CASE-42', $filtered['dsat']['rows'][0]['case_id']);
        self::assertSame($all['dsat']['reasons'], $filtered['dsat']['reasons']);
        self::assertSame($all['dsat']['missing_reason'], $filtered['dsat']['missing_reason']);
        foreach (['summary', 'groups', 'agents', 'agentCount'] as $key) { self::assertSame($all[$key], $filtered[$key]); }
        self::assertSame(1, $this->report(['dsat_reason' => 'Reason not provided', 'dsat_comments' => 'without'])['dsat']['matched_total']);
        self::assertSame(1, $this->report(['dsat_search' => '50%'])['dsat']['matched_total']);
        self::assertSame(1, $this->report(['dsat_search' => 'ALICE@EXAMPLE.TEST survey-3 chat SUPPORT'])['dsat']['matched_total']);
        self::assertSame(0, $this->report(['dsat_search' => '%_'])['dsat']['matched_total']);
        self::assertSame(0, $this->report(['dsat_reason' => 'Spe'])['dsat']['matched_total']);
        $none = $this->report(['dsat_reason' => 'Unavailable', 'dsat_page' => 999]);
        self::assertSame(0, $none['dsat']['matched_total']);
        self::assertSame([], $none['dsat']['rows']);
        self::assertSame(1, $none['dsat']['page']);
        self::assertSame(1, $none['dsat']['pages']);
    }

    public function testDsatSortAndFilteredPaginationRemainStableAndOwned(): void
    {
        for ($i = 1; $i <= 23; $i++) {
            $this->row(['agent_name' => sprintf('Agent %02d', 24 - $i), 'response_date' => sprintf('2026-10-%02d', $i)],
                ['dsat_reason' => $i % 2 ? 'Speed' : 'Accuracy', 'customer_comment' => 'Owned recorded feedback']);
        }
        $this->row(['agent_email' => 'other@example.test'], ['dsat_reason' => 'Private reason', 'customer_comment' => 'Private feedback']);
        $this->row(['import_id' => $this->otherImportId], ['dsat_reason' => 'Other module', 'customer_comment' => 'Private feedback']);
        $input = ['dsat_comments' => 'with', 'dsat_search' => 'recorded', 'dsat_page_size' => 10, 'dsat_page' => 2];
        $report = $this->report($input, 1, 'alice@example.test');
        self::assertSame(23, $report['dsat']['total']);
        self::assertSame(23, $report['dsat']['matched_total']);
        self::assertSame(3, $report['dsat']['pages']);
        self::assertCount(10, $report['dsat']['rows']);
        self::assertSame('2026-10-13', $report['dsat']['rows'][0]['response_date']);
        $oldest = $this->report(array_replace($input, ['dsat_sort' => 'oldest']), 1, 'alice@example.test');
        self::assertSame('2026-10-11', $oldest['dsat']['rows'][0]['response_date']);
        $reason = $this->report(['dsat_sort' => 'reason_asc', 'dsat_page_size' => 100], 1, 'alice@example.test');
        self::assertSame('Accuracy', $reason['dsat']['rows'][0]['reason']);
        $agent = $this->report(['dsat_sort' => 'agent_asc', 'dsat_page_size' => 100], 1, 'alice@example.test');
        self::assertSame('Agent 01', $agent['dsat']['rows'][0]['agent_name']);
        self::assertSame($agent, $this->report(['dsat_sort' => 'agent_asc', 'dsat_page_size' => 100], 1, 'alice@example.test'));
        foreach ($reason['dsat']['rows'] as $row) { self::assertStringNotContainsString('Private', $row['comment']); }
        $private = $this->report(['dsat_search' => 'Private', 'dsat_reason' => 'Private reason'], 1, 'alice@example.test');
        self::assertSame(0, $private['dsat']['matched_total']);
        self::assertCount(0, $private['dsat']['rows']);
        self::assertNotContains('Private reason', array_column($private['dsat']['reasons'], 'reason'));
    }
}
