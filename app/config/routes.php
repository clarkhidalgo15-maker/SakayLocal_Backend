<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
/**
 * ------------------------------------------------------------------
 * LavaLust - an opensource lightweight PHP MVC Framework
 * ------------------------------------------------------------------
 *
 * MIT License
 *
 * Copyright (c) 2020 Ronald M. Marasigan
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @package LavaLust
 * @author Ronald M. Marasigan <ronald.marasigan@yahoo.com>
 * @since Version 1
 * @link https://github.com/ronmarasigan/LavaLust
 * @license https://opensource.org/licenses/MIT MIT License
 */

/*
| -------------------------------------------------------------------
| URI ROUTING
| -------------------------------------------------------------------
| Here is where you can register web routes for your application.
|
|
*/
/** @var object $router **/

$router->get('/', 'Welcome::index');
$router->get('/api/health', 'PublicApiController::health');
$router->options('/api/auth/login', 'ApiController::cors');
$router->options('/api/reports', 'ApiController::cors');
$router->post('/api/auth/login', 'AuthController::login');
$router->options('/api/auth/register', 'ApiController::cors');
$router->post('/api/auth/register', 'AuthController::register');
$router->options('/api/account', 'ApiController::cors');
$router->options('/api/account/reports', 'ApiController::cors');
$router->get('/api/account', 'AccountController::account');
$router->get('/api/account/reports', 'AccountController::reports');
$router->post('/api/account', 'AccountController::account');
$router->get('/api/notifications/run', 'NotificationController::run');
$router->get('/api/terminals', 'PublicApiController::terminals');
$router->get('/api/routes', 'PublicApiController::routes');
$router->get('/api/areas', 'PublicApiController::areas');
$router->get('/api/advisories', 'PublicApiController::advisories');
$router->post('/api/reports', 'ReportController::submit_report');
$router->get('/api/report', 'ReportController::report');
$router->get('/api/reports/(:any)', 'ReportController::report');
$router->options('/api/admin/reports', 'ApiController::cors');
$router->options('/api/admin/terminals', 'ApiController::cors');
$router->options('/api/admin/routes', 'ApiController::cors');
$router->options('/api/admin/areas', 'ApiController::cors');
$router->options('/api/admin/users', 'ApiController::cors');
$router->any('/api/admin/reports', 'AdminController::admin_reports');
$router->any('/api/admin/terminals', 'AdminController::admin_terminals');
$router->delete('/api/admin/terminals', 'AdminController::delete_terminal');
$router->any('/api/admin/routes', 'AdminController::admin_routes');
$router->delete('/api/admin/routes', 'AdminController::delete_route');
$router->any('/api/admin/areas', 'AdminController::admin_areas');
$router->any('/api/admin/users', 'AdminController::admin_users');
$router->delete('/api/admin/users', 'AdminController::delete_user');
$router->options('/api/admin/fares', 'ApiController::cors');
$router->options('/api/admin/schedules', 'ApiController::cors');
$router->options('/api/admin/pickup-points', 'ApiController::cors');
$router->options('/api/admin/advisories', 'ApiController::cors');
$router->any('/api/admin/fares', 'AdminController::admin_fares');
$router->any('/api/admin/schedules', 'AdminController::admin_schedules');
$router->any('/api/admin/pickup-points', 'AdminController::admin_pickup_points');
$router->any('/api/admin/advisories', 'AdminController::admin_advisories');