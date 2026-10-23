<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddSheetSources extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => ['type' => 'INT', 'unsigned' => true],
            'spreadsheet_id' => ['type' => 'VARCHAR', 'constraint' => 160],
            'sheet_gid' => ['type' => 'VARCHAR', 'constraint' => 20],
            'sheet_title' => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'enabled' => ['type' => 'INT', 'default' => 1],
            'refresh_minutes' => ['type' => 'INT', 'default' => 15],
            'reason_column' => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'comment_column' => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'case_column' => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => ''],
            'configured_by' => ['type' => 'INT', 'unsigned' => true],
            'import_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'content_hash' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'row_count' => ['type' => 'INT', 'default' => 0],
            'duplicate_count' => ['type' => 'INT', 'default' => 0],
            'last_attempt_at' => ['type' => 'DATETIME', 'null' => true],
            'last_synced_at' => ['type' => 'DATETIME', 'null' => true],
            'last_error' => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => true],
            'updated_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        // References are checked by the sync service so existing import/account deletion remains supported.
        $this->forge->createTable('sheet_sources');
    }

    public function down()
    {
        $this->forge->dropTable('sheet_sources');
    }
}
