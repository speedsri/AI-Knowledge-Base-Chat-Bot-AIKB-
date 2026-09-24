# AIKB Fresh Installation Release

This release contains the current AI Knowledge Base application source
prepared for a clean installation.

## Current administration improvements

System Settings supports database-backed management of:

- Public Base URL
- Public Chat ON/OFF
- Embedded Widget ON/OFF
- Widget Allowed Origins
- Widget Title and Greeting
- Widget Position and Language
- Widget Voice ON/OFF
- Widget Request Rate Limits
- Generated public/login/widget URLs
- Generated embeddable script snippet

## Widget security

- Exact-origin allow-listing is enforced server-side.
- Unauthorized origins receive `origin_not_allowed`.
- Widget ON/OFF is enforced server-side.
- Widget voice OFF is enforced server-side with `widget_voice_disabled`.
- The widget UI supports `data-voice="true"` and `data-voice="false"`.
- `.env` widget enablement remains an emergency server-level master switch.

## Public chat

Public chat can be independently enabled or disabled from Admin
System Settings.

## Migration

The release includes the database migration that adds application-access
and widget settings to `system_settings`.
