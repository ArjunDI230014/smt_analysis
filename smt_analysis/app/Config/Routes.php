<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->setAutoRoute(false);
$routes->get('login', 'Auth::index');
$routes->post('login', 'Auth::login');
$routes->get('register', 'Registration::index');
$routes->post('register', 'Registration::complete');
$routes->post('logout', 'Auth::logout', ['filter' => 'auth']);
$routes->group('', ['filter' => 'auth'], static function ($routes) {
    $routes->get('/', 'Dashboard::index');
    $routes->get('dashboard', 'Dashboard::index');
});
$routes->group('admin', ['filter' => 'auth:admin'], static function ($routes) {
    $routes->get('uploads', 'Uploads::index');
    $routes->post('uploads', 'Uploads::store');
    $routes->post('uploads/(:num)/archive', 'Uploads::archive/$1');
    $routes->post('uploads/(:num)/restore', 'Uploads::restore/$1');
    $routes->post('uploads/(:num)/delete', 'Uploads::delete/$1');
    $routes->get('users', 'Users::index');
    $routes->post('users', 'Users::store');
    $routes->post('users/(:num)/toggle', 'Users::toggle/$1');
    $routes->post('users/(:num)/password', 'Users::password/$1');
    $routes->post('users/(:num)/archive', 'Users::archive/$1');
    $routes->post('users/(:num)/restore', 'Users::restore/$1');
    $routes->post('users/(:num)/delete', 'Users::delete/$1');
});
