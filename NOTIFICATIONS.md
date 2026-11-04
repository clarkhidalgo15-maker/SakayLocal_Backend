# Passenger email notifications

Email subscriptions are opt-in and disabled by default. When creating an advisory, administrators can choose passengers/users, staff, or both as the recipient audience. Publishing queues the advisory automatically for active members of that group who enabled advisory email notifications in their account settings. Weather alerts use Open-Meteo data for Calapan City and are queued for forecast thunderstorms (WMO code 95 or higher) or heavy rain (at least 20 mm/hour, or at least 80% probability with 10 mm/hour or more). The worker checks weather no more than once every 30 minutes and deduplicates each alert type per calendar day.

## Email delivery on Render

The preferred delivery method is the Resend HTTPS API. This avoids SMTP connection failures on hosting platforms that restrict outbound SMTP.

1. Create a Resend account, add and verify a sender domain, and create an API key.
2. In the Render backend service environment, set `RESEND_API_KEY` to the API key and `RESEND_FROM_EMAIL` to an email address on the verified domain. Do not use a Gmail address as the sender unless Resend explicitly verifies it.
3. Redeploy the backend and register with an email address you can access. The API reports `email_sent: true` only when Resend accepts the message; if delivery fails, inspect the backend logs.
4. The Docker image loads PHPMailer for optional SMTP fallback. When `RESEND_API_KEY` is set, all welcome and queued notification emails use the Resend HTTPS API instead.

For local development or an SMTP provider that permits outbound SMTP, configure `SMTP_FROM_EMAIL` and `SMTP_PASSWORD`. Gmail SMTP also defaults to host `smtp.gmail.com`, port `587`, and username `SMTP_FROM_EMAIL`. Use a Google App Password, never the regular Gmail password, and do not commit secrets.

New passenger registrations receive a welcome email immediately. If delivery fails, account creation still succeeds, the API reports `email_sent: false`, and the backend logs the delivery error.

Passenger advisory and weather notifications are opt-in. Log in to a passenger account and open **Account settings** to enable them.

The database schema is applied through the versioned `user_notifications_v3` migration on the first API request. Fresh database imports also include the required notification columns and tables in `../dbsql/sakaylokal.sql`.

## Scheduled worker

The worker sends up to 25 queued messages each run. Schedule it to run once per minute so email backlogs are drained while weather checks remain rate-limited to 30 minutes.
On Render, create a separate Cron Job using the backend repository and Docker environment, and run `php console/cli.php notifications:run` once per minute. Configure the same database and email environment variables on the Cron Job as on the web service. Without this worker, advisory and weather notifications remain queued and are not emailed.

From the `LavaLust` directory, the command is:

```text
php console/cli.php notifications:run
```

On Windows Task Scheduler, create a task to run this command every minute, using the full path to `php.exe` and setting the working directory to `LavaLust`. On Linux, add a cron entry such as:

```cron
* * * * * cd /path/to/LavaLust && php console/cli.php notifications:run
```

Check the PHP/server error log for delivery failures. A message is retried up to five times, then marked failed.
