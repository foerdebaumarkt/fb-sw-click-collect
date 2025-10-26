import { test, expect, APIRequestContext, request as playwrightRequest, Page } from '@playwright/test';

const SHOPWARE_URL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080';
const MAILCATCHER_URL = process.env.MAILCATCHER_URL ?? 'http://localhost:1080';
const ADMIN_USERNAME = process.env.SHOPWARE_ADMIN_USERNAME ?? 'admin';
const ADMIN_PASSWORD = process.env.SHOPWARE_ADMIN_PASSWORD ?? 'shopware';

function resolveUrl(path: string): string {
  return new URL(path, SHOPWARE_URL.endsWith('/') ? SHOPWARE_URL : `${SHOPWARE_URL}/`).toString();
}

async function mailApi(): Promise<APIRequestContext> {
  return await playwrightRequest.newContext({ baseURL: MAILCATCHER_URL });
}

async function clearMailbox(): Promise<void> {
  const api = await mailApi();
  await api.delete('/messages');
}

async function waitForMessage(predicate: (m: any) => boolean, timeoutMs = 60000): Promise<any> {
  const api = await mailApi();
  const start = Date.now();
  while (Date.now() - start < timeoutMs) {
    const res = await api.get('/messages');
    const messages = await res.json();
    const match = (messages as any[]).find(predicate);
    if (match) return match;
    await new Promise((r) => setTimeout(r, 1000));
  }
  throw new Error('Timed out waiting for expected email');
}

async function acceptCookies(page: Page) {
  const candidates = [
    page.getByRole('button', { name: /Alle akzeptieren|Accept all|Akzeptieren/i }),
    page.getByRole('button', { name: /Zustimmen|Okay|OK|Verstanden/i }),
    page.locator('button').filter({ hasText: /Alle akzeptieren|Accept all|Akzeptieren|Zustimmen|Okay|OK|Verstanden/i }),
  ];
  for (const btn of candidates) {
    if (await btn.isVisible({ timeout: 1000 }).catch(() => false)) {
      await btn.click({ force: true });
      break;
    }
  }
}

async function chooseFirstVariantIfNeeded(page: Page) {
  const buyBtn = page.getByRole('button', { name: /In den Warenkorb|Add to shopping cart|Jetzt kaufen|Kaufen/i }).first();
  const disabled = await buyBtn.isVisible().catch(() => false)
    ? await buyBtn.isDisabled().catch(() => false)
    : true;
  if (!disabled) return;

  const selects = page.locator('select:visible');
  const count = await selects.count();
  for (let i = 0; i < count; i++) {
    const sel = selects.nth(i);
    try {
      const options = await sel.locator('option:not([disabled]):not([value=""])').all();
      if (options.length > 0) {
        const value = await options[0].getAttribute('value');
        if (value) await sel.selectOption(value);
      }
    } catch {/* ignore */}
  }

  const swatches = page.locator('[data-variant-id], .product-variant, .sw-product-variant__option:visible');
  const swCount = await swatches.count().catch(() => 0);
  if (swCount > 0) {
    try { await swatches.first().click({ force: true }); } catch {/* ignore */}
  }
}

