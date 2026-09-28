<?php

namespace Tests\Support\Database\Seeds;

use CodeIgniter\Database\Seeder;

/** Explicitly isolated smoke-test accounts; never seeds the application database. */
class CsatSmokeSeeder extends Seeder
{
    public function run()
    {
        if (!str_ends_with(str_replace('\\', '/', $this->db->database), '/csat-smoke.sqlite')) {
            throw new \RuntimeException('Smoke fixtures require an isolated csat-smoke.sqlite database.');
        }
        foreach (['admin', 'user'] as $role) {
            $email = $role . '@example.test';
            if (!$this->db->table('users')->where('email', $email)->countAllResults()) {
                $this->db->table('users')->insert([
                    'name' => 'Preview ' . ucfirst($role), 'email' => $email,
                    'password_hash' => password_hash('Preview-only-test-123!', PASSWORD_DEFAULT),
                    'role' => $role, 'active' => 1, 'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }
}
