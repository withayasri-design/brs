const { test, expect, BASE } = require('./fixtures');
test.describe('Audit Log', () => {
  test.beforeEach(async ({ loggedIn: page }) => {
    await page.goto(BASE + '/audit-log.php');
    await page.waitForFunction(() => !document.querySelector('#log-body td')?.textContent?.includes('Loading'), { timeout: 5000 });
  });
  test('shows entries with action badges', async ({ loggedIn: page }) => {
    expect(await page.locator('#log-body tr').count()).toBeGreaterThan(0);
    await expect(page.locator('#log-body .badge').first()).toBeVisible();
  });
  test('pagination shows total', async ({ loggedIn: page }) => {
    await page.waitForFunction(() => document.querySelector('#page-info')?.textContent?.includes('entries'), { timeout: 5000 });
    await expect(page.locator('#page-info')).toContainText('entries');
  });
  test('action filter works', async ({ loggedIn: page }) => {
    await page.fill('#f-action', 'backup');
    await page.click('button:has-text("Search")');
    await page.waitForFunction(() => !document.querySelector('#log-body td')?.textContent?.includes('Loading'), { timeout: 5000 });
    expect(await page.locator('#log-body tr').count()).toBeGreaterThan(0);
  });
  test('clear resets filters', async ({ loggedIn: page }) => {
    await page.fill('#f-action', 'auth');
    await page.click('button[title="Clear"]');
    await expect(page.locator('#f-action')).toHaveValue('');
  });
});