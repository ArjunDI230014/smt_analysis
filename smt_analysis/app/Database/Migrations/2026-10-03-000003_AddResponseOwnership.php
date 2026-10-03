<?php

namespace App\Database\Migrations;

use App\Libraries\AgentRoster;
use CodeIgniter\Database\Migration;

class AddResponseOwnership extends Migration
{
    public function up()
    {
        $this->forge->addColumn('responses', [
            'agent_email' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
        ]);
        // Backfill in batches; missing or invalid identities remain administrator-only.
        $lastId = 0;
        do {
            $rows = $this->db->table('responses')->select('id, raw_json')->where('id >', $lastId)->orderBy('id')->limit(500)->get()->getResultArray();
            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                try {
                    $raw = json_decode($row['raw_json'], true);
                    $identity = AgentRoster::identity(is_array($raw) ? $raw : []);
                    if ($identity) {
                        $this->db->table('responses')->where('id', $lastId)->update(['agent_email' => $identity['email']]);
                    }
                } catch (\DomainException $e) {
                    // Do not infer ownership from names.
                }
            }
        } while (count($rows) === 500);
    }

    public function down()
    {
        $this->forge->dropColumn('responses', 'agent_email');
    }
}
