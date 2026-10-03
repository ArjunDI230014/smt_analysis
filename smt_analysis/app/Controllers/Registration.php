<?php
namespace App\Controllers;

class Registration extends BaseController
{
    public function index()
    {
        return view('auth/register', ['title' => 'Create account']);
    }

    public function complete()
    {
        $email = strtolower(trim((string) $this->request->getPost('email')));
        $throttler = service('throttler');
        if (!$throttler->check('register-ip-' . hash('sha256', $this->request->getIPAddress()), 10, 300)
            || !$throttler->check('register-email-' . hash('sha256', $email), 3, 900)) {
            return redirect()->to(site_url('register'))->with('error', 'Too many attempts. Please try again later.');
        }
        $password = (string) $this->request->getPost('password');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            return redirect()->to(site_url('register'))->with('error', 'Enter a valid agent email.');
        }
        if (strlen($password) < 12 || strlen($password) > 72 || $password !== (string) $this->request->getPost('password_confirmation')) {
            return redirect()->to(site_url('register'))->with('error', 'Passwords must match and contain 12–72 bytes.');
        }
        $db = db_connect();
        $identity = $db->table('agent_roster')->where('email', $email)->get()->getRowArray();
        if (!$identity) {
            return redirect()->to(site_url('register'))->with('error', 'This email is not listed in the imported agent sheet. Contact your administrator.');
        }
        if ($db->table('users')->where('LOWER(email)', $email)->countAllResults()) {
            return redirect()->to(site_url('login'))->with('error', 'An account already exists for this email. Sign in or contact your administrator.');
        }
        // The unique email constraint also blocks simultaneous registration requests.
        try {
            $saved = $db->table('users')->insert([
                'name' => $identity['name'], 'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => 'user', 'active' => 1, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            if (!$saved) {
                throw new \RuntimeException('Registration insert failed.');
            }
        } catch (\Throwable $e) {
            return redirect()->to(site_url('login'))->with('error', 'Account could not be created. If you already have an account, sign in; otherwise contact your administrator.');
        }
        return redirect()->to(site_url('login'))->with('success', 'Your account is ready. Sign in with your email and password.');
    }
}
