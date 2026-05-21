# Super Mailer for Craft CMS 5

Super Mailer adds event-driven email notifications to Craft CMS. Create Control Panel managed notifications, choose the Craft or plugin event that should trigger them, add conditions, render Twig email templates, queue delivery, and review success or failure logs.

## Features

- Create email notifications from the Craft Control Panel.
- Choose from Craft element lifecycle events and supported third-party plugin events such as form submission events.
- Send HTML and/or plain text templates from the site templates folder.
- Use Twig in subjects, recipients, sender fields, and email templates.
- Add condition rows and optional PHP expressions before a notification queues.
- Preview event context, rendered recipients, subject, body, and condition results before sending.
- Send test emails from the preview screen.
- Queue delivery with detailed success and failure logs.
- Resend logged emails individually or in bulk.
- Purge logs automatically by retention setting or manually from CP/console.

## Requirements

- Craft CMS 5
- PHP 8.2 or newer

## Installation

Run this command in your Craft project:

```bash
composer require amici/craft-super-mailer
php craft plugin/install super-mailer
```

You can also install it from **Settings -> Plugins** in the Craft Control Panel.

For the full setup flow, see the [Super Mailer documentation](https://docs.amiciinfotech.com/craft-cms/super-mailer).

## Quick Setup

1. Go to **Super Mailer -> Notifications**.
2. Create a new notification.
3. Choose the event that should trigger the email.
4. Add recipients, subject, and template paths.
5. Add conditions if the email should only send in certain cases.
6. Use **Preview** to inspect rendered recipients, body, variables, and condition results.
7. Send a test email, then enable the notification.
8. Review delivery under **Super Mailer -> Logs**.

## Documentation

Read the full documentation at [docs.amiciinfotech.com/craft-cms/super-mailer](https://docs.amiciinfotech.com/craft-cms/super-mailer).

Local docs are split by task:

- [Documentation Home](docs/README.md)
- [Installation and Setup](docs/installation.md)
- [Core Concepts](docs/concepts.md)
- [Control Panel Guide](docs/control-panel.md)
- [Creating Notifications](docs/notifications.md)
- [Email Templates](docs/templates.md)
- [Events and Conditions](docs/events-and-conditions.md)
- [Preview and Test Send](docs/preview-and-test-send.md)
- [Logs and Resending](docs/logs-and-resending.md)
- [Settings and Console Commands](docs/settings-and-console.md)
- [Troubleshooting](docs/troubleshooting.md)

## Control Panel

The plugin adds a **Super Mailer** section with:

- **Notifications** for creating and managing event-triggered emails.
- **Logs** for viewing delivery attempts, failures, details, and resends.
- **Settings** for plugin name and email log retention.

## Permissions

The plugin registers these permissions:

- `super-mailer:view-notifications`
- `super-mailer:manage-notifications`

## License

Proprietary - Copyright (c) 2026 Amici Infotech
