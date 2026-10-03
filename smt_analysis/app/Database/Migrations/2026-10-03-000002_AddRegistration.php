<?php

namespace App\Database\Migrations;

use App\Libraries\AgentRoster;
use CodeIgniter\Database\Migration;

class AddRegistration extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'email' => ['type' => 'VARCHAR', 'constraint' => 190],
            'name' => ['type' => 'VARCHAR', 'constraint' => 120],
        ]);
        $this->forge->addKey('email', true);
        $this->forge->createTable('agent_roster');
        $this->forge->addField([
            'token_hash' => ['type' => 'VARCHAR', 'constraint' => 64],
            'email' => ['type' => 'VARCHAR', 'constraint' => 190],
            'expires_at' => ['type' => 'INT'],
        ]);
        $this->forge->addKey('token_hash', true);
        $this->forge->addKey('email');
        $this->forge->createTable('registration_tokens');
        // Existing uploads retain the original email column in raw_json.
        $query = $this->db->table('responses')->select('raw_json')->orderBy('id')->get();
        while ($row = $query->getUnbufferedRow('array')) {
            try {
                $identity = AgentRoster::identity(json_decode($row['raw_json'], true) ?: []);
                if ($identity) {
                    AgentRoster::remember($this->db, $identity);
                }
            } catch (\DomainException $e) {
                // Invalid historical identities never become eligible.
            }
        }
    }

    public function down()
    {
        $this->forge->dropTable('registration_tokens');
        $this->forge->dropTable('agent_roster');
    }
}
