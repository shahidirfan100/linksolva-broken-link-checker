const { chromium } = require('playwright');
const fs = require('fs');
const { execFileSync } = require('child_process');
let page;
(async () => {
  const browser = await chromium.launch({ headless: true });
  page = await browser.newPage({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 1 });
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.goto('http://127.0.0.1:8080/wp-login.php');
  await page.locator('#user_login').fill('admin');
  await page.locator('#user_pass').fill('test-password');
  await page.locator('#wp-submit').click();
  let dashboardWaiting = false;
  let listStartedDuringDashboard = false;
  const loadingRoute = async route => {
    const url = decodeURIComponent(route.request().url());
    if (url.includes('/sblc/v1/dashboard')) {
      dashboardWaiting = true;
      await new Promise(resolve => setTimeout(resolve, 750));
      dashboardWaiting = false;
    } else if (url.includes('/sblc/v1/resources') && dashboardWaiting) {
      listStartedDuringDashboard = true;
    }
    return route.continue();
  };
  await page.route('**/*', loadingRoute);
  await page.goto('http://127.0.0.1:8080/wp-admin/admin.php?page=sblc-dashboard');
  await page.locator('[data-resource-row]').first().waitFor();
  if (!listStartedDuringDashboard) throw new Error('Findings list waited for the dashboard request');
  await page.unroute('**/*', loadingRoute);
  await page.getByText('example.com/missing-page', { exact: false }).first().waitFor();
  fs.mkdirSync('screenshots', { recursive: true });
  await page.screenshot({ path: 'screenshots/screenshot-1.png', fullPage: true });
  const broken = page.locator('[data-resource-row]').filter({ hasText: 'example.com/missing-page' });
  await broken.locator('[data-action="open-resource"]').click();
  await page.locator('[role="dialog"]').waitFor();
  await page.getByText('Request history', { exact: true }).waitFor();
  await page.screenshot({ path: 'screenshots/screenshot-2.png', fullPage: true });
  await page.keyboard.press('Escape');
  if (await page.locator('[role="dialog"]').count()) throw new Error('Escape did not close the evidence drawer');
  const redirect = page.locator('[data-resource-row]').filter({ hasText: 'example.com/old-page' });
  await redirect.locator('[data-action="open-resource"]').click();
  await page.getByText('Redirect chain', { exact: true }).waitFor();
  await page.screenshot({ path: 'screenshots/screenshot-3.png', fullPage: true });
  await page.keyboard.press('Escape');
  await page.locator('[data-resource-row]').filter({ hasText: 'example.com/slow-page' }).locator('[data-action="open-resource"]').click();
  await page.locator('[role="dialog"]').waitFor();
  if (await page.locator('.sblc-repair-form').count()) throw new Error('Read-only sources must not show repair controls');
  await page.keyboard.press('Escape');
  await page.locator('[data-resource-row]').filter({ hasText: 'example.com/missing-page' }).locator('[data-action="open-resource"]').click();
  await page.locator('.sblc-repair-form').waitFor();
  page.on('dialog', dialog => dialog.accept());
  let repairAttempts = 0;
  const repairRoute = async route => {
    if (!decodeURIComponent(route.request().url()).includes('/sblc/v1/repair')) return route.continue();
    repairAttempts++;
    if (repairAttempts === 1) return route.fulfill({ status: 403, json: { code: 'rest_forbidden', message: 'Sorry, you do not have permission to make REST API requests.' } });
    if (repairAttempts === 2) return route.fulfill({ status: 401, json: { code: 'rest_not_logged_in', message: 'Not logged in' } });
    if (repairAttempts === 3) return route.fulfill({ status: 403, json: { code: 'rest_cookie_invalid_nonce', message: 'Expired nonce' } });
    return route.continue();
  };
  await page.route('**/*', repairRoute);
  const unlink = page.locator('[data-action="apply-repair"][data-operation="unlink"]');
  await unlink.click();
  await page.locator('[data-repair-error]').getByText('Sorry, you do not have permission to make REST API requests.').waitFor();
  if (repairAttempts !== 1) throw new Error('A permission denial must not be retried or bypassed');
  await unlink.click();
  await page.locator('[data-repair-error]').getByText('Your session has expired. Sign in again and retry.').waitFor();
  if (repairAttempts !== 2) throw new Error('A signed-out request must not be retried');
  await unlink.click();
  await page.getByText('The link was unlinked and removed from the finding when no sources still used it.').waitFor();
  if (repairAttempts !== 4) throw new Error('Expired nonce was not refreshed and retried exactly once');
  await page.unroute('**/*', repairRoute);
  const postId = await page.evaluate(async () => {
    const headers = { 'X-WP-Nonce': window.LINKSOLVA_BLC_DATA.nonce };
    const response = await fetch('/?rest_route=/wp/v2/posts&search=Sample%20article&context=edit', { headers });
    const posts = await response.json();
    if (!response.ok || posts.length !== 1 || posts[0].content.raw.includes('href="https://example.com/missing-page"')) throw new Error('Unlink did not change the WordPress post');
    return posts[0].id;
  });
  await page.goto('http://127.0.0.1:8080/wp-admin/admin.php?page=sblc-settings');
  await page.locator('#sblc-timeout').waitFor();
  await page.screenshot({ path: 'screenshots/screenshot-4.png', fullPage: true });
  await page.locator('#sblc-timeout').fill('9');
  await page.locator('[data-action="save-settings"]').click();
  await page.getByText('Settings saved.').waitFor();
  await page.reload();
  await page.locator('#sblc-timeout').waitFor();
  if (await page.locator('#sblc-timeout').inputValue() !== '9') throw new Error('Settings did not persist');

  await page.evaluate(async id => {
    const urls = ['healthy', 'missing', 'redirect', 'slow', 'head-unsupported'];
    const content = urls.map(value => '<a href="http://127.0.0.1:8080/sblc-test-target.php?case=' + value + '">' + value + '</a>').join(' ');
    const response = await fetch('/?rest_route=/wp/v2/posts/' + id, { method: 'POST', headers: { 'X-WP-Nonce': window.LINKSOLVA_BLC_DATA.nonce, 'Content-Type': 'application/json' }, body: JSON.stringify({ content }) });
    if (!response.ok) throw new Error('Could not update the real scan fixture');
  }, postId);
  await page.goto('http://127.0.0.1:8080/wp-admin/admin.php?page=sblc-dashboard');
  await page.locator('[data-action="start-scan"]').waitFor();
  const samples = [];
  const sample = () => {
    try {
      const rows = execFileSync('ps', ['-C', 'php', '-o', 'rss=,%cpu='], { encoding: 'utf8' }).trim().split('\n');
      const values = rows.map(row => row.trim().split(/\s+/).map(Number));
      samples.push({ rss: values.reduce((sum, value) => sum + value[0], 0), cpu: values.reduce((sum, value) => sum + value[1], 0) });
    } catch (error) { throw new Error('Could not sample PHP worker resources: ' + error.message); }
  };
  sample();
  const sampler = setInterval(sample, 250);
  const scanStart = Date.now();
  await Promise.all([
    page.waitForResponse(response => decodeURIComponent(response.url()).includes('/sblc/v1/scan/start') && response.request().method() === 'POST'),
    page.locator('[data-action="start-scan"]').click()
  ]);
  await page.locator('[data-action="start-scan"]').waitFor({ state: 'visible', timeout: 120000 });
  await page.locator('[data-resource-row]').filter({ hasText: 'case=slow' }).waitFor();
  clearInterval(sampler);
  for (const [name, status] of [['healthy', 'Healthy'], ['missing', 'Broken'], ['redirect', 'Redirects'], ['slow', 'Healthy'], ['head-unsupported', 'Healthy']]) {
    const row = page.locator('[data-resource-row]').filter({ hasText: 'case=' + name });
    if (!(await row.innerText()).includes(status)) throw new Error('Unexpected real HTTP scan result for ' + name + ': ' + await row.innerText());
  }
  console.log('Real HTTP scan: URLs=5, elapsed=' + ((Date.now() - scanStart) / 1000).toFixed(2) + 's, peak summed PHP RSS=' + (Math.max(...samples.map(value => value.rss)) / 1024).toFixed(1) + ' MiB, peak sampled PHP CPU=' + Math.max(...samples.map(value => value.cpu)).toFixed(1) + '% (summed process RSS includes shared pages).');
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('http://127.0.0.1:8080/wp-admin/admin.php?page=sblc-dashboard');
  await page.locator('[data-resource-row]').first().waitFor();
  if (await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)) throw new Error('Mobile page overflows horizontally');
  if (errors.length) throw new Error('Browser errors: ' + errors.join('; '));
  await browser.close();
  console.log('Screenshots captured; evidence, keyboard dismissal, mobile layout and browser errors checked.');
})().catch(async error => {
  console.error(error);
  if (page) {
    fs.mkdirSync('screenshots', { recursive: true });
    console.error('Current page:', page.url());
    console.error('Page text:', (await page.locator('body').innerText()).slice(0, 3000));
    await page.screenshot({ path: 'screenshots/failure.png', fullPage: true });
  }
  process.exit(1);
});
