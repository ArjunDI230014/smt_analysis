<?php
namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddUserArchive extends Migration
{
    public function up()
    {
        $this->forge->addColumn('users', ['archived' => ['type' => 'INT', 'default' => 0]]);
    }

    public function down()
    {
        $this->forge->dropColumn('users', 'archived');
    }
}
