# Passenger email notifications

Email subscriptions are opt-in and disabled by default. When creating an advisory, administrators can choose passengers/users, staff, or both as the recipient audience. Publishing queues the advisory automatically for active members of that group who enabled advisory email notifications in their account settings. Weather alerts use Open-Meteo data for Calapan City and are queued for forecast thunderstorms (WMO code 95 or higher) or heavy rain (at least 20 mm/hour, or at least 80% probability with 10 mm/hour or more). The worker checks weather no more than once every 30 minutes and deduplicates each alert type per calendar day.

## Email delivery without a Cron Job

Email is sent through PHPMailer over SMTP. Welcome emails are sent during account registration, and advisory/announcement emails are sent during the admin publish request; neither requires a Cron Job. Configure `SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_FROM_EMAIL`, and `SMTP_FROM_NAME` on the backend web service. For Gmail, use `smtp.gmail.com`, port `587`, and a Google App Password (not the normal Gmail password); use the Gmail address for both `SMTP_USERNAME` and `SMTP_FROM_EMAIL`.

The static frontend cannot run PHP or send email by itself. Keep the PHP backend web service deployed and set its SMTP environment variables. Render must also allow outbound connections to the SMTP host and port. If sending fails, inspect backend logs for PHPMailer’s SMTP error. The admin publish response includes queued, sent, and failed email counts. Users must opt in to advisory emails in Account settings.

New passenger registrations receive a welcome email immediately. If delivery fails, account creation still succeeds, the API reports `email_sent: false`, and the backend logs the delivery error.

Passenger advisory and weather notifications are opt-in. Log in to a passenger account and open **Account settings** to enable them.

The database schema is applied through the versioned `user_notifications_v3` migration on the first API request. Fresh database imports also include the required notification columns and tables in `../dbsql/sakaylokal.sql`.

## Optional weather worker

Severe-weather emails still require the optional CLI worker because weather forecasts are checked on a schedule. Without a Cron Job or another scheduler, weather alerts are not automatically checked or sent. This does not affect welcome or admin advisory emails.

If you have a scheduler, run this from the `LavaLust` directory:

```text
php console/cli.php notifications:run
```

On Windows Task Scheduler, create a task to run this command every minute, using the full path to `php.exe` and setting the working directory to `LavaLust`. On Linux, add a cron entry such as:

```cron
* * * * * cd /path/to/LavaLust && php console/cli.php notifications:run
```

Check the PHP/server error log for delivery failures. A message is retried up to five times, then marked failed.
