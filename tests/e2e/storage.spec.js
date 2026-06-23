const { test, expect, BASE } = require('./fixtures');
test.describe('Storage Targets', () => {
  test.beforeEach(async ({ loggedIn: page }) => {
    await page.goto(BASE + '/storage-targets.php');
    await page.waitForFunction(() => !document.querySelector('#targets-body td')?.textContent?.includes('Loading'), { timeout: 5000 });
  });
  test('lists targets', async ({ loggedIn: page }) => {
    expect(await page.locator('#targets-body tr').count()).toBeGreaterThan(0);
  });
  test('Add Target modal opens', async ({ loggedIn: page }) => {
    await page.click('button:has-text("Add Target")');
    await expect(page.locator('#target-modal')).toBeVisible({ timeout: 3000 });
    await page.keyboard.press('Escape');
  });
  test('provider selection renders dynamic config fields', async ({ loggedIn: page }) => {
    await page.click('button:has-text("Add Target")');
    await page.waitForSelector('#target-modal', { timeout: 3000 });
    await page.selectOption('#f-provider', 'local');
    await expect(page.locator('#cfg-path')).toBeVisible();
    await page.selectOption('#f-provider', 's3');
    await expect(page.locator('#cfg-bucket')).toBeVisible();
    await page.selectOption('#f-provider', 'sftp');
    await expect(page.locator('#cfg-host')).toBeVisible();
    await page.keyboard.press('Escape');
  });
});