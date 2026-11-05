# Passenger email notifications

Email subscriptions are opt-in and disabled by default. When creating an advisory, administrators can choose passengers/users, staff, or both as the recipient audience. Publishing queues the advisory automatically for active members of that group who enabled advisory email notifications in their account settings. Weather alerts use Open-Meteo data for Calapan City and are queued for forecast thunderstorms (WMO code 95 or higher) or heavy rain (at least 20 mm/hour, or at least 80% probability with 10 mm/hour or more). The worker checks weather no more than once every 30 minutes and deduplicates each alert type per calendar day.

## Email delivery without a Cron Job

Email is sent through the Resend HTTPS API. Create a Resend account, add and verify a domain you own, then create an API key in the Resend dashboard. In the Render backend web service, configure `RESEND_API_KEY`, `RESEND_FROM_EMAIL` (an address at the verified domain), and optionally `RESEND_FROM_NAME`. Remove the old `SMTP_*` variables. Do not put the API key in frontend code or commit it. Resend must accept the sender domain before emails can be sent to arbitrary users.

The static frontend cannot run PHP or send email by itself. Keep the PHP backend web service deployed and set its Resend environment variables. HTTPS API requests do not use SMTP ports. If sending fails, inspect backend logs for the Resend error. The admin publish response reports how many email notifications were queued; the background worker sends them after the API response. Users must opt in to advisory emails in Account settings.

New passenger registrations receive a welcome email immediately. If delivery fails, account creation still succeeds, the API reports `email_sent: false`, and the backend logs the delivery error.

Passenger advisory and weather notifications are opt-in. Log in to a passenger account and open **Account settings** to enable them.

The database schema is applied through the versioned `user_notifications_v3` migration on the first API request. Fresh database imports also include the required notification columns and tables in `../dbsql/sakaylokal.sql`.

## Background worker and free Render services

The Docker image runs Apache and a CLI notification worker under Supervisor. The worker checks for queued messages and severe-weather alerts once per minute without a separate Render Cron Job. It sends up to 25 queued messages each run. Render free web services can spin down when idle, so queued messages may wait until the backend wakes or may take multiple runs to drain a large queue. The worker uses the backend service's resources.

To run a one-off check manually from the `LavaLust` directory:

```text
php console/cli.php notifications:run
```

Check the PHP/server error log for delivery failures. A message is retried up to five times, then marked failed. Publishing the same advisory again resets its failed email deliveries for another attempt.
