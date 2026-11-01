<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Notification_model extends Model
{
    public function queueAdvisory($id, $title, $body, $audience = 'passengers')
    {
        if (!in_array($audience, ['passengers', 'staff', 'both'], true)) {
            throw new InvalidArgumentException('Unsupported advisory recipient audience.');
        }
        $roles = $audience === 'both'
            ? ['passenger', 'staff']
            : [$audience === 'staff' ? 'staff' : 'passenger'];
        $rolePlaceholders = implode(',', array_fill(0, count($roles), '?'));
        $this->execute(
            "INSERT IGNORE INTO email_notifications (user_id, event_key, subject, message)
             SELECT user_id, ?, ?, ? FROM users
             WHERE is_active = 1 AND notify_advisories = 1 AND email <> ''
               AND role IN ({$rolePlaceholders})",
            array_merge(
                ['advisory-' . (int) $id, 'SakayLokal public advisory: ' . $title, $body],
                $roles
            )
        );
    }

    public function queueWeather($eventKey, $subject, $message)
    {
        return $this->insertForSubscribers('notify_weather', $eventKey, $subject, $message);
    }

    public function pending($limit = 25)
    {
        $limit = max(1, min(100, (int) $limit));
        $this->execute(
            "UPDATE email_notifications SET status = 'pending'
             WHERE status = 'sending' AND updated_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
        );
        return $this->execute(
            "SELECT notification_id, user_id, event_key, subject, message, attempts
             FROM email_notifications
             WHERE status = 'pending' AND attempts < 5
             ORDER BY created_at, notification_id
             LIMIT {$limit}"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function markSending($id)
    {
        return $this->execute(
            "UPDATE email_notifications SET status = 'sending' WHERE notification_id = ? AND status = 'pending'",
            [(int) $id]
        )->rowCount() > 0;
    }

    public function markSent($id)
    {
        $this->execute(
            "UPDATE email_notifications SET status = 'sent', sent_at = NOW(), last_error = NULL WHERE notification_id = ?",
            [(int) $id]
        );
    }

    public function markFailedAttempt($id, $attempts, $error)
    {
        $message = substr($error, 0, 500);
        $this->execute(
            "UPDATE email_notifications
             SET attempts = ?, status = ?, last_error = ?
             WHERE notification_id = ?",
            [(int) $attempts + 1, (int) $attempts + 1 >= 5 ? 'failed' : 'pending', $message, (int) $id]
        );
    }

    public function getWeatherCheckTime()
    {
        $result = $this->execute("SELECT checked_at FROM weather_alert_state WHERE state_key = 'open-meteo'")->fetch(PDO::FETCH_ASSOC);
        return $result['checked_at'] ?? null;
    }

    public function markWeatherChecked()
    {
        $this->execute(
            "INSERT INTO weather_alert_state (state_key, checked_at) VALUES ('open-meteo', NOW())
             ON DUPLICATE KEY UPDATE checked_at = VALUES(checked_at)"
        );
    }

    private function insertForSubscribers($preference, $eventKey, $subject, $message)
    {
        if (!in_array($preference, ['notify_advisories', 'notify_weather'], true)) {
            throw new InvalidArgumentException('Unsupported notification preference.');
        }
        return $this->execute(
            "INSERT IGNORE INTO email_notifications (user_id, event_key, subject, message)
             SELECT user_id, ?, ?, ? FROM users
             WHERE is_active = 1 AND {$preference} = 1 AND email <> ''",
            [$eventKey, $subject, $message]
        )->rowCount();
    }

    private function execute($sql, array $parameters = [])
    {
        $statement = $this->db->connection()->prepare($sql);
        $statement->execute($parameters);
        return $statement;
    }
}
