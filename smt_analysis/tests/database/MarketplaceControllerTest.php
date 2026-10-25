<?php

namespace Tests\Database;

use App\Libraries\GoogleSheetsSync;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;

final class MarketplaceControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh = true;
    private int $adminId;
    private int $viewerId;
    private array $reports = [];
    private array $responses = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['admin', 'user'] as $role) {
            $this->db->table('users')->insert([
                'name' => 'Module ' . $role, 'email' => $role . '@example.test', 'password_hash' => 'unused',
                'role' => $role, 'active' => 1, 'created_at' => '2026-10-25 00:00:00',
            ]);
            $id = (int) $this->db->insertID();
            if ($role === 'admin') { $this->adminId = $id; } else { $this->viewerId = $id; }
        }
        foreach (['shopee-food' => 'Food-only-report.xlsx', 'marketplace' => 'Market-only-report.xlsx'] as $module => $filename) {
            $this->db->table('imports')->insert([
                'module' => $module, 'filename' => $filename, 'file_hash' => hash('sha256', $filename),
                'uploaded_by' => $this->adminId, 'row_count' => 1, 'date_from' => '2026-10-24',
                'date_to' => '2026-10-24', 'created_at' => '2026-10-25 00:00:00',
            ]);
            $this->reports[$module] = (int) $this->db->insertID();
            $this->db->table('responses')->insert([
                'import_id' => $this->reports[$module], 'source_row' => 2, 'response_date' => '2026-10-24',
                'team' => $module . '-team', 'agent_name' => $module . '-agent', 'agent_email' => 'user@example.test',
                'channel' => 'Chat', 'category' => '', 'csat_id' => 'survey-1', 'good' => 1, 'bad' => 0,
                'is_excluded' => 0, 'raw_json' => '{}',
            ]);
            $this->responses[$module] = (int) $this->db->insertID();
        }
    }

    private function postAs(?int $userId, string $path, array $data = []): TestResponse
    {
        $name = csrf_token();
        $hash = csrf_hash();
        $session = [$name => $hash];
        if ($userId !== null) { $session['user_id'] = $userId; }
        return $this->withSession($session)->post($path, $data + [$name => $hash]);
    }

    private function assertMissing(callable $request): void
    {
        try {
            $request();
            self::fail('A report from the other module must be rejected.');
        } catch (PageNotFoundException $e) {
            self::assertSame(404, $e->getCode());
        }
    }

    public function testMainPageOffersOnlyTheTwoModuleStatisticsChoices(): void
    {
        $result = $this->withSession(['user_id' => $this->adminId])->get('/');
        $result->assertOK();
        $document = new \DOMDocument();
        @$document->loadHTML($result->getBody());
        $xpath = new \DOMXPath($document);
        $links = $xpath->query('//div[@class="content"]//a');
        self::assertCount(2, $links);
        self::assertSame(site_url('dashboard'), $links->item(0)->getAttribute('href'));
        self::assertStringContainsString('Shopee Food', $links->item(0)->textContent);
        self::assertSame(site_url('marketplace/dashboard'), $links->item(1)->getAttribute('href'));
        self::assertStringContainsString('Marketplace', $links->item(1)->textContent);
        self::assertCount(0, $xpath->query('//div[@class="content"]//form'));
        self::assertStringNotContainsString('name="spreadsheet_url"', $result->getBody());
        $this->withSession(['user_id' => $this->viewerId])->get('/')->assertRedirectTo('/dashboard');
    }

    public function testDashboardsAndHistoryContainOnlyTheirModuleData(): void
    {
        foreach (['/dashboard', '/admin/uploads'] as $path) {
            $result = $this->withSession(['user_id' => $this->adminId])->get($path);
            $result->assertOK();
            self::assertStringContainsString('Food-only-report.xlsx', $result->getBody());
            self::assertStringNotContainsString('Market-only-report.xlsx', $result->getBody());
        }
        foreach (['/marketplace', '/marketplace/dashboard', '/marketplace/admin/uploads'] as $path) {
            $result = $this->withSession(['user_id' => $this->adminId])->get($path);
            $result->assertOK();
            self::assertStringContainsString('Market-only-report.xlsx', $result->getBody());
            self::assertStringNotContainsString('Food-only-report.xlsx', $result->getBody());
        }
    }

    public function testMarketplaceFormsAndLinksKeepTheCurrentModule(): void
    {
        $dashboard = $this->withSession(['user_id' => $this->adminId])->get('/marketplace/dashboard?tab=agents');
        foreach (['/marketplace/dashboard', '/marketplace/admin/uploads', '/marketplace/admin/agents/' . $this->responses['marketplace']] as $url) {
            self::assertStringContainsString($url, $dashboard->getBody());
        }
        self::assertStringNotContainsString('action="http://example.com/dashboard"', $dashboard->getBody());
        $uploads = $this->withSession(['user_id' => $this->adminId])->get('/marketplace/admin/uploads');
        foreach (['/marketplace/admin/uploads', '/marketplace/admin/uploads/' . $this->reports['marketplace'] . '/archive'] as $url) {
            self::assertStringContainsString($url, $uploads->getBody());
        }
        $settings = $this->withSession(['user_id' => $this->adminId])->get('/marketplace/admin/sheets');
        $settings->assertOK();
        self::assertStringContainsString('/marketplace/admin/sheets', $settings->getBody());
        self::assertStringContainsString('aria-label="Google Sheets modules"', $settings->getBody());
        foreach (['/admin/sheets', '/marketplace/admin/sheets'] as $url) {
            self::assertStringContainsString($url, $settings->getBody());
        }
        self::assertStringNotContainsString(config('GoogleSheets')->defaultSpreadsheetId, $settings->getBody());
    }

    public function testReportSectionsRenderSeparatelyAndPreserveFiltersInBothModules(): void
    {
        foreach (['shopee-food' => '/dashboard', 'marketplace' => '/marketplace/dashboard'] as $module => $path) {
            $this->db->table('responses')->where('id', $this->responses[$module])->update([
                'good' => 0, 'bad' => 1, 'raw_json' => json_encode(['dsat_reason' => 'Recorded ' . $module . ' reason']),
            ]);
            $filters = ['import' => $this->reports[$module], 'team' => $module . '-team', 'from' => '2026-10-24',
                'to' => '2026-10-24', 'period' => 'weekly', 'scope' => 'all'];
            foreach (['statistics', 'dsat', 'agents'] as $tab) {
                $result = $this->withSession(['user_id' => $this->adminId])->get($path . '?' . http_build_query($filters + ['tab' => $tab]));
                $result->assertOK();
                $body = $result->getBody();
                self::assertStringContainsString('id="dashboard-filters"', $body);
                self::assertStringContainsString('data-dsat-total="1"', $body);
                foreach (['statistics' => 'chart-error', 'dsat' => 'dsat-feedback', 'agents' => 'agent-performance'] as $section => $id) {
                    self::assertSame($section === $tab, str_contains($body, 'id="' . $id . '"'), $module . ' ' . $tab . ' section ' . $section);
                }
                $document = new \DOMDocument();
                @$document->loadHTML($body);
                $xpath = new \DOMXPath($document);
                $tabs = $xpath->query('//*[@id="report-sections"]//a');
                self::assertCount(3, $tabs);
                foreach ($tabs as $link) {
                    self::assertSame(parse_url(site_url(ltrim($path, '/')), PHP_URL_PATH), parse_url($link->getAttribute('href'), PHP_URL_PATH));
                    parse_str((string) parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
                    foreach ($filters as $key => $value) { self::assertSame((string) $value, $query[$key]); }
                    self::assertContains($query['tab'], ['statistics', 'dsat', 'agents']);
                }
                self::assertSame($tab, $xpath->query('//*[@id="dashboard-filters"]//input[@name="tab"]')->item(0)->getAttribute('value'));
            }
            $default = $this->withSession(['user_id' => $this->adminId])->get($path);
            self::assertStringContainsString('id="chart-error"', $default->getBody());
            self::assertStringNotContainsString('id="dsat-feedback"', $default->getBody());
            self::assertStringNotContainsString('id="agent-performance"', $default->getBody());
        }
    }

    public function testViewersKeepTheirOwnResponsesAcrossAllReportSections(): void
    {
        foreach (['shopee-food' => '/dashboard', 'marketplace' => '/marketplace/dashboard'] as $module => $path) {
            $this->db->table('responses')->where('id', $this->responses[$module])->update([
                'good' => 0, 'bad' => 1, 'raw_json' => json_encode(['customer_comment' => 'Owned feedback ' . $module]),
            ]);
            $foreign = $this->db->table('responses')->where('id', $this->responses[$module])->get()->getRowArray();
            unset($foreign['id']);
            $foreign = array_replace($foreign, ['csat_id' => 'private-survey', 'agent_name' => 'Private Agent ' . $module,
                'agent_email' => 'other@example.test', 'raw_json' => json_encode(['customer_comment' => 'Private feedback ' . $module])]);
            $this->db->table('responses')->insert($foreign);
            foreach (['statistics', 'dsat', 'agents'] as $tab) {
                $result = $this->withSession(['user_id' => $this->viewerId])->get($path . '?tab=' . $tab);
                $result->assertOK();
                self::assertStringContainsString('data-response-count="1"', $result->getBody());
                self::assertStringNotContainsString('Private Agent', $result->getBody());
                self::assertStringNotContainsString('Private feedback', $result->getBody());
                self::assertStringNotContainsString('name="agent"', $result->getBody());
                if ($tab === 'dsat') { self::assertStringContainsString('Owned feedback ' . $module, $result->getBody()); }
                if ($tab === 'agents') { self::assertStringContainsString($module . '-agent', $result->getBody()); }
            }
        }
    }

    public function testReportPaginationKeepsItsSectionModuleAndFilters(): void
    {
        foreach (['shopee-food' => '/dashboard', 'marketplace' => '/marketplace/dashboard'] as $module => $path) {
            $template = $this->db->table('responses')->where('id', $this->responses[$module])->get()->getRowArray();
            unset($template['id']);
            for ($i = 1; $i <= 51; $i++) {
                $this->db->table('responses')->insert(array_replace($template, [
                    'source_row' => $i + 2, 'agent_name' => sprintf('Pagination Agent %03d', $i), 'csat_id' => 'paging-' . $i,
                    'good' => 0, 'bad' => 1, 'raw_json' => json_encode(['customer_comment' => 'Paging feedback ' . $i]),
                ]));
            }
            $filters = ['import' => $this->reports[$module], 'agent' => 'Pagination', 'period' => 'monthly',
                'team' => $module . '-team', 'scope' => 'included'];
            foreach (['dsat' => 'DSAT feedback pages', 'agents' => 'Agent performance pages'] as $tab => $label) {
                $result = $this->withSession(['user_id' => $this->adminId])->get($path . '?' . http_build_query($filters + ['tab' => $tab]));
                $result->assertOK();
                $document = new \DOMDocument();
                @$document->loadHTML($result->getBody());
                $xpath = new \DOMXPath($document);
                $next = $xpath->query('//nav[@aria-label="' . $label . '"]//a[@rel="next"]');
                self::assertCount(1, $next);
                $href = $next->item(0)->getAttribute('href');
                self::assertSame(parse_url(site_url(ltrim($path, '/')), PHP_URL_PATH), parse_url($href, PHP_URL_PATH));
                parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
                foreach ($filters as $key => $value) { self::assertSame((string) $value, $query[$key]); }
                self::assertSame($tab, $query['tab']);
                self::assertSame('2', $query[$tab === 'dsat' ? 'dsat_page' : 'page']);
                $nextPage = $this->withSession(['user_id' => $this->adminId])->get($path . '?' . http_build_query($query));
                $nextPage->assertOK();
                self::assertStringContainsString('id="' . ($tab === 'dsat' ? 'dsat-feedback' : 'agent-performance') . '"', $nextPage->getBody());
                unset($query['tab']);
                $legacy = $this->withSession(['user_id' => $this->adminId])->get($path . '?' . http_build_query($query));
                $legacy->assertOK();
                self::assertStringContainsString('id="' . ($tab === 'dsat' ? 'dsat-feedback' : 'agent-performance') . '"', $legacy->getBody());
            }
        }
    }

    public function testOtherModuleReportAndAgentIdsCannotBeOpened(): void
    {
        foreach ([
            '/marketplace/dashboard?import=' . $this->reports['shopee-food'],
            '/dashboard?import=' . $this->reports['marketplace'],
            '/marketplace/admin/agents/' . $this->responses['shopee-food'],
            '/admin/agents/' . $this->responses['marketplace'],
        ] as $path) {
            $this->assertMissing(fn () => $this->withSession(['user_id' => $this->adminId])->get($path));
        }
        $this->withSession(['user_id' => $this->adminId])->get('/marketplace/admin/agents/' . $this->responses['marketplace'])->assertOK();
    }

    public function testOtherModuleIdsCannotBeArchivedRestoredOrDeleted(): void
    {
        foreach (['archive', 'restore', 'delete'] as $action) {
            $this->assertMissing(fn () => $this->postAs($this->adminId, '/marketplace/admin/uploads/' . $this->reports['shopee-food'] . '/' . $action));
            $this->assertMissing(fn () => $this->postAs($this->adminId, '/admin/uploads/' . $this->reports['marketplace'] . '/' . $action));
        }
        self::assertSame(2, $this->db->table('imports')->where('archived', 0)->countAllResults());
        self::assertSame(2, $this->db->table('responses')->countAllResults());
        $this->postAs($this->adminId, '/marketplace/admin/uploads/' . $this->reports['marketplace'] . '/archive')->assertRedirectTo('/marketplace/admin/uploads');
        self::assertSame(1, (int) $this->db->table('imports')->where('id', $this->reports['marketplace'])->get()->getRowArray()['archived']);
        self::assertSame(0, (int) $this->db->table('imports')->where('id', $this->reports['shopee-food'])->get()->getRowArray()['archived']);
    }

    public function testMarketplaceSettingsRemainAdministratorOnly(): void
    {
        foreach (['/marketplace/admin/sheets', '/marketplace/admin/uploads', '/marketplace/admin/agents/' . $this->responses['marketplace']] as $path) {
            $this->withSession(['user_id' => $this->viewerId])->get($path)->assertStatus(403);
            $this->withSession([])->get($path)->assertRedirectTo('/login');
        }
        foreach (['/marketplace/admin/sheets', '/marketplace/admin/sheets/refresh', '/marketplace/admin/uploads'] as $path) {
            $this->postAs($this->viewerId, $path)->assertStatus(403);
            $this->postAs(null, $path)->assertRedirectTo('/login');
        }
    }

    public function testGoogleSheetsFormsSaveAndUpdateEachModuleIndependently(): void
    {
        $food = new GoogleSheetsSync($this->db);
        $food->configure(['spreadsheet_id' => str_repeat('f', 25), 'enabled' => 0], $this->adminId);
        $foodOriginal = $food->status()['source'];
        $settings = ['spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/' . str_repeat('m', 25) . '/edit#gid=26',
            'enabled' => '0', 'refresh_minutes' => '30', 'reason_column' => 'Market rating reason',
            'comment_column' => 'Market feedback', 'case_column' => 'Market case'];
        $this->postAs($this->adminId, '/marketplace/admin/sheets', $settings)->assertRedirectTo('/marketplace/admin/sheets');
        $market = new GoogleSheetsSync($this->db, null, null, 'marketplace');
        $source = $market->status()['source'];
        self::assertSame(str_repeat('m', 25), $source['spreadsheet_id']);
        self::assertSame('26', $source['sheet_gid']);
        self::assertSame(30, $source['refresh_minutes']);
        self::assertSame('Market rating reason', $source['reason_column']);
        self::assertSame('Market feedback', $source['comment_column']);
        self::assertSame('Market case', $source['case_column']);
        $settings['spreadsheet_url'] = 'https://docs.google.com/spreadsheets/d/' . str_repeat('n', 25) . '/edit#gid=27';
        $settings['refresh_minutes'] = '60';
        $this->postAs($this->adminId, '/marketplace/admin/sheets', $settings)->assertRedirectTo('/marketplace/admin/sheets');
        self::assertSame(str_repeat('n', 25), $market->status()['source']['spreadsheet_id']);
        self::assertSame(60, $market->status()['source']['refresh_minutes']);
        self::assertSame($foodOriginal, $food->status()['source']);
        $marketSaved = $market->status()['source'];
        $foodSettings = array_replace($settings, [
            'spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/' . str_repeat('s', 25) . '/edit#gid=28',
            'refresh_minutes' => '45', 'reason_column' => 'Food reason', 'comment_column' => 'Food feedback',
            'case_column' => 'Food case',
        ]);
        $this->postAs($this->adminId, '/admin/sheets', $foodSettings)->assertRedirectTo('/admin/sheets');
        $foodSaved = $food->status()['source'];
        self::assertSame(str_repeat('s', 25), $foodSaved['spreadsheet_id']);
        self::assertSame('28', $foodSaved['sheet_gid']);
        self::assertSame(45, $foodSaved['refresh_minutes']);
        self::assertSame('Food reason', $foodSaved['reason_column']);
        self::assertSame('Food feedback', $foodSaved['comment_column']);
        self::assertSame('Food case', $foodSaved['case_column']);
        self::assertSame($marketSaved, $market->status()['source']);
        foreach (['https://invalid.example/sheet', ['unexpected']] as $badUrl) {
            $this->postAs($this->adminId, '/marketplace/admin/sheets', array_replace($settings, ['spreadsheet_url' => $badUrl]))->assertRedirectTo('/marketplace/admin/sheets');
            $this->postAs($this->adminId, '/admin/sheets', array_replace($foodSettings, ['spreadsheet_url' => $badUrl]))->assertRedirectTo('/admin/sheets');
            self::assertSame($marketSaved, $market->status()['source']);
            self::assertSame($foodSaved, $food->status()['source']);
        }
        $this->postAs($this->adminId, '/marketplace/admin/sheets/refresh')->assertRedirectTo('/marketplace/admin/sheets');
        self::assertNull($market->status()['source']['last_attempt_at']);
        $this->withSession(['user_id' => $this->adminId])->get('/marketplace/dashboard?from=invalid')->assertRedirectTo('/marketplace/dashboard');
    }

    public function testMarketplaceAutomaticRefreshDoesNotUseFoodSource(): void
    {
        (new GoogleSheetsSync($this->db))->configure(['enabled' => 0], $this->adminId);
        $result = $this->postAs($this->viewerId, '/marketplace/sheets/refresh');
        $result->assertOK();
        self::assertSame('not_configured', json_decode($result->getJSON(), true)['state']);
        (new GoogleSheetsSync($this->db, null, null, 'marketplace'))->configure(['spreadsheet_id' => str_repeat('m', 25), 'enabled' => 0], $this->adminId);
        $result = $this->postAs($this->viewerId, '/marketplace/sheets/refresh');
        self::assertSame('disabled', json_decode($result->getJSON(), true)['state']);
    }
}
