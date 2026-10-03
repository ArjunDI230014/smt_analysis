<?php

namespace Tests\Database;

use App\Libraries\AgentRoster;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

final class RegistrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    protected $namespace = 'App';
    protected $refresh = true;

    private function submit(array $data)
    {
        $data[csrf_token()] = csrf_hash();
        $data['email'] ??= 'agent@example.test';
        service('cache')->clean();
        return $this->post('register', $data);
    }


    public function testRosterNormalizesEmailAndDoesNotDuplicate(): void
    {
        $identity = AgentRoster::identity(['agent_name' => 'Agent One', 'email' => ' AGENT@Example.test ']);
        self::assertSame('agent@example.test', $identity['email']);
        AgentRoster::remember($this->db, $identity);
        AgentRoster::remember($this->db, $identity);
        self::assertSame(1, $this->db->table('agent_roster')->countAllResults());
    }

    public function testAccountUsesSheetIdentityAndCannotRegisterTwice(): void
    {
        AgentRoster::remember($this->db, ['email' => 'agent@example.test', 'name' => 'Sheet Name']);
        $data = ['password' => 'secure-password-123', 'password_confirmation' => 'secure-password-123', 'role' => 'admin', 'name' => 'Impersonator'];
        $this->submit($data)->assertRedirectTo(site_url('login'));
        $user = $this->db->table('users')->get()->getRowArray();
        self::assertSame('Sheet Name', $user['name']);
        self::assertSame('user', $user['role']);
        self::assertTrue(password_verify($data['password'], $user['password_hash']));
        self::assertSame(0, $this->db->table('registration_tokens')->countAllResults());
        $this->submit($data);
        $this->submit($data);
        self::assertSame(1, $this->db->table('users')->countAllResults());
    }

    public function testInvalidAndUnlistedEmailsCannotRegister(): void
    {
        $data = ['password' => 'secure-password-123', 'password_confirmation' => 'secure-password-123'];
        $this->submit(['email' => 'invalid'] + $data);
        $this->submit($data);
        self::assertSame(0, $this->db->table('users')->countAllResults());
    }

    public function testInactiveAccountCannotBeRegisteredAgain(): void
    {
        AgentRoster::remember($this->db, ['email' => 'agent@example.test', 'name' => 'Sheet Name']);
        $this->db->table('users')->insert(['email' => 'agent@example.test', 'name' => 'Original', 'password_hash' => 'original-hash', 'role' => 'user', 'active' => 0, 'created_at' => '2026-10-03 00:00:00']);
        $this->submit(['password' => 'secure-password-123', 'password_confirmation' => 'secure-password-123']);
        self::assertSame(1, $this->db->table('users')->countAllResults());
        self::assertSame('original-hash', $this->db->table('users')->get()->getRowArray()['password_hash']);
    }
}
