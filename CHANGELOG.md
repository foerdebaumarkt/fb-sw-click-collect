# Changelog

All notable changes to this project will be documented in this file.

## [0.1.4] - 2025-10-31

### Fixed
- Staff mail template migration now idempotent (skips if type already exists)
- Prevents "Duplicate entry" error when legacy data exists from Foerdebaumarkt plugin

## [0.1.3] - 2025-10-31

### Added
- Proper uninstall cleanup with keepUserData support
- Staff notification mail template (Migration1761264001)
- Documentation for template update workflow (docs/TEMPLATE_UPDATES.md)

### Changed
- Removed "Förde" branding from customer-facing mail templates (12 signatures updated)
- Mail templates now use correct Twig syntax (`{{ order.orderNumber }}` instead of `{{ orderNumber }}`)
- Uninstall now removes mail templates, flows, and system config when "Delete all app data" is checked
- Order custom field data explicitly preserved during uninstall (permanent business records)

### Fixed
- Staff mail template uses proper variable scope for order data
- Uninstall respects UI checkbox for data deletion

## [0.1.2] - 2025-10-29

- Migrations cleanup: remove upgrade-only migrations and assume fresh-install-only policy.
- Hardened flow provisioning: recipients strictly from FbClickCollect.config, no Twig or core fallbacks.
- Local dev: makefile/scripts adjustments aligned to fresh installs; avoid legacy Foerde references.

## [0.1.1] - 2025-10-28

- ReminderService cleanup and rebranding to fb_* custom fields.
- Initial strict recipient logic and flow fixes for staff notifications.

## [0.1.0] - 2025-10-20

- Initial public release.
