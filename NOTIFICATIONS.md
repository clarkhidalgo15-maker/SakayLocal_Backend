# Passenger email notifications

Email subscriptions are opt-in and disabled by default. When creating an advisory, administrators can choose passengers/users, staff, or both as the recipient audience. Publishing queues the advisory automatically for active members of that group who enabled advisory email notifications in their account settings. Weather alerts use Open-Meteo data for Calapan City and are queued for forecast thunderstorms (WMO code 95 or higher) or heavy rain (at least 20 mm/hour, or at least 80% probability with 10 mm/hour or more). The worker checks weather no more than once every 30 minutes and deduplicates each alert type per calendar day.

## Configure Brevo email delivery

Welcome emails and queued advisory/weather notifications are sent through the Brevo API over HTTPS, so they do not depend on SMTP ports.

1. In Brevo, create an API key and verify the sender email address you want to send from.
2. In the `LavaLust` `.env` file, set `BREVO_API_KEY` to the API key, `BREVO_SENDER_EMAIL` to the verified sender address, and optionally `BREVO_SENDER_NAME` (defaults to `SakayLokal`).
3. Keep `.env` local; it is ignored by Git and must not be committed.
4. On Render, set the same three variables in the backend web service's Environment settings. Remove the old `SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, and `SMTP_PASSWORD` variables; they are no longer used.
5. Redeploy the backend after changing the environment or code.
6. Register a passenger account with an email address you can access. On successful delivery, the API response contains `data.email_sent: true`; if delivery fails, account creation still succeeds and the backend error log contains the Brevo API error.

To process queued advisory and weather emails manually from the `LavaLust` directory, run:

```text
php console/cli.php notifications:run
```

This sends real email to opted-in users, so use test accounts while developing.

## Email delivery without a Cron Job

The static frontend cannot run PHP or send email by itself. Keep the PHP backend web service deployed and configure its Brevo API environment variables. The admin publish response reports how many email notifications were queued; the background worker sends them after the API response. Users must opt in to advisory emails in Account settings.

New passenger registrations receive a welcome email immediately. If delivery fails, account creation still succeeds, the API reports `email_sent: false`, and the backend logs the delivery error.

Passenger advisory and weather notifications are opt-in. Log in to a passenger account and open **Account settings** to enable them.

The database schema is applied through the versioned `user_notifications_v3` migration on the first API request. Fresh database imports also include the required notification columns and tables in `../dbsql/sakaylokal.sql`.

## Background worker and free Render services

The Docker image runs Apache and a CLI notification worker under Supervisor. The worker checks for queued messages and severe-weather alerts once per minute without a separate Render Cron Job. It sends up to 25 queued messages each run. Render free web services can spin down when idle, so queued messages may wait until the backend wakes or may take multiple runs to drain a large queue. The worker uses the backend service's resources.

To run a one-off check manually from the `LavaLust` directory:

```text
php console/cli.php notifications:run
```

Check the PHP/server error log and Brevo transactional email logs for delivery failures. A message is retried up to five times, then marked failed. Publishing the same advisory again resets its failed email deliveries for another attempt.
