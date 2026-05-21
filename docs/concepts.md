# Core Concepts

## Notifications

A notification is a custom Craft element that stores:

- Event class, constant, and name.
- Recipients, sender values, reply-to, and subject.
- HTML and plain text template paths.
- Condition rows and optional PHP condition.
- Enabled/disabled state.

Notifications can be duplicated, disabled, deleted, and restored through Craft's element index actions.

## Events

Each notification listens to one event. Super Mailer discovers supported event constants and lists content-related events in the event picker.

Examples:

- Craft element save/delete/restore events.
- Third-party form submission element events.
- Third-party submission processing events.
- Custom element events from installed plugins when they follow Craft event patterns.

## Event Context

Events are normalized into a serializable context before being pushed to the queue. This keeps queue payloads safe while still letting templates work with useful objects later.

In templates, you can use:

```twig
{{ event.element.title }}
{{ event.getElement().title }}
{{ event.eventName }}
{{ rawEventContext|json_encode }}
```

`rawEventContext` is the stored queue payload. `event` is the template-facing render context. The selected notification event determines which values are available, so use the preview page's **Template Variables** panel as the source of truth for a specific notification.

## Conditions

Conditions run before queueing. A notification queues only when its condition rows and PHP condition pass.

Supported condition rows include:

- Element status.
- Whether the event is new.
- Site ID.
- Entry section handle.
- Entry type handle.
- Entry author.

Condition rows can match all rules or any rule.

## Queue Jobs

When conditions pass, Super Mailer pushes a `SendNotificationEmailJob` to Craft's queue. The queue job renders recipients, subject, and templates, sends the message, and records a log.

## Logs

Every send attempt records:

- Notification title and ID.
- Recipients and subject.
- Status.
- Error details when failed.
- Event class/name.
- Element type/ID/site.
- Serialized event context.

Logs can be inspected, deleted, bulk deleted, resent, and automatically purged by retention settings.
