# Click & Collect Flow Builder Automation Plan

## 1. Objective

Provision the Click & Collect order-confirmation flow via code so it survives `make fresh-start`, becomes the single confirmation pathway, and reliably notifies store staff even when the configured mailbox is empty.

## 2. Trigger & Scope

- **Event**: `checkout.order.placed` (confirmed requirement).
- **Primary rule**: `Click & Collect: only with pickup shipping` (already provisioned alongside the payment method).
- **Goal**: Replace the stock Shopware confirmation flow; after deployment, all orders run through this plugin-managed flow.

## 3. Flow Structure

| Branch | Condition | Actions |
| --- | --- | --- |
| **Click & Collect branch** | Rule passes (order uses `click_collect` shipping) | 1. Send email using template **“Click & Collect: Order confirmation”** (customer copy).<br>2. Send email using template **“Click & Collect: Staff notification”** with recipient resolved from config.<br>&nbsp;&nbsp;• To: `FbClickCollect.config.storeEmail`.<br>&nbsp;&nbsp;• Name: `FbClickCollect.config.storeName`.<br>&nbsp;&nbsp;• Fallbacks handled below. |
| **Default branch** | Rule fails (not a Click & Collect shipment) | 1. Send email using core template **“Order confirmation”** so non Click & Collect orders retain the standard mail even after the core flow is disabled. |

### Staff Recipient Fallbacks

- If the store email is empty, fall back to the Shopware admin email (system config or primary admin user).
- Snapshot includes the resolved store email on order placement so Flow Builder uses the captured address first.
- If the store name is empty, fall back to the admin company/name (or the email if no name exists).

### Additional Flow: Ready-for-pickup Notification

- **Event**: `state_enter.order_delivery.state.ready` (fires when the delivery enters the pickup-ready state).
- **Action**: Single `action.mail.send` using template **“Click & Collect: Ready for pickup”** (customer-facing); no extra branches or rule checks.

### Additional Flow: Pickup Reminder

- **Event**: `fb.click_collect.pickup_reminder` (custom business event emitted when a reminder should be delivered).
- **Action**: Single `action.mail.send` using template **“Click & Collect: Pickup reminder”**; send to the default customer recipients with no additional branching.
- **Notes**: Reminder scheduling logic lives outside the flow (e.g. cron job emits the event); flow just delivers the mail once triggered.

## 4. Dependencies & Lookups

- Existing migrations already supply the rule and current Click & Collect mail templates (order confirmation, staff notification, ready-for-pickup).
- Need stable lookups (by technical name) for rule `Click & Collect: only with pickup shipping`, templates `Click & Collect: Order confirmation`, `Click & Collect: Staff notification`, `Click & Collect: Ready for pickup`, `Click & Collect: Pickup reminder`, plus the stock `Order confirmation`, and admin contact data (system config `core.basicInformation.email` / `core.basicInformation.company` or admin user fallback).
- Seed `Click & Collect: Pickup reminder` template and its type if they are missing; use deterministic UUIDs to align with the reference JSON.
- Ensure pickup snapshot custom fields continue to power the templates (already implemented).

## 5. Implementation Steps

- [x] **Capture reference JSON** from the Flow Builder UI for field mapping.
  - Current snapshots: see `fb-sw-click-collect/docs/click_collect_flow_reference.json`, `fb-sw-click-collect/docs/click_collect_flow_ready_reference.json`, and `fb-sw-click-collect/docs/click_collect_flow_pickup_reminder_reference.json` (basis for migration wiring; replace IDs if final export differs).
- [x] **Migration / Provisioning**:
  - Upsert the flow row (`flow`) with deterministic UUID, `event_name = checkout.order.placed`, and `active = 1`.
  - Seed the `flow_sequence` graph (condition + two branches + send actions) in an idempotent manner.
  - Store template IDs in the action config referencing their technical names.
  - Configure the staff email action to resolve recipients dynamically via Twig/expression, including fallbacks.
  - Seed the pickup-reminder mail template/type if it does not already exist so the flow can reference deterministic IDs.
  - Provision a second flow (`state_enter.order_delivery.state.ready`) that immediately sends the ready-for-pickup template with a single mail action.
  - Provision a third flow (`fb.click_collect.pickup_reminder`) that simply sends the pickup-reminder template when the event fires.
- [x] **Deactivate the stock confirmation flow** in the same migration (set `active = 0` on the core `Order placed` flow) to avoid duplicate customer mails.
- [ ] **Testing**:
  - Integration test asserting the new flow exists and the stock flow is disabled after plugin install/activate.
  - Functional test (if feasible) exercising both branches to verify recipients/templates.
- [ ] **Documentation**: Update README with flow behaviour, staff-email fallback logic, and guidance for merchants.

## 6. Edge Cases & Safeguards

- Migration must be idempotent (re-runnable without creating duplicates or reactivating the core flow unintentionally).
- Provide graceful logging/error handling if template or rule lookups fail (e.g., skip action instead of crashing).

## 7. Outstanding Items

- No additional Flow Builder actions (tags, state changes, etc.) are required for this confirmation flow.
- Gather additional confirmation if manually derived reference JSON needs adjustments, then proceed to implementation.
