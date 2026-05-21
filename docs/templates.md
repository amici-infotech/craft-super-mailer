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

## Freeform Submission Example

```twig
{% set submission = event.getSubmission() %}
{% set form = event.getForm() %}

<h1>New submission for {{ form.name }}</h1>
<p>Submission ID: {{ submission.id }}</p>
<p>Status: {{ submission.status }}</p>
```

Equivalent aliases:

```twig
{{ event.submission.title }}
{{ event.form.name }}
{{ event.element.form.name }}
```

## Formie Submission Example

```twig
{% set submission = event.element %}

<h1>New Formie submission</h1>
<p>{{ submission.title }}</p>

{% if submission.form ?? false %}
    <p>Form: {{ submission.form.title ?? submission.form.name }}</p>
{% endif %}
```

## Custom Fields

Normalized custom field values are exposed on `event.element.fields` and directly by field handle where possible:

```twig
{{ event.element.fields.myField ?? null }}
{{ event.element.myField ?? null }}
```

When the element can be rehydrated, `event.element` is a real Craft element object.

## Subject Example

```twig
New submission for {{ event.getForm().name ?? event.element.title ?? 'Website' }}
```

## Recipient Example

```twig
{{ event.element.author.email ?? 'admin@example.com' }}
```

Multiple recipients:

```twig
admin@example.com, {{ event.submission.email.value ?? '' }}
```

## Plain Text Fallback

If no template path is configured or rendering fails, Super Mailer records the render error in preview/logs and can fall back to a simple text summary.
