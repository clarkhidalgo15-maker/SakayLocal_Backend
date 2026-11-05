<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once __DIR__ . '/ApiController.php';

class NotificationController extends ApiController
{
    public function run()
    {
        if (PHP_SAPI !== 'cli') {
            $this->respond(['error' => 'This worker is available only through the CLI.'], 404);
        }
        $this->database();
        $this->call->model('Notification_model');
        try {
            $weatherQueued = $this->checkWeather();
        } catch (Throwable $exception) {
            error_log('SakayLokal weather check failed: ' . $exception->getMessage());
            $weatherQueued = 0;
        }
        $this->call->library('Email_sender');
        $sent = 0;
        $failed = 0;
        foreach ($this->Notification_model->pending(25) as $notification) {
            if (!$this->Notification_model->markSending($notification['notification_id'])) {
                continue;
            }
            $this->db = $this->properties['db'];
            $recipient = $this->db->table('users')
                ->select('email')
                ->where('user_id', (int) $notification['user_id'])
                ->where('is_active', 1)
                ->limit(1)
                ->get();
            if (!$recipient) {
                $this->Notification_model->markFailedAttempt($notification['notification_id'], $notification['attempts'], 'Subscriber no longer exists or is inactive.');
                $failed++;
                continue;
            }
            try {
                $this->Email_sender->send(
                    $recipient['email'],
                    $notification['subject'],
                    $notification['message'],
                    'sakaylokal-notification-' . $notification['notification_id']
                );
                $this->Notification_model->markSent($notification['notification_id']);
                $sent++;
            } catch (Throwable $exception) {
                error_log('SakayLokal notification delivery failed: ' . $exception->getMessage());
                $this->Notification_model->markFailedAttempt($notification['notification_id'], $notification['attempts'], $exception->getMessage());
                $failed++;
            }
        }
        $this->respond(['data' => ['weather_alerts_queued' => $weatherQueued, 'sent' => $sent, 'failed' => $failed]]);
    }

    private function checkWeather()
    {
        $checkedAt = $this->Notification_model->getWeatherCheckTime();
        if ($checkedAt && strtotime($checkedAt) > time() - 1800) {
            return 0;
        }
        $context = stream_context_create(['http' => ['timeout' => 15, 'header' => "User-Agent: SakayLokal/1.0\r\n"]]);
        $url = 'https://api.open-meteo.com/v1/forecast?latitude=13.4117&longitude=121.1803&hourly=weather_code,precipitation_probability,precipitation&forecast_days=2&timezone=Asia%2FManila';
        $response = file_get_contents($url, false, $context);
        if ($response === false) {
            throw new RuntimeException('Open-Meteo weather request failed.');
        }
        $forecast = json_decode($response, true);
        if (!isset($forecast['hourly']['time'], $forecast['hourly']['weather_code'], $forecast['hourly']['precipitation_probability'], $forecast['hourly']['precipitation'])) {
            throw new RuntimeException('Open-Meteo returned an incomplete forecast.');
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
        $windowEnd = $now->modify('+24 hours');
        $thunderstorm = false;
        $heavyRain = false;
        foreach ($forecast['hourly']['time'] as $index => $time) {
            $forecastTime = new DateTimeImmutable($time, new DateTimeZone('Asia/Manila'));
            if ($forecastTime < $now || $forecastTime > $windowEnd) {
                continue;
            }
            if ((int) $forecast['hourly']['weather_code'][$index] >= 95) {
                $thunderstorm = true;
            }
            if ((float) $forecast['hourly']['precipitation'][$index] >= 20
                || ((float) $forecast['hourly']['precipitation_probability'][$index] >= 80
                    && (float) $forecast['hourly']['precipitation'][$index] >= 10)) {
                $heavyRain = true;
            }
        }
        $dateKey = $now->format('Y-m-d');
        $queued = 0;
        if ($thunderstorm) {
            $queued += $this->Notification_model->queueWeather('weather-' . $dateKey . '-thunderstorm', 'Severe weather alert for Calapan', 'Thunderstorms are forecast in Calapan within the next 24 hours. Please check local advisories before travelling and take precautions.');
        }
        if ($heavyRain) {
            $queued += $this->Notification_model->queueWeather('weather-' . $dateKey . '-heavy-rain', 'Heavy rain alert for Calapan', 'Heavy rain is forecast in Calapan within the next 24 hours. Allow extra travel time and check local advisories before travelling.');
        }
        $this->Notification_model->markWeatherChecked();
        return $queued;
    }
}