async function fillGuestCheckout(page: Page, email: string) {
  const guestButtons = [
    page.getByRole('button', { name: /Als Gast fortfahren|Weiter als Gast|Gastbestellung|Checkout as guest/i }),
    page.getByRole('link', { name: /Als Gast fortfahren|Weiter als Gast|Gastbestellung|Checkout as guest/i }),
  ];
  for (const btn of guestButtons) {
    if (await btn.isVisible({ timeout: 1000 }).catch(() => false)) {
      await btn.click();
      await page.waitForLoadState('networkidle');
      break;
    }
  }

  const noAccount = page.getByLabel(/Kein Kundenkonto anlegen|Ohne Registrierung|Gastbestellung/i);
  if (await noAccount.isVisible({ timeout: 1000 }).catch(() => false)) {
    await noAccount.check({ force: true });
  }

  let emailField = page.getByLabel(/E-?Mail/i, { exact: false }).first();
  if (!(await emailField.isVisible({ timeout: 1000 }).catch(() => false))) {
    emailField = page.locator('input[type="email"]:visible').first();
  }
  await emailField.fill(email);

  let firstName = page.getByLabel(/Vorname/i);
  if (!(await firstName.isVisible({ timeout: 1000 }).catch(() => false))) {
    firstName = page.locator('input[name*="firstName"]:visible').first();
  }
  await firstName.fill('Max');
  let lastName = page.getByLabel(/Nachname/i);
  if (!(await lastName.isVisible({ timeout: 1000 }).catch(() => false))) {
    lastName = page.locator('input[name*="lastName"]:visible').first();
  }
  await lastName.fill('Mustermann');

  let street = page.getByLabel(/Straße|Strasse/i);
  if (!(await street.isVisible({ timeout: 1000 }).catch(() => false))) {
    street = page.locator('input[name*="street"]:visible').first();
  }
  await street.fill('Musterstraße 1');
  let plz = page.getByLabel(/PLZ|Postleitzahl/i);
  if (!(await plz.isVisible({ timeout: 1000 }).catch(() => false))) {
    plz = page.locator('input[name*="zipcode" i]:visible, input[name*="postal" i]:visible').first();
  }
  await plz.fill('24159');
  let city = page.getByLabel(/Ort|Stadt/i);
  if (!(await city.isVisible({ timeout: 1000 }).catch(() => false))) {
    city = page.locator('input[name*="city"]:visible').first();
  }
  await city.fill('Kiel');

  const country = page.getByLabel(/Land/i);
  if (await country.isVisible({ timeout: 1000 }).catch(() => false)) {
    await country.selectOption({ label: 'Deutschland' });
  }
}

async function proceedFromRegisterToConfirm(page: Page) {
  const isOnRegister = /\/checkout\/register/i.test(page.url());
  const weiter = page.getByRole('button', { name: /Weiter|Continue/i }).first();
  if (isOnRegister || (await weiter.isVisible({ timeout: 1000 }).catch(() => false))) {
    if (await weiter.isVisible().catch(() => false)) {
      await weiter.click({ force: true });
      await page.waitForLoadState('networkidle');
    }
    await page.waitForURL(/checkout\/(confirm|finish)/, { timeout: 10000 }).catch(() => undefined);
  }
}

async function ensureShippingAndPayment(page: Page) {
  const pickupShipping = page.getByRole('radio', { name: /Abholung im Markt/i });
  if (await pickupShipping.isVisible()) await pickupShipping.check();
  const marketPayment = page.getByRole('radio', { name: /Bezahlung im Markt/i });
  if (await marketPayment.isVisible()) await marketPayment.check();
}

async function acceptTerms(page: Page) {
  const candidates = [
    'input[type="checkbox"][name*="tos" i]',
    'input[type="checkbox"]#tos',
    'input[type="checkbox"][name*="revocation" i]',
    'input[type="checkbox"][required]'
  ];
  for (const sel of candidates) {
    const el = page.locator(sel);
    if (await el.isVisible().catch(() => false)) {
      try {
        await el.check({ force: true });
      } catch {
        await page.evaluate((selector) => {
          const cb = document.querySelector(selector) as HTMLInputElement | null;
          if (cb) {
            cb.checked = true;
            cb.dispatchEvent(new Event('input', { bubbles: true }));
            cb.dispatchEvent(new Event('change', { bubbles: true }));
          }
        }, sel);
      }
    }
  }
}

async function placeOrder(page: Page) {
  const selectors = [
    page.getByRole('button', { name: /Zahlungspflichtig bestellen|Jetzt kaufen|Kaufen/i }),
    page.locator('form[name="confirmForm" i] button[type="submit"]:not([disabled])'),
    page.getByRole('button', { name: /Bestellung abschließen|Place order|Complete order/i }),
    page.locator('button[data-form-submit]:not([disabled])'),
    page.locator('button[type="submit"]:not([disabled])'),
  ];
  for (const loc of selectors) {
    if (await loc.isVisible({ timeout: 1000 }).catch(() => false)) {
      await loc.click();
      return;
    }
  }
  const clicked = await page.evaluate(() => {
    const candidates = Array.from(document.querySelectorAll(
      'form[name*="confirm" i] button[type="submit"], button[name*="submit" i], button[data-form-submit], form button[type="submit"]'
    )) as HTMLButtonElement[];
    for (const b of candidates) {
      if (b && !b.hasAttribute('disabled') && (b.offsetParent !== null || b.getClientRects().length)) {
        try { b.click(); return true; } catch {}
      }
    }
    for (const b of candidates) {
      try { (b as any).disabled = false; b.click(); return true; } catch {}
    }
    return false;
  });
  if (!clicked) throw new Error('Could not find checkout submit button');
}

