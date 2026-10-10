<?php
// Trimmed copy of the FloCMS skeleton's config/config.php

$routes = array(
    'default' => '',
    'admin' => 'admin_',
);
if (Env::get('LEGACY_API') === true) {
    $routes['api'] = 'api_';
}
$routes['login'] = 'login_';
Config::set('routes', $routes);
unset($routes);

Config::set('default_route', 'default');
Config::set('default_controller', 'pages');
Config::set('default_action', 'index');

Config::set('permissions', array(
    0 => array(),
    1 => array('content.*'),
    2 => array('content.*', 'users.manage', 'users.assign_role', 'settings.*'),
    3 => array('*'),
));
