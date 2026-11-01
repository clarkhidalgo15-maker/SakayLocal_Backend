# Passenger email notifications

Email subscriptions are opt-in and disabled by default. When creating an advisory, administrators can choose passengers/users, staff, or both as the recipient audience. Publishing queues the advisory automatically for active members of that group who enabled advisory email notifications in their account settings. Weather alerts use Open-Meteo data for Calapan City and are queued for forecast thunderstorms (WMO code 95 or higher) or heavy rain (at least 20 mm/hour, or at least 80% probability with 10 mm/hour or more). The worker checks weather no more than once every 30 minutes and deduplicates each alert type per calendar day.

## Gmail SMTP

1. Copy `.env.example` to `.env` if needed.
2. Set `SMTP_USERNAME` to the sending Gmail address and `SMTP_FROM_EMAIL` to that same address.
3. Set `SMTP_PASSWORD` to a Google App Password. Do not use the normal Gmail password or commit `.env`.
4. Run `composer install` in this directory and configure Apache/PHP to allow outbound SMTP on port 587.
5. Log in to a passenger account and open **Account settings** to opt in to advisory and weather notifications.

The database schema is applied through the versioned `user_notifications_v3` migration on the first API request. Fresh database imports also include the required notification columns and tables in `../dbsql/sakaylokal.sql`.

## Scheduled worker

The worker sends up to 25 queued messages each run. Schedule it to run once per minute so email backlogs are drained while weather checks remain rate-limited to 30 minutes.

From the `LavaLust` directory, the command is:

```text
php console/cli.php notifications:run
```

On Windows Task Scheduler, create a task to run this command every minute, using the full path to `php.exe` and setting the working directory to `LavaLust`. On Linux, add a cron entry such as:

```cron
* * * * * cd /path/to/LavaLust && php console/cli.php notifications:run
```

Check the PHP/server error log for delivery failures. A message is retried up to five times, then marked failed.
