<?php

namespace Tests\Database;

use App\Libraries\GoogleSheetsClient;
use App\Libraries\GoogleSheetsSync;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use DomainException;

class FakeGoogleSheetsClient extends GoogleSheetsClient
{
    public int $calls = 0;
    public array $values = [];
    public ?\Throwable $failure = null;
    public bool $streamed = false;
    public ?\Throwable $streamFailure = null;

    public function __construct() {}

    public function credentialsStatus(): array
    {
        return ['credentials_ready' => true, 'service_email' => 'sync@example.test', 'credentials_error' => null];
    }

    public function fetch(string $spreadsheetId, int $gid): array
    {
        $this->calls++;
        if ($this->failure) { throw $this->failure; }
        if ($this->streamed) {
            $values = $this->values;
            $failure = $this->streamFailure;
            $rows = static function () use ($values, $failure): \Generator {
                foreach ($values as $index => $cells) {
                    if ($index === 0) { continue; }
                    yield $index + 1 => $cells;
                    if ($failure) { throw $failure; }
                }
            };
            return ['title' => 'Raw Data CSAT', 'headers' => $values[0], 'rows' => $rows()];
        }
        return ['title' => 'Raw Data CSAT', 'values' => $this->values];
    }
}

final class GoogleSheetsSyncTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh = true;
    private int $adminId;
    private FakeGoogleSheetsClient $client;
    private GoogleSheetsSync $sync;
    private string $lockPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->table('users')->insert(['name' => 'Sheet Admin', 'email' => 'admin@example.test',
            'password_hash' => 'unused-test-hash', 'role' => 'admin', 'active' => 1, 'created_at' => '2026-10-23 00:00:00']);
        $this->adminId = (int) $this->db->insertID();
        $this->client = new FakeGoogleSheetsClient();
        $this->client->values = [
            ['Date', 'Team', 'Good', 'Bad', 'agent_name', 'csat_id', 'csat_submission_channel_name', 'is_excluded', 'agent_email', 'Reason'],
            ['2026-10-22', 'Support', 0, 1, 'Agent One', '2096599610335211576', 'Chat', 0, 'agent@example.test', 'Late reply'],
        ];
        $this->lockPath = tempnam(sys_get_temp_dir(), 'sheet_sync_');
        $this->sync = new GoogleSheetsSync($this->db, $this->client, $this->lockPath);
        $this->configure();
    }

    protected function tearDown(): void
    {
        if (is_file($this->lockPath)) { unlink($this->lockPath); }
        parent::tearDown();
    }

    private function configure(array $extra = []): array
    {
        return $this->sync->configure(array_merge(['spreadsheet_id' => str_repeat('a', 25), 'sheet_gid' => '2030175176',
            'reason_column' => 'Reason', 'refresh_minutes' => '15', 'enabled' => '1'], $extra), $this->adminId);
    }

    public function testStreamedResponseRowsUseTheSameDeduplicationAndAtomicImport(): void
    {
        $this->client->streamed = true;
        $this->client->values[] = $this->client->values[1];
        $result = $this->sync->sync(true);
        self::assertSame('synced', $result['state']);
        self::assertSame(1, $result['row_count']);
        self::assertSame(1, $result['duplicate_count']);
        self::assertSame(1, $this->db->table('responses')->countAllResults());
    }

    public function testFailureInALaterStreamedChunkKeepsTheLastCompleteReport(): void
    {
        $first = $this->sync->sync(true);
        $original = $this->db->table('responses')->get()->getResultArray();
        $this->client->streamed = true;
        $this->client->values[1][9] = 'A changed reason must not be partially saved';
        $this->client->streamFailure = new DomainException('A later Google chunk was unavailable.');
        try {
            $this->sync->sync(true);
            self::fail('An incomplete source must not be imported.');
        } catch (DomainException $e) {
            self::assertSame('A later Google chunk was unavailable.', $e->getMessage());
        }
        self::assertSame($original, $this->db->table('responses')->get()->getResultArray());
        self::assertSame($first['import_id'], $this->sync->status()['source']['import_id']);
    }

    public function testRepeatedSyncKeepsOneReportAndIdenticalDuplicatesCountOnce(): void
    {
        $this->client->values[] = $this->client->values[1];
        $first = $this->sync->sync(true);
        self::assertSame('synced', $first['state']);
        self::assertSame(1, $first['row_count']);
        self::assertSame(1, $first['duplicate_count']);
        self::assertSame(1, $this->db->table('imports')->countAllResults());
        $response = $this->db->table('responses')->get()->getRowArray();
        self::assertSame('2096599610335211576', $response['csat_id']);
        self::assertSame('agent@example.test', $response['agent_email']);
        self::assertSame('Late reply', json_decode($response['raw_json'], true)['dsat_reason']);
        self::assertSame(1, $this->db->table('agent_roster')->where('email', 'agent@example.test')->countAllResults());
        $second = $this->sync->sync(true);
        self::assertSame('unchanged', $second['state']);
        self::assertSame($first['import_id'], $second['import_id']);
        self::assertSame($response['id'], $this->db->table('responses')->get()->getRowArray()['id']);
        $due = $this->sync->sync();
        self::assertSame('not_due', $due['state']);
        self::assertSame(2, $this->client->calls);
        $status = $this->sync->status();
        self::assertTrue($status['configured']);
        self::assertTrue($status['credentials_ready']);
        self::assertSame('Reason', $status['source']['mappings']['reason_column']);
    }

    public function testChangedSourceUpdatesExistingReportAtomically(): void
    {
        $first = $this->sync->sync(true);
        $this->client->values[1][9] = 'Incorrect answer';
        $this->client->values[] = ['2026-10-23', 'Support', 1, 0, 'Agent One', 'another-id', 'Chat', 0, 'agent@example.test', ''];
        $second = $this->sync->sync(true);
        self::assertSame('synced', $second['state']);
        self::assertSame($first['import_id'], $second['import_id']);
        self::assertSame(2, $this->db->table('responses')->countAllResults());
        self::assertSame(1, $this->db->table('imports')->countAllResults());
        $raw = $this->db->table('responses')->where('csat_id', '2096599610335211576')->get()->getRowArray()['raw_json'];
        self::assertSame('Incorrect answer', json_decode($raw, true)['dsat_reason']);
        self::assertSame(2, (int) $this->db->table('imports')->get()->getRowArray()['row_count']);
    }

    public function testUnchangedRefreshRestoresArchivedManagedReport(): void
    {
        $first = $this->sync->sync(true);
        $this->db->table('imports')->where('id', $first['import_id'])->update(['archived' => 1]);
        self::assertSame('unchanged', $this->sync->sync(true)['state']);
        self::assertSame(0, (int) $this->db->table('imports')->where('id', $first['import_id'])->get()->getRowArray()['archived']);
        $this->withSession(['user_id' => $this->adminId])->get('/dashboard?import=' . $first['import_id'])->assertOK();
    }

    public function testChangedDueRefreshRestoresArchivedManagedReport(): void
    {
        $first = $this->sync->sync(true);
        $this->db->table('imports')->where('id', $first['import_id'])->update(['archived' => 1]);
        $this->db->table('sheet_sources')->where('id', 1)->update(['last_attempt_at' => '2020-01-01 00:00:00']);
        $this->client->values[1][9] = 'Updated reason';
        self::assertSame('synced', $this->sync->sync()['state']);
        self::assertSame(0, (int) $this->db->table('imports')->where('id', $first['import_id'])->get()->getRowArray()['archived']);
        $this->withSession(['user_id' => $this->adminId])->get('/dashboard?import=' . $first['import_id'])->assertOK();
    }

    public function testConflictsPreserveLastReportAndThrottleAutomaticRetry(): void
    {
        $first = $this->sync->sync(true);
        $before = $this->db->table('responses')->get()->getResultArray();
        $this->client->values[] = $this->client->values[1];
        $this->client->values[2][9] = 'Conflicting cause';
        try {
            $this->sync->sync(true);
            self::fail('Conflicting duplicate IDs must reject sync.');
        } catch (DomainException $e) {
            self::assertStringContainsString('conflicts', $e->getMessage());
        }
        self::assertSame($before, $this->db->table('responses')->get()->getResultArray());
        self::assertSame($first['import_id'], $this->sync->status()['source']['import_id']);
        self::assertStringContainsString('conflicts', $this->sync->status()['source']['last_error']);
        self::assertSame('not_due', $this->sync->sync()['state']);
        self::assertSame(2, $this->client->calls);
    }

    public function testDatabaseWriteFailureRollsBackReplacementAndHidesInternalError(): void
    {
        $this->sync->sync(true);
        $before = $this->db->table('responses')->get()->getResultArray();
        $table = $this->db->prefixTable('responses');
        $this->db->query('CREATE TRIGGER fail_sheet_insert BEFORE INSERT ON ' . $table
            . " WHEN NEW.csat_id = 'fail' BEGIN SELECT RAISE(ABORT, 'sensitive-internal-detail'); END");
        $this->client->values[1][5] = 'fail';
        try {
            $this->sync->sync(true);
            self::fail('Database write failure must reject sync.');
        } catch (DomainException $e) {
            self::assertStringContainsString('last successful report was kept', $e->getMessage());
            self::assertStringNotContainsString('sensitive-internal-detail', $e->getMessage());
        } finally {
            $this->db->query('DROP TRIGGER fail_sheet_insert');
        }
        self::assertSame($before, $this->db->table('responses')->get()->getResultArray());
        self::assertSame(1, $this->db->table('imports')->countAllResults());
    }

    public function testBusyAndDisabledSkipNetwork(): void
    {
        $handle = fopen($this->lockPath, 'c');
        flock($handle, LOCK_EX);
        try {
            self::assertSame('busy', $this->sync->sync(true)['state']);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        $this->configure(['enabled' => '0']);
        self::assertSame('disabled', $this->sync->sync(true)['state']);
        self::assertSame(0, $this->client->calls);
    }

    public function testSettingsParseOriginalGoogleUrlWithoutAcceptingOtherHosts(): void
    {
        $status = $this->configure(['spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/' . str_repeat('b', 25) . '/edit?gid=123#gid=456', 'sheet_gid' => '456']);
        self::assertSame(str_repeat('b', 25), $status['source']['spreadsheet_id']);
        self::assertSame('456', $status['source']['sheet_gid']);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Google Sheets link');
        $this->configure(['spreadsheet_url' => 'https://docs.google.com.attacker.invalid/spreadsheets/d/' . str_repeat('b', 25)]);
    }
}
