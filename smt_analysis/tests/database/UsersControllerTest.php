<?php

namespace Tests\Database;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;

final class UsersControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $namespace = 'App';
    protected $refresh = true;
    private int $adminId;
    private int $viewerId;
    private int $inactiveId;
    private int $archivedId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = $this->account('Workspace Admin', 'admin@example.test', 'admin');
        $this->viewerId = $this->account('Active Member', 'member@example.test');
        $this->inactiveId = $this->account('Inactive Member', 'inactive@example.test', 'user', 0);
        $this->archivedId = $this->account('Archived Admin', 'archived@example.test', 'admin', 1, 1);
    }

    private function account(string $name, string $email, string $role = 'user', int $active = 1, int $archived = 0): int
    {
        $this->db->table('users')->insert([
            'name' => $name, 'email' => $email, 'role' => $role, 'active' => $active, 'archived' => $archived,
            'password_hash' => 'original-hash', 'created_at' => '2026-10-26 00:00:00',
        ]);
        return (int) $this->db->insertID();
    }

    private function postAs(?int $userId, string $path, array $data = []): TestResponse
    {
        $name = csrf_token();
        $hash = csrf_hash();
        $session = [$name => $hash];
        if ($userId !== null) { $session['user_id'] = $userId; }
        return $this->withSession($session)->post($path, $data + [$name => $hash]);
    }

    private function user(int $id): array
    {
        return $this->db->table('users')->where('id', $id)->get()->getRowArray();
    }

    private function edits(array $overrides = []): array
    {
        return $overrides + ['name' => 'Updated Member', 'email' => 'updated@example.test', 'role' => 'admin'];
    }

    public function testDirectoryCombinesRoleStatusAndAllSearchTerms(): void
    {
        $this->withSession(['user_id' => $this->adminId])->get('/admin/users?q=member%20active&status=active&role=user')->assertOK();
        $data = service('renderer')->getData();
        self::assertSame([$this->viewerId], array_map('intval', array_column($data['users'], 'id')));
        self::assertSame(1, $data['total']);
        self::assertSame('user', $data['role']);
        self::assertSame(['total' => 4, 'active' => 2, 'inactive' => 1, 'archived' => 1, 'admins' => 2], $data['stats']);

        $this->withSession(['user_id' => $this->adminId])->get('/admin/users?status=archived&role=admin')->assertOK();
        self::assertSame([$this->archivedId], array_map('intval', array_column(service('renderer')->getData()['users'], 'id')));
    }

    public function testMalformedFiltersAreIgnoredAndOutOfRangePageIsClamped(): void
    {
        $this->withSession(['user_id' => $this->adminId])->get('/admin/users?q[]=invalid&role[]=admin&status=invalid&page=999')->assertOK();
        $data = service('renderer')->getData();
        self::assertSame('', $data['search']);
        self::assertSame('all', $data['status']);
        self::assertSame('all', $data['role']);
        self::assertSame(4, $data['total']);
        self::assertSame(1, $data['page']);
    }

    public function testEditUpdatesNormalizedDetailsWithoutChangingPasswordOrAccessState(): void
    {
        $this->postAs($this->adminId, '/admin/users/' . $this->inactiveId . '/update', $this->edits([
            'name' => '  Updated Member  ', 'email' => '  UPDATED@Example.test ', 'active' => 1, 'archived' => 1,
            'password_hash' => 'injected',
        ]))->assertRedirectTo(site_url('admin/users'));
        $user = $this->user($this->inactiveId);
        self::assertSame('Updated Member', $user['name']);
        self::assertSame('updated@example.test', $user['email']);
        self::assertSame('admin', $user['role']);
        self::assertSame(0, (int) $user['active']);
        self::assertSame(0, (int) $user['archived']);
        self::assertSame('original-hash', $user['password_hash']);
    }

    public function testUnchangedEmailIsAllowedAndDuplicateEmailIsRejected(): void
    {
        $this->postAs($this->adminId, '/admin/users/' . $this->viewerId . '/update', $this->edits(['email' => 'member@example.test']))->assertSessionHas('success');
        self::assertSame('Updated Member', $this->user($this->viewerId)['name']);

        $this->postAs($this->adminId, '/admin/users/' . $this->viewerId . '/update', $this->edits(['email' => 'ADMIN@example.test']))->assertSessionHas('error');
        self::assertSame('member@example.test', $this->user($this->viewerId)['email']);
        self::assertSame('admin@example.test', $this->user($this->adminId)['email']);
    }

    public function testInvalidEditRetainsSafeCorrectionFields(): void
    {
        $this->postAs($this->adminId, '/admin/users/' . $this->viewerId . '/update', $this->edits([
            'name' => '', 'email' => 'not-an-email', 'role' => 'owner', 'password' => 'must-not-be-retained',
        ]))->assertSessionHas('error');
        $form = session()->getFlashdata('user_form');
        self::assertSame('edit', $form['mode']);
        self::assertSame($this->viewerId, $form['id']);
        self::assertSame(['name' => '', 'email' => 'not-an-email', 'role' => 'owner'], $form['values']);
        self::assertArrayHasKey('name', $form['errors']);
        self::assertArrayHasKey('email', $form['errors']);
        self::assertArrayHasKey('role', $form['errors']);
        self::assertSame('Active Member', $this->user($this->viewerId)['name']);
    }

    public function testFailedEditCanBeRecoveredWhenTargetIsOutsideCurrentPage(): void
    {
        for ($i = 0; $i < 21; $i++) {
            $this->account('A Member ' . $i, 'first-' . $i . '@example.test');
        }
        $this->postAs($this->adminId, '/admin/users/' . $this->adminId . '/update', $this->edits(['role' => 'user']))->assertSessionHas('error');
        $this->withSession()->get('/admin/users')->assertOK();
        $data = service('renderer')->getData();
        self::assertSame($this->adminId, (int) $data['recoveryUser']['id']);
        self::assertNotContains($this->adminId, array_map('intval', array_column($data['users'], 'id')));
        self::assertSame(25, $data['total']);
    }

    public function testOwnDetailsCanBeEditedButOwnAdministratorRoleCannotBeRemoved(): void
    {
        $this->postAs($this->adminId, '/admin/users/' . $this->adminId . '/update', $this->edits(['email' => 'admin@example.test']))->assertSessionHas('success');
        self::assertSame('Updated Member', $this->user($this->adminId)['name']);
        $this->postAs($this->adminId, '/admin/users/' . $this->adminId . '/update', $this->edits(['role' => 'user']))->assertSessionHas('error');
        self::assertSame('admin', $this->user($this->adminId)['role']);
        self::assertSame('admin@example.test', $this->user($this->adminId)['email']);
    }

    public function testCreateNormalizesIdentityAndNeverFlashesPasswordOnFailure(): void
    {
        $password = 'secure-password-123';
        $this->postAs($this->adminId, '/admin/users', $this->edits(['email' => ' NEW@Example.test ', 'password' => $password]))->assertSessionHas('success');
        $created = $this->db->table('users')->where('email', 'new@example.test')->get()->getRowArray();
        self::assertTrue(password_verify($password, $created['password_hash']));
        self::assertSame(1, (int) $created['active']);
        $this->postAs($this->adminId, '/admin/users', $this->edits(['email' => 'invalid', 'password' => $password]))->assertSessionHas('error');
        $form = session()->getFlashdata('user_form');
        self::assertSame('create', $form['mode']);
        self::assertArrayNotHasKey('password', $form['values']);
        self::assertSame(5, $this->db->table('users')->countAllResults());
    }

    public function testNonScalarFieldsAndOverlongPasswordBytesAreRejectedWithoutCrashing(): void
    {
        $this->postAs($this->adminId, '/admin/users/' . $this->viewerId . '/update', $this->edits(['name' => ['unexpected']]))->assertSessionHas('error');
        $this->postAs($this->adminId, '/admin/users', $this->edits(['password' => str_repeat('é', 40)]))->assertSessionHas('error');
        self::assertSame(4, $this->db->table('users')->countAllResults());
        self::assertSame('Active Member', $this->user($this->viewerId)['name']);
    }

    public function testOnlyActiveAdministratorsCanAccessDirectoryAndUpdateAccounts(): void
    {
        $this->withSession(['user_id' => $this->viewerId])->get('/admin/users')->assertStatus(403);
        $this->postAs($this->viewerId, '/admin/users/' . $this->inactiveId . '/update', $this->edits())->assertStatus(403);
        $this->postAs(null, '/admin/users/' . $this->inactiveId . '/update', $this->edits())->assertRedirectTo(site_url('login'));
        $this->postAs($this->archivedId, '/admin/users/' . $this->inactiveId . '/update', $this->edits())->assertRedirectTo(site_url('login'));
        self::assertSame('Inactive Member', $this->user($this->inactiveId)['name']);
    }

    public function testMissingEditTargetReturnsNotFound(): void
    {
        try {
            $this->postAs($this->adminId, '/admin/users/999999/update', $this->edits());
            self::fail('An unknown account must be rejected.');
        } catch (PageNotFoundException $e) {
            self::assertSame(404, $e->getCode());
        }
    }

    public function testOwnAccessProtectionsAndArchivedAccessRulesStillApply(): void
    {
        foreach (['toggle', 'archive', 'delete'] as $action) {
            $this->postAs($this->adminId, '/admin/users/' . $this->adminId . '/' . $action)->assertSessionHas('error');
        }
        $this->postAs($this->adminId, '/admin/users/' . $this->archivedId . '/toggle')->assertSessionHas('error');
        self::assertSame(1, (int) $this->user($this->adminId)['active']);
        self::assertSame(0, (int) $this->user($this->adminId)['archived']);
        self::assertSame(1, (int) $this->user($this->archivedId)['active']);
    }
}
