<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once __DIR__ . '/ApiController.php';

class AuthController extends ApiController
{
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
            $token = $this->createAdminToken($user);
            unset($user['password_hash']);
            $user['access_token'] = $token;
            $this->respond(['data' => $user]);
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

            try {
                $this->sendWelcomeEmail($email, $fullName);
            } catch (Throwable $exception) {
                error_log('SakayLokal welcome email failed for ' . $email . ': ' . $exception->getMessage());
            }

            $this->respond(['data' => ['id' => $id, 'message' => 'Account created.']], 201);
        }

        private function sendWelcomeEmail($email, $fullName)
        {
            $host = getenv('SMTP_HOST') ?: '';
            $username = getenv('SMTP_USERNAME') ?: '';
            $password = getenv('SMTP_PASSWORD') ?: '';

            if ($host === '' || $username === '' || $password === '') {
                return;
            }

            $this->call->library('Smtp_mailer');
            $subject = 'Welcome to SakayLokal';
            $safeName = htmlspecialchars($fullName ?: 'Passenger', ENT_QUOTES, 'UTF-8');
            $message = "<p>Hi {$safeName},</p>"
                . "<p>Welcome to SakayLokal! Your passenger account has been created successfully.</p>"
                . "<p>You can now log in to find terminals, routes, fares, and submit reports.</p>"
                . "<p>Thank you for joining the SakayLokal community.</p>";

            $this->Smtp_mailer->send($email, $subject, $message);
        }

}
