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
        $host = $this->envValue('SMTP_HOST');
        $username = $this->envValue('SMTP_USERNAME');
        $password = $this->envValue('SMTP_PASSWORD');
        $port = (int) $this->envValue('SMTP_PORT', '587');
        $fromEmail = $this->envValue('SMTP_FROM_EMAIL', $username);
        $fromName = $this->envValue('SMTP_FROM_NAME', 'SakayLokal');

        if ($host === '' || $username === '' || $password === '' || $fromEmail === '') {
            throw new RuntimeException('Email delivery is not configured. Set the SMTP values in the server .env file.');
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
}
