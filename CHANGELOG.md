# Changelog

All notable changes to this project will be documented in this file.

## [0.1.2] - 2025-10-29

- Migrations cleanup: remove upgrade-only migrations and assume fresh-install-only policy.
- Hardened flow provisioning: recipients strictly from FbClickCollect.config, no Twig or core fallbacks.
- Local dev: makefile/scripts adjustments aligned to fresh installs; avoid legacy Foerde references.

## [0.1.1] - 2025-10-28

- ReminderService cleanup and rebranding to fb_* custom fields.
- Initial strict recipient logic and flow fixes for staff notifications.

## [0.1.0] - 2025-10-20

- Initial public release.
