<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');
$routes->match(['GET', 'POST', 'OPTIONS'], 'api', 'Api::index');
$routes->match(['GET', 'POST', 'OPTIONS'], 'api/index.php', 'Api::index');
