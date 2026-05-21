# Email Templates

Super Mailer renders templates with Twig. Template paths are relative to Craft's site templates folder.

## Available Variables

Common variables:

```twig
notification
event
eventContext
rawEventContext
craft
```

`event` is the template-facing context. `rawEventContext` is the serialized queue payload.

The exact values available under `event` depend on the selected notification event. Super Mailer exposes the selected event's available `$event` data when it can be normalized, plus `event.element` for element-backed events. Use the preview page's **Template Variables** panel to confirm what a specific notification can use.

## Element Events

For element-backed events:

```twig
{% set element = event.element %}

<h1>{{ element.title }}</h1>
<p>Updated: {{ element.dateUpdated|datetime }}</p>
<p>Edit: <a href="{{ element.cpEditUrl }}">Open in CP</a></p>
```

You can also use getter-style access:

```twig
{{ event.getElement().title }}
```

## Entry Example

```twig
{% set entry = event.element %}

<h1>{{ entry.title }}</h1>

{% if entry.authorName ?? false %}
    <p>Author: {{ entry.authorName }}</p>
{% endif %}

<p>Updated: {{ entry.dateUpdated|datetime }}</p>
```

## Third-Party Element Example

```twig
{% set element = event.element %}

<h1>{{ element.title ?? 'New submission' }}</h1>
<p>Element ID: {{ element.id }}</p>
<p>Element type: {{ element.type ?? element.className() ?? null }}</p>
```

If the selected third-party event exposes additional data, use the preview page's **Template Variables** panel to see the available property names before using them in a template.

## Custom Fields

Normalized custom field values are exposed on `event.element.fields` and directly by field handle where possible:

```twig
{{ event.element.fields.myField ?? null }}
{{ event.element.myField ?? null }}
```

When the element can be rehydrated, `event.element` is a real Craft element object.

## Subject Example

```twig
Notification for {{ event.element.title ?? event.eventName ?? 'Website' }}
```

## Recipient Example

```twig
{{ event.element.author.email ?? 'admin@example.com' }}
```

Multiple recipients:

```twig
admin@example.com, {{ event.element.email ?? '' }}
```

## Plain Text Fallback

If no template path is configured or rendering fails, Super Mailer records the render error in preview/logs and can fall back to a simple text summary.
