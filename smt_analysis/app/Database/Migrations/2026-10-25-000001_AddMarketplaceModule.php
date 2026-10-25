<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddMarketplaceModule extends Migration
{
    public function up()
    {
        // Existing reports and the original source (id 1) remain Shopee Food.
        $this->forge->addColumn('imports', [
            'module' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'shopee-food'],
        ]);
        // Detect the actual index name, including databases created from mysql.sql.
        foreach ($this->db->getIndexData('imports') as $name => $index) {
            if ($index->fields === ['file_hash'] && $index->type === 'UNIQUE') {
                $this->forge->dropKey('imports', $name, false);
            }
        }
        $this->forge->addUniqueKey(['module', 'file_hash']);
        $this->forge->processIndexes('imports');
    }

    public function down()
    {
        // Reversing this feature removes its new module's reports; original Shopee
        // Food reports and the original source configuration are retained.
        $ids = array_column($this->db->table('imports')->select('id')->where('module', 'marketplace')->get()->getResultArray(), 'id');
        if ($ids !== []) {
            $this->db->table('responses')->whereIn('import_id', $ids)->delete();
            $this->db->table('imports')->whereIn('id', $ids)->delete();
        }
        $this->db->table('sheet_sources')->where('id', 2)->delete();
        foreach ($this->db->getIndexData('imports') as $name => $index) {
            if ($index->fields === ['module', 'file_hash'] && $index->type === 'UNIQUE') {
                $this->forge->dropKey('imports', $name, false);
            }
        }
        $this->forge->dropColumn('imports', 'module');
        $this->forge->addUniqueKey('file_hash');
        $this->forge->processIndexes('imports');
    }
}
