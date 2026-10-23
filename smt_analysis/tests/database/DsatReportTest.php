<?php

namespace Tests\Database;

use App\Libraries\CsatReport;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;

final class DsatReportTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $refresh = true;
    private int $importId;
    private int $nextId = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->table('users')->insert([
            'name' => 'Report Admin', 'email' => 'admin@example.test', 'password_hash' => 'unused',
            'role' => 'admin', 'active' => 1, 'created_at' => '2026-01-01 00:00:00',
        ]);
        $this->db->table('imports')->insert([
            'filename' => 'dsat-test.xlsx', 'file_hash' => str_repeat('d', 64),
            'uploaded_by' => (int) $this->db->insertID(), 'row_count' => 0,
            'date_from' => '2025-12-28', 'date_to' => '2026-12-31', 'created_at' => '2026-01-01 00:00:00',
        ]);
        $this->importId = (int) $this->db->insertID();
    }

    public function testRanksRecordedReasonsAndKeepsCustomerCommentSeparate(): void
    {
        $this->row(['raw_json' => json_encode([
            'dsat_reason' => 'Resolution delayed', 'customer_comment' => 'I waited two days.',
            'case_id' => 'CASE-101', 'reason' => 'Legacy value', 'l1_name' => 'Delivery',
        ])]);
        $this->row(['raw_json' => json_encode(['DSAT Reason' => 'resolution delayed', 'Customer Feedback' => 'Still waiting.', 'Ticket ID' => 102])]);
        $this->row(['raw_json' => json_encode(['root_cause' => 'Incorrect information'])]);
        $this->row(['raw_json' => json_encode(['customer_comment' => 'Your reply was unhelpful.', 'l1_name' => 'Returns', 'category' => 'Returns'])]);
        $this->row(['good' => 1, 'bad' => 0, 'raw_json' => json_encode(['dsat_reason' => 'Must not appear'])]);

        $report = $this->report();
        self::assertSame(4, $report['dsat']['total']);
        self::assertSame(4, $report['summary']['bad']);
        self::assertSame(80.0, $report['summary']['dsat_rate']);
        self::assertSame(1, $report['dsat']['missing_reason']);
        self::assertSame(2, $report['dsat']['reasons'][0]['count']);
        self::assertSame(50.0, $report['dsat']['reasons'][0]['share']);
        self::assertSame(100.0, array_sum(array_column($report['dsat']['reasons'], 'share')));
        $rows = array_column($report['dsat']['rows'], null, 'csat_id');
        self::assertSame('Resolution delayed', $rows['survey-1']['reason']);
        self::assertSame('I waited two days.', $rows['survey-1']['comment']);
        self::assertSame('CASE-101', $rows['survey-1']['case_id']);
        self::assertSame('102', $rows['survey-2']['case_id']);
        self::assertSame('Reason not provided', $rows['survey-4']['reason']);
        self::assertSame('Your reply was unhelpful.', $rows['survey-4']['comment']);
        self::assertArrayNotHasKey('raw_json', $rows['survey-1']);
        self::assertSame(2, $rows['survey-1']['source_row']);
    }

    public function testCanonicalBlankAndAmbiguousLegacyAliasesDoNotInventReasons(): void
    {
        $this->row(['raw_json' => json_encode(['dsat_reason' => '', 'reason' => 'Not selected', 'customer_comment' => 'Long wait'])]);
        $this->row(['raw_json' => json_encode(['reason' => 'Speed', 'root_cause' => 'Accuracy', 'comments' => ['nested'], 'l1_name' => 'Billing'])]);
        $this->row(['raw_json' => '{malformed']);
        $this->row(['raw_json' => json_encode(['reason' => 'Speed', 'DSAT Reason' => 'Speed'])]);

        $report = $this->report();
        self::assertSame(3, $report['dsat']['missing_reason']);
        self::assertSame(['reason' => 'Reason not provided', 'count' => 3, 'share' => 75.0], $report['dsat']['reasons'][0]);
        $rows = array_column($report['dsat']['rows'], null, 'csat_id');
        self::assertSame('', $rows['survey-2']['comment']);
        self::assertSame('Speed', $rows['survey-4']['reason']);
    }

    public function testMultipleReasonsCountEachDsatOncePerDistinctTagAndKeepSurveyDenominator(): void
    {
        $this->row(['raw_json' => json_encode([
            'dsat_reasons' => ['Speed', 'Accuracy', ' speed ', '', null, ['nested']],
            'dsat_reason' => 'Speed; Accuracy',
        ])]);
        $this->row(['raw_json' => json_encode(['dsat_reasons' => ['Speed', 'Accuracy']])]);
        $this->row(['raw_json' => json_encode(['dsat_reasons' => [], 'dsat_reason' => '', 'reason' => 'Legacy alias'])]);
        $this->row(['good' => 1, 'bad' => 0, 'raw_json' => json_encode(['dsat_reasons' => ['Positive survey tag']])]);
        $this->row(['is_excluded' => 1, 'raw_json' => json_encode(['dsat_reasons' => ['Excluded survey tag']])]);

        $report = $this->report();
        self::assertSame(3, $report['dsat']['total']);
        self::assertSame(3, $report['summary']['bad']);
        self::assertSame(75.0, $report['summary']['dsat_rate']);
        self::assertSame(25.0, $report['summary']['score']);
        self::assertSame(1, $report['dsat']['missing_reason']);
        $ranked = array_column($report['dsat']['reasons'], null, 'reason');
        self::assertSame(['reason' => 'Speed', 'count' => 2, 'share' => 66.67], $ranked['Speed']);
        self::assertSame(['reason' => 'Accuracy', 'count' => 2, 'share' => 66.67], $ranked['Accuracy']);
        self::assertSame(['reason' => 'Reason not provided', 'count' => 1, 'share' => 33.33], $ranked['Reason not provided']);
        self::assertEqualsWithDelta(166.67, array_sum(array_column($ranked, 'share')), 0.001);
        self::assertArrayNotHasKey('Positive survey tag', $ranked);
        self::assertArrayNotHasKey('Excluded survey tag', $ranked);
        $details = array_column($report['dsat']['rows'], null, 'csat_id');
        self::assertSame('Speed; Accuracy', $details['survey-1']['reason']);
        self::assertSame('Speed; Accuracy', $details['survey-2']['reason']);
        self::assertSame('Reason not provided', $details['survey-3']['reason']);
    }

    public function testMultipleReasonsAndMissingCountsStayWithinOwnerAndReportFilters(): void
    {
        $this->row(['raw_json' => json_encode(['dsat_reasons' => ['Speed', 'Accuracy', 'Speed']])]);
        $this->row(['raw_json' => json_encode(['dsat_reasons' => []])]);
        $this->row(['good' => 1, 'bad' => 0]);
        foreach ([
            ['agent_email' => 'other@example.test'], ['agent_email' => null],
            ['team' => 'B'], ['channel' => 'Email'], ['is_excluded' => 1],
            ['response_date' => '2026-01-09'],
        ] as $override) {
            $this->row($override + ['raw_json' => json_encode(['dsat_reasons' => ['Private reason', 'Speed']])]);
        }
        $service = new CsatReport($this->db, 'viewer@example.test');
        $report = $service->build($this->importId, $service->filters([
            'team' => 'A', 'channel' => 'Chat', 'from' => '2026-01-04', 'to' => '2026-01-05',
        ]));
        self::assertSame(2, $report['dsat']['total']);
        self::assertSame(1, $report['dsat']['missing_reason']);
        self::assertSame(66.67, $report['summary']['dsat_rate']);
        self::assertSame(33.33, $report['summary']['score']);
        $ranked = array_column($report['dsat']['reasons'], null, 'reason');
        self::assertCount(3, $ranked);
        self::assertArrayNotHasKey('Private reason', $ranked);
        foreach (['Speed', 'Accuracy', 'Reason not provided'] as $label) {
            self::assertSame(['reason' => $label, 'count' => 1, 'share' => 50.0], $ranked[$label]);
        }
    }

    public function testUnknownRecordedReasonRemainsVisibleAsOneTag(): void
    {
        $this->row(['raw_json' => json_encode(['dsat_reasons' => ['9'], 'dsat_reason' => '9'])]);
        $report = $this->report();
        self::assertSame(0, $report['dsat']['missing_reason']);
        self::assertSame([['reason' => '9', 'count' => 1, 'share' => 100.0]], $report['dsat']['reasons']);
        self::assertSame('9', $report['dsat']['rows'][0]['reason']);
    }

    public function testUnknownSourceCodeWithEmptyDecodedListUsesOnlyExplicitCanonicalReason(): void
    {
        $this->row(['raw_json' => json_encode(['csat_reason' => '5', 'dsat_reasons' => [], 'dsat_reason' => '5', 'reason' => 'Unselected alias'])]);
        $this->row(['raw_json' => json_encode(['dsat_reasons' => [], 'reason' => 'Must not fall back'])]);
        $report = $this->report();
        self::assertSame(2, $report['dsat']['total']);
        self::assertSame(1, $report['dsat']['missing_reason']);
        $ranked = array_column($report['dsat']['reasons'], null, 'reason');
        self::assertSame(['reason' => '5', 'count' => 1, 'share' => 50.0], $ranked['5']);
        self::assertSame(['reason' => 'Reason not provided', 'count' => 1, 'share' => 50.0], $ranked['Reason not provided']);
        $details = array_column($report['dsat']['rows'], null, 'csat_id');
        self::assertSame('5', $details['survey-1']['reason']);
        self::assertSame('Reason not provided', $details['survey-2']['reason']);
    }

    public function testDsatDetailsAndRankingsRespectOwnerExactNameAndEveryReportFilter(): void
    {
        $this->row(['raw_json' => json_encode(['dsat_reason' => 'Owned reason'])]);
        $this->row(['good' => 1, 'bad' => 0]);
        foreach ([
            ['agent_email' => 'other@example.test'],
            ['agent_name' => 'Agent Amy'],
            ['team' => 'B'],
            ['channel' => 'Email'],
            ['is_excluded' => 1],
            ['response_date' => '2026-01-09'],
        ] as $override) {
            $this->row($override + ['raw_json' => json_encode(['dsat_reason' => 'Private or filtered'])]);
        }
        $service = new CsatReport($this->db, ' VIEWER@example.test ', 'Agent Ann');
        $report = $service->build($this->importId, $service->filters([
            'agent' => 'Ann', 'team' => 'A', 'channel' => 'Chat',
            'from' => '2026-01-04', 'to' => '2026-01-05',
        ]));
        self::assertSame(2, $report['summary']['total']);
        self::assertSame(50.0, $report['summary']['dsat_rate']);
        self::assertSame(1, $report['dsat']['total']);
        self::assertSame('Owned reason', $report['dsat']['rows'][0]['reason']);
        self::assertSame([['reason' => 'Owned reason', 'count' => 1, 'share' => 100.0]], $report['dsat']['reasons']);
        self::assertCount(1, $report['groups']['trend']);
        self::assertSame(1, $report['agentCount']);

        $all = $service->build($this->importId, $service->filters(['scope' => 'all']));
        self::assertSame(6, $all['summary']['total']);
        self::assertSame(5, $all['dsat']['total']);
        $excluded = $service->build($this->importId, $service->filters(['scope' => 'excluded']));
        self::assertSame(1, $excluded['dsat']['total']);
        $none = $service->build($this->importId, $service->filters(['agent' => 'Missing person']));
        self::assertSame(0, $none['dsat']['total']);
        self::assertSame([], $none['dsat']['reasons']);
    }

    public function testPersonalReportNeverExposesAnotherAgentsReasonWithDuplicateNames(): void
    {
        $this->row(['raw_json' => json_encode(['dsat_reason' => 'My reason'])]);
        $this->row(['agent_email' => 'other@example.test', 'raw_json' => json_encode(['dsat_reason' => 'Private other reason'])]);
        $this->row(['agent_email' => null, 'raw_json' => json_encode(['dsat_reason' => 'Unlinked reason'])]);
        $service = new CsatReport($this->db, 'viewer@example.test');
        $report = $service->build($this->importId, $service->filters(['scope' => 'all']));
        self::assertSame(1, $report['dsat']['total']);
        self::assertSame('My reason', $report['dsat']['rows'][0]['reason']);
        self::assertSame(100.0, $report['dsat']['reasons'][0]['share']);
    }

    public function testWeeklyTrendUsesMondayBoundariesAcrossYearAndWeightedCounts(): void
    {
        $this->calendarRows();
        $report = $this->report(['period' => 'weekly']);
        self::assertSame(['2025-12-22', '2025-12-29', '2026-01-05'], array_column($report['groups']['trend'], 'label'));
        self::assertSame(['label' => '2025-12-29', 'good' => 3, 'bad' => 3, 'total' => 6, 'score' => 50.0], $report['groups']['trend'][1]);
        self::assertSame(37.5, $report['summary']['dsat_rate']);
        self::assertCount(7, $this->report()['groups']['trend']);
        $partial = $this->report(['period' => 'weekly', 'from' => '2026-01-01', 'to' => '2026-01-04']);
        self::assertSame('2025-12-29', $partial['groups']['trend'][0]['label']);
        self::assertSame(2, $partial['groups']['trend'][0]['total']);
    }

    public function testMonthlyTrendKeepsCalendarYearsSeparateAndUsesWeightedCounts(): void
    {
        $this->calendarRows();
        $this->row(['response_date' => '2026-12-01']);
        $report = $this->report(['period' => 'monthly']);
        self::assertSame(['2025-12', '2026-01', '2026-12'], array_column($report['groups']['trend'], 'label'));
        self::assertSame(['label' => '2025-12', 'good' => 3, 'bad' => 2, 'total' => 5, 'score' => 60.0], $report['groups']['trend'][0]);
        self::assertSame(66.67, $report['groups']['trend'][1]['score']);
        self::assertSame(0.0, $report['groups']['trend'][2]['score']);
    }

    public function testDsatPaginationDoesNotChangeRankingsOrAgentPagination(): void
    {
        for ($i = 0; $i < 53; $i++) {
            $this->row(['raw_json' => json_encode(['dsat_reason' => $i % 2 === 0 ? 'Speed' : 'Accuracy'])]);
        }
        $report = $this->report(['dsat_page' => '2']);
        self::assertSame(53, $report['dsat']['total']);
        self::assertSame(50, $report['dsat']['page_size']);
        self::assertSame(2, $report['dsat']['pages']);
        self::assertSame(2, $report['dsat']['page']);
        self::assertCount(3, $report['dsat']['rows']);
        self::assertSame('survey-3', $report['dsat']['rows'][0]['csat_id']);
        self::assertSame(53, array_sum(array_column($report['dsat']['reasons'], 'count')));
        self::assertSame(1, $report['page']);
        self::assertSame(2, $this->report(['dsat_page' => '999'])['dsat']['page']);
    }

    public function testEmptyReportHasNoFabricatedRatesOrReasons(): void
    {
        $report = $this->report();
        self::assertNull($report['summary']['dsat_rate']);
        self::assertSame([], $report['dsat']['rows']);
        self::assertSame([], $report['dsat']['reasons']);
        self::assertSame(0, $report['dsat']['missing_reason']);
        self::assertSame(1, $report['dsat']['page']);
        self::assertSame(1, $report['dsat']['pages']);
        $service = new CsatReport($this->db);
        self::assertSame('daily', $service->filters([])['period']);
        self::assertSame(1, $service->filters([])['dsat_page']);
    }

    public function testConcurrentSyncCannotMixAnOldSummaryWithNewFeedback(): void
    {
        $this->row(['raw_json' => json_encode(['dsat_reason' => 'Before refresh'])]);
        $path = tempnam(sys_get_temp_dir(), 'dsat_snapshot_');
        $copy = new \SQLite3($path);
        self::assertTrue($this->db->connID->backup($copy));
        $copy->close();
        $settings = ['database' => $path, 'DBDriver' => 'SQLite3', 'DBPrefix' => $this->db->DBPrefix,
            'pConnect' => false, 'DBDebug' => true, 'busyTimeout' => 1000];
        $reader = \Config\Database::connect($settings, false);
        $writer = \Config\Database::connect($settings, false);
        // WAL allows the writer to commit while a reader retains its prior snapshot.
        $reader->query('PRAGMA journal_mode = WAL')->getRowArray();
        $writer->initialize();
        $replaced = false;
        $listener = function ($query) use (&$replaced, $writer): void {
            if (!$replaced && str_contains($query->getQuery(), 'COUNT(*) AS total')) {
                $replaced = true;
                $writer->table('responses')->where('import_id', $this->importId)->update(['good' => 1, 'bad' => 0]);
            }
        };
        Events::on('DBQuery', $listener);
        try {
            $service = new CsatReport($reader);
            $report = $service->build($this->importId, $service->filters([]));
            self::assertTrue($replaced);
            self::assertSame(1, $report['summary']['bad']);
            self::assertSame(1, $report['dsat']['total']);
            self::assertSame('Before refresh', $report['dsat']['rows'][0]['reason']);
            self::assertSame(0, $reader->transDepth);
            self::assertSame(0, (int) $writer->table('responses')->selectSum('bad')->get()->getRowArray()['bad']);
        } finally {
            Events::removeListener('DBQuery', $listener);
            $reader->close();
            $writer->close();
            foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
                if (is_file($file)) { unlink($file); }
            }
        }
    }

    public function testBuildingReportDoesNotCommitTheCallersTransaction(): void
    {
        self::assertTrue($this->db->transBegin());
        try {
            $this->row();
            $report = $this->report();
            self::assertSame(1, $report['dsat']['total']);
            self::assertSame(1, $this->db->transDepth);
        } finally {
            $this->db->transRollback();
        }
        self::assertSame(0, $this->db->transDepth);
        self::assertSame(0, $this->db->table('responses')->countAllResults());
    }

    public function testInterruptedReportReleasesItsReadTransaction(): void
    {
        $this->row();
        $listener = static function (): void { throw new \RuntimeException('Report interrupted'); };
        Events::on('DBQuery', $listener);
        try {
            $this->report();
            self::fail('The interrupted report should throw.');
        } catch (\RuntimeException $e) {
            self::assertSame('Report interrupted', $e->getMessage());
            self::assertSame(0, $this->db->transDepth);
        } finally {
            Events::removeListener('DBQuery', $listener);
        }
        self::assertSame(1, $this->report()['dsat']['total']);
    }

    #[DataProvider('invalidFilters')]
    public function testRejectsInvalidPeriodAndDsatPagination(array $filter): void
    {
        $this->expectException(DomainException::class);
        (new CsatReport($this->db))->filters($filter);
    }

    public static function invalidFilters(): array
    {
        return [
            [['period' => 'yearly']], [['period' => ['monthly']]],
            [['dsat_page' => []]], [['dsat_page' => '-1']], [['dsat_page' => 0]],
            [['dsat_page' => '1.5']], [['dsat_page' => '1e2']], [['dsat_page' => true]],
        ];
    }

    private function report(array $input = []): array
    {
        $service = new CsatReport($this->db);
        return $service->build($this->importId, $service->filters($input));
    }

    private function calendarRows(): void
    {
        foreach ([
            ['2025-12-28', 1], ['2025-12-29', 1], ['2025-12-29', 1],
            ['2025-12-30', 0], ['2025-12-31', 0], ['2026-01-01', 1],
            ['2026-01-04', 0], ['2026-01-05', 1],
        ] as [$date, $good]) {
            $this->row(['response_date' => $date, 'good' => $good, 'bad' => 1 - $good]);
        }
    }

    private function row(array $override = []): void
    {
        $id = $this->nextId++;
        $this->db->table('responses')->insert($override + [
            'import_id' => $this->importId, 'source_row' => $id + 1,
            'response_date' => '2026-01-04', 'team' => 'A', 'agent_name' => 'Agent Ann',
            'agent_email' => 'viewer@example.test', 'channel' => 'Chat', 'category' => 'General',
            'csat_id' => 'survey-' . $id, 'good' => 0, 'bad' => 1,
            'is_excluded' => 0, 'handling_seconds' => null, 'raw_json' => '{}',
        ]);
    }
}
