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

async function getMessageHtml(id: number): Promise<string> {
  const api = await mailApi();
  const res = await api.get(`/messages/${id}.html`);
  return await res.text();
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
  // If the buy button is disabled or not visible, try to select first available variant options
  const buyBtn = page.getByRole('button', { name: /In den Warenkorb|Add to shopping cart|Jetzt kaufen|Kaufen/i }).first();
  const disabled = await buyBtn.isVisible().catch(() => false)
    ? await buyBtn.isDisabled().catch(() => false)
    : true;
  if (!disabled) return;

  // Try select elements first
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
    } catch {
      /* ignore */
    }
  }

  // Try clickable variant swatches
  const swatches = page.locator('[data-variant-id], .product-variant, .sw-product-variant__option:visible');
  const swCount = await swatches.count().catch(() => 0);
  if (swCount > 0) {
    try {
      await swatches.first().click({ force: true });
    } catch {/* ignore */}
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
  const shippingLabels = [
    /Abholung im Markt/i,
    /Abholung/i,
    /Pick[-\s]?up/i,
    /Click ?& ?Collect/i,
    /Store pickup/i,
  ];
  let shippingSelected = false;
  for (const pattern of shippingLabels) {
    const option = page.getByRole('radio', { name: pattern }).first();
    if (await option.isVisible({ timeout: 500 }).catch(() => false)) {
      await option.check();
      shippingSelected = true;
      break;
    }
  }
  if (!shippingSelected) {
    const fallback = page.locator('input[type="radio"][name*="shipping" i][value*="pick" i]');
    if (await fallback.isVisible({ timeout: 500 }).catch(() => false)) {
      await fallback.check({ force: true });
      shippingSelected = true;
    }
  }

  const paymentLabels = [
    /Bezahlung im Markt/i,
    /Zahlung im Markt/i,
    /Payment in store/i,
    /Pay in store/i,
    /Cash on pickup/i,
    /Cash in store/i,
  ];
  let paymentSelected = false;
  for (const pattern of paymentLabels) {
    const option = page.getByRole('radio', { name: pattern }).first();
    if (await option.isVisible({ timeout: 500 }).catch(() => false)) {
      await option.check();
      paymentSelected = true;
      break;
    }
  }
  if (!paymentSelected) {
    const fallback = page.locator('input[type="radio"][name*="payment" i][value*="store" i]');
    if (await fallback.isVisible({ timeout: 500 }).catch(() => false)) {
      await fallback.check({ force: true });
    }
  }
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
    page.getByRole('button', { name: /Bestellung abschließen|Place order|Complete order/i }),
    page.locator('form[name="confirmForm" i] button[type="submit"]:not([disabled])'),
    page.locator('form[action*="checkout/order" i] button[type="submit"]:not([disabled])'),
    page.locator('form[data-form-submit="true"] button[type="submit"]:not([disabled])'),
    page.locator('[data-form-submit-order]'),
  ];
  for (const loc of selectors) {
    if (await loc.isVisible({ timeout: 1000 }).catch(() => false)) {
      await loc.scrollIntoViewIfNeeded().catch(() => undefined);
      await loc.click();
      return;
    }
  }
  // Last-resort DOM trigger limited to known confirm forms to avoid deleting cart items
  const clicked = await page.evaluate(() => {
    const forms = Array.from(document.querySelectorAll(
      'form[name*="confirm" i], form[action*="checkout/order" i]'
    )) as HTMLFormElement[];
    for (const form of forms) {
      const buttons = Array.from(form.querySelectorAll('button[type="submit"]')) as HTMLButtonElement[];
      for (const button of buttons) {
        if (!button.hasAttribute('disabled') && (button.offsetParent !== null || button.getClientRects().length)) {
          try {
            button.click();
            return true;
          } catch {
            /* ignore */
          }
        }
      }
      for (const button of buttons) {
        try {
          button.disabled = false;
          button.click();
          return true;
        } catch {
          /* ignore */
        }
      }
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
    // eslint-disable-next-line no-console
    console.log(`[transitionDeliveryToReady] POST ${p} -> ${res.status}`);
    if (res.ok) return;
  }
  throw new Error(`Failed to transition delivery to ready (last status ${lastStatus})`);
}

async function findLatestOrderNumberByEmail(token: string, email: string): Promise<string | null> {
  const criteria = {
    limit: 1,
    filter: [
      { type: 'equals', field: 'orderCustomer.email', value: email },
    ],
    sort: [
      { field: 'createdAt', order: 'DESC' as const },
    ],
    includes: { order: ['orderNumber'] },
  } as any;
  const res = await fetch(resolveUrl('api/search/order'), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${token}` },
    body: JSON.stringify(criteria),
  });
  if (!res.ok) {
    return null;
  }
  const data = (await res.json()) as { data?: Array<any> };
  const first = Array.isArray(data?.data) && data.data.length ? data.data[0] : null;
  if (!first) return null;
  const attributes = (first && typeof first === 'object' && 'attributes' in first) ? (first as any).attributes : first;
  const number = attributes?.orderNumber ?? null;
  return typeof number === 'string' && number.length > 0 ? number : null;
}

async function waitForLatestOrderNumberByEmail(token: string, email: string, timeoutMs = 30_000): Promise<string | null> {
  const start = Date.now();
  while (Date.now() - start < timeoutMs) {
    const number = await findLatestOrderNumberByEmail(token, email);
    if (number) {
      return number;
    }
    await new Promise((resolve) => setTimeout(resolve, 1000));
  }
  return null;
}

test('Click & Collect: ready email is sent on delivery -> ready', async ({ page }) => {
  test.setTimeout(180_000);

  await clearMailbox();

  await page.goto(resolveUrl('?_e2e=' + Date.now()));
  await page.waitForLoadState('networkidle');
  await acceptCookies(page);

  const searchBox = page.locator('input[type="search"], input[placeholder*="Suche" i], input[placeholder*="Search" i]');
  await expect(searchBox.first()).toBeVisible();
  await searchBox.first().fill('SWDEMO10002');
  await searchBox.first().press('Enter');
  await page.waitForLoadState('networkidle');

  const productLinkByNumber = page.locator('a[href*="SWDEMO10002"]').first();
  if (await productLinkByNumber.isVisible().catch(() => false)) {
    await productLinkByNumber.click();
  } else {
    await page.locator('a.product-name, a.card-title, a[href*="/detail/"]').first().click();
  }
  await page.waitForLoadState('networkidle');

  const addToCart = page.getByRole('button', { name: /In den Warenkorb|Add to shopping cart/i });
  await expect(addToCart).toBeVisible();
  // If variants required, pick first available
  await chooseFirstVariantIfNeeded(page);
  await addToCart.click();

  // Wait for offcanvas or any confirmation flash
  const offcanvas = page.locator('.offcanvas, .cart-offcanvas, .offcanvas.is-open');
  await offcanvas.waitFor({ state: 'visible', timeout: 5000 }).catch(() => undefined);

  // If "go to cart/checkout" button is present, use it; else we'll navigate explicitly
  const goToCart = page.getByRole('button', { name: /Warenkorb anzeigen|Zum Warenkorb|Warenkorb öffnen/i }).first();
  const goToCheckout = page.getByRole('button', { name: /Zur Kasse|Checkout/i }).first();
  if (await goToCheckout.isVisible().catch(() => false)) {
    await goToCheckout.click();
    await page.waitForLoadState('networkidle');
  } else if (await goToCart.isVisible().catch(() => false)) {
    await goToCart.click();
    await page.waitForLoadState('networkidle');
  }

  // Ensure we are in checkout context: first go to cart, then progress
  if (!/checkout\/(cart|confirm|register)/.test(page.url())) {
    await page.goto(resolveUrl('checkout/cart'));
  }
  // If cart is empty, retry a direct navigation to product page and add once more
  const cartEmpty = page.locator('body');
  const cartEmptyText = await cartEmpty.innerText().catch(() => '');
  if (/Warenkorb ist leer|Cart is empty/i.test(cartEmptyText)) {
    // Retry add-to-cart once in case the first click was swallowed
    await page.goBack({ waitUntil: 'domcontentloaded' }).catch(() => undefined);
    if (!/detail\//.test(page.url())) {
      // We might be on listing; click first product
      const firstProduct = page.locator('a.product-name, a.card-title, a[href*="/detail/"]').first();
      if (await firstProduct.isVisible().catch(() => false)) {
        await firstProduct.click();
        await page.waitForLoadState('networkidle');
      }
    }
    await chooseFirstVariantIfNeeded(page);
    await addToCart.click();
    await page.goto(resolveUrl('checkout/cart'));
  }

  // Proceed to confirm/register from cart
  const proceed = page.getByRole('button', { name: /Zur Kasse|Weiter zur Kasse|Proceed to checkout/i }).first();
  if (await proceed.isVisible().catch(() => false)) {
    await proceed.click();
    await page.waitForLoadState('networkidle');
  }

  // Land on confirm or register
  await page.goto(resolveUrl('checkout/confirm'));
  await page.waitForLoadState('networkidle');

  const email = `qa+cc-ready-${Date.now()}@example.com`;
  await fillGuestCheckout(page, email);
  await proceedFromRegisterToConfirm(page);
  await ensureShippingAndPayment(page);
  await acceptTerms(page);
  await placeOrder(page);

  let orderNumber: string | null = null;
  // Try to detect finish page and extract order number; fall back to Admin API lookup by email
  try {
    const body = page.locator('body');
    await expect(body).toContainText(/Bestellnummer|Your order number/i, { timeout: 20000 });
    const bodyText = await body.innerText();
    orderNumber = extractOrderNumber(bodyText);
  } catch {
    // ignore, we'll resolve via Admin API
  }

  const token = await getAdminToken();
  if (!orderNumber) {
    orderNumber = await waitForLatestOrderNumberByEmail(token, email);
  }
  if (!orderNumber) {
    throw new Error('Failed to determine order number from finish page or Admin API.');
  }

  const deliveryId = await findFirstDeliveryIdByOrderNumber(token, orderNumber);
  await transitionDeliveryToReady(token, deliveryId);

  const message = await waitForMessage((m) => /abholbereit|ready for pickup/i.test(m?.subject ?? ''), 90_000);
  const html = await getMessageHtml(message.id);
  expect(html).toMatch(/Abholbereit|ready for pickup/i);
  expect(html).toMatch(new RegExp(orderNumber));
  // New policy: should NOT mention an ID requirement
  expect(html).not.toMatch(/Ausweis|Valid ID/i);
  // Should contain hint that name is enough and payment is in store (DE or EN)
  expect(html).toMatch(/Ihren Namen zu nennen|payment (?:is|happens) in store/i);
});
