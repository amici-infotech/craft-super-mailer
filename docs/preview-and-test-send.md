# Preview and Test Send

Preview helps verify a notification before enabling it.

## Open Preview

From a saved notification:

1. Open the notification editor.
2. Click **Preview email** in the sidebar.

Or open:

```text
/admin/super-mailer/notifications/123/preview
```

[Screenshot for Preview Summary]
Add a screenshot of the top of the preview page showing the notification summary header, selected event, preview element ID if available, and condition status pill.

## Preview a Specific Element

Pass an `id` query parameter:

```text
/admin/super-mailer/notifications/123/preview?id=456
```

Super Mailer tries to find a matching element for the selected event and use it for preview.

## Preview Sections

The preview page shows:

[Screenshot for Preview Recipients and Test Send]
Add a screenshot of the preview page showing the Message card, Recipients card, and Test Send card with an email entered.

- Notification/event summary.
- Rendered subject.
- Rendered recipients.
- Test send form.
- Condition debug.
- HTML body preview.
- Plain text body.
- Template variables.
- Raw event context.

## Template Variables

Template variables show what Twig templates can use:

[Screenshot for Template Variables Panel]
Add a screenshot of the Template Variables card showing the values available for the selected notification event.

```twig
event.element
event.getElement()
event.data
```

Availability depends on the selected event and element type. Do not assume every notification has the same event properties.

## Raw Event Context

Raw context is the serialized queue payload. It is useful for debugging logs and resends, but templates should generally use `event`.

[Screenshot for Raw Event Context Panel]
Add a screenshot of the Raw Event Context card with pretty-printed JSON.

## Test Send

Use **Test Send** to send the currently previewed notification to one email address.

[Screenshot for Test Send Success Notice]
Add a screenshot after sending a test email, showing Craft's success notice and the preview page.

Test sends:

- Use the same preview context.
- Override the To recipient with the test email.
- Do not send CC/BCC.
- Still log success or failure.

## Render Errors

If an HTML or plain text template fails to render, the preview page shows the error near that body section. The email log will also store failures that happen during queued sends.

[Screenshot for Preview Render Error]
Add a screenshot of the preview page showing a template render error for an invalid HTML or plain text template.
