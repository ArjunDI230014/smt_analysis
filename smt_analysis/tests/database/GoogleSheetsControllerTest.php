<?php

namespace Tests\Database;

use App\Models\UserModel;
use CodeIgniter\HTTP\Files\FileCollection;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Security\Exceptions\SecurityException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;

final class GoogleSheetsControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait { setupRequest as private featureSetupRequest; }

    protected $namespace = 'App';
    protected $refresh = true;
    private int $adminId;
    private int $viewerId;
    private string $credentialsPath;
    private array $previousCredentialsEnvironment;
    private ?UploadedFile $uploadedCredentials = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousCredentialsEnvironment = [getenv('GOOGLE_SHEETS_CREDENTIALS'), $_ENV['GOOGLE_SHEETS_CREDENTIALS'] ?? null, $_SERVER['GOOGLE_SHEETS_CREDENTIALS'] ?? null];
        $this->credentialsPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sheets-controller-' . bin2hex(random_bytes(8)) . '.json';
        // No test can accidentally use a developer's real key or contact Google.
        putenv('GOOGLE_SHEETS_CREDENTIALS=' . $this->credentialsPath);
        $_ENV['GOOGLE_SHEETS_CREDENTIALS'] = $this->credentialsPath;
        $_SERVER['GOOGLE_SHEETS_CREDENTIALS'] = $this->credentialsPath;
        $users = new UserModel();
        foreach (['admin', 'user'] as $role) {
            $id = (int) $users->insert(['name' => 'Sheets ' . $role, 'email' => $role . '@example.test', 'password_hash' => password_hash('test-password-123', PASSWORD_DEFAULT), 'role' => $role, 'active' => 1, 'created_at' => '2026-10-23 00:00:00']);
            if ($role === 'admin') {
                $this->adminId = $id;
            } else {
                $this->viewerId = $id;
            }
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->credentialsPath) && is_file($this->credentialsPath)) {
            unlink($this->credentialsPath);
        }
        if (isset($this->previousCredentialsEnvironment)) {
            [$process, $environment, $server] = $this->previousCredentialsEnvironment;
            $process === false ? putenv('GOOGLE_SHEETS_CREDENTIALS') : putenv('GOOGLE_SHEETS_CREDENTIALS=' . $process);
            if ($environment === null) {
                unset($_ENV['GOOGLE_SHEETS_CREDENTIALS']);
            } else {
                $_ENV['GOOGLE_SHEETS_CREDENTIALS'] = $environment;
            }
            if ($server === null) {
                unset($_SERVER['GOOGLE_SHEETS_CREDENTIALS']);
            } else {
                $_SERVER['GOOGLE_SHEETS_CREDENTIALS'] = $server;
            }
        }
        parent::tearDown();
    }

    private function settings(array $changes = []): array
    {
        return array_replace([
            'spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/1HFE5cyls6xn_uIhG9XaGE7ZH0FbSax-JNjLxEYFkPH4/edit?gid=2030175176#gid=2030175176',
            'sheet_gid' => '2030175176', 'refresh_minutes' => '15', 'reason_column' => 'DSAT Reason',
            'comment_column' => 'Customer Comment', 'case_column' => 'Case ID', 'enabled' => '1',
        ], $changes);
    }

    protected function setupRequest(string $method, ?string $path = null): IncomingRequest
    {
        $request = $this->featureSetupRequest($method, $path);
        if ($this->uploadedCredentials !== null) {
            $collection = new FileCollection();
            self::setPrivateProperty($collection, 'files', ['credentials' => $this->uploadedCredentials]);
            self::setPrivateProperty($request, 'files', $collection);
        }
        return $request;
    }

    private function postAs(?int $userId, string $path, array $data = []): TestResponse
    {
        $name = csrf_token();
        $hash = csrf_hash();
        $session = [$name => $hash];
        if ($userId !== null) {
            $session['user_id'] = $userId;
        }
        return $this->withSession($session)->post($path, $data + [$name => $hash]);
    }

    private function json(TestResponse $response, string $state, int $status = 200): array
    {
        $response->assertStatus($status);
        $payload = json_decode($response->getJSON(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame($state, $payload['state']);
        self::assertArrayHasKey('import_id', $payload);
        self::assertArrayHasKey('updated_at', $payload);
        self::assertSame(csrf_token(), $payload['csrf_name']);
        self::assertIsString($payload['csrf_hash']);
        self::assertNotSame('', $payload['csrf_hash']);
        return $payload;
    }

    public function testOnlyAdministratorsCanViewOrSaveConnectionSettings(): void
    {
        $settings = $this->withSession(['user_id' => $this->adminId])->get('/admin/sheets');
        $settings->assertOK();
        self::assertStringContainsString('1HFE5cyls6xn_uIhG9XaGE7ZH0FbSax-JNjLxEYFkPH4', $settings->getBody());
        self::assertStringContainsString('name="spreadsheet_url"', $settings->getBody());
        $this->withSession(['user_id' => $this->viewerId])->get('/admin/sheets')->assertStatus(403);
        $this->postAs($this->viewerId, '/admin/sheets', $this->settings())->assertStatus(403);
        $this->postAs($this->viewerId, '/admin/sheets/refresh')->assertStatus(403);
        self::assertSame(0, $this->db->table('sheet_sources')->countAllResults());
    }

    public function testGuestsMustSignInBeforeSettingsOrRefresh(): void
    {
        $this->withSession([])->get('/admin/sheets')->assertRedirectTo('/login');
        $this->postAs(null, '/admin/sheets', $this->settings())->assertRedirectTo('/login');
        $this->postAs(null, '/admin/sheets/refresh')->assertRedirectTo('/login');
        $this->postAs(null, '/sheets/refresh')->assertRedirectTo('/login');
        self::assertSame(0, $this->db->table('sheet_sources')->countAllResults());
    }

    public function testSavingTheSourceAndMappingsDoesNotFetchData(): void
    {
        $this->postAs($this->adminId, '/admin/sheets', $this->settings())->assertRedirectTo('/admin/sheets');
        $source = $this->db->table('sheet_sources')->where('id', 1)->get()->getRowArray();
        self::assertSame('1HFE5cyls6xn_uIhG9XaGE7ZH0FbSax-JNjLxEYFkPH4', $source['spreadsheet_id']);
        self::assertSame('2030175176', $source['sheet_gid']);
        self::assertSame(15, (int) $source['refresh_minutes']);
        self::assertSame('DSAT Reason', $source['reason_column']);
        self::assertSame('Customer Comment', $source['comment_column']);
        self::assertSame('Case ID', $source['case_column']);
        self::assertSame($this->adminId, (int) $source['configured_by']);
        self::assertNull($source['last_attempt_at']);
        self::assertSame(0, $this->db->table('imports')->countAllResults());
        self::assertFileDoesNotExist($this->credentialsPath);
    }

    public function testInvalidUrlAndIntervalPreserveTheSavedConnection(): void
    {
        $this->postAs($this->adminId, '/admin/sheets', $this->settings());
        $original = $this->db->table('sheet_sources')->get()->getRowArray();
        foreach ([['spreadsheet_url' => 'https://attacker.example/spreadsheets/d/' . $original['spreadsheet_id']], ['refresh_minutes' => '0'], ['spreadsheet_url' => ['unexpected']]] as $changes) {
            $this->postAs($this->adminId, '/admin/sheets', $this->settings($changes))->assertRedirectTo('/admin/sheets');
            self::assertSame($original, $this->db->table('sheet_sources')->get()->getRowArray());
        }
    }

    public function testRefreshWithoutASourceIsAnAuthenticatedNoOpWithRenewedCsrf(): void
    {
        $previous = csrf_hash();
        $payload = $this->json($this->postAs($this->viewerId, '/sheets/refresh'), 'not_configured');
        self::assertNull($payload['import_id']);
        self::assertNotSame($previous, $payload['csrf_hash']);
        self::assertSame(0, $this->db->table('imports')->countAllResults());
    }

    public function testDisabledConnectionDoesNotAttemptARefresh(): void
    {
        $this->postAs($this->adminId, '/admin/sheets', $this->settings(['enabled' => '0']));
        $this->json($this->postAs($this->viewerId, '/sheets/refresh'), 'disabled');
        self::assertNull($this->db->table('sheet_sources')->get()->getRowArray()['last_attempt_at']);
        self::assertSame(0, $this->db->table('imports')->countAllResults());
    }

    public function testViewerReceivesSafeFailureWithoutCredentials(): void
    {
        $this->postAs($this->adminId, '/admin/sheets', $this->settings());
        $response = $this->postAs($this->viewerId, '/sheets/refresh');
        $this->json($response, 'error', 503);
        self::assertStringNotContainsString($this->credentialsPath, $response->getBody());
        self::assertStringNotContainsString('GOOGLE_SHEETS_CREDENTIALS', $response->getBody());
        self::assertStringNotContainsString('private_key', $response->getBody());
        self::assertSame(0, $this->db->table('imports')->countAllResults());
    }

    public function testManualRefreshFailureReturnsToSettingsWithoutPartialImport(): void
    {
        $this->postAs($this->adminId, '/admin/sheets', $this->settings());
        $this->postAs($this->adminId, '/admin/sheets/refresh')->assertRedirectTo('/admin/sheets');
        self::assertSame(0, $this->db->table('imports')->countAllResults());
        self::assertSame(0, $this->db->table('responses')->countAllResults());
    }

    public function testCredentialContentsAreNeverRenderedAndViewerCannotSeeDiagnosticErrors(): void
    {
        $secret = 'PRIVATE_KEY_TEST_SENTINEL_8a92f';
        file_put_contents($this->credentialsPath, json_encode(['type' => 'service_account', 'client_email' => 'test@example.test', 'private_key' => $secret], JSON_THROW_ON_ERROR));
        $this->postAs($this->adminId, '/admin/sheets', $this->settings(['enabled' => '0']));
        $settings = $this->withSession(['user_id' => $this->adminId])->get('/admin/sheets');
        $settings->assertOK();
        self::assertStringNotContainsString($secret, $settings->getBody());
        self::assertStringNotContainsString('"private_key"', $settings->getBody());
        $this->db->table('sheet_sources')->where('id', 1)->update(['last_error' => 'Internal diagnostic ' . $secret]);
        $viewer = $this->withSession(['user_id' => $this->viewerId])->get('/dashboard');
        $viewer->assertOK();
        self::assertStringNotContainsString($secret, $viewer->getBody());
        self::assertStringNotContainsString($this->credentialsPath, $viewer->getBody());
    }

    public function testInvalidUploadedKeyPreservesCurrentKeyAndSource(): void
    {
        $this->postAs($this->adminId, '/admin/sheets', $this->settings());
        $source = $this->db->table('sheet_sources')->get()->getRowArray();
        $key = 'PRIVATE_KEY_FILE_SENTINEL_27f1';
        file_put_contents($this->credentialsPath, $key);
        // Simulate an HTTP upload; the replacement is deliberately invalid JSON.
        $upload = $this->getMockBuilder(UploadedFile::class)->setConstructorArgs([$this->credentialsPath, 'replacement.json', 'application/json', strlen($key), UPLOAD_ERR_OK])->onlyMethods(['isValid'])->getMock();
        $upload->method('isValid')->willReturn(true);
        $this->uploadedCredentials = $upload;
        putenv('GOOGLE_SHEETS_CREDENTIALS');
        unset($_ENV['GOOGLE_SHEETS_CREDENTIALS'], $_SERVER['GOOGLE_SHEETS_CREDENTIALS']);
        try {
            // Invalid JSON must be rejected before configuring or touching any server key.
            $result = $this->postAs($this->adminId, '/admin/sheets', $this->settings(['refresh_minutes' => '60']));
            $result->assertRedirectTo('/admin/sheets');
            $result->assertSessionHas('error', 'The credentials file is not valid service-account JSON.');
        } finally {
            $this->uploadedCredentials = null;
            putenv('GOOGLE_SHEETS_CREDENTIALS=' . $this->credentialsPath);
            $_ENV['GOOGLE_SHEETS_CREDENTIALS'] = $this->credentialsPath;
            $_SERVER['GOOGLE_SHEETS_CREDENTIALS'] = $this->credentialsPath;
        }
        self::assertSame($key, file_get_contents($this->credentialsPath));
        self::assertSame($source, $this->db->table('sheet_sources')->get()->getRowArray());
        self::assertSame(0, $this->db->table('imports')->countAllResults());
    }

    public function testSettingsPostRequiresValidCsrf(): void
    {
        $this->expectException(SecurityException::class);
        $this->withSession(['user_id' => $this->adminId])->post('/admin/sheets', $this->settings());
    }

    public function testAutomaticRefreshRequiresValidCsrf(): void
    {
        $this->expectException(SecurityException::class);
        $this->withSession(['user_id' => $this->viewerId])->post('/sheets/refresh');
    }
}
