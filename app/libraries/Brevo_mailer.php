<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Brevo_mailer
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
        $apiKey = $this->envValue('BREVO_API_KEY');
        $fromEmail = $this->envValue('BREVO_SENDER_EMAIL');
        $fromName = $this->envValue('BREVO_SENDER_NAME', 'SakayLokal');

        if ($apiKey === '') {
            throw new RuntimeException('Email delivery is not configured. Set BREVO_API_KEY in the server environment.');
        }
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Recipient email is invalid.');
        }
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('BREVO_SENDER_EMAIL must be a valid, verified Brevo sender address.');
        }

        $isHtml = $this->looksLikeHtml($message);
        $payload = [
            'sender' => ['email' => $fromEmail, 'name' => $fromName],
            'to' => [['email' => $recipient]],
            'subject' => (string) $subject,
        ];

        if ($isHtml) {
            $plainText = preg_replace('/<br\s*\/?\s*>/i', "\n", (string) $message);
            $plainText = strip_tags($plainText);
            $payload['htmlContent'] = (string) $message;
            $payload['textContent'] = trim(preg_replace('/\s*\n\s*/', "\n", $plainText) ?? $plainText);
        } else {
            $payload['textContent'] = (string) $message;
        }

        $body = json_encode($payload);
        if ($body === false) {
            throw new RuntimeException('Email content could not be encoded for Brevo.');
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "accept: application/json\r\n"
                    . "api-key: {$apiKey}\r\n"
                    . "content-type: application/json\r\n",
                'content' => $body,
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);
        $response = @file_get_contents('https://api.brevo.com/v3/smtp/email', false, $context);

        if ($response === false) {
            throw new RuntimeException('Could not connect to the Brevo API.');
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
                $status = (int) $matches[1];
            }
        }

        if ($status < 200 || $status >= 300) {
            $error = json_decode($response, true);
            $detail = is_array($error) && isset($error['message']) ? (string) $error['message'] : '';
            $detail = trim(preg_replace('/[\r\n\x00-\x1F\x7F]+/', ' ', $detail) ?? '');
            if ($detail !== '') {
                $detail = ' ' . substr($detail, 0, 300);
            }
            throw new RuntimeException('Brevo API returned HTTP ' . $status . '.' . $detail);
        }
    }
}
