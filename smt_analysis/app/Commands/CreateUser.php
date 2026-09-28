<?php

namespace App\Commands;

use App\Models\UserModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class CreateUser extends BaseCommand
{
    protected $group = 'CSAT';
    protected $name = 'csat:user';
    protected $description = 'Create an administrator or viewer account interactively.';
    protected $options = ['--if-empty' => 'Only prompt when no accounts exist.'];

    public function run(array $params)
    {
        if (CLI::getOption('if-empty') && (new UserModel())->countAllResults() > 0) {
            return;
        }
        $data = [
            'name' => trim(CLI::prompt('Full name', null, 'required|max_length[120]')),
            'email' => strtolower(trim(CLI::prompt('Email', null, 'required|valid_email|max_length[190]|is_unique[users.email]'))),
            'role' => CLI::prompt('Role', ['admin', 'user']),
        ];
        CLI::write('Choose a password of 12–72 characters.');
        $password = CLI::prompt('Password', null, 'required|min_length[12]|max_length[72]');
        $data['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        $data['active'] = 1;
        $data['created_at'] = date('Y-m-d H:i:s');
        (new UserModel())->insert($data);
        CLI::write('Account created. You can now sign in.', 'green');
    }
}
