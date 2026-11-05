# Passenger email notifications

Email subscriptions are opt-in and disabled by default. When creating an advisory, administrators can choose passengers/users, staff, or both as the recipient audience. Publishing queues the advisory automatically for active members of that group who enabled advisory email notifications in their account settings. Weather alerts use Open-Meteo data for Calapan City and are queued for forecast thunderstorms (WMO code 95 or higher) or heavy rain (at least 20 mm/hour, or at least 80% probability with 10 mm/hour or more). The worker checks weather no more than once every 30 minutes and deduplicates each alert type per calendar day.

## Email delivery on Render

Email is sent through PHPMailer over SMTP. In the backend web service and the notification Cron Job, set `SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_FROM_EMAIL`, and `SMTP_FROM_NAME`. For Gmail, use `smtp.gmail.com`, port `587`, and a Google App Password (not the normal Gmail password); use the Gmail address for both `SMTP_USERNAME` and `SMTP_FROM_EMAIL`.

Render must allow outbound connections to the selected SMTP host and port. If sending fails, inspect the backend or Cron Job logs for PHPMailer’s SMTP error. The API reports `email_sent: false` for failed welcome email attempts; queued notifications are retried up to five times.

New passenger registrations receive a welcome email immediately. If delivery fails, account creation still succeeds, the API reports `email_sent: false`, and the backend logs the delivery error.

Passenger advisory and weather notifications are opt-in. Log in to a passenger account and open **Account settings** to enable them.

The database schema is applied through the versioned `user_notifications_v3` migration on the first API request. Fresh database imports also include the required notification columns and tables in `../dbsql/sakaylokal.sql`.

## Scheduled worker

The worker sends up to 25 queued messages each run. Schedule it to run once per minute so email backlogs are drained while weather checks remain rate-limited to 30 minutes.
The backend `render.yaml` defines a `sakaylocal-notifications` Cron Job that runs `php /var/www/html/console/cli.php notifications:run` once per minute. When syncing the Render Blueprint, set its database, JWT, and SMTP environment variables to match the backend service. Without this worker, advisory and weather notifications remain queued and are not emailed, even if SMTP is configured correctly.

From the `LavaLust` directory, the command is:

```text
php console/cli.php notifications:run
```

On Windows Task Scheduler, create a task to run this command every minute, using the full path to `php.exe` and setting the working directory to `LavaLust`. On Linux, add a cron entry such as:

```cron
* * * * * cd /path/to/LavaLust && php console/cli.php notifications:run
```

Check the PHP/server error log for delivery failures. A message is retried up to five times, then marked failed.
