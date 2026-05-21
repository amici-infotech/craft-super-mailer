# Changelog

All notable changes to this project will be documented in this file.

## 5.0.0 - 2026-05-21

### Added
- Initial Craft CMS 5 release.
- Added the `MailerNotification` custom element for Control Panel managed email notifications.
- Added event selection for Craft element lifecycle events and supported plugin events, including Formie and Freeform submissions.
- Added HTML and plain text template path support using Craft site templates.
- Added Twig rendering for recipients, sender fields, subjects, and templates.
- Added condition rows with status, site, entry section/type, author, and new element checks.
- Added optional custom PHP condition expressions.
- Added dynamic event preview with real matching elements where possible.
- Added support for previewing a specific element with the `id` query parameter.
- Added event context rehydration so templates receive a real `event.element` object during preview and queued delivery.
- Added Twig-facing event helpers such as `event.getElement()`, `event.getSubmission()`, `event.getForm()`, and matching property aliases where available.
- Added duplicate, enable/disable, delete, and restore actions for notification elements.
- Added delivery logging for success and failure attempts.
- Added detailed failure logging with exception traces and recent mailer errors.
- Added log retention setting and automatic purge after sends.
- Added console commands for purging logs by retention and purging all logs.
- Added CP Logs section with delete, delete selected, delete all, resend, and resend selected actions.
- Added queue-based resend from logs.
- Added log detail pages with message metadata, recipients, errors, event context, and rendered output.
- Added notification edit page recent run history.
- Added preview recipient rendering, condition debug output, rendered template variables, and raw event context.
- Added test send from preview to a selected email address.
- Added Craft-style read-only settings behavior when `allowAdminChanges` is disabled.
- Added structured documentation under `docs/`.

### Changed
- Conditions are evaluated before queueing emails.
- Draft, revision, derivative, and provisional draft events are ignored for element events.
- Entry status conditions compare enabled/disabled state consistently across element types.
- Author condition rows support selecting multiple users.
- Author condition chips use Craft-style element chip UI.
- Preview and log detail pages use card-based Craft Control Panel styling.

### Fixed
- Fixed Formie and Freeform preview contexts showing unrelated sample entry data.
- Fixed preview rendering errors being hidden.
- Fixed condition row values being reset after save.
- Fixed keyboard navigation in the event picker.
- Fixed custom field access in templates by exposing field handles directly on the event element context.
- Fixed double logging of failed sends.
- Fixed generic mailer failure messages by storing more specific errors where available.
- Fixed notifications firing when opening new draft element screens.
- Fixed new entry checks around Craft's draft application flow.
- Fixed logs sidebar selection.
- Fixed mask icon placement.
- Fixed single-row log delete actions.
- Fixed condition toggle visual state and saved values.
- Fixed missing Freeform submission event support.
