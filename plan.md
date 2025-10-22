# Click & Collect Plugin Plan

This plan assumes Shopware 6.7 CE as the baseline and follows the guidance from the official developer documentation (notably the *Plugin Base Guide*, *Database migrations*, *Checkout customizations*, *Storefront template extensions*, *Administration extensions*, *Email templates*, and *Plugin testing* sections).

## 1. Plugin Foundation

- Generate minimal plugin skeleton (`composer.json`, `src/FoerdeClickCollect.php`, `src/Resources/config/services.xml`) per [Plugin Base Guide], including system config schema to control the customer pickup window in full days (default 2) and optional pickup-expiry override, plus registration of a Shopware scheduled task for reminder dispatching.
- Register autoloading and metadata (label/description, compatibility `shopware/core ~6.7`) and provide activation lifecycle hooks.
- Prepare basic README, LICENSE, CI placeholders.

## 2. Data Model & Install Logic

- No additional persistence beyond core entities: use the dedicated click & collect shipping method plus delivery state to identify orders—no custom fields or tables required.
- Register the click & collect shipping method (`Foerde Click & Collect`) during plugin install/activate with type `pickup` and standard delivery time; link to all sales channels.

## 3. Checkout & Storefront Adjustments

- Subscribe to `CheckoutConfirmPageLoadedEvent` / `CheckoutOrderPlacedEvent` to enforce click & collect shipping, validate terms, and keep the experience aligned with [Checkout customizations].
- Extend checkout confirm Twig block to show pickup instructions and a static store card (name, address, opening hours) sourced from plugin config constants using [Storefront template extensions].
- Auto-select click & collect shipping method on cart recalculation; prevent deselection by overriding storefront JS plugin or using `ShippingMethodRoute` decorator.

## 4. Payment Restriction & Order Tagging

- Decorate `PaymentMethodRoute` to filter methods down to offline “Pay in store” option when click & collect shipping is active; ensure fallback if method disabled.
- On `CheckoutOrderPlacedEvent`, assert the click & collect shipping method is locked in, optionally add an order tag for reporting, and keep Shopware transactions untouched for in-store payment.
- Skip payment capture by ensuring chosen payment method is manual and leaving transaction as `open`.

## 5. Pickup Lifecycle Management

- Extend the existing order-delivery state machine at runtime with click & collect states (`pending`, `ready`, `picked`, `cancelled`) to leverage built-in transition history without schema changes.
- Reuse the standard Administration order detail view and expose the new delivery state transitions through the built-in state change actions, keeping the workflow aligned with core UI for now.
- Dispatch domain events on status transitions for optional notifications or logging.
- Calculate an informational pickup-expiry date by adding the configured number of full days (default 2, overridable via plugin config) to the order date for customer messaging; staff manage the informal 4 h preparation goal manually.

## 6. Customer Communications

- Add email templates for order confirmation (state that items are typically ready within 4 h and should be collected within the configured pickup window), “Ready for pickup”, staff notification (“New pickup order”), and periodic customer reminder; register via `mail_template` migrations and template files in `Resources/views/email/` per [Email templates].
- Trigger staff email when the order is placed with the click & collect shipping method (highlighting the 4 h preparation target), automatically send the “Ready for pickup” email as soon as the delivery state transitions to `ready`, and schedule recurring customer reminders via a Shopware scheduled task using the configurable cadence (default every full day until pickup window expires).
- Extend storefront account order history Twig to display pickup status and instructions.
- Suggested baseline copy: confirmation subject “Danke für Ihre Click & Collect Bestellung” summarising pickup window, store address, and reminder to bring ID/order number; ready email subject “Ihre Bestellung ist abholbereit” with short checklist for pickup; reminder subject “Erinnerung: Bitte holen Sie Ihre Bestellung ab” highlighting remaining days until expiry; staff notification subject “Neue Click & Collect Bestellung” listing order number, customer name, items, and reiterating the 4 h preparation goal.

## 7. Notifications for Staff

- Provide Symfony messenger message dispatched on order placement to send an email to the store mailbox (leveraging the staff-specific template) and allow future integration with alternative channels.

