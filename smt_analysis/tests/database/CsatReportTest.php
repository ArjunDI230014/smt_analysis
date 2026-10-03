<?php

namespace Tests\Database;

use App\Libraries\CsatReport;
use App\Libraries\DailyCsatImporter;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

final class CsatReportTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh = true;
    private int $adminId;
    private int $viewerId;
    private int $importId;

    protected function setUp(): void
    {
        parent::setUp();
        $users = new UserModel();
        $this->adminId = (int) $users->insert(['name' => 'Test Admin', 'email' => 'admin@example.test', 'password_hash' => password_hash('test-password-123', PASSWORD_DEFAULT), 'role' => 'admin', 'active' => 1, 'created_at' => '2026-09-28 10:00:00']);
        $this->viewerId = (int) $users->insert(['name' => 'Test Viewer', 'email' => 'viewer@example.test', 'password_hash' => password_hash('test-password-123', PASSWORD_DEFAULT), 'role' => 'user', 'active' => 1, 'created_at' => '2026-09-28 10:00:00']);
        $this->db->table('imports')->insert(['filename' => 'synthetic.xlsx', 'file_hash' => str_repeat('a', 64), 'uploaded_by' => $this->adminId, 'row_count' => 3, 'date_from' => '2026-09-06', 'date_to' => '2026-09-07', 'created_at' => '2026-09-28 10:00:00']);
        $this->importId = (int) $this->db->insertID();
        foreach ([[1, 1, 0, 0, 'A', '2026-09-06'], [2, 1, 0, 1, 'A', '2026-09-06'], [3, 0, 1, 0, 'B', '2026-09-07']] as [$id, $good, $bad, $excluded, $team, $date]) {
            $this->db->table('responses')->insert(['import_id' => $this->importId, 'source_row' => $id + 1, 'response_date' => $date, 'team' => $team, 'agent_name' => 'Agent ' . $id, 'channel' => 'Chat', 'category' => 'Test', 'csat_id' => (string) $id, 'good' => $good, 'bad' => $bad, 'is_excluded' => $excluded, 'handling_seconds' => null, 'raw_json' => '{}']);
        }
    }

    public function testDefaultExcludesFlaggedRowsAndAllRowsIsOptIn(): void
    {
        $service = new CsatReport($this->db);
        $report = $service->build($this->importId, $service->filters([]));
        self::assertSame(2, $report['summary']['total']);
        self::assertSame(50.0, $report['summary']['score']);
        self::assertSame(0, $report['summary']['flagged']);
        self::assertSame(2, $report['agentCount']);
        self::assertSame('included', $service->filters(['scope' => ''])['scope']);
        $report = $service->build($this->importId, $service->filters(['scope' => 'all']));
        self::assertSame(3, $report['summary']['total']);
        self::assertSame(66.67, $report['summary']['score']);
        self::assertSame(1, $report['summary']['flagged']);
        self::assertSame(3, $report['agentCount']);
    }

    public function testExclusionAndDateFilters(): void
    {
        $service = new CsatReport($this->db);
        $report = $service->build($this->importId, $service->filters(['scope' => 'included']));
        self::assertSame(2, $report['summary']['total']);
        self::assertSame(50.0, $report['summary']['score']);
        $report = $service->build($this->importId, $service->filters(['from' => '2026-09-07', 'to' => '2026-09-07']));
        self::assertSame(1, $report['summary']['total']);
        self::assertSame(0.0, $report['summary']['score']);
    }

    public function testEmptyFiltersReturnUnavailableScore(): void
    {
        $service = new CsatReport($this->db);
        $report = $service->build($this->importId, $service->filters(['team' => 'Missing']));
        self::assertSame(0, $report['summary']['total']);
        self::assertNull($report['summary']['score']);
    }

    public function testArchiveAndRestorePreserveResponsesAndDashboardVisibility(): void
    {
        $this->postAsAdmin('/admin/uploads/' . $this->importId . '/archive', [])->assertRedirectTo('/admin/uploads');
        self::assertSame(1, (int) $this->db->table('imports')->where('id', $this->importId)->get()->getRowArray()['archived']);
        self::assertSame(3, $this->db->table('responses')->where('import_id', $this->importId)->countAllResults());
        try {
            $this->withSession(['user_id' => $this->adminId])->get('/dashboard?import=' . $this->importId);
            self::fail('Archived reports must be unavailable on the dashboard.');
        } catch (\CodeIgniter\Exceptions\PageNotFoundException $e) {
            self::assertSame(404, $e->getCode());
        }
        $this->withSession(['user_id' => $this->adminId])->get('/admin/uploads')->assertSee('Restore');
        $this->postAsAdmin('/admin/uploads/' . $this->importId . '/restore', [])->assertRedirectTo('/admin/uploads');
        $this->withSession(['user_id' => $this->adminId])->get('/dashboard?import=' . $this->importId)->assertOK();
    }

    public function testDeleteRemovesOnlySelectedSnapshotAndResponses(): void
    {
        $this->db->table('imports')->insert(['filename' => 'other.xlsx', 'file_hash' => str_repeat('b', 64), 'uploaded_by' => $this->adminId, 'row_count' => 0, 'date_from' => '2026-09-06', 'date_to' => '2026-09-07', 'created_at' => '2026-09-28 10:00:00']);
        $otherId = (int) $this->db->insertID();
        $this->postAsAdmin('/admin/uploads/' . $this->importId . '/delete', [])->assertRedirectTo('/admin/uploads');
        self::assertSame(0, $this->db->table('imports')->where('id', $this->importId)->countAllResults());
        self::assertSame(0, $this->db->table('responses')->where('import_id', $this->importId)->countAllResults());
        self::assertSame(1, $this->db->table('imports')->where('id', $otherId)->countAllResults());
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);
        $this->postAsAdmin('/admin/uploads/' . $this->importId . '/delete', []);
    }

    public function testViewerCannotManageImports(): void
    {
        foreach (['archive', 'restore', 'delete'] as $action) {
            $security = service('security');
            $name = $security->getTokenName();
            $hash = $security->getHash();
            $this->withSession(['user_id' => $this->viewerId, $name => $hash])->post('/admin/uploads/' . $this->importId . '/' . $action, [$name => $hash])->assertStatus(403);
        }
        self::assertSame(0, (int) $this->db->table('imports')->where('id', $this->importId)->get()->getRowArray()['archived']);
        self::assertSame(3, $this->db->table('responses')->where('import_id', $this->importId)->countAllResults());
    }

    public function testGuestCannotAccessDashboard(): void
    {
        $this->get('/dashboard')->assertRedirectTo('/login');
    }

    public function testViewerCannotAccessAdmin(): void
    {
        $this->withSession(['user_id' => $this->viewerId])->get('/admin/uploads')->assertStatus(403);
        $this->withSession(['user_id' => $this->viewerId])->get('/admin/users')->assertStatus(403);
    }

    public function testViewerCanViewDashboard(): void
    {
        $result = $this->withSession(['user_id' => $this->viewerId])->get('/dashboard');
        $result->assertOK();
        $result->assertSee('50.00');
        $result->assertDontSee('Manage users');
    }

    public function testDisabledUserCannotAccessDashboard(): void
    {
        (new UserModel())->update($this->viewerId, ['active' => 0]);
        $this->withSession(['user_id' => $this->viewerId])->get('/dashboard')->assertRedirectTo('/login');
    }

    public function testAdminCanAccessUploads(): void
    {
        $this->withSession(['user_id' => $this->adminId])->get('/admin/uploads')->assertOK();
    }

    public function testAdminCanCreateAndDeactivateViewer(): void
    {
        $this->postAsAdmin('/admin/users', [
            'name' => 'New Viewer', 'email' => 'new@example.test', 'password' => 'new-viewer-password', 'role' => 'user',
        ])->assertRedirectTo('/admin/users');
        $user = (new UserModel())->where('email', 'new@example.test')->first();
        self::assertNotNull($user);
        self::assertTrue(password_verify('new-viewer-password', $user['password_hash']));
        $this->postAsAdmin('/admin/users/' . $user['id'] . '/toggle')->assertRedirect();
        self::assertSame(0, (int) (new UserModel())->find($user['id'])['active']);
    }

    public function testAdminCannotDeactivateSelf(): void
    {
        $this->postAsAdmin('/admin/users/' . $this->adminId . '/toggle')->assertRedirect();
        self::assertSame(1, (int) (new UserModel())->find($this->adminId)['active']);
    }

    private function postAsAdmin(string $path, array $data = [])
    {
        $security = service('security');
        $name = $security->getTokenName();
        $hash = $security->getHash();
        return $this->withSession(['user_id' => $this->adminId, $name => $hash])->post($path, array_merge($data, [$name => $hash]));
    }

    public function testFuzzySearchKeepsTotalsChartsAndExclusionsConsistent(): void
    {
        foreach (['1' => 'SN Ahmad Syafiq', '2' => 'SN Ahmad Sharif', '3' => 'SN Amira Syafiq'] as $id => $name) {
            $this->db->table('responses')->where('csat_id', (string) $id)->update(['agent_name' => $name]);
        }
        $service = new CsatReport($this->db);
        $report = $service->build($this->importId, $service->filters(['agent' => 'SYFIQ']));
        self::assertSame(2, $report['summary']['total']);
        self::assertSame(2, $report['matchedNameCount']);
        self::assertSame(2, $report['agentCount']);
        self::assertSame(50.0, $report['summary']['score']);
        self::assertSame(2, (int) $report['groups']['channels'][0]['total']);
        $report = $service->build($this->importId, $service->filters(['agent' => 'syafiq ahmad', 'team' => 'A']));
        self::assertSame(1, $report['summary']['total']);
        $report = $service->build($this->importId, $service->filters(['agent' => 'ahmad']));
        self::assertSame(1, $report['summary']['total']);
        $report = $service->build($this->importId, $service->filters(['agent' => 'ahmad', 'scope' => 'all']));
        self::assertSame(2, $report['summary']['total']);
        $report = $service->build($this->importId, $service->filters(['agent' => '%_']));
        self::assertSame(0, $report['summary']['total']);
    }
}
