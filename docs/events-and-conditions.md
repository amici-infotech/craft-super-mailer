# Events and Conditions

## Supported Events

Super Mailer lists content-related Craft events and supported plugin events.

Common examples:

- `craft\services\Elements::EVENT_AFTER_SAVE_ELEMENT`
- `craft\services\Elements::EVENT_AFTER_DELETE_ELEMENT`
- Formie submission element save events.
- `Solspace\Freeform\Elements\Submission::EVENT_PROCESS_SUBMISSION`

The event list is generated from installed Craft/plugin classes, so available options depend on the project.

## Event Variables

The event picker shows PHP callback variables for the selected event. Email templates receive a Twig-friendly render context.

PHP condition example:

```php
$event->getForm()->handle === 'contactForm'
```

Twig template equivalent:

```twig
{{ event.getForm().handle }}
{{ event.form.handle }}
```

## Condition Rows

Condition rows are evaluated before a queue job is pushed.

[Screenshot for Condition Rows]
Add a screenshot of the condition builder with multiple rows, such as Status enabled, Is New enabled, Site ID, and Author with multiple selected user chips.

Available fields:

- **Status**: enabled/disabled element state.
- **Is New**: whether Super Mailer considers the event a new element/content event.
- **Site ID**: element site ID.
- **Section Handle**: entry section handle.
- **Entry Type Handle**: entry type handle.
- **Author**: one or more Craft users.

## Match Mode

Use **All conditions must match** when every row must pass.

Use **Any condition can match** when at least one row can pass.

## Author Conditions

Author conditions can include multiple users. The saved value is a comma-separated ID list and the condition uses `contains`.

[Screenshot for Author Condition Picker]
Add a screenshot of the Craft user selector modal used from the Author condition row with multiple users selected.

[Screenshot for Saved Author Chips]
Add a screenshot of a reloaded notification edit page showing saved Author condition users rendered as Craft-style element chips.

## Status Conditions

Craft entries can return statuses such as `live`, while other element types may return `enabled`. Super Mailer normalizes these values for enabled/disabled comparisons.

Normalized enabled values:

```text
enabled, true, 1, yes, on, live
```

Normalized disabled values:

```text
disabled, false, 0, no, off
```

## PHP Conditions

Use PHP conditions for advanced checks that cannot be expressed with condition rows.

[Screenshot for PHP Condition Field]
Add a screenshot of the Custom PHP Condition textarea with a realistic expression, for example a Freeform form handle check.

Enter only the expression:

```php
($event->getForm()->handle ?? null) === 'contactForm'
```

Super Mailer evaluates the expression as a boolean. Failed PHP conditions are logged as warnings and treated as false.

## Draft and Revision Filtering

Super Mailer ignores drafts, revisions, derivative elements, and provisional drafts for element events. This prevents sends when Craft creates draft/provisional records during editing.

## Condition Debug

The preview page includes a condition debug table showing:

[Screenshot for Condition Debug]
Add a screenshot of the preview page's Condition Debug card showing passing and failing condition rows.

- Field.
- Expected value.
- Actual value.
- Pass/fail result.

PHP conditions are evaluated against the live event object when the actual event fires, so preview shows a note for PHP conditions rather than executing them against a synthetic preview object.