## 8. Testing & Tooling

- Add PHPUnit integration tests covering migrations, DAL repository, checkout subscriber, status transitions, following [Plugin testing] guidance.
- Add Cypress / Playwright e2e test demonstrating checkout flow with click & collect shipping enforced.
- Provide fixtures & instructions in README for enabling plugin locally using `bin/console plugin:refresh/install/activate`.

## 9. Milestones

1. **Foundation**: skeleton, migration scaffolding, shipping method creation, manual verification.
2. **Checkout**: storefront template adjustments, event subscribers, payment filter, data persistence, unit tests.
3. **Lifecycle & Admin**: state management, delivery-state actions in the existing admin view, notifications.
4. **Messaging**: email templates, scheduled tasks, staff notification pipeline.
5. **QA & Docs**: automated tests, README updates, release preparation.

## 10. Implementation Steps & Checkpoints

1. **Plugin bootstrap**: Scaffold the plugin, register services.xml, composer autoloading, and activation hooks. Checkpoint: install and activate the plugin in a dev shop, confirm the plugin appears with its system config section and no errors in the logs.
2. **Shipping method provisioning**: Implement install/activate logic that creates and links the click & collect shipping method. Checkpoint: after reinstall, verify in the admin shipping overview and storefront checkout that the new method is selectable.
3. **Checkout enforcement**: Add event subscribers and route decorators to auto-select the pickup method and lock payment to “Pay in store.” Checkpoint: run through checkout manually to ensure the method cannot be deselected and the correct payment option remains.
4. **Storefront messaging**: Extend confirm page Twig and provide static store card information. Checkpoint: reload the confirm page and confirm the pickup instructions render with configured data.
5. **Lifecycle wiring**: Register delivery state machine extensions and expose transitions in the admin order detail view. Checkpoint: place a test order, open it in admin, and walk states through pending → ready → picked to confirm transitions exist and history logs correctly.
6. **Notifications & reminders**: Implement messenger message for staff email, ready-for-pickup trigger, and scheduled task for reminders. Checkpoint: use the Mail Preview or log to confirm template rendering on state change, and run the scheduled task manually (`bin/console scheduled-task:run`) to see reminder dispatches.
7. **Customer surfaces**: Update storefront account order history and ensure reminder/pickup status appears. Checkpoint: log in as the test customer to confirm statuses and messaging.
8. **Automated and manual QA**: Add unit/integration tests, smoke through checkout once more, and update README with enablement steps. Checkpoint: run the `ci: lint+type+test` task and document manual validation steps before promoting the build.

## 11. Open Questions

- Email copy specifics: confirm whether the suggested baseline subjects and talking points in section 6 are acceptable or require localisation tweaks.
- SMTP / mail channel configuration: rely on existing Shopware mail setup; no plugin-level override planned.

## 12. Future Enhancements

- Optional escalation reminders or multi-channel nudges if pickup remains pending beyond the configured daily cadence.
- Dedicated Administration module for a focused pickup dashboard following the Module system guidance in [Administration extensions].
- CLI command `foerde:click-collect:list` for daily handover reports if manual exports become unwieldy.
- Additional reporting/tagging integrations for BI dashboards.
- Store staff dashboard widgets showing live pickup queue.
- Optional staff comment logging or attachment uploads per pickup order.
- Optional order tagging for BI exports if needed later.
- Admin dashboard badges or widgets highlighting click & collect orders if needed.

[Plugin Base Guide]: https://developer.shopware.com/docs/guides/plugins/plugins/plugin-base-guide.html
[Checkout customizations]: https://developer.shopware.com/docs/guides/plugins/plugins/checkout
[Storefront template extensions]: https://developer.shopware.com/docs/guides/plugins/plugins/storefront/add-custom-data-to-checkout.html
[Administration extensions]: https://developer.shopware.com/docs/guides/plugins/plugins/administration/add-module.html
[Email templates]: https://developer.shopware.com/docs/guides/plugins/plugins/content/email.html
[Plugin testing]: https://developer.shopware.com/docs/guides/plugins/plugins/testing
