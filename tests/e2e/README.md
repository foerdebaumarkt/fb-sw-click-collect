# End-to-End Scenario Overview

This folder contains the Playwright suite that exercises the Click & Collect flow end-to-end against a local Shopware instance. The main scenario lives in `click_collect_ready_email.spec.ts`. Below is a step-by-step description of what the test performs:

1. **Environment bootstrap**
   - Clears the Mailcatcher inbox so only new messages are observed.
   - Opens the storefront homepage at `PLAYWRIGHT_BASE_URL` (defaults to `http://localhost:8080`).
   - Accepts any cookie/banner prompts to avoid UI blockers.

2. **Product discovery and cart add**
   - Uses the storefront search field to look up the demo product `SWDEMO10002`.
   - Navigates to the product detail page, selecting the first available variant if the buy button is disabled.
   - Adds the product to the cart and handles the off-canvas confirmation, choosing the "Go to checkout" or "Show cart" action when available.

3. **Checkout as guest**
   - Ensures the session reaches the cart page and retries the add-to-cart step if the cart appears empty.
   - Clicks the primary checkout CTA, arriving on the confirm/register flow.
   - Chooses guest checkout, filling address data with deterministic values ("Max Mustermann", Kiel, Germany, etc.).
   - Selects the Click & Collect shipping method and the in-store payment option if they are shown.
   - Accepts all required terms/checkboxes and submits the order.

4. **Order identification**
   - Waits for the finish page to display a confirmation and tries to parse the order number from the page body.
   - If no order number is visible, authenticates against the Admin API using `SHOPWARE_ADMIN_USERNAME`/`SHOPWARE_ADMIN_PASSWORD` (defaults `admin` / `shopware`) to locate the newest order for the generated guest email.

5. **Backend state transition**
   - With the Admin API bearer token, finds the first delivery associated with the order.
   - Calls the state machine transition endpoint (`mark_ready`) across the known route variants until one succeeds, effectively moving the Click & Collect delivery into the "ready" state.

6. **Mail assertion**
   - Polls Mailcatcher for a message whose subject indicates the delivery is ready for pickup (German or English wording).
   - Downloads the HTML content and asserts that it contains the order number, the pickup information block, the expected copy about bringing only the name and paying in store, and that it does *not* mention ID requirements.

7. **Companion reminder flow**
   - The secondary spec `click_collect_reminder_email.spec.ts` reuses the helper utilities to prepare data, then triggers the reminder job and validates the reminder email copy.

## Running the suite

1. Ensure `shopware-local` containers are running (`make up` in the `shopware-local` repository or execute `tests/e2e/install_plugin.sh` which calls it automatically).
2. Synchronise and install the plugin inside the container by running:

   ```bash
   ./tests/e2e/install_plugin.sh
   ```

3. Execute the Playwright tests:

   ```bash
   npx playwright test --config tests/e2e/playwright.config.ts
   ```
   Optionally set `PLAYWRIGHT_BASE_URL` or `MAILCATCHER_URL` if your environment differs from the defaults.

The README should give contributors a clear understanding of the test coverage and how to reproduce the checks locally.
