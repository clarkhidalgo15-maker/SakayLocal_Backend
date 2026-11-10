<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once __DIR__ . '/ApiController.php';

class AdminController extends ApiController
{
    public function admin_dashboard()
    {
        $user = $this->requireAdmin();
        $this->database();
        $this->call->model('Passenger_report_model');
        $city = $user['role'] === 'staff' ? ($user['assigned_city'] ?? '') : null;
        $this->respond(['data' => $this->Passenger_report_model->getDashboardSummary($city)]);
    }

    public function admin_reports()
            {
                $user = $this->requireAdmin();
                $db = $this->database();
                if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                    $this->call->model('Passenger_report_model');
                    $city = $user['role'] === 'staff' ? ($user['assigned_city'] ?? '') : null;
                    $this->respond(['data' => $this->Passenger_report_model->getAdminList($city)]);
                }
                $data = $this->input();
                $id = (int) ($_GET['id'] ?? $data['id'] ?? 0);
                $this->assertReportAccess($db, $user, $id);
                $this->required($data, ['status']);
                $allowed = ['submitted', 'under_review', 'in_progress', 'resolved'];
                if (!in_array($data['status'], $allowed, true) || $id < 1) {
                    $this->respond(['error' => 'Invalid report update.'], 422);
                }
                $this->call->model('Passenger_report_model');
                if (!$this->Passenger_report_model->updateStatusWithHistory($id, $data['status'], $data['admin_remarks'] ?? null)) {
                    $this->respond(['error' => 'Report not found.'], 404);
                }
                $this->respond(['data' => ['id' => $id, 'status' => $data['status']]]);
            }

    public function admin_terminals()
            {
                $user = $this->requireAdmin();
                $db = $this->database();
                if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                    $this->call->model('Terminal_model');
                    $city = $user['role'] === 'staff' ? ($user['assigned_city'] ?? '') : null;
                    $this->respond($this->pageMeta($this->Terminal_model->getAdminList($city)));
                }
                $data = $this->input();
                if (($data['action'] ?? '') === 'permanent_delete') {
                    $id = (int) ($data['id'] ?? 0);
                    if ($id < 1) $this->respond(['error' => 'Terminal id is required.'], 422);
                    $this->assertTerminalAccess($db, $user, $id);
                    $this->call->model('Terminal_model');
                    if (!$this->Terminal_model->permanentlyDelete($id)) {
                        $this->respond(['error' => 'Terminal not found or already deleted.'], 404);
                    }
                    $this->respond(['data' => ['id' => $id, 'deleted' => true, 'permanent' => true]]);
                }
                if (($data['action'] ?? '') === 'restore') {
                    $id = (int) ($data['id'] ?? 0);
                    if ($id < 1) $this->respond(['error' => 'Terminal id is required.'], 422);
                    $this->assertTerminalAccess($db, $user, $id);
                    $this->call->model('Terminal_model');
                    $this->Terminal_model->setActive($id, true);
                    $this->respond(['data' => ['id' => $id, 'restored' => true]]);
                }
                if (in_array($data['action'] ?? '', ['approve', 'reject', 'submit'], true)) {
                    if (($data['action'] ?? '') !== 'submit') $this->requireSuperAdmin();
                    $id = (int) ($data['id'] ?? 0);
                    if ($id < 1) $this->respond(['error' => 'Terminal id is required.'], 422);
                    $this->assertTerminalAccess($db, $user, $id);
                    $status = $data['action'] === 'approve' ? 'published' : ($data['action'] === 'reject' ? 'rejected' : 'submitted');
                    $this->call->model('Terminal_model');
                    $this->Terminal_model->setWorkflow($id, $status, $status === 'published', $user['user_id'], $data['remarks'] ?? null);
                    $this->respond(['data' => ['id' => $id, 'workflow_status' => $status]]);
                }
                if (($data['action'] ?? '') === 'delete') {
                    $id = (int) ($data['id'] ?? 0);
                    if ($id < 1) $this->respond(['error' => 'Terminal id is required.'], 422);
                    $this->assertTerminalAccess($db, $user, $id);
                    $this->call->model('Terminal_model');
                    if (!$this->Terminal_model->permanentlyDelete($id)) {
                        $this->respond(['error' => 'Terminal not found or already deleted.'], 404);
                    }
                    $this->respond(['data' => ['id' => $id, 'deleted' => true, 'permanent' => true]]);
                }
                $id = (int) ($_GET['id'] ?? $data['id'] ?? 0);
                $this->required($data, ['area_id', 'name', 'address', 'lat', 'lng', 'hours']);
                $this->call->model('Area_model');
                if (!$this->Area_model->existsById($data['area_id'])) $this->respond(['error' => 'Select a valid municipality and barangay.'], 422);
                $this->assertAreaAccess($db, $user, (int) $data['area_id']);
                if ($id > 0) $this->assertTerminalAccess($db, $user, $id);
                $this->call->model('Terminal_model');
                $id = $this->Terminal_model->saveAdmin(
                    $id,
                    $data,
                    $user['role'] === 'admin' ? 'published' : 'draft',
                    $user['user_id']
                );
                $this->respond(['data' => ['id' => $id]], $id > 0 ? 200 : 201);
            }

    public function delete_terminal()
            {
                $user = $this->requireAdmin();
                $id = (int) ($_GET['id'] ?? 0);
                if ($id < 1) {
                    $this->respond(['error' => 'Terminal id is required.'], 422);
                }
                $db = $this->database();
                $this->assertTerminalAccess($db, $user, $id);
                $this->call->model('Terminal_model');
                $this->Terminal_model->setActive($id, false);
                $this->respond(['data' => ['id' => $id, 'deleted' => true]]);
            }

    public function admin_routes()
            {
                $user = $this->requireAdmin();
                $db = $this->database();
                if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                    $this->call->model('Route_model');
                    $city = $user['role'] === 'staff' ? ($user['assigned_city'] ?? '') : null;
                    $workflowStatus = ($_GET['workflow_status'] ?? '') === 'submitted' ? 'submitted' : null;
                    $this->respond($this->pageMeta($this->Route_model->getAdminList($city, $workflowStatus)));
                }
                $data = $this->input();
                if (($data['action'] ?? '') === 'permanent_delete') {
                    $id = (int) ($data['id'] ?? 0);
                    if ($id < 1) $this->respond(['error' => 'Route id is required.'], 422);
                    $this->assertRouteAccess($db, $user, $id);
                    $this->call->model('Route_model');
                    if (!$this->Route_model->permanentlyDelete($id)) {
                        $this->respond(['error' => 'Route not found or already deleted.'], 404);
                    }
                    $this->respond(['data' => ['id' => $id, 'deleted' => true, 'permanent' => true]]);
                }
                if (($data['action'] ?? '') === 'restore') {
                    $id = (int) ($data['id'] ?? 0);
                    if ($id < 1) $this->respond(['error' => 'Route id is required.'], 422);
                    $this->assertRouteAccess($db, $user, $id);
                    $this->call->model('Route_model');
                    $this->Route_model->setActive($id, true);
                    $this->respond(['data' => ['id' => $id, 'restored' => true]]);
                }
                if (($data['action'] ?? '') === 'delete') {
                    $id = (int) ($data['id'] ?? 0);
                    if ($id < 1) $this->respond(['error' => 'Route id is required.'], 422);
                    $this->assertRouteAccess($db, $user, $id);
                    $this->call->model('Route_model');
                    $this->Route_model->setActive($id, false);
                    $this->respond(['data' => ['id' => $id, 'deleted' => true]]);
                }
                if (in_array($data['action'] ?? '', ['approve', 'reject', 'submit'], true)) {
                    if (($data['action'] ?? '') !== 'submit') $this->requireSuperAdmin();
                    $id = (int) ($data['id'] ?? 0);
                    if ($id < 1) $this->respond(['error' => 'Route id is required.'], 422);
                    $this->assertRouteAccess($db, $user, $id);
                    $status = $data['action'] === 'approve' ? 'published' : ($data['action'] === 'reject' ? 'rejected' : 'submitted');
                    $this->call->model('Route_model');
                    $this->Route_model->setWorkflow($id, $status, $status === 'published', $user['user_id'], $data['remarks'] ?? null);
                    $this->respond(['data' => ['id' => $id, 'workflow_status' => $status]]);
                }
                $id = (int) ($_GET['id'] ?? $data['id'] ?? 0);
                $this->required($data, ['name', 'vehicle', 'origin', 'destination']);
                if (empty($data['origin_terminal_id']) || empty($data['destination_terminal_id'])) $this->respond(['error' => 'Select origin and destination terminals.'], 422);
                $this->call->model('Terminal_model');
                if ($this->Terminal_model->countActiveByIds($data['origin_terminal_id'], $data['destination_terminal_id']) < 2
                    && (int) $data['origin_terminal_id'] !== (int) $data['destination_terminal_id']) {
                    $this->respond(['error' => 'Select active origin and destination terminals.'], 422);
                }
                $this->assertTerminalAccess($db, $user, (int) $data['origin_terminal_id']);
                $this->assertTerminalAccess($db, $user, (int) $data['destination_terminal_id']);
                if ($id > 0) $this->assertRouteAccess($db, $user, $id);
                $this->call->model('Route_model');
                $id = $this->Route_model->saveAdmin(
                    $id,
                    $data,
                    $user['role'] === 'admin' ? 'published' : 'draft',
                    $user['user_id']
                );
                if ($id > 0 && !empty($data['origin_terminal_id']) && !empty($data['destination_terminal_id'])) {
                    $this->call->model('Route_terminal_model');
                    $this->Route_terminal_model->replaceForRoute($id, $data['origin_terminal_id'], $data['destination_terminal_id']);
                }
                $this->respond(['data' => ['id' => $id]]);
            }

    public function delete_route()
            {
                $user = $this->requireAdmin();
                $id = (int) ($_GET['id'] ?? 0);
                if ($id < 1) $this->respond(['error' => 'Route id is required.'], 422);
                $db = $this->database();
                $this->assertRouteAccess($db, $user, $id);
                $this->call->model('Route_model');
                if (!$this->Route_model->permanentlyDelete($id)) {
                    $this->respond(['error' => 'Route not found or already deleted.'], 404);
                }
                $this->respond(['data' => ['id' => $id, 'deleted' => true, 'permanent' => true]]);
            }

    public function admin_areas()
            {
                $user = $this->requireAdmin();
                $db = $this->database();
                if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                    $this->call->model('Area_model');
                    $city = $user['role'] === 'staff' ? ($user['assigned_city'] ?? '') : null;
                    $this->respond(['data' => $this->Area_model->getAdminList($city)]);
                }
                if ($user['role'] !== 'admin') {
                    $this->respond(['error' => 'Super admin access is required to edit areas.'], 403);
                }
                $data = $this->input();
                if (($data['action'] ?? '') === 'delete') {
                    $id = (int) ($data['id'] ?? 0);
                    if ($id < 1) $this->respond(['error' => 'Area id is required.'], 422);
                    $this->call->model('Area_model');
                    if ($this->Area_model->hasActiveTerminals($id)) $this->respond(['error' => 'This area is still assigned to an active terminal.'], 409);
                    $this->Area_model->deleteById($id);
                    $this->respond(['data' => ['id' => $id, 'deleted' => true]]);
                }
                $id = (int) ($_GET['id'] ?? $data['id'] ?? 0);
                $this->required($data, ['name', 'city', 'province']);
                $this->call->model('Area_model');
                $this->respond(['data' => ['id' => $this->Area_model->saveAdmin($id, $data)]]);
            }

    public function admin_users()
    {
        $this->requireSuperAdmin();
        $this->database();
        $this->call->model('User_model');

        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->respond(['error' => 'Passenger accounts are read-only in admin.'], 405);
        }

        $search = trim((string) ($_GET['search'] ?? ''));
        $this->respond($this->pageMeta($this->User_model->getAdminList('passenger', $search)));
    }

    public function admin_staff()
    {
        $this->manageAccountsByRole('staff');
    }

    private function manageAccountsByRole($role)
    {
        $this->requireSuperAdmin();
        $this->database();
        $this->call->model('User_model');

        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $this->respond($this->pageMeta($this->User_model->getAdminList($role)));
        }

        $data = $this->input();
        if (($data['action'] ?? '') === 'delete') {
            $id = (int) ($data['id'] ?? 0);
            if ($id < 1) $this->respond(['error' => 'User id is required.'], 422);
            $this->respond(['data' => ['id' => $id, 'deleted' => $this->User_model->deactivate($id, true, $role)]]);
        }

        $id = (int) ($_GET['id'] ?? $data['id'] ?? 0);
        $this->required($data, ['full_name', 'email']);
        if ($id > 0 && !$this->User_model->hasRole($id, $role)) {
            $this->respond(['error' => 'Account not found in this account group.'], 404);
        }
        if ($id < 1) {
            $this->required($data, ['password']);
            $password = is_string($data['password'] ?? null) ? $data['password'] : '';
            if (strlen($password) < 6 || strlen($password) > 255) {
                $this->respond(['error' => 'Password must be between 6 and 255 characters.'], 422);
            }
        }

        if ($role === 'staff') {
            $assignedCity = trim((string) ($data['assigned_city'] ?? ''));
            if ($assignedCity === '') {
                $this->respond(['error' => 'Select an assigned municipality/city for staff.'], 422);
            }
            if (!$this->User_model->cityExists($assignedCity)) {
                $this->respond(['error' => 'Select a municipality/city from the areas list.'], 422);
            }
        } else {
            $assignedCity = null;
        }

        $data['assigned_city'] = $assignedCity;
        try {
            $savedId = $this->User_model->saveAdmin($id, $data, $role);
        } catch (PDOException $exception) {
            $databaseError = $exception->getMessage();
            if ($exception->getCode() === '23000' || strpos($databaseError, '23000') !== false || stripos($databaseError, 'duplicate entry') !== false) {
                $this->respond(['error' => 'That email is already in use.'], 409);
            }
            error_log('SakayLokal admin account save failed: ' . $databaseError);
            $this->respond(['error' => 'Could not save this account. Check the account details and try again.'], 500);
        } catch (Throwable $exception) {
            error_log('SakayLokal admin account save failed: ' . $exception->getMessage());
            $this->respond(['error' => 'Could not save this account. Please try again.'], 500);
        }
        if (!$savedId) {
            $this->respond(['error' => 'Could not save this account. Please try again.'], 500);
        }
        $this->respond(['data' => ['id' => (int) $savedId]]);
    }

    public function admin_fares()
            {
                $user = $this->requireAdmin();
                $db = $this->database();
                if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                    $this->call->model('Fare_model');
                    $city = $user['role'] === 'staff' ? ($user['assigned_city'] ?? '') : null;
                    $this->respond(['data' => $this->Fare_model->getAdminList($city)]);
                }
                $data = $this->input();
                if (in_array($data['action'] ?? '', ['approve', 'reject', 'submit'], true) && ($user['role'] ?? '') === 'staff') {
                    $this->assertRelatedRouteAccess($db, $user, 'fares', (int) ($data['id'] ?? 0), 'fare');
                }
                $this->workflowAction($db, 'fares', (int) ($data['id'] ?? 0), $data['action'] ?? '', $user, 'Fare', $data['remarks'] ?? null);
                if (($data['action'] ?? '') === 'delete') {
                    $id = (int) ($data['id'] ?? 0);
                    if ($id < 1) $this->respond(['error' => 'Fare id is required.'], 422);
                    $this->assertRelatedRouteAccess($db, $user, 'fares', $id, 'fare');
                    $this->call->model('Fare_model');
                    $this->respond(['data' => ['id' => $id, 'deleted' => $this->Fare_model->deleteById($id)]]);
                }
                $id = (int) ($_GET['id'] ?? $data['id'] ?? 0);
                $this->required($data, ['route_id', 'fare_amount']);
                $this->assertRouteAccess($db, $user, (int) $data['route_id']);
                if ($id > 0) $this->assertRelatedRouteAccess($db, $user, 'fares', $id, 'fare');
                $this->call->model('Fare_model');
                if (!$this->Fare_model->hasActiveRoute($data['route_id'])) $this->respond(['error' => 'Select an active route.'], 422);
                $this->respond(['data' => ['id' => $this->Fare_model->saveAdmin(
                    $id,
                    $data,
                    $user['role'] === 'admin' ? 'published' : 'draft',
                    $user['user_id']
                )]]);
            }

    public function admin_advisories()
            {
                $user = $this->requireAdmin();
                $db = $this->database();
                if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                    $this->call->model('Advisory_model');
                    $this->respond(['data' => $this->Advisory_model->getAdminList()]);
                }
                $data = $this->input();
                $emailDelivery = null;
                if (($data['action'] ?? '') === 'permanent_delete') {
                    $id = (int) ($data['id'] ?? 0);
                    if ($id < 1) $this->respond(['error' => 'Advisory id is required.'], 422);
                    $this->call->model('Advisory_model');
                    if (!$this->Advisory_model->deleteById($id)) {
                        $this->respond(['error' => 'Advisory not found or already deleted.'], 404);
                    }
                    $this->respond(['data' => ['id' => $id, 'deleted' => true, 'permanent' => true]]);
                }
                if (($data['action'] ?? '') === 'restore') {
                    $id = (int) ($data['id'] ?? 0);
                    if ($id < 1) $this->respond(['error' => 'Advisory id is required.'], 422);
                    $this->call->model('Advisory_model');
                    $this->Advisory_model->setActive($id, true);
                    $this->respond(['data' => ['id' => $id, 'restored' => true]]);
                }
                if (($data['action'] ?? '') === 'delete') {
                    $id = (int) ($data['id'] ?? 0);
                    if ($id < 1) $this->respond(['error' => 'Advisory id is required.'], 422);
                    $this->call->model('Advisory_model');
                    $this->Advisory_model->setActive($id, false);
                    $this->respond(['data' => ['id' => $id, 'deleted' => true]]);
                }
                if (in_array($data['action'] ?? '', ['approve', 'reject', 'submit'], true)) {
                    if (($data['action'] ?? '') !== 'submit') $this->requireSuperAdmin();
                    $id = (int) ($data['id'] ?? 0);
                    if ($id < 1) $this->respond(['error' => 'Advisory id is required.'], 422);
                    $status = $data['action'] === 'approve' ? 'published' : ($data['action'] === 'reject' ? 'rejected' : 'submitted');
                    $this->call->model('Advisory_model');
                    $this->Advisory_model->setWorkflow($id, $status, $status === 'published', $user['user_id'], $data['remarks'] ?? null);
                    if ($status === 'published') {
                        $advisory = $db->prepare('SELECT title, body, recipient_audience FROM advisories WHERE advisory_id = ?');
                        $advisory->execute([$id]);
                        $published = $advisory->fetch(PDO::FETCH_ASSOC);
                        if ($published) {
                            $this->call->model('Notification_model');
                            $queued = $this->Notification_model->queueAdvisory(
                                $id,
                                $published['title'],
                                $published['body'],
                                $published['recipient_audience']
                            );
                            $emailDelivery = ['queued' => $queued];
                        }
                    }
                    $response = ['id' => $id, 'workflow_status' => $status];
                    if ($emailDelivery !== null) {
                        $response['email_delivery'] = $emailDelivery;
                    }
                    $this->respond(['data' => $response]);
                }
                $id = (int) ($_GET['id'] ?? $data['id'] ?? 0);
                $this->required($data, ['title', 'body']);
                $title = $this->text($data['title'], 'Title', 200);
                $body = $this->text($data['body'], 'Message', 2000, false);
                if ($body === '') $this->respond(['error' => 'Message is required.'], 422);
                $advisoryType = in_array($data['advisory_type'] ?? '', ['advisory', 'announcement', 'alert'], true) ? $data['advisory_type'] : 'advisory';
                $audience = $data['recipient_audience'] ?? 'passengers';
                if (!in_array($audience, ['passengers', 'staff', 'both'], true)) {
                    $this->respond(['error' => 'Select passengers, staff, or both as the advisory recipients.'], 422);
                }
                $this->call->model('Advisory_model');
                $id = $this->Advisory_model->saveAdmin(
                    $id,
                    ['title' => $title, 'body' => $body, 'advisory_type' => $advisoryType, 'recipient_audience' => $audience],
                    $user['role'] === 'admin' ? 'published' : 'draft',
                    $user['user_id']
                );
                if ($user['role'] === 'admin') {
                    $this->call->model('Notification_model');
                    $queued = $this->Notification_model->queueAdvisory($id, $title, $body, $audience);
                    $emailDelivery = ['queued' => $queued];
                }
                $response = ['id' => $id];
                if ($emailDelivery !== null) {
                    $response['email_delivery'] = $emailDelivery;
                }
                $this->respond(['data' => $response]);
            }

    public function admin_schedules()
            {
                $user = $this->requireAdmin();
                $db = $this->database();
                if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                    $this->call->model('Schedule_model');
                    $city = $user['role'] === 'staff' ? ($user['assigned_city'] ?? '') : null;
                    $this->respond(['data' => $this->Schedule_model->getAdminList($city)]);
                }
                $data = $this->input();
                if (in_array($data['action'] ?? '', ['approve', 'reject', 'submit'], true) && ($user['role'] ?? '') === 'staff') {
                    $this->assertRelatedRouteAccess($db, $user, 'schedules', (int) ($data['id'] ?? 0), 'schedule');
                }
                $this->workflowAction($db, 'schedules', (int) ($data['id'] ?? 0), $data['action'] ?? '', $user, 'Schedule', $data['remarks'] ?? null);
                if (($data['action'] ?? '') === 'delete') {
                    $id = (int) ($data['id'] ?? 0);
                    if ($id < 1) $this->respond(['error' => 'Schedule id is required.'], 422);
                    $this->assertRelatedRouteAccess($db, $user, 'schedules', $id, 'schedule');
                    $this->call->model('Schedule_model');
                    $deleted = $this->Schedule_model->deleteById($id);
                    $this->respond(['data' => ['id' => $id, 'deleted' => $deleted]]);
                }
                $id = (int) ($_GET['id'] ?? $data['id'] ?? 0);
                $this->required($data, ['route_id', 'schedule_label', 'start_time', 'end_time', 'days_available']);
                $this->assertRouteAccess($db, $user, (int) $data['route_id']);
                if ($id > 0) $this->assertRelatedRouteAccess($db, $user, 'schedules', $id, 'schedule');
                $this->call->model('Schedule_model');
                if (!$this->Schedule_model->hasActiveRoute($data['route_id'])) $this->respond(['error' => 'Select an active route.'], 422);
                $this->respond(['data' => ['id' => $this->Schedule_model->saveAdmin(
                    $id,
                    $data,
                    $user['role'] === 'admin' ? 'published' : 'draft',
                    $user['user_id']
                )]]);
            }

    public function admin_pickup_points()
            {
                $user = $this->requireAdmin();
                $db = $this->database();
                if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                    $this->call->model('Pickup_point_model');
                    $city = $user['role'] === 'staff' ? ($user['assigned_city'] ?? '') : null;
                    $this->respond(['data' => $this->Pickup_point_model->getAdminList($city)]);
                }
                $data = $this->input();
                if (in_array($data['action'] ?? '', ['approve', 'reject', 'submit'], true) && ($user['role'] ?? '') === 'staff') {
                    $this->assertRelatedRouteAccess($db, $user, 'pickup_points', (int) ($data['id'] ?? 0), 'pickup point');
                }
                $this->workflowAction($db, 'pickup_points', (int) ($data['id'] ?? 0), $data['action'] ?? '', $user, 'Pickup point', $data['remarks'] ?? null);
                if (($data['action'] ?? '') === 'delete') {
                    $id = (int) ($data['id'] ?? 0);
                    if ($id < 1) $this->respond(['error' => 'Pickup point id is required.'], 422);
                    $this->assertRelatedRouteAccess($db, $user, 'pickup_points', $id, 'pickup point');
                    $this->call->model('Pickup_point_model');
                    $this->respond(['data' => ['id' => $id, 'deleted' => $this->Pickup_point_model->deleteById($id)]]);
                }
                $id = (int) ($_GET['id'] ?? $data['id'] ?? 0);
                $this->required($data, ['route_id', 'point_name', 'lat', 'lng', 'sequence_no']);
                $this->assertRouteAccess($db, $user, (int) $data['route_id']);
                if ($id > 0) $this->assertRelatedRouteAccess($db, $user, 'pickup_points', $id, 'pickup point');
                $this->call->model('Pickup_point_model');
                if (!$this->Pickup_point_model->hasActiveRoute($data['route_id'])) $this->respond(['error' => 'Select an active route.'], 422);
                $this->respond(['data' => ['id' => $this->Pickup_point_model->saveAdmin(
                    $id,
                    $data,
                    $user['role'] === 'admin' ? 'published' : 'draft',
                    $user['user_id']
                )]]);
            }

}
