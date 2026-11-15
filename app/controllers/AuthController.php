<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once __DIR__ . '/ApiController.php';

class AuthController extends ApiController
{
    private static $loginOtpSchemaReady = false;

    public function login()
        {
            $api = $this->apiLibrary();
            $api->require_method('POST');
            $api->rate_limit(null, 10, 300);
            $input = $this->input(true);
            $identifier = trim((string) ($input['identifier'] ?? ''));
            $password = (string) ($input['password'] ?? '');
            if ($identifier === '' || $password === '') {
                $this->respond(['error' => 'Username and password are required.'], 422);
            }
            $this->database();
            $this->call->model('User_model');
            $user = $this->User_model->findByIdentifier($identifier);
            if (!$user || !password_verify($password, $user['password_hash'])) {
                $this->respond(['error' => 'Invalid username or password.'], 401);
            }

            if (in_array($user['role'], ['staff', 'passenger'], true)) {
                if (!filter_var($user['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                    $this->respond(['error' => 'This account does not have an email address for verification. Please contact an administrator.'], 422);
                }
                $db = $this->database();
                $this->ensureLoginOtpSchema($db);
                $challengeToken = bin2hex(random_bytes(32));
                $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $otpHash = hash_hmac(
                    'sha256',
                    $challengeToken . ':' . $code . ':' . hash('sha256', $user['password_hash']),
                    config_item('jwt_secret')
                );
                $challengeHash = hash('sha256', $challengeToken);

                $delete = $db->prepare('DELETE FROM login_otp_challenges WHERE user_id = ? OR expires_at <= NOW()');
                $delete->execute([(int) $user['user_id']]);
                $insert = $db->prepare(
                    'INSERT INTO login_otp_challenges (challenge_hash, user_id, otp_hash, expires_at) '
                    . 'VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))'
                );
                $insert->execute([$challengeHash, (int) $user['user_id'], $otpHash]);

                try {
                    $this->sendLoginOtpEmail($user['email'], $code);
                } catch (Throwable $exception) {
                    $deleteChallenge = $db->prepare('DELETE FROM login_otp_challenges WHERE challenge_hash = ?');
                    $deleteChallenge->execute([$challengeHash]);
                    error_log('SakayLokal login OTP email failed for ' . $user['email'] . ': ' . $exception->getMessage());
                    $this->respond(['error' => 'Could not send the verification code. Please try again later.'], 503);
                }

                $this->respond([
                    'data' => [
                        'otp_required' => true,
                        'challenge_token' => $challengeToken,
                        'message' => 'A verification code has been sent to your registered email.',
                    ],
                ]);
            }

            if ($user['role'] !== 'admin') {
                $this->respond(['error' => 'This account role cannot log in. Please contact an administrator.'], 403);
            }

            $token = $this->createAdminToken($user);
            unset($user['password_hash']);
            $user['access_token'] = $token;
            $this->respond(['data' => $user]);
        }

    public function verify_login_otp()
        {
            $api = $this->apiLibrary();
            $api->require_method('POST');
            $api->rate_limit(null, 10, 300);
            $input = $this->input();
            $challengeToken = trim((string) ($input['challenge_token'] ?? ''));
            $code = trim((string) ($input['otp'] ?? ''));
            if (!preg_match('/^[a-f0-9]{64}$/', $challengeToken) || !preg_match('/^\d{6}$/', $code)) {
                $this->respond(['error' => 'Enter the six-digit verification code.'], 422);
            }

            $db = $this->database();
            $this->ensureLoginOtpSchema($db);
            $challengeHash = hash('sha256', $challengeToken);
            $db->beginTransaction();
            $query = $db->prepare(
                'SELECT challenge_hash, user_id, otp_hash, attempts '
                . 'FROM login_otp_challenges '
                . 'WHERE challenge_hash = ? AND expires_at > NOW() AND attempts < 5 '
                . 'FOR UPDATE'
            );
            $query->execute([$challengeHash]);
            $challenge = $query->fetch(PDO::FETCH_ASSOC);
            if (!$challenge) {
                $db->rollBack();
                $this->respond(['error' => 'This code has expired or is no longer valid. Please log in again.'], 401);
            }

            $this->call->model('User_model');
            $user = $this->User_model->findActiveById((int) $challenge['user_id']);
            if (!$user || !in_array($user['role'], ['staff', 'passenger'], true)) {
                $delete = $db->prepare('DELETE FROM login_otp_challenges WHERE challenge_hash = ?');
                $delete->execute([$challengeHash]);
                $db->commit();
                $this->respond(['error' => 'This account is no longer eligible to log in. Please contact an administrator.'], 401);
            }

            $expectedHash = hash_hmac(
                'sha256',
                $challengeToken . ':' . $code . ':' . hash('sha256', $user['password_hash']),
                config_item('jwt_secret')
            );
            if (!hash_equals($challenge['otp_hash'], $expectedHash)) {
                $attempts = (int) $challenge['attempts'] + 1;
                if ($attempts >= 5) {
                    $delete = $db->prepare('DELETE FROM login_otp_challenges WHERE challenge_hash = ?');
                    $delete->execute([$challengeHash]);
                } else {
                    $update = $db->prepare('UPDATE login_otp_challenges SET attempts = ? WHERE challenge_hash = ?');
                    $update->execute([$attempts, $challengeHash]);
                }
                $db->commit();
                $message = $attempts >= 5
                    ? 'Too many incorrect codes. Please log in again to request a new code.'
                    : 'The verification code is incorrect or expired. Please try again.';
                $this->respond(['error' => $message], 401);
            }

            $delete = $db->prepare('DELETE FROM login_otp_challenges WHERE challenge_hash = ?');
            $delete->execute([$challengeHash]);
            $db->commit();

            $user['access_token'] = $this->createAdminToken($user);
            unset($user['password_hash']);
            $this->respond(['data' => $user]);
        }

    private function ensureLoginOtpSchema($db)
        {
            if (self::$loginOtpSchemaReady) {
                return;
            }
            $lock = $db->query("SELECT GET_LOCK('sakaylokal_login_otp_schema', 10)");
            if ((int) $lock->fetchColumn() !== 1) {
                $this->respond(['error' => 'Account verification is busy. Please retry shortly.'], 503);
            }
            try {
                $db->exec(
                    "CREATE TABLE IF NOT EXISTS login_otp_challenges (
                        challenge_hash CHAR(64) NOT NULL PRIMARY KEY,
                        user_id INT UNSIGNED NOT NULL,
                        otp_hash CHAR(64) NOT NULL,
                        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                        expires_at DATETIME NOT NULL,
                        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        KEY idx_login_otp_user (user_id),
                        KEY idx_login_otp_expiry (expires_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
                );
                self::$loginOtpSchemaReady = true;
            } finally {
                $db->query("SELECT RELEASE_LOCK('sakaylokal_login_otp_schema')");
            }
        }

    private function sendLoginOtpEmail($email, $code)
        {
            $this->call->library('Brevo_mailer');
            $subject = 'Your SakayLokal login verification code';
            $message = '<p>Your SakayLokal verification code is:</p>'
                . '<p style="font-size:24px;font-weight:bold;letter-spacing:6px">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</p>'
                . '<p>This code expires in 10 minutes. If you did not request it, you can ignore this email.</p>';
            $this->Brevo_mailer->send($email, $subject, $message);
        }

    public function register()
        {
            $api = $this->apiLibrary();
            $api->require_method('POST');
            $input = $this->input(true);
            $this->required($input, ['full_name', 'email', 'password']);
            $fullName = $this->text($input['full_name'], 'Full name', 150);
            $email = strtolower($this->text($input['email'], 'Email', 255));
            $password = is_string($input['password'] ?? null) ? $input['password'] : '';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->respond(['error' => 'A valid email is required.'], 422);
            }
            if (strlen($password) < 6 || strlen($password) > 255) {
                $this->respond(['error' => 'Password must be at least 6 characters.'], 422);
            }
            $phone = $this->text($input['phone'] ?? '', 'Phone', 30, false);
            $this->database();
            $this->call->model('User_model');
            try {
                $id = $this->User_model->create([
                    'full_name' => $fullName,
                    'email' => $email,
                    'password' => $password,
                    'phone' => $phone ?: null,
                ]);
            } catch (PDOException $exception) {
                $this->respond(['error' => 'That email is already registered.'], 409);
            }

            $emailSent = false;
            try {
                $this->sendWelcomeEmail($email, $fullName);
                $emailSent = true;
            } catch (Throwable $exception) {
                error_log('SakayLokal welcome email failed for ' . $email . ': ' . $exception->getMessage());
            }

            $this->respond([
                'data' => [
                    'id' => $id,
                    'message' => 'Account created.',
                    'email_sent' => $emailSent,
                ],
            ], 201);
        }

        private function sendWelcomeEmail($email, $fullName)
        {
            $this->call->library('Brevo_mailer');
            $subject = 'Welcome to SakayLokal';
            $safeName = htmlspecialchars($fullName ?: 'Passenger', ENT_QUOTES, 'UTF-8');
            $message = "<p>Hi {$safeName},</p>"
                . "<p>Welcome to SakayLokal! Your passenger account has been created successfully.</p>"
                . "<p>You can now log in to find terminals, routes, fares, and submit reports.</p>"
                . "<p>Thank you for joining the SakayLokal community.</p>";

            $this->Brevo_mailer->send($email, $subject, $message);
        }

}
