const { test, expect, BASE } = require('./fixtures');
test.describe('Backup Jobs', () => {
  test.beforeEach(async ({ loggedIn: page }) => {
    await page.goto(BASE + '/jobs.php');
    await page.waitForFunction(() => !document.querySelector('#jobs-body td')?.textContent?.includes('Loading'), { timeout: 5000 });
  });
  test('lists jobs', async ({ loggedIn: page }) => {
    expect(await page.locator('#jobs-body tr').count()).toBeGreaterThan(0);
  });
  test('row has all action buttons', async ({ loggedIn: page }) => {
    const row = page.locator('#jobs-body tr').first();
    await expect(row.locator('a[title="View History"]')).toBeVisible();
    await expect(row.locator('a[title="Edit"]')).toBeVisible();
    await expect(row.locator('button[title="Delete"]')).toBeVisible();
    await expect(row.locator('button[title="Backup Now"]')).toBeVisible();
  });
  test('history link goes to history page', async ({ loggedIn: page }) => {
    const link = page.locator('#jobs-body tr').first().locator('a[title="View History"]');
    expect(await link.getAttribute('href')).toMatch(/history\.php\?job_id=\d+/);
    await link.click();
    await expect(page).toHaveURL(/history\.php/);
  });
  test('new job form has storage target checkboxes', async ({ loggedIn: page }) => {
    await page.goto(BASE + '/job-edit.php');
    await page.waitForFunction(() => document.querySelector('input[name="storage_targets"]') !== null, { timeout: 5000 });
    await expect(page.locator('input[name="storage_targets"]').first()).toBeVisible();
  });
});