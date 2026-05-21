# Changelog

All notable changes to this project will be documented in this file.

## 5.0.0 - 2026-05-21

### Added
- Initial Craft CMS 5 release.

### Added - Notifications
- Added the `MailerNotification` custom element for Control Panel managed email notifications.
- Added notification fields for event class, event constant, event name, recipients, sender details, reply-to, subject, HTML template path, and plain text template path.
- Added notification handle generation and uniqueness validation.
- Added notification duplication with unique copied titles and handles.
- Added notification enable/disable support backed by Craft element status actions.
- Added notification delete and restore actions using Craft's element soft-delete flow.
- Added preview links in the notification element index and editor sidebar.
- Added recent run history to the notification edit page.

### Added - Event Selection
- Added event selection for Craft element lifecycle events and supported third-party plugin submission events.
- Added searchable event picker by class, event constant, and event name.
- Added generated example listener code for the selected event.
- Added available event variable display based on the selected callback event type.
- Added keyboard navigation for the event picker dropdown.
- Added explicit support for supported third-party submission processing events that do not follow Craft's `EVENT_AFTER_*` naming pattern.

### Added - Templates and Variables
- Added HTML and plain text template path support using Craft site templates.
- Added Twig rendering for recipients, sender fields, subjects, and templates.
- Added event context normalization for safe queue payloads.
- Added event context rehydration so templates receive a real `event.element` object during preview and queued delivery when possible.
- Added direct custom field handle access on rehydrated event elements where normalized data is available.
- Added Twig-facing event helpers such as `event.getElement()` and normalized event properties where available.
- Added `rawEventContext` for debugging the serialized queue payload from templates.

### Added - Conditions
- Added condition rows with status, site, entry section/type, author, and new element checks.
- Added optional custom PHP condition expressions.
- Added all/any condition match modes.
- Added multiple user selection for author condition rows.
- Added Craft-style user element chips for saved author conditions.
- Added entry-only filtering for entry section and entry type condition fields.
- Added normalized status comparisons for enabled/disabled checks across different element types.

### Added - Preview and Testing
- Added dynamic event preview with real matching elements where possible.
- Added support for previewing a specific element with the `id` query parameter.
- Added preview recipient rendering for To, CC, BCC, From, and Reply-To values.
- Added preview subject rendering.
- Added rendered HTML and plain text body preview.
- Added condition debug output in preview.
- Added template variable and raw event context panels in preview.
- Added render error output in preview when HTML or plain text templates fail.
- Added test send from preview to a selected email address.
- Added card-based preview page layout.

### Added - Delivery and Logging
- Added delivery logging for success and failure attempts.
- Added detailed failure logging with exception traces and recent mailer errors.
- Added queue-based email delivery with `SendNotificationEmailJob`.
- Added log retention setting and automatic purge after sends.
- Added console commands for purging logs by retention and purging all logs.
- Added CP Logs section with delete, delete selected, delete all, resend, and resend selected actions.
- Added queue-based resend from logs.
- Added log detail pages with message metadata, recipients, errors, event context, and rendered output.
- Added log detail resend action.
- Added confirmation prompts for log delete, bulk delete, delete all, resend, and bulk resend actions.
- Added card-based log detail page layout.

### Added - Settings and Navigation
- Added Control Panel sidebar navigation for Notifications, Logs, and Settings.
- Added plugin name setting for the Control Panel navigation label.
- Added Craft-style read-only settings behavior when `allowAdminChanges` is disabled.
- Added email log retention setting.

### Added - Assets and Documentation
- Added Control Panel icon and mask icon.
- Added root README.
- Added changelog.
- Added structured documentation under `docs/`.
- Added screenshot placeholders throughout the documentation for future docs screenshots.

### Changed
- Conditions are evaluated before queueing emails.
- Draft, revision, derivative, and provisional draft events are ignored for element events.
- Entry status conditions compare enabled/disabled state consistently across element types.
- Entry section and entry type condition fields only appear for Entry events.
- Resend actions push new queue jobs instead of sending synchronously.
- Preview and queued sends use the same render context wrapper for Twig.
- Test sends override the To recipient and suppress CC/BCC for the test email.
- Preview and log detail pages use card-based Craft Control Panel styling.
- Settings follow Craft's read-only environment pattern when admin changes are disabled.
- Documentation now describes third-party plugin events generically instead of naming specific vendors.

### Fixed
- Fixed third-party submission preview contexts showing unrelated sample entry data.
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
- Fixed missing support for a third-party submission processing event.
- Fixed delete/resend buttons in log rows by using dedicated forms/actions.
- Fixed log resend selected behavior so selected resends are queued.
- Fixed notification restore action missing for trashed notifications.
- Fixed saved author condition labels showing only `Author ID: {id}` after reload.
- Fixed condition author UI not matching Craft element chip styling.
- Fixed documentation mailer test command to use Craft's `--to=` option.
