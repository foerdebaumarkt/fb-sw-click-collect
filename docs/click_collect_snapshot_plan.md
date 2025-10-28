# Click & Collect Order Snapshot Plan

## Goals

- Persist essential store pickup metadata on orders/deliveries so customer-facing and staff emails consume a single source of truth.
- Align staff notification and customer confirmation mails to use the persisted data rather than live system-config lookups.
- Prepare groundwork for Flow Builder integration by making pickup data accessible via order context.

## Action Items

- [x] Define custom fields
  - Scope: `order_delivery`
  - Fields: `storeName`, `storeAddress`, `openingHours`, `pickupWindowDays`, `pickupPreparationHours` (consider JSON for future expansion)
  - Deliverables: migration/setup update, config metadata (if exposed in Admin)
- [x] Capture snapshot on order placement
  - Extend `OrderPlacedSubscriber` (or dedicated service) to compute normalized store config once per order
  - Persist snapshot onto matching Click & Collect deliveries inside the checkout transaction (ensure idempotency)
- [x] Refactor mail senders
  - Update staff mail subscriber to read custom fields first, fallback to config for legacy orders
  - Update ready-for-pickup mail to reuse same snapshot data path
- [x] Enhance customer confirmation mail
  - Extend order-confirmation Twig (or template-data subscriber) to surface pickup info from custom fields
  - Ensure translations/formatting consistent with ready mail
- [x] Flow readiness
  - Document how Flow Builder/custom actions can access the snapshot data via the `clickCollectPickup` struct on `MailBeforeSentEvent`
  - Optional: expose snapshot as structured array in template data/event payloads
- [ ] Testing & rollout
  - Automated tests covering snapshot persistence and mail fallbacks
  - Manual verification (place order, inspect confirmation + staff mails)
  - Optional backfill script for existing open pickups
