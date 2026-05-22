# Troubleshooting

## Event Is Missing From the Picker

Super Mailer only lists supported content-related events.

Check:

- The plugin that owns the event is installed and enabled.
- The event constant is discoverable.
- The event is content-related or explicitly supported.

Supported third-party submission events depend on the installed plugin and the event classes it exposes.

## Preview Shows the Wrong Element

Use an explicit element ID:

```text
/admin/super-mailer/notifications/123/preview?id=456
```

If no matching element exists, preview shows a warning in the raw context.

## Template Variable Does Not Work

The event picker displays PHP callback variables for the selected event. Email templates use the Twig `event` variable, which contains the selected event's normalized data where available.

PHP:

```php
$event->sender
```

Twig:

```twig
{{ event.sender }}
{{ event.element.title ?? null }}
```

Use preview's **Template Variables** section to see exactly what is available for the selected notification event. Not every event exposes the same properties.

## Email Sends for Drafts

Super Mailer ignores drafts, revisions, derivative elements, and provisional drafts. If sends still appear to happen during editing, check which event is selected and inspect the log detail event context.

## Conditions Do Not Match

Use preview's **Condition Debug** section.

Common issues:

- Entry status uses enabled/disabled logic, not only Craft's `live` value.
- Author and customer conditions store user IDs.
- Site ID must match the element's site ID.
- Selector conditions store IDs or handles depending on the filter. Check the **Expected** and **Actual** columns in condition debug.
- Negated rows use the **Comparison** column, such as `is not` or `does not contain`, to explain why a rule passed.
- PHP conditions only run against the actual event object when the event fires.

## New Entry Condition Does Not Send

Craft can save drafts before applying content to a canonical entry. Super Mailer checks common new-element indicators and the draft apply request, but custom element types may behave differently.

Use log detail and condition debug to inspect:

```twig
event.isNew
event.element.firstSave
rawEventContext
```

## Send Fails With "Mailer returned false"

Check the log detail page. Super Mailer stores recent Craft mailer errors when available.

Also test Craft's mailer directly:

```bash
php craft mailer/test --to=to@example.com
```

## Queue Does Not Send

Make sure the queue is running:

```bash
php craft queue/listen
```

Or process pending jobs from **Utilities -> Queue Manager** in the Control Panel if available.

## Logs Are Missing

Check:

- The email log table exists.
- The notification was enabled.
- Conditions passed.
- The queue job actually ran.
- Logs were not purged by retention.

## Cannot Save Settings

If `allowAdminChanges` is false, Craft blocks settings changes in that environment. Change the config in the appropriate environment or update settings where admin changes are allowed.

## Deleted Notification Cannot Be Restored

Switch the notification element index to trashed items and use the restore action. If restore is not visible, confirm the user has `super-mailer:manage-notifications`.
