<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->setAutoRoute(false);
$routes->get('login', 'Auth::index');
$routes->post('login', 'Auth::login');
$routes->post('logout', 'Auth::logout', ['filter' => 'auth']);
$routes->group('', ['filter' => 'auth'], static function ($routes) {
    $routes->get('/', 'Dashboard::index');
    $routes->get('dashboard', 'Dashboard::index');
});
$routes->group('admin', ['filter' => 'auth:admin'], static function ($routes) {
    $routes->get('uploads', 'Uploads::index');
    $routes->post('uploads', 'Uploads::store');
    $routes->get('users', 'Users::index');
    $routes->post('users', 'Users::store');
    $routes->post('users/(:num)/toggle', 'Users::toggle/$1');
});
