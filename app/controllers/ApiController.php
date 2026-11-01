<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class ApiController extends Controller
{
    private static $workflowSchemaReady = false;
    private static $notificationSchemaReady = false;

    public function __construct()
    {
        parent::__construct();
        $this->apiLibrary();
    }

    public function cors()
    {
        $this->apiLibrary();
        http_response_code(204);
        exit;
    }

    protected function respond($payload, $status = 200)
    {
        $this->apiLibrary()->respond($payload, $status);
    }

    protected function apiLibrary()
    {
        $this->properties['api'] = $this->call->library('api');
        return $this->properties['api'];
    }

    protected function database()
    {
        try {
            $database = load_class('database', 'database');
            $queryBuilder = $database::instance('main');
            $this->properties['db'] = $queryBuilder;
            $connection = $queryBuilder->connection();
            $this->ensureWorkflowSchema($connection);
            $this->ensureNotificationSchema($connection);
            return $connection;
        } catch (PDOException $exception) {
            $this->respond(['error' => 'Database connection failed. Import dbsql/sakaylokal.sql and check your local database settings.'], 503);
        }
    }

    protected function ensureNotificationSchema($db)
    {
        if (self::$notificationSchemaReady) return;

        $migrationKey = 'user_notifications_v3';
        $check = $db->prepare('SELECT 1 FROM sakaylokal_schema_migrations WHERE migration_key = ? LIMIT 1');
        $check->execute([$migrationKey]);
        if ($check->fetchColumn()) {
            self::$notificationSchemaReady = true;
            return;
        }

        $lockName = 'sakaylokal_user_notifications_schema';
        $lock = $db->query("SELECT GET_LOCK('{$lockName}', 10)");
        if ((int) $lock->fetchColumn() !== 1) {
            $this->respond(['error' => 'Account schema is busy. Please retry shortly.'], 503);
        }
        try {
            $check->execute([$migrationKey]);
            if ($check->fetchColumn()) {
                self::$notificationSchemaReady = true;
                return;
            }
            $columns = [
                'profile_photo' => "VARCHAR(255) NULL AFTER phone",
                'notify_advisories' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER profile_photo',
                'notify_weather' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER notify_advisories',
            ];
            foreach ($columns as $column => $definition) {
                $columnCheck = $db->query("SHOW COLUMNS FROM users LIKE " . $db->quote($column));
                if (!$columnCheck->fetch()) {
                    $db->exec("ALTER TABLE users ADD COLUMN {$column} {$definition}");
                }
            }
            $audienceColumn = $db->query("SHOW COLUMNS FROM advisories LIKE 'recipient_audience'");
            if (!$audienceColumn->fetch()) {
                $db->exec("ALTER TABLE advisories ADD COLUMN recipient_audience ENUM('passengers','staff','both') NOT NULL DEFAULT 'passengers' AFTER advisory_type");
            }
            $db->exec(
                "CREATE TABLE IF NOT EXISTS email_notifications (
                    notification_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id INT UNSIGNED NOT NULL,
                    event_key VARCHAR(190) NOT NULL,
                    subject VARCHAR(200) NOT NULL,
                    message TEXT NOT NULL,
                    status ENUM('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
                    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                    last_error VARCHAR(500) NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    sent_at DATETIME NULL,
                    UNIQUE KEY uq_email_notification_user_event (user_id, event_key),
                    KEY idx_email_notifications_pending (status, attempts, created_at),
                    CONSTRAINT fk_email_notification_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            $notificationColumns = $db->query("SHOW COLUMNS FROM email_notifications LIKE 'updated_at'");
            if (!$notificationColumns->fetch()) {
                $db->exec("ALTER TABLE email_notifications ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
            }
            $db->exec(
                "CREATE TABLE IF NOT EXISTS weather_alert_state (
                    state_key VARCHAR(50) PRIMARY KEY,
                    checked_at DATETIME NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            $mark = $db->prepare('INSERT INTO sakaylokal_schema_migrations (migration_key) VALUES (?)');
            $mark->execute([$migrationKey]);
            self::$notificationSchemaReady = true;
        } finally {
            $db->query("SELECT RELEASE_LOCK('{$lockName}')");
        }
    }

    protected function ensureWorkflowSchema($db)
    {
        if (self::$workflowSchemaReady) return;
        $migrationKey = 'workflow_schema_v1';
        $markerTable = $db->query("SHOW TABLES LIKE 'sakaylokal_schema_migrations'");
        if ($markerTable->fetchColumn()) {
            $check = $db->prepare("SELECT 1 FROM sakaylokal_schema_migrations WHERE migration_key = :migration_key LIMIT 1");
            $check->execute([':migration_key' => $migrationKey]);
            if ($check->fetchColumn()) {
                self::$workflowSchemaReady = true;
                return;
            }
        }

        $lockName = 'sakaylokal_workflow_schema';
        $lock = $db->query("SELECT GET_LOCK('{$lockName}', 10)");
        if ((int) $lock->fetchColumn() !== 1) {
            $this->respond(['error' => 'Database schema is busy. Please retry shortly.'], 503);
        }

        try {
            if (self::$workflowSchemaReady) return;
            $db->exec(
                "CREATE TABLE IF NOT EXISTS sakaylokal_schema_migrations (
                    migration_key VARCHAR(100) PRIMARY KEY,
                    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            $check = $db->prepare("SELECT 1 FROM sakaylokal_schema_migrations WHERE migration_key = :migration_key LIMIT 1");
            $check->execute([':migration_key' => $migrationKey]);
            if ($check->fetchColumn()) {
                self::$workflowSchemaReady = true;
                return;
            }

            $this->applyWorkflowSchema($db);
            $mark = $db->prepare("INSERT INTO sakaylokal_schema_migrations (migration_key) VALUES (:migration_key)");
            $mark->execute([':migration_key' => $migrationKey]);
            self::$workflowSchemaReady = true;
        } finally {
            $db->query("SELECT RELEASE_LOCK('{$lockName}')");
        }
    }

    protected function applyWorkflowSchema($db)
    {
        // Advisories module - created automatically so the admin and public
        // endpoints always have a table to read from (seed data lives in dbsql/008_advisories.sql).
        $db->exec(
            "CREATE TABLE IF NOT EXISTS advisories (
                advisory_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(200) NOT NULL,
                body TEXT NOT NULL,
                advisory_type ENUM('advisory','announcement','alert') NOT NULL DEFAULT 'advisory',
                recipient_audience ENUM('passengers','staff','both') NOT NULL DEFAULT 'passengers',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $columns = [
            'users' => ['assigned_city VARCHAR(120) NULL AFTER role'],
            'terminals' => [
                "workflow_status ENUM('draft','submitted','approved','rejected','published','archived') NOT NULL DEFAULT 'draft' AFTER is_active",
                'created_by INT UNSIGNED NULL AFTER workflow_status', 'reviewed_by INT UNSIGNED NULL AFTER created_by',
                'reviewed_at DATETIME NULL AFTER reviewed_by', 'review_remarks TEXT NULL AFTER reviewed_at'
            ],
            'routes' => [
                "workflow_status ENUM('draft','submitted','approved','rejected','published','archived') NOT NULL DEFAULT 'draft' AFTER is_active",
                'created_by INT UNSIGNED NULL AFTER workflow_status', 'reviewed_by INT UNSIGNED NULL AFTER created_by',
                'reviewed_at DATETIME NULL AFTER reviewed_by', 'review_remarks TEXT NULL AFTER reviewed_at'
            ],
            'fares' => [
                "workflow_status ENUM('draft','submitted','approved','rejected','published','archived') NOT NULL DEFAULT 'draft' AFTER notes",
                'created_by INT UNSIGNED NULL AFTER workflow_status', 'reviewed_by INT UNSIGNED NULL AFTER created_by',
                'reviewed_at DATETIME NULL AFTER reviewed_by', 'review_remarks TEXT NULL AFTER reviewed_at'
            ],
            'schedules' => [
                "workflow_status ENUM('draft','submitted','approved','rejected','published','archived') NOT NULL DEFAULT 'draft' AFTER notes",
                'created_by INT UNSIGNED NULL AFTER workflow_status', 'reviewed_by INT UNSIGNED NULL AFTER created_by',
                'reviewed_at DATETIME NULL AFTER reviewed_by', 'review_remarks TEXT NULL AFTER reviewed_at'
            ],
            'pickup_points' => [
                "workflow_status ENUM('draft','submitted','approved','rejected','published','archived') NOT NULL DEFAULT 'draft' AFTER sequence_no",
                'created_by INT UNSIGNED NULL AFTER workflow_status', 'reviewed_by INT UNSIGNED NULL AFTER created_by',
                'reviewed_at DATETIME NULL AFTER reviewed_by', 'review_remarks TEXT NULL AFTER reviewed_at'
            ],
            'advisories' => [
                "workflow_status ENUM('draft','submitted','approved','rejected','published','archived') NOT NULL DEFAULT 'draft' AFTER is_active",
                'reviewed_by INT UNSIGNED NULL AFTER created_by', 'reviewed_at DATETIME NULL AFTER reviewed_by',
                'review_remarks TEXT NULL AFTER reviewed_at'
            ]
        ];
        foreach ($columns as $table => $definitions) {
            foreach ($definitions as $definition) {
                $column = preg_replace('/\s.*$/', '', $definition);
                $check = $db->query("SHOW COLUMNS FROM {$table} LIKE " . $db->quote($column));
                if (!$check->fetch()) {
                    $db->exec("ALTER TABLE {$table} ADD COLUMN {$definition}");
                }
            }
        }
        $db->exec("UPDATE terminals SET workflow_status = 'published' WHERE is_active = 1 AND workflow_status = 'draft'");
        $db->exec("UPDATE routes SET workflow_status = 'published' WHERE is_active = 1 AND workflow_status = 'draft'");
        $db->exec("UPDATE fares SET workflow_status = 'published' WHERE workflow_status = 'draft'");
        $db->exec("UPDATE schedules SET workflow_status = 'published' WHERE workflow_status = 'draft'");
        $db->exec("UPDATE pickup_points SET workflow_status = 'published' WHERE workflow_status = 'draft'");
        $db->exec("UPDATE advisories SET workflow_status = 'published' WHERE is_active = 1 AND workflow_status = 'draft'");
    }

    protected function input($preservePassword = false)
        {
        $input = $this->apiLibrary()->body();
        if ($preservePassword) {
            $rawInput = json_decode(file_get_contents('php://input'), true);
            if (is_array($rawInput) && is_string($rawInput['password'] ?? null)) {
                $input['password'] = $rawInput['password'];
            }
        }

        return $input;
        }

        protected function text($value, $field, $maxLength = 255, $required = true)
        {
            $value = is_string($value) ? trim($value) : '';
            if ($required && $value === '') {
                $this->respond(['error' => "{$field} is required."], 422);
            }
            if (strlen($value) > $maxLength) {
                $this->respond(['error' => "{$field} is too long."], 422);
            }
            return $value;
        }

        protected function integer($value, $field, $minimum = 1)
        {
            $validated = filter_var($value, FILTER_VALIDATE_INT);
            if ($validated === false || $validated < $minimum) {
                $this->respond(['error' => "Invalid {$field}."], 422);
            }
            return (int) $validated;
        }

        protected function createAdminToken($user)
        {
            $api = $this->apiLibrary();
            return $api->encode_jwt([
                'sub' => (int) $user['user_id'],
                'role' => $user['role'],
                'auth' => hash('sha256', $user['password_hash']),
            ]);
        }

        protected function requireAdmin()
        {
            $payload = $this->apiLibrary()->require_jwt();
            $this->database();
            $this->call->model('User_model');
            $user = $this->User_model->findActiveAdministratorById($payload['sub']);
            if (!$user || !isset($payload['auth']) || !hash_equals(hash('sha256', $user['password_hash']), $payload['auth'])) {
                $this->respond(['error' => 'Administrator session is invalid.'], 401);
            }
            return $user;
        }

        protected function requireAuthenticatedUser()
        {
            $payload = $this->apiLibrary()->require_jwt();
            $this->database();
            $this->call->model('User_model');
            $user = $this->User_model->findActiveById($payload['sub']);
            if (!$user || $user['role'] !== 'passenger' || !isset($payload['auth']) || !hash_equals(hash('sha256', $user['password_hash']), $payload['auth'])) {
                $this->respond(['error' => 'Your session is invalid. Please log in again.'], 401);
            }
            return $user;
        }

        protected function optionalAuthenticatedPassenger()
        {
            $headers = function_exists('getallheaders') ? getallheaders() : [];
            $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $headers['Authorization'] ?? $headers['authorization'] ?? '';
            if ($authorization === '') {
                return null;
            }
            return $this->requireAuthenticatedUser();
        }

        protected function requireSuperAdmin()
        {
            $user = $this->requireAdmin();
            if ($user['role'] !== 'admin') {
                $this->respond(['error' => 'Super admin access is required for this action.'], 403);
            }
            return $user;
        }

        protected function pageMeta($rows)
        {
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $pageSize = min(50, max(5, (int) ($_GET['page_size'] ?? 10)));
            $total = count($rows);
            return ['data' => array_slice($rows, ($page - 1) * $pageSize, $pageSize), 'meta' => ['page' => $page, 'page_size' => $pageSize, 'total' => $total, 'pages' => max(1, (int) ceil($total / $pageSize))]];
        }

        protected function workflowFilter($alias = '')
        {
            $prefix = $alias ? $alias . '.' : '';
            return " AND {$prefix}workflow_status IN ('approved','published')";
        }

        protected function workflowAction($db, $table, $id, $action, $user, $label, $remarks = null)
        {
            if (!in_array($action, ['approve', 'reject', 'submit'], true)) return;
            if ($action !== 'submit' && $user['role'] !== 'admin') {
                $this->respond(['error' => 'Super admin access is required for this action.'], 403);
            }
            if ($id < 1) $this->respond(['error' => "{$label} id is required."], 422);
            $status = $action === 'approve' ? 'published' : ($action === 'reject' ? 'rejected' : 'submitted');
            $models = [
                'fares' => ['Fare_model', 'fare'],
                'schedules' => ['Schedule_model', 'schedule'],
                'pickup_points' => ['Pickup_point_model', 'pickup_point'],
            ];
            if (!isset($models[$table])) {
                $this->respond(['error' => 'Unsupported workflow resource.'], 400);
            }
            [$model, $object] = $models[$table];
            $this->call->model($model, $object);
            $this->{$object}->setWorkflow($id, $status, $user['user_id'], $remarks);
            $this->respond(['data' => ['id' => $id, 'workflow_status' => $status]]);
        }

        protected function assertAreaAccess($db, $user, $areaId)
        {
            $this->call->model('Area_model');
            $city = $this->Area_model->findCityById($areaId);
            if ($city === false) {
                $this->respond(['error' => 'Select a valid municipality and barangay.'], 422);
            }
            if ($user['role'] === 'staff' && $city !== $user['assigned_city']) {
                $this->respond(['error' => 'This municipality is outside your assigned area.'], 403);
            }
        }

        protected function assertTerminalAccess($db, $user, $terminalId)
        {
            if ($user['role'] !== 'staff') return;
            $this->call->model('Terminal_model');
            if (!$this->Terminal_model->belongsToCity($terminalId, $user['assigned_city'])) {
                $this->respond(['error' => 'This terminal is outside your assigned municipality.'], 403);
            }
        }

        protected function assertRouteAccess($db, $user, $routeId)
        {
            if ($user['role'] !== 'staff') return;
            $this->call->model('Route_model');
            if (!$this->Route_model->belongsToCity($routeId, $user['assigned_city'])) {
                $this->respond(['error' => 'This route is outside your assigned municipality.'], 403);
            }
        }

        protected function assertRelatedRouteAccess($db, $user, $table, $id, $label)
        {
            if ($user['role'] !== 'staff') return;
            $models = [
                'fares' => ['Fare_model', 'fare'],
                'schedules' => ['Schedule_model', 'schedule'],
                'pickup_points' => ['Pickup_point_model', 'pickup_point'],
            ][$table];
            [$model, $object] = $models;
            $this->call->model($model, $object);
            if (!$this->{$object}->belongsToCity($id, $user['assigned_city'])) {
                $this->respond(['error' => "This {$label} is outside your assigned municipality."], 403);
            }
        }

        protected function assertReportAccess($db, $user, $reportId)
        {
            if ($user['role'] !== 'staff') return;
            $this->call->model('Passenger_report_model');
            if (!$this->Passenger_report_model->belongsToCity($reportId, $user['assigned_city'])) {
                $this->respond(['error' => 'This report is outside your assigned municipality.'], 403);
            }
        }

        protected function required($data, $fields)
        {
            foreach ($fields as $field) {
                if (!isset($data[$field]) || $data[$field] === '') {
                    $this->respond(['error' => "{$field} is required."], 422);
                }
            }
        }
}
