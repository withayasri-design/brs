const { test, expect } = require('./fixtures');
test.describe('Dashboard', () => {
  test('loads stats from API', async ({ loggedIn: page }) => {
    await page.waitForFunction(() => document.querySelector('#stat-total-jobs')?.textContent !== '—', { timeout: 5000 });
    expect(Number(await page.locator('#stat-total-jobs').textContent())).toBeGreaterThanOrEqual(0);
  });
  test('shows storage usage', async ({ loggedIn: page }) => {
    await page.waitForFunction(() => !document.querySelector('#storage-table')?.textContent?.includes('Loading'), { timeout: 5000 });
    await expect(page.locator('#storage-table')).not.toContainText('No storage targets');
  });
  test('all nav links present', async ({ loggedIn: page }) => {
    for (const label of ['Backup Jobs','History','Restore','Storage','Users','Audit Log','Settings'])
      await expect(page.locator('nav').getByText(label)).toBeVisible();
  });
});