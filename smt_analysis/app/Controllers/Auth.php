<?php

namespace App\Controllers;

use App\Models\UserModel;

class Auth extends BaseController
{
    public function index()
    {
        if (session()->has('user_id')) {
            return redirect()->to(site_url('/'));
        }
        return view('auth/login', ['title' => 'Sign in']);
    }

    public function login()
    {
        $email = strtolower(trim((string) $this->request->getPost('email')));
        $ipKey = 'login-ip-' . hash('sha256', $this->request->getIPAddress());
        $emailKey = 'login-email-' . hash('sha256', $email);
        $throttler = service('throttler');
        if (!$throttler->check($ipKey, 20, 300) || !$throttler->check($emailKey, 10, 300)) {
            return redirect()->back()->with('error', 'Too many attempts. Please try again in five minutes.');
        }
        $user = (new UserModel())->where('email', $email)->first();
        $password = (string) $this->request->getPost('password');
        // Use a valid dummy hash to avoid skipping password verification for unknown accounts.
        $hash = $user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $valid = password_verify($password, $hash);
        if (!$user || !$valid || !(int) $user['active']) {
            return redirect()->back()->with('error', 'The email or password is incorrect.');
        }
        session()->regenerate(true);
        session()->set('user_id', $user['id']);
        return redirect()->to(site_url('/'));
    }

    public function logout()
    {
        session()->destroy();
        return redirect()->to(site_url('login'));
    }
}
