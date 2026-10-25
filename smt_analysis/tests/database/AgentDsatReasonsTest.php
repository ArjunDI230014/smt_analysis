<?php

namespace Tests\Database;

use App\Libraries\CsatReport;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

final class AgentDsatReasonsTest extends CIUnitTestCase
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
        $this->db->table('users')->insert(['name' => 'Test Admin', 'email' => 'admin@example.test',
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

    public function testRanksFourReasonsPerAgentTeamUsingDistinctTagsAndDsatDenominator(): void
    {
        $this->row([], ['dsat_reasons' => ['Speed', 'Accuracy', ' speed ', '']]);
        foreach (['Speed', 'Speed', 'Accuracy', 'Attitude', 'Process', '', 'Resolution'] as $reason) {
            $this->row([], ['dsat_reason' => $reason]);
        }
        $this->row(['good' => 1, 'bad' => 0], ['dsat_reason' => 'Positive tag']);
        $this->row(['is_excluded' => 1], ['dsat_reason' => 'Excluded tag']);
        $this->row(['team' => 'Other team'], ['dsat_reason' => 'Other team reason']);
        $this->row(['agent_name' => 'Bob'], ['dsat_reason' => 'Bob reason']);

        $agents = $this->report()['agents'];
        $alice = $agents[0];
        self::assertSame('Alice', $alice['agent_name']);
        self::assertSame('Support', $alice['team']);
        self::assertSame(8, (int) $alice['bad']);
        self::assertSame(1, $alice['missing_dsat_reason']);
        self::assertSame([
            ['reason' => 'Speed', 'count' => 3, 'share' => 37.5],
            ['reason' => 'Accuracy', 'count' => 2, 'share' => 25.0],
            ['reason' => 'Attitude', 'count' => 1, 'share' => 12.5],
            ['reason' => 'Process', 'count' => 1, 'share' => 12.5],
        ], $alice['top_dsat_reasons']);
        foreach (array_slice($agents, 1) as $agent) {
            self::assertSame(0, $agent['missing_dsat_reason']);
            self::assertSame(1, $agent['top_dsat_reasons'][0]['count']);
            self::assertSame(100.0, $agent['top_dsat_reasons'][0]['share']);
            self::assertSame($agent['agent_name'] === 'Bob' ? 'Bob reason' : 'Other team reason', $agent['top_dsat_reasons'][0]['reason']);
        }
    }

    public function testPersonalRankingsRespectOwnershipExactNameAndReportFilters(): void
    {
        $this->row([], ['dsat_reasons' => ['Speed', 'Accuracy', 'Speed']]);
        $this->row();
        $this->row(['good' => 1, 'bad' => 0]);
        foreach ([
            ['agent_email' => 'other@example.test'], ['agent_email' => null],
            ['agent_name' => 'Other name'], ['team' => 'Other team'], ['channel' => 'Email'],
            ['response_date' => '2026-10-01'], ['is_excluded' => 1], ['import_id' => $this->otherImportId],
        ] as $changes) {
            $this->row($changes, ['dsat_reason' => 'Private or filtered reason']);
        }
        $service = new CsatReport($this->db, 'alice@example.test', 'Alice');
        $report = $service->build($this->importId, $service->filters([
            'team' => 'Support', 'channel' => 'Chat', 'from' => '2026-10-24', 'to' => '2026-10-24',
        ]));
        self::assertCount(1, $report['agents']);
        $agent = $report['agents'][0];
        self::assertSame(3, (int) $agent['total']);
        self::assertSame(1, $agent['missing_dsat_reason']);
        self::assertSame([
            ['reason' => 'Accuracy', 'count' => 1, 'share' => 50.0],
            ['reason' => 'Reason not provided', 'count' => 1, 'share' => 50.0],
            ['reason' => 'Speed', 'count' => 1, 'share' => 50.0],
        ], $agent['top_dsat_reasons']);
    }

    public function testAgentPaginationAndFeedbackFiltersDoNotTruncateOrChangeAgentRankings(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->row(['agent_name' => sprintf('Agent %02d', $i)], ['dsat_reason' => 'Reason ' . $i]);
        }
        $filters = ['agent_sort' => 'name_asc', 'agent_page_size' => 10];
        $pageTwo = $this->report($filters, 2);
        self::assertSame(['Agent 11', 'Agent 12'], array_column($pageTwo['agents'], 'agent_name'));
        foreach ($pageTwo['agents'] as $offset => $agent) {
            self::assertSame([['reason' => 'Reason ' . ($offset + 11), 'count' => 1, 'share' => 100.0]], $agent['top_dsat_reasons']);
        }
        $searched = $this->report($filters + ['dsat_search' => 'no match', 'dsat_reason' => 'Missing', 'dsat_comments' => 'with', 'dsat_page' => 999], 2);
        self::assertSame($pageTwo['agents'], $searched['agents']);
        self::assertSame([], $searched['dsat']['rows']);
        self::assertSame([], $this->report(['min_responses' => 2])['agents']);
    }

    public function testAgentsWithNoNegativeRatingsHaveNoInventedReasons(): void
    {
        self::assertSame([], $this->report()['agents']);
        $this->row(['good' => 1, 'bad' => 0], ['dsat_reason' => 'Positive tag']);
        $this->row(['agent_name' => 'Unrated', 'good' => 0, 'bad' => 0]);
        foreach ($this->report()['agents'] as $agent) {
            self::assertSame([], $agent['top_dsat_reasons']);
            self::assertSame(0, $agent['missing_dsat_reason']);
        }
    }

    private function report(array $input = [], int $page = 1): array
    {
        $service = new CsatReport($this->db);
        return $service->build($this->importId, $service->filters($input), $page);
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
}
