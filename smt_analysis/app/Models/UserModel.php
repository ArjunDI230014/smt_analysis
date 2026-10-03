<?php

namespace App\Models;

use CodeIgniter\Model;

class UserModel extends Model
{
    protected $table = 'users';
    protected $allowedFields = ['name', 'email', 'password_hash', 'role', 'active', 'archived', 'created_at'];
    protected $returnType = 'array';
}
