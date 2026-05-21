# Settings and Console Commands

## Settings

Go to **Super Mailer -> Settings**.

[Screenshot for General Settings Page]
Add a screenshot of the Super Mailer settings page showing Plugin Name and Email Log Retention fields in an editable environment.

Available settings:

- **Plugin Name**: label shown in the Control Panel navigation.
- **Email Log Retention**: number of days to keep email logs.

Set retention to `0` to disable automatic purging.

## Console Commands

### Purge Logs by Retention

```bash
php craft super-mailer/logs/purge
```

This uses the configured **Email Log Retention** value unless a custom retention value is provided by the command options.

### Purge All Logs

```bash
php craft super-mailer/logs/purge-all
```

This permanently deletes all email logs.

## Queue

Super Mailer sends email through Craft's queue. For production, use a queue worker:

```bash
php craft queue/listen
```

Or use a supervisor-managed worker.

## Mailer

Super Mailer uses Craft's configured mailer transport. Test the Craft mailer before debugging Super Mailer sends:

```bash
php craft mailer/test --to=to@example.com
```
