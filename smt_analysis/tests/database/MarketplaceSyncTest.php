<?php

namespace Tests\Database;

use App\Libraries\DailyCsatImporter;
use App\Libraries\GoogleSheetsClient;
use App\Libraries\GoogleSheetsSync;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use DomainException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ModuleSheetsClient extends GoogleSheetsClient
{
    public array $values = [
        ['Date', 'Team', 'Good', 'Bad', 'agent_name', 'csat_id', 'csat_submission_channel_name', 'is_excluded', 'agent_email', 'Reason'],
        ['2026-10-22', 'Support', 0, 1, 'Agent One', 'survey-1', 'Chat', 0, 'agent@example.test', 'Late reply'],
    ];
    public ?\Throwable $failure = null;

    public function __construct() {}

    public function credentialsStatus(): array
    {
        return ['credentials_ready' => true, 'service_email' => 'sync@example.test', 'credentials_error' => null];
    }

    public function fetch(string $spreadsheetId, int $gid): array
    {
        if ($this->failure) { throw $this->failure; }
        return ['title' => 'Raw Data CSAT', 'values' => $this->values];
    }
}

final class MarketplaceSyncTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'App';
    protected $refresh = true;
    private int $adminId;
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->table('users')->insert(['name' => 'Module Admin', 'email' => 'admin@example.test',
            'password_hash' => 'unused', 'role' => 'admin', 'active' => 1, 'created_at' => '2026-10-25 00:00:00']);
        $this->adminId = (int) $this->db->insertID();
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) { unlink($file); }
        }
        parent::tearDown();
    }

    private function sync(string $module, ?ModuleSheetsClient $client = null): GoogleSheetsSync
    {
        $lock = tempnam(sys_get_temp_dir(), 'module_sync_');
        $this->files[] = $lock;
        return new GoogleSheetsSync($this->db, $client ?? new ModuleSheetsClient(), $lock, $module);
    }

    private function settings(string $id = 'a'): array
    {
        return ['spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/' . str_repeat($id, 25) . '/edit',
            'enabled' => '1', 'refresh_minutes' => '15', 'reason_column' => 'Reason'];
    }

    public function testSourcesAndRefreshesRemainIndependent(): void
    {
        $food = $this->sync('shopee-food');
        $marketClient = new ModuleSheetsClient();
        $marketClient->values[0] = array_merge($marketClient->values[0], ['Feedback', 'Comment']);
        $marketClient->values[1] = array_merge($marketClient->values[1], ['Marketplace feedback', 'Marketplace comment']);
        $market = $this->sync('marketplace', $marketClient);
        $food->configure($this->settings(), $this->adminId);
        self::assertFalse($market->status()['configured']);
        $foodResult = $food->sync(true);
        $foodSource = $food->status()['source'];
        $foodResponses = $this->db->table('responses')->where('import_id', $foodResult['import_id'])->get()->getResultArray();

        $market->configure($this->settings('b') + ['comment_column' => 'Feedback'], $this->adminId);
        $marketResult = $market->sync(true);
        self::assertNotSame($foodResult['import_id'], $marketResult['import_id']);
        self::assertSame('marketplace', $market->status()['module']);
        self::assertSame('marketplace', $this->db->table('imports')->where('id', $marketResult['import_id'])->get()->getRowArray()['module']);
        self::assertSame($foodSource, $food->status()['source']);

        $marketClient->values[1][9] = 'Updated Marketplace reason';
        $market->configure(array_replace($this->settings('c'), ['refresh_minutes' => '60', 'comment_column' => 'Comment']), $this->adminId);
        $updated = $market->sync(true);
        self::assertSame($marketResult['import_id'], $updated['import_id']);
        self::assertSame('Comment', $market->status()['source']['comment_column']);
        self::assertSame(60, $market->status()['source']['refresh_minutes']);
        self::assertSame($foodSource, $food->status()['source']);
        self::assertSame($foodResponses, $this->db->table('responses')->where('import_id', $foodResult['import_id'])->get()->getResultArray());

        $marketClient->failure = new DomainException('Marketplace unavailable.');
        try {
            $market->sync(true);
            self::fail('The failed source must not replace either report.');
        } catch (DomainException $e) {
            self::assertSame('Marketplace unavailable.', $e->getMessage());
        }
        self::assertSame($foodSource, $food->status()['source']);
        self::assertSame('unchanged', $food->sync(true)['state']);
        self::assertSame(2, $this->db->table('imports')->countAllResults());
    }

    public function testMarketplaceCannotUseTheShopeeDefaultUrl(): void
    {
        $market = $this->sync('marketplace');
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('valid Google spreadsheet ID');
        $market->configure(['enabled' => '1'], $this->adminId);
    }

    public function testCrossModuleSourceReferenceCannotOverwriteAnotherReport(): void
    {
        $food = $this->sync('shopee-food');
        $market = $this->sync('marketplace');
        $food->configure($this->settings(), $this->adminId);
        $foodResult = $food->sync(true);
        $market->configure($this->settings('b'), $this->adminId);
        $original = $this->db->table('responses')->get()->getResultArray();
        $this->db->table('sheet_sources')->where('id', 2)->update(['import_id' => $foodResult['import_id'],
            'content_hash' => $this->db->table('sheet_sources')->where('id', 1)->get()->getRowArray()['content_hash']]);
        try {
            $market->sync(true);
            self::fail('Cross-module report references must be rejected.');
        } catch (DomainException $e) {
            self::assertStringContainsString('another module', $e->getMessage());
        }
        self::assertSame($original, $this->db->table('responses')->get()->getResultArray());
        self::assertSame(1, $this->db->table('imports')->countAllResults());
    }

    public function testIdenticalWorkbooksMayBeImportedOncePerModule(): void
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->setTitle('Raw Data CSAT')->fromArray((new ModuleSheetsClient())->values, null, 'A1', true);
        $file = tempnam(sys_get_temp_dir(), 'module_workbook_');
        $this->files[] = $file;
        (new Xlsx($book))->save($file);
        $book->disconnectWorksheets();
        $food = new DailyCsatImporter();
        $market = new DailyCsatImporter('marketplace');
        $foodId = $food->import($file, 'same.xlsx', $this->adminId, $this->db);
        $marketId = $market->import($file, 'same.xlsx', $this->adminId, $this->db);
        self::assertNotSame($foodId, $marketId);
        self::assertSame(2, $this->db->table('imports')->where('file_hash', hash_file('sha256', $file))->countAllResults());
        foreach ([$food, $market] as $importer) {
            try {
                $importer->import($file, 'same.xlsx', $this->adminId, $this->db);
                self::fail('Repeated imports must be rejected within each module.');
            } catch (DomainException $e) {
                self::assertStringContainsString('already been imported', $e->getMessage());
            }
        }
    }

    public function testUpgradePreservesExistingShopeeReportsAndConnection(): void
    {
        $food = $this->sync('shopee-food');
        $food->configure($this->settings(), $this->adminId);
        $food->sync(true);
        $source = $this->db->table('sheet_sources')->get()->getRowArray();
        $responses = $this->db->table('responses')->get()->getResultArray();
        require_once APPPATH . 'Database/Migrations/2026-10-25-000001_AddMarketplaceModule.php';
        $migration = new \App\Database\Migrations\AddMarketplaceModule(\Config\Database::forge($this->db));
        $migration->down();
        $legacyImport = $this->db->table('imports')->get()->getRowArray();
        $migration->up();
        $upgradedImport = $this->db->table('imports')->get()->getRowArray();
        self::assertSame('shopee-food', $upgradedImport['module']);
        unset($upgradedImport['module']);
        self::assertSame($legacyImport, $upgradedImport);
        self::assertSame($source, $this->db->table('sheet_sources')->get()->getRowArray());
        self::assertSame($responses, $this->db->table('responses')->get()->getResultArray());
    }

    public function testCredentialLocationsAndEnvironmentKeysAreIndependent(): void
    {
        $config = config('GoogleSheets');
        self::assertSame('GOOGLE_SHEETS_CREDENTIALS', $config->credentialsEnvironment());
        self::assertSame('MARKETPLACE_GOOGLE_SHEETS_CREDENTIALS', $config->credentialsEnvironment('marketplace'));
        self::assertNotSame($config->credentialsPath(), $config->credentialsPath('marketplace'));
    }
}
