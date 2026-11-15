<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once __DIR__ . '/ApiController.php';

class AuthController extends ApiController
{
    private static $signupOtpSchemaReady = false;

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
        if (!in_array($user['role'], ['admin', 'staff', 'passenger'], true)) {
            $this->respond(['error' => 'This account role cannot log in. Please contact an administrator.'], 403);
        }

        $user['access_token'] = $this->createAdminToken($user);
        unset($user['password_hash']);
        $this->respond(['data' => $user]);
    }

    public function register()
    {
        $api = $this->apiLibrary();
        $api->require_method('POST');
        $api->rate_limit(null, 10, 300);
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
        $db = $this->database();
        $this->call->model('User_model');
        $existing = $db->prepare('SELECT user_id FROM users WHERE email = ? LIMIT 1');
        $existing->execute([$email]);
        if ($existing->fetchColumn()) {
            $this->respond(['error' => 'That email is already registered.'], 409);
        }

        $this->ensureSignupOtpSchema($db);
        $challengeToken = bin2hex(random_bytes(32));
        $challengeHash = hash('sha256', $challengeToken);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $otpHash = hash_hmac(
            'sha256',
            $challengeToken . ':' . $code . ':' . hash('sha256', $passwordHash),
            config_item('jwt_secret')
        );

        $db->beginTransaction();
        try {
            $deleteExpired = $db->prepare('DELETE FROM signup_otp_challenges WHERE expires_at <= NOW() OR email = ?');
            $deleteExpired->execute([$email]);
            $insert = $db->prepare(
                'INSERT INTO signup_otp_challenges '
                . '(challenge_hash, full_name, email, password_hash, phone, otp_hash, expires_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))'
            );
            $insert->execute([$challengeHash, $fullName, $email, $passwordHash, $phone ?: null, $otpHash]);
            $db->commit();
        } catch (PDOException $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }
            $this->respond(['error' => 'A signup verification for this email is already in progress. Try again shortly.'], 409);
        }

        try {
            $this->sendSignupOtpEmail($email, $code);
        } catch (Throwable $exception) {
            $delete = $db->prepare('DELETE FROM signup_otp_challenges WHERE challenge_hash = ?');
            $delete->execute([$challengeHash]);
            error_log('SakayLokal signup OTP email failed for ' . $email . ': ' . $exception->getMessage());
            $this->respond(['error' => 'Could not send the verification code. Please try again later.'], 503);
        }

        $this->respond([
            'data' => [
                'otp_required' => true,
                'challenge_token' => $challengeToken,
                'message' => 'A verification code has been sent to your email.',
            ],
        ], 202);
    }

    public function verify_signup_otp()
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
        $this->ensureSignupOtpSchema($db);
        $challengeHash = hash('sha256', $challengeToken);
        $db->beginTransaction();
        $query = $db->prepare(
            'SELECT full_name, email, password_hash, phone, otp_hash, attempts '
            . 'FROM signup_otp_challenges '
            . 'WHERE challenge_hash = ? AND expires_at > NOW() AND attempts < 5 '
            . 'FOR UPDATE'
        );
        $query->execute([$challengeHash]);
        $challenge = $query->fetch(PDO::FETCH_ASSOC);
        if (!$challenge) {
            $db->rollBack();
            $this->respond(['error' => 'This code has expired or is no longer valid. Please sign up again.'], 401);
        }

        $expectedHash = hash_hmac(
            'sha256',
            $challengeToken . ':' . $code . ':' . hash('sha256', $challenge['password_hash']),
            config_item('jwt_secret')
        );
        if (!hash_equals($challenge['otp_hash'], $expectedHash)) {
            $attempts = (int) $challenge['attempts'] + 1;
            if ($attempts >= 5) {
                $delete = $db->prepare('DELETE FROM signup_otp_challenges WHERE challenge_hash = ?');
                $delete->execute([$challengeHash]);
            } else {
                $update = $db->prepare('UPDATE signup_otp_challenges SET attempts = ? WHERE challenge_hash = ?');
                $update->execute([$attempts, $challengeHash]);
            }
            $db->commit();
            $message = $attempts >= 5
                ? 'Too many incorrect codes. Please sign up again to request a new code.'
                : 'The verification code is incorrect or expired. Please try again.';
            $this->respond(['error' => $message], 401);
        }

        $this->call->model('User_model');
        try {
            $id = $this->User_model->createWithPasswordHash([
                'full_name' => $challenge['full_name'],
                'email' => $challenge['email'],
                'password_hash' => $challenge['password_hash'],
                'phone' => $challenge['phone'],
            ]);
        } catch (PDOException $exception) {
            if ($exception->getCode() !== '23000') {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $exception;
            }
            $delete = $db->prepare('DELETE FROM signup_otp_challenges WHERE challenge_hash = ?');
            $delete->execute([$challengeHash]);
            $db->commit();
            $this->respond(['error' => 'That email is already registered.'], 409);
        }

        $delete = $db->prepare('DELETE FROM signup_otp_challenges WHERE challenge_hash = ?');
        $delete->execute([$challengeHash]);
        $db->commit();

        $emailSent = false;
        try {
            $this->sendWelcomeEmail($challenge['email'], $challenge['full_name']);
            $emailSent = true;
        } catch (Throwable $exception) {
            error_log('SakayLokal welcome email failed for ' . $challenge['email'] . ': ' . $exception->getMessage());
        }

        $this->respond([
            'data' => [
                'id' => $id,
                'message' => 'Email verified and account created.',
                'email_sent' => $emailSent,
            ],
        ], 201);
    }

    public function resend_signup_otp()
    {
        $api = $this->apiLibrary();
        $api->require_method('POST');
        $api->rate_limit(null, 10, 300);
        $input = $this->input();
        $challengeToken = trim((string) ($input['challenge_token'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/', $challengeToken)) {
            $this->respond(['error' => 'This verification request is no longer valid. Please sign up again.'], 401);
        }

        $db = $this->database();
        $this->ensureSignupOtpSchema($db);
        $challengeHash = hash('sha256', $challengeToken);
        $db->beginTransaction();
        $query = $db->prepare(
            'SELECT email, password_hash, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS seconds_since_sent '
            . 'FROM signup_otp_challenges '
            . 'WHERE challenge_hash = ? AND expires_at > NOW() AND attempts < 5 '
            . 'FOR UPDATE'
        );
        $query->execute([$challengeHash]);
        $challenge = $query->fetch(PDO::FETCH_ASSOC);
        if (!$challenge) {
            $db->rollBack();
            $this->respond(['error' => 'This verification request has expired. Please sign up again.'], 401);
        }

        $secondsSinceSent = max(0, (int) $challenge['seconds_since_sent']);
        if ($secondsSinceSent < 30) {
            $db->rollBack();
            $this->respond([
                'error' => 'Please wait before requesting another verification code.',
                'retry_after' => 30 - $secondsSinceSent,
            ], 429);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $otpHash = hash_hmac(
            'sha256',
            $challengeToken . ':' . $code . ':' . hash('sha256', $challenge['password_hash']),
            config_item('jwt_secret')
        );
        try {
            $this->sendSignupOtpEmail($challenge['email'], $code);
        } catch (Throwable $exception) {
            $db->rollBack();
            error_log('SakayLokal signup OTP resend email failed for ' . $challenge['email'] . ': ' . $exception->getMessage());
            $this->respond(['error' => 'Could not resend the verification code. Please try again later.'], 503);
        }

        $update = $db->prepare(
            'UPDATE signup_otp_challenges '
            . 'SET otp_hash = ?, attempts = 0, expires_at = DATE_ADD(NOW(), INTERVAL 10 MINUTE), created_at = NOW() '
            . 'WHERE challenge_hash = ?'
        );
        $update->execute([$otpHash, $challengeHash]);
        $db->commit();
        $this->respond([
            'data' => [
                'message' => 'A new verification code has been sent to your email.',
                'resend_cooldown' => 30,
            ],
        ]);
    }

    private function ensureSignupOtpSchema($db)
    {
        if (self::$signupOtpSchemaReady) {
            return;
        }
        $lock = $db->query("SELECT GET_LOCK('sakaylokal_signup_otp_schema', 10)");
        if ((int) $lock->fetchColumn() !== 1) {
            $this->respond(['error' => 'Account verification is busy. Please retry shortly.'], 503);
        }
        try {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS signup_otp_challenges (
                    challenge_hash CHAR(64) NOT NULL PRIMARY KEY,
                    full_name VARCHAR(150) NOT NULL,
                    email VARCHAR(255) NOT NULL,
                    password_hash VARCHAR(255) NOT NULL,
                    phone VARCHAR(30) NULL,
                    otp_hash CHAR(64) NOT NULL,
                    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                    expires_at DATETIME NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_signup_otp_email (email),
                    KEY idx_signup_otp_expiry (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            self::$signupOtpSchemaReady = true;
        } finally {
            $db->query("SELECT RELEASE_LOCK('sakaylokal_signup_otp_schema')");
        }
    }

    private function sendSignupOtpEmail($email, $code)
    {
        $this->call->library('Brevo_mailer');
        $subject = 'Verify your SakayLokal account';
        $message = '<p>Your SakayLokal email verification code is:</p>'
            . '<p style="font-size:24px;font-weight:bold;letter-spacing:6px">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p>This code expires in 10 minutes. If you did not request it, you can ignore this email.</p>';
        $this->Brevo_mailer->send($email, $subject, $message);
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
