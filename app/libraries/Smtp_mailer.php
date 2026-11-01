<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

use PHPMailer\PHPMailer\PHPMailer;

class Smtp_mailer
{
    public function send($recipient, $subject, $message)
    {
        $host = getenv('SMTP_HOST') ?: '';
        $username = getenv('SMTP_USERNAME') ?: '';
        $password = getenv('SMTP_PASSWORD') ?: '';
        $port = (int) (getenv('SMTP_PORT') ?: 587);
        $fromEmail = getenv('SMTP_FROM_EMAIL') ?: $username;
        $fromName = getenv('SMTP_FROM_NAME') ?: 'SakayLokal';
        if ($host === '' || $username === '' || $password === '' || $fromEmail === '') {
            throw new RuntimeException('Email delivery is not configured. Set the SMTP values in the server .env file.');
        }

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->SMTPAuth = true;
        $mail->Username = $username;
        $mail->Password = $password;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $port;
        $mail->Timeout = 20;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($recipient);
        $mail->Subject = $subject;
        $mail->Body = $message;
        $mail->send();
    }
}
