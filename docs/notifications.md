# Creating Notifications

## Basic Notification

[Screenshot for New Notification Page]
Add a screenshot of the blank **New Notification** page before an event is selected, showing the title/handle fields and event search input.

1. Go to **Super Mailer -> Notifications**.
2. Click **New Notification**.
3. Enter a title.
4. Choose an event.
5. Enter recipients.
6. Enter a subject.
7. Add an HTML template path, a plain text template path, or both.
8. Save.
9. Open **Preview**.
10. Send a test email.
11. Enable the notification.

## Event Picker

Start typing a class name, event constant, or event name.

[Screenshot for Event Picker Search]
Add a screenshot of the event picker dropdown while searching, showing multiple matching event options and keyboard-highlighted selection.

Examples:

```text
craft\services\Elements::EVENT_AFTER_SAVE_ELEMENT
Solspace\Freeform\Elements\Submission::EVENT_PROCESS_SUBMISSION
```

The selected event controls which event is registered and what context will be available.

[Screenshot for Selected Event Details]
Add a screenshot after selecting an event, showing the selected event summary, generated example listener code, and available event variables.

## Recipients

Recipient fields accept comma-separated email addresses:

```text
admin@example.com, support@example.com
```

Twig is supported:

```twig
{{ event.element.author.email ?? 'fallback@example.com' }}
```

## Sender and Reply-To

Leave **From Email** and **From Name** blank to use Craft's system email settings.

Use **Reply To** when form submissions should reply to the submitter:

```twig
{{ event.submission.email.value ?? null }}
```

## Templates

Template paths are relative to the Craft site templates folder:

[Screenshot for Email Configuration Fields]
Add a screenshot of the notification editor's recipient, sender, subject, HTML template path, and plain text template path fields filled with realistic values.

```text
super-mailer/freeform-contact-form
_emails/entry-updated
```

You can configure HTML, plain text, or both.

## Enable and Disable

Disabled notifications do not register active sends. Use the element index status action or the editor details sidebar.

[Screenshot for Enabled Sidebar]
Add a screenshot of the notification editor sidebar showing the Enabled lightswitch and Preview URL block.

## Duplicate

Duplicate an existing notification to reuse event, recipient, condition, and template settings. The copied notification receives a unique title and handle.

## Delete and Restore

Notifications are Craft elements and use Craft's soft-delete flow. Deleted notifications can be restored from the trashed source in the element index.

[Screenshot for Trashed Notifications Restore]
Add a screenshot of the notification element index filtered to trashed notifications with the restore action visible.
