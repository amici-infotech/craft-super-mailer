# Super Mailer Documentation

Super Mailer creates event-driven email notifications for Craft CMS. Notifications are configured in the Control Panel, rendered with Twig, queued for delivery, and logged for auditing.

This documentation is split by task so setup, authoring, testing, and troubleshooting stay easy to find.

Read the full documentation at [docs.amiciinfotech.com/craft-cms/super-mailer](https://docs.amiciinfotech.com/craft-cms/super-mailer).

## Start Here

- [Installation and Setup](installation.md) - install the plugin, enable it, and run migrations.
- [Core Concepts](concepts.md) - understand notifications, events, contexts, conditions, queues, and logs.
- [Control Panel Guide](control-panel.md) - Notifications, Logs, Settings, permissions, and read-only environments.
- [Creating Notifications](notifications.md) - create, duplicate, disable, restore, and configure notifications.

## Developer Reference

- [Email Templates](templates.md) - Twig variables, event context, entry, Formie, and Freeform examples.
- [Events and Conditions](events-and-conditions.md) - event picker, condition rows, PHP conditions, supported event context.
- [Preview and Test Send](preview-and-test-send.md) - preview output, condition debug, recipient preview, and test sends.
- [Logs and Resending](logs-and-resending.md) - delivery logs, detail pages, resends, deletes, and retention.
- [Settings and Console Commands](settings-and-console.md) - plugin settings, `allowAdminChanges`, and log purge commands.
- [Troubleshooting](troubleshooting.md) - common setup, preview, condition, queue, and email delivery issues.

## Quick Mental Model

- A notification listens to one event, such as an entry save or form submission.
- When the event fires, Super Mailer normalizes the event into a serializable context.
- Conditions run before the email is queued.
- The queue job rehydrates the element where possible, renders Twig templates, sends the email, and records a log.
- Preview uses the same rendering layer, but with a fetched example element or a specific `id` query parameter.
- Logs preserve event context so failed or successful emails can be inspected and resent.
