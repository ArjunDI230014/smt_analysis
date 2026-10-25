<?php

namespace App\Controllers;

use App\Libraries\Modules;

class Home extends BaseController
{
    public function index()
    {
        if (session('user')['role'] !== 'admin') {
            return redirect()->to(site_url('dashboard'));
        }
        $modules = [];
        foreach (Modules::all() as $key => $label) {
            $modules[] = compact('key', 'label');
        }
        return view('home/index', ['title' => 'Choose a module', 'modules' => $modules]);
    }
}
