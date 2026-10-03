<?php

namespace App\Controllers;

use App\Models\UserModel;

class Users extends BaseController
{
    public function index()
    {
        $input = $this->request->getGet();
        $search = is_scalar($input['q'] ?? '') ? mb_substr(trim((string) ($input['q'] ?? '')), 0, 190) : '';
        $status = is_string($input['status'] ?? null) ? $input['status'] : 'all';
        if (!in_array($status, ['all', 'active', 'inactive', 'archived'], true)) {
            $status = 'all';
        }
        $query = db_connect()->table('users');
        if ($search !== '') {
            foreach (preg_split('/\s+/u', $search) as $term) {
                $query->groupStart()->like('name', $term)->orLike('email', $term)->groupEnd();
            }
        }
        if ($status === 'archived') {
            $query->where('archived', 1);
        } elseif ($status !== 'all') {
            $query->where('archived', 0)->where('active', $status === 'active' ? 1 : 0);
        }
        $total = (clone $query)->countAllResults();
        $perPage = 20;
        $pages = max(1, (int) ceil($total / $perPage));
        $requestedPage = is_scalar($input['page'] ?? 1) ? filter_var($input['page'] ?? 1, FILTER_VALIDATE_INT) : 1;
        $page = max(1, min($pages, $requestedPage ?: 1));
        $users = $query->select('id, name, email, role, active, archived, created_at')->orderBy('name')->orderBy('id')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();
        return view('users/index', array_merge(compact('users', 'search', 'status', 'total', 'page', 'pages', 'perPage'), ['title' => 'Manage users']));
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
        if ($user['archived']) {
            return redirect()->to(site_url('admin/users'))->with('error', 'Restore this account before changing its access.');
        }
        $model->update($id, ['active' => (int) !$user['active']]);
        return redirect()->back()->with('success', 'Account access updated.');
    }

    public function password(int $id)
    {
        $this->findUser($id);
        $password = (string) $this->request->getPost('password');
        if (strlen($password) < 12 || strlen($password) > 72
            || $password !== (string) $this->request->getPost('password_confirmation')) {
            return redirect()->to(site_url('admin/users'))->with('error', 'Passwords must match and contain 12–72 bytes.');
        }
        (new UserModel())->update($id, ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        return redirect()->to(site_url('admin/users'))->with('success', 'Password updated. Share the new password privately with the user.');
    }

    public function archive(int $id)
    {
        return $this->setArchived($id, 1);
    }

    public function restore(int $id)
    {
        return $this->setArchived($id, 0);
    }

    private function setArchived(int $id, int $archived)
    {
        $this->findUser($id);
        if ($id === (int) session('user_id')) {
            return redirect()->to(site_url('admin/users'))->with('error', 'You cannot archive your own account.');
        }
        (new UserModel())->update($id, ['archived' => $archived]);
        return redirect()->to(site_url('admin/users'))->with('success', $archived ? 'Account archived. Access is blocked until restored.' : 'Account restored.');
    }

    public function delete(int $id)
    {
        $this->findUser($id);
        if ($id === (int) session('user_id')) {
            return redirect()->to(site_url('admin/users'))->with('error', 'You cannot delete your own account.');
        }
        $db = db_connect();
        try {
            $db->transException(true)->transStart();
            // Preserve reports when their uploader's account is removed.
            $db->table('imports')->where('uploaded_by', $id)->update(['uploaded_by' => (int) session('user_id')]);
            $db->table('users')->where('id', $id)->delete();
            $db->transComplete();
            if (!$db->transStatus()) {
                throw new \RuntimeException('Account deletion failed.');
            }
        } catch (\Throwable $e) {
            $db->transRollback();
            return redirect()->to(site_url('admin/users'))->with('error', 'Account could not be deleted. Please try again.');
        }
        return redirect()->to(site_url('admin/users'))->with('success', 'Account permanently deleted. Imported reports were preserved.');
    }

    private function findUser(int $id): array
    {
        $user = (new UserModel())->find($id);
        if (!$user) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound('User not found.');
        }
        return $user;
    }
}
