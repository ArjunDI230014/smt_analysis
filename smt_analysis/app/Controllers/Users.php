<?php

namespace App\Controllers;

use App\Models\UserModel;

class Users extends BaseController
{
    public function index()
    {
        return view('users/index', ['title' => 'Manage users', 'users' => (new UserModel())->select('id, name, email, role, active, created_at')->orderBy('id', 'DESC')->findAll()]);
    }

    public function store()
    {
        $data = [
            'name' => trim((string) $this->request->getPost('name')),
            'email' => strtolower(trim((string) $this->request->getPost('email'))),
            'password' => (string) $this->request->getPost('password'),
            'role' => (string) $this->request->getPost('role'),
        ];
        if (!$this->validateData($data, [
            'name' => 'required|max_length[120]',
            'email' => 'required|valid_email|max_length[190]|is_unique[users.email]',
            'password' => 'required|min_length[12]|max_length[72]',
            'role' => 'required|in_list[admin,user]',
        ])) {
            return redirect()->back()->with('error', implode(' ', $this->validator->getErrors()));
        }
        $data['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
        unset($data['password']);
        $data['active'] = 1;
        $data['created_at'] = date('Y-m-d H:i:s');
        (new UserModel())->insert($data);
        return redirect()->to(site_url('admin/users'))->with('success', 'Account created. Share its credentials privately with the user.');
    }

    public function toggle(int $id)
    {
        if ($id === (int) session('user_id')) {
            return redirect()->back()->with('error', 'You cannot deactivate your own account.');
        }
        $model = new UserModel();
        $user = $model->find($id);
        if (!$user) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }
        $model->update($id, ['active' => (int) !$user['active']]);
        return redirect()->back()->with('success', 'Account access updated.');
    }
}
