<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddImportArchive extends Migration
{
    public function up()
    {
        $this->forge->addColumn('imports', ['archived' => ['type' => 'INT', 'default' => 0]]);
    }

    public function down()
    {
        $this->forge->dropColumn('imports', 'archived');
    }
}
