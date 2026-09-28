<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCsatTables extends Migration
{
    public function up()
    {
        $id = ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true];
        $this->forge->addField([
            'id' => $id,
            'name' => ['type' => 'VARCHAR', 'constraint' => 120],
            'email' => ['type' => 'VARCHAR', 'constraint' => 190],
            'password_hash' => ['type' => 'VARCHAR', 'constraint' => 255],
            'role' => ['type' => 'VARCHAR', 'constraint' => 10],
            'active' => ['type' => 'INT', 'default' => 1],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('email');
        $this->forge->createTable('users', true);

        $this->forge->addField([
            'id' => $id,
            'filename' => ['type' => 'VARCHAR', 'constraint' => 255],
            'file_hash' => ['type' => 'VARCHAR', 'constraint' => 64],
            'uploaded_by' => ['type' => 'INT', 'unsigned' => true],
            'row_count' => ['type' => 'INT'],
            'date_from' => ['type' => 'DATE'],
            'date_to' => ['type' => 'DATE'],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('file_hash');
        $this->forge->addForeignKey('uploaded_by', 'users', 'id');
        $this->forge->createTable('imports', true);

        $this->forge->addField([
            'id' => $id,
            'import_id' => ['type' => 'INT', 'unsigned' => true],
            'source_row' => ['type' => 'INT'],
            'response_date' => ['type' => 'DATE'],
            'team' => ['type' => 'VARCHAR', 'constraint' => 120],
            'agent_name' => ['type' => 'VARCHAR', 'constraint' => 255],
            'channel' => ['type' => 'VARCHAR', 'constraint' => 120],
            'category' => ['type' => 'VARCHAR', 'constraint' => 255],
            'csat_id' => ['type' => 'VARCHAR', 'constraint' => 100],
            'good' => ['type' => 'INT'],
            'bad' => ['type' => 'INT'],
            'is_excluded' => ['type' => 'INT'],
            'handling_seconds' => ['type' => 'DOUBLE', 'null' => true],
            'raw_json' => ['type' => 'TEXT'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['import_id', 'response_date']);
        $this->forge->addUniqueKey(['import_id', 'csat_id']);
        $this->forge->addForeignKey('import_id', 'imports', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('responses', true);
    }

    public function down()
    {
        $this->forge->dropTable('responses');
        $this->forge->dropTable('imports');
        $this->forge->dropTable('users');
    }
}
