<?php

namespace App\Filters;

use App\Models\UserModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $id = session()->get('user_id');
        $user = $id ? (new UserModel())->find($id) : null;
        if (!$user || !(int) $user['active'] || (int) $user['archived']) {
            session()->remove(['user_id', 'user']);
            return redirect()->to(site_url('login'));
        }
        unset($user['password_hash']);
        session()->set('user', $user);
        if (in_array('admin', $arguments ?? [], true) && $user['role'] !== 'admin') {
            return service('response')->setStatusCode(403)->setBody('Administrator access is required.');
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $response->setHeader('Cache-Control', 'no-store, private');
    }
}
