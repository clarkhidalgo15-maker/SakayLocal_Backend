<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

use PHPMailer\PHPMailer\PHPMailer;

class Smtp_mailer
{
    private function envValue($key, $default = '')
    {
        $value = getenv($key);
        if ($value === false || $value === null || $value === '') {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
        }

        if (is_string($value)) {
            return trim($value);
        }

        return (string) $value;
    }

    private function looksLikeHtml($content)
    {
        return is_string($content) && preg_match('/<\s*(?:[A-Za-z][^>]*>|\/?[A-Za-z][^>]*\s*\/?)>/', $content) === 1;
    }

    public function send($recipient, $subject, $message)
    {
        $resendApiKey = $this->envValue('RESEND_API_KEY');
        if ($resendApiKey !== '') {
            $this->sendWithResend($recipient, $subject, $message, $resendApiKey);
            return;
        }

        $fromEmail = $this->envValue('SMTP_FROM_EMAIL');
        $username = $this->envValue('SMTP_USERNAME', $fromEmail);
        $host = $this->envValue('SMTP_HOST', 'smtp.gmail.com');
        $password = $this->envValue('SMTP_PASSWORD');
        $port = (int) $this->envValue('SMTP_PORT', '587');
        $fromEmail = $fromEmail !== '' ? $fromEmail : $username;
        $fromName = $this->envValue('SMTP_FROM_NAME', 'SakayLokal');

        if ($host === '' || $username === '' || $password === '' || $fromEmail === '') {
            throw new RuntimeException('Email delivery is not configured. Set SMTP_FROM_EMAIL and SMTP_PASSWORD in the server environment.');
        }

        if (strtolower($host) === 'smtp.gmail.com') {
            $password = preg_replace('/\s+/', '', $password);
        }

        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Recipient email is invalid: ' . htmlspecialchars($recipient, ENT_QUOTES, 'UTF-8'));
        }

        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Sender email is invalid: ' . htmlspecialchars($fromEmail, ENT_QUOTES, 'UTF-8'));
        }

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->SMTPAuth = true;
        $mail->Username = $username;
        $mail->Password = $password;
        $mail->Port = $port;
        $mail->SMTPAutoTLS = true;
        $mail->Timeout = 20;
        $mail->CharSet = 'UTF-8';
        $mail->SMTPSecure = $port === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($recipient);
        $mail->Subject = (string) $subject;
        $isHtml = $this->looksLikeHtml($message);
        $mail->isHTML($isHtml);
        $mail->Body = (string) $message;
        if ($isHtml) {
            $plainText = preg_replace('/<br\s*\/?\s*>/i', "\n", (string) $message);
            $plainText = strip_tags($plainText);
            $mail->AltBody = trim(preg_replace('/\s*\n\s*/', "\n", $plainText) ?? $plainText);
        } else {
            $mail->AltBody = (string) $message;
        }
        $mail->send();
    }

    private function sendWithResend($recipient, $subject, $message, $apiKey)
    {
        $fromEmail = $this->envValue('RESEND_FROM_EMAIL');
        $fromName = $this->envValue('SMTP_FROM_NAME', 'SakayLokal');

        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Resend email delivery requires a valid RESEND_FROM_EMAIL on a verified sender domain.');
        }
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Recipient email is invalid: ' . htmlspecialchars($recipient, ENT_QUOTES, 'UTF-8'));
        }

        $payload = [
            'from' => $fromName . ' <' . $fromEmail . '>',
            'to' => [$recipient],
            'subject' => (string) $subject,
        ];
        if ($this->looksLikeHtml($message)) {
            $payload['html'] = (string) $message;
            $plainText = preg_replace('/<br\s*\/?\s*>/i', "\n", (string) $message);
            $plainText = strip_tags($plainText);
            $payload['text'] = trim(preg_replace('/\s*\n\s*/', "\n", $plainText) ?? $plainText);
        } else {
            $payload['text'] = (string) $message;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Authorization: Bearer {$apiKey}\r\nContent-Type: application/json\r\nAccept: application/json\r\n",
                'content' => json_encode($payload, JSON_THROW_ON_ERROR),
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);
        $connectionError = null;
        set_error_handler(static function ($severity, $message) use (&$connectionError) {
            $connectionError = $message;
            return true;
        });
        try {
            $responseBody = file_get_contents('https://api.resend.com/emails', false, $context);
        } finally {
            restore_error_handler();
        }
        $statusLine = $http_response_header[0] ?? '';

        if ($responseBody === false) {
            $detail = $connectionError !== null ? ' ' . $connectionError : '';
            throw new RuntimeException('Could not connect to the Resend email API over HTTPS.' . $detail);
        }

        if (!preg_match('/\s(\d{3})\s/', $statusLine, $statusMatch)) {
            throw new RuntimeException('Resend returned an invalid HTTP response.');
        }

        $statusCode = (int) $statusMatch[1];
        $response = json_decode($responseBody, true);
        if ($statusCode < 200 || $statusCode >= 300) {
            $providerMessage = is_array($response) ? ($response['message'] ?? $response['error'] ?? null) : null;
            $detail = is_string($providerMessage) && $providerMessage !== ''
                ? $providerMessage
                : 'HTTP ' . $statusCode;
            throw new RuntimeException('Resend rejected the email: ' . $detail);
        }

        if (!is_array($response) || empty($response['id'])) {
            throw new RuntimeException('Resend returned success without an email ID.');
        }
    }
}