function extractOrderNumber(text: string): string | null {
  const hashMatch = text.match(/#\s*(\d{3,})/);
  if (hashMatch) return hashMatch[1];
  const labelMatch = text.match(/Bestellnummer[^\d]*(\d{3,})/i);
  return labelMatch ? labelMatch[1] : null;
}

async function getAdminToken(): Promise<string> {
  const params = new URLSearchParams({
    grant_type: 'password',
    username: ADMIN_USERNAME,
    password: ADMIN_PASSWORD,
    client_id: 'administration',
    scopes: 'write',
  });
  const authUrl = resolveUrl('api/oauth/token');
  const response = await fetch(authUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
    body: params,
  });
  if (!response.ok) throw new Error(`Admin auth failed (${response.status})`);
  const token = (await response.json()) as { access_token?: string };
  if (!token.access_token) throw new Error('No access_token from Admin API');
  return token.access_token;
}

async function findFirstDeliveryIdByOrderNumber(token: string, orderNumber: string): Promise<string> {
  const deliveryByOrderNo = {
    limit: 1,
    filter: [{ type: 'equals', field: 'order.orderNumber', value: orderNumber }],
    includes: { 'order-delivery': ['id'] },
  } as any;
  const tryDel = await fetch(resolveUrl('api/search/order-delivery'), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${token}` },
    body: JSON.stringify(deliveryByOrderNo),
  });
  if (tryDel.ok) {
    const dd = (await tryDel.json()) as { data: Array<{ id: string }> };
    if (dd.data.length) return dd.data[0].id;
  }

  const orderCriteria = { limit: 1, filter: [{ type: 'equals', field: 'orderNumber', value: orderNumber }] };
  const orderRes = await fetch(resolveUrl('api/search/order'), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${token}` },
    body: JSON.stringify(orderCriteria),
  });
  if (!orderRes.ok) {
    const txt = await orderRes.text().catch(() => '');
    throw new Error(`Order search failed (${orderRes.status}): ${txt}`);
  }
  const orderData = (await orderRes.json()) as { data: Array<{ id: string }> };
  if (!orderData.data.length) throw new Error('Order not found');
  const orderId = orderData.data[0].id;

  const deliveryCriteria = { limit: 1, filter: [{ type: 'equals', field: 'orderId', value: orderId }] };
  const delRes = await fetch(resolveUrl('api/search/order-delivery'), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${token}` },
    body: JSON.stringify(deliveryCriteria),
  });
  if (!delRes.ok) {
    const txt = await delRes.text().catch(() => '');
    throw new Error(`Order-delivery search failed (${delRes.status}): ${txt}`);
  }
  const delData = (await delRes.json()) as { data: Array<{ id: string }> };
  if (!delData.data.length) throw new Error('Delivery not found');
  return delData.data[0].id;
}

async function transitionDeliveryToReady(token: string, deliveryId: string): Promise<void> {
  const paths = [
    `api/_action/order-delivery/${deliveryId}/state/mark_ready`,
    `api/_action/order_delivery/${deliveryId}/state/mark_ready`,
    `api/_action/state-machine/order-delivery/${deliveryId}/state/mark_ready`,
    `api/_action/state-machine/order_delivery/${deliveryId}/state/mark_ready`,
  ];
  let lastStatus = 0;
  for (const p of paths) {
    const res = await fetch(resolveUrl(p), { method: 'POST', headers: { Accept: 'application/json', Authorization: `Bearer ${token}` } });
    lastStatus = res.status;
    if (res.ok) return;
  }
  throw new Error(`Failed to transition delivery to ready (last status ${lastStatus})`);
}

// Skip by default unless REMINDER_E2E=1
const shouldRun = process.env.REMINDER_E2E === '1';
(shouldRun ? test : test.skip)('Click & Collect: reminder email is sent via dev action (best-effort)', async () => {
  test.setTimeout(120_000);

  await clearMailbox();
  const token = await getAdminToken();

  // Trigger reminders via dev-only action. Assumes there is at least one ready C&C order
  // (e.g., created by the ready-email spec running before this spec).
  const res = await fetch(resolveUrl('api/_action/foerde-click-collect/run-reminders'), {
    method: 'POST',
    headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
  });
  expect(res.status, await res.text()).toBeLessThan(500);

  const msg = await waitForMessage((m) => /Reminder:|Erinnerung:/.test(m.subject ?? ''), 60000);
  expect(msg).toBeTruthy();
});
