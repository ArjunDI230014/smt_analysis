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
        $role = is_string($input['role'] ?? null) ? $input['role'] : 'all';
        if (!in_array($role, ['all', 'admin', 'user'], true)) {
            $role = 'all';
        }
        $db = db_connect();
        $counts = $db->table('users')->select('COUNT(*) AS total,
            SUM(CASE WHEN archived = 0 AND active = 1 THEN 1 ELSE 0 END) AS active,
            SUM(CASE WHEN archived = 0 AND active = 0 THEN 1 ELSE 0 END) AS inactive,
            SUM(CASE WHEN archived = 1 THEN 1 ELSE 0 END) AS archived,
            SUM(CASE WHEN role = \'admin\' THEN 1 ELSE 0 END) AS admins', false)->get()->getRowArray();
        $stats = array_map('intval', $counts);
        $query = $db->table('users');
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
        if ($role !== 'all') {
            $query->where('role', $role);
        }
        $total = (clone $query)->countAllResults();
        $perPage = 20;
        $pages = max(1, (int) ceil($total / $perPage));
        $requestedPage = is_scalar($input['page'] ?? 1) ? filter_var($input['page'] ?? 1, FILTER_VALIDATE_INT) : 1;
        $page = max(1, min($pages, $requestedPage ?: 1));
        $users = $query->select('id, name, email, role, active, archived, created_at')->orderBy('name')->orderBy('id')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();
        $form = session()->getFlashdata('user_form');
        $recoveryUser = null;
        if (is_array($form) && ($form['mode'] ?? null) === 'edit' && !in_array((int) ($form['id'] ?? 0), array_map('intval', array_column($users, 'id')), true)) {
            $recoveryUser = (new UserModel())->select('id, name, email, role, active, archived, created_at')->find((int) ($form['id'] ?? 0));
        }
        return view('users/index', array_merge(compact('users', 'search', 'status', 'role', 'stats', 'total', 'page', 'pages', 'perPage', 'recoveryUser'), ['title' => 'Manage users']));
    }

    public function store()
    {
        $data = [
            'name' => trim($this->postString('name')),
            'email' => strtolower(trim($this->postString('email'))),
            'password' => $this->postString('password'),
            'role' => $this->postString('role'),
        ];
        if (!$this->validateData($data, [
            'name' => 'required|max_length[120]',
            'email' => 'required|valid_email|max_length[190]|is_unique[users.email]',
            'password' => 'required|min_length[12]|max_length[72]',
            'role' => 'required|in_list[admin,user]',
        ])) {
            return $this->formError('create', $data, $this->validator->getErrors());
        }
        if (strlen($data['password']) < 12 || strlen($data['password']) > 72) {
            return $this->formError('create', $data, ['password' => 'Use a password containing 12–72 bytes.']);
        }
        $data['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
        unset($data['password']);
        $data['active'] = 1;
        $data['created_at'] = date('Y-m-d H:i:s');
        (new UserModel())->insert($data);
        return redirect()->to(site_url('admin/users'))->with('success', 'Account created. Share its credentials privately with the user.');
    }

    public function update(int $id)
    {
        $this->findUser($id);
        $data = [
            'name' => trim($this->postString('name')),
            'email' => strtolower(trim($this->postString('email'))),
            'role' => $this->postString('role'),
        ];
        if (!$this->validateData($data, [
            'name' => 'required|max_length[120]',
            'email' => 'required|valid_email|max_length[190]|is_unique[users.email,id,' . $id . ']',
            'role' => 'required|in_list[admin,user]',
        ])) {
            return $this->formError('edit', $data, $this->validator->getErrors(), $id);
        }
        if ($id === (int) session('user_id') && $data['role'] !== 'admin') {
            return $this->formError('edit', $data, ['role' => 'You cannot remove your own administrator access.'], $id);
        }
        (new UserModel())->update($id, $data);
        return redirect()->to(site_url('admin/users'))->with('success', 'Account details updated.');
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
        $password = $this->postString('password');
        if (strlen($password) < 12 || strlen($password) > 72
            || $password !== $this->postString('password_confirmation')) {
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

    private function postString(string $key): string
    {
        $value = $this->request->getPost($key);
        return is_scalar($value) ? (string) $value : '';
    }

    private function formError(string $mode, array $data, array $errors, ?int $id = null)
    {
        // Keep only non-secret fields so a rejected form can be corrected safely.
        $values = array_intersect_key($data, array_flip(['name', 'email', 'role']));
        return redirect()->to(site_url('admin/users'))
            ->with('error', implode(' ', $errors))
            ->with('user_form', compact('mode', 'id', 'values', 'errors'));
    }
}
