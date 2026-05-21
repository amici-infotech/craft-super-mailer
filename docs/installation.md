# Installation and Setup

## Requirements

- Craft CMS 5
- PHP 8.2 or newer

## Install

Run:

```bash
composer require amici/craft-super-mailer
php craft plugin/install super-mailer
```

You can also install it from **Settings -> Plugins** in the Craft Control Panel.

## Run Migrations

Installing the plugin creates:

- `super_mailer_notifications` for notification element settings.
- `super_mailer_email_logs` for success/failure delivery logs.

If Craft does not run migrations automatically, run:

```bash
php craft migrate/all
```

## Permissions

Assign permissions under **Settings -> Users -> User Groups**:

- `super-mailer:view-notifications`
- `super-mailer:manage-notifications`

Users with view access can inspect notifications and logs. Users with manage access can create, edit, delete, restore, resend, and change settings.

## Mailer Setup

Super Mailer sends through Craft's configured mailer. Confirm Craft's email settings work first:

```bash
php craft mailer/test --to=to@example.com
```

If the Craft mailer cannot send, Super Mailer logs will also show delivery failures.

## Queue Setup

Notification sends are queued. For production, make sure the Craft queue is processed by a worker or cron:

```bash
php craft queue/listen
```

Or use your preferred supervisor setup for Craft queue jobs.

## First Notification

1. Go to **Super Mailer -> Notifications**.
2. Click **New Notification**.
3. Choose an event.
4. Add recipient email addresses.
5. Add a subject.
6. Add an HTML or plain text template path.
7. Preview, test send, then enable the notification.
