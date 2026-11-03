# Passenger email notifications

Email subscriptions are opt-in and disabled by default. When creating an advisory, administrators can choose passengers/users, staff, or both as the recipient audience. Publishing queues the advisory automatically for active members of that group who enabled advisory email notifications in their account settings. Weather alerts use Open-Meteo data for Calapan City and are queued for forecast thunderstorms (WMO code 95 or higher) or heavy rain (at least 20 mm/hour, or at least 80% probability with 10 mm/hour or more). The worker checks weather no more than once every 30 minutes and deduplicates each alert type per calendar day.

## Gmail SMTP

1. Copy `.env.example` to `.env` if needed.
2. In Render's backend service environment, set `SMTP_FROM_EMAIL` to the sending Gmail address and `SMTP_PASSWORD` to its Google App Password. Do not use the normal Gmail password or commit `.env`.
3. Gmail host, port 587, and username default to `smtp.gmail.com`, `587`, and `SMTP_FROM_EMAIL`; set `SMTP_HOST`, `SMTP_PORT`, or `SMTP_USERNAME` only if you need to override these defaults.
4. The Docker image installs PHPMailer from `composer.json` and loads Composer's autoloader when the backend starts.
5. Log in to a passenger account and open **Account settings** to opt in to advisory and weather notifications.

New passenger registrations receive a welcome email immediately. If delivery fails, account creation still succeeds, the API reports `email_sent: false`, and the backend logs the delivery error.

The database schema is applied through the versioned `user_notifications_v3` migration on the first API request. Fresh database imports also include the required notification columns and tables in `../dbsql/sakaylokal.sql`.

## Scheduled worker

The worker sends up to 25 queued messages each run. Schedule it to run once per minute so email backlogs are drained while weather checks remain rate-limited to 30 minutes.
On Render, create a separate Cron Job using the backend repository and Docker environment, and run `php console/cli.php notifications:run` once per minute. Configure the same database and SMTP environment variables on the Cron Job as on the web service. Without this worker, advisory and weather notifications remain queued and are not emailed.

From the `LavaLust` directory, the command is:

```text
php console/cli.php notifications:run
```

On Windows Task Scheduler, create a task to run this command every minute, using the full path to `php.exe` and setting the working directory to `LavaLust`. On Linux, add a cron entry such as:

```cron
* * * * * cd /path/to/LavaLust && php console/cli.php notifications:run
```

Check the PHP/server error log for delivery failures. A message is retried up to five times, then marked failed.
