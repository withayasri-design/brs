const { test, expect, BASE } = require('./fixtures');
test.describe('Settings', () => {
  test.beforeEach(async ({ loggedIn: page }) => {
    await page.goto(BASE + '/settings.php');
    await page.waitForFunction(
      () => document.querySelector('#f-mode') !== null && !document.querySelector('#token-status')?.textContent?.includes('Loading'),
      { timeout: 5000 }
    );
  });
  test('loads notify mode from API', async ({ loggedIn: page }) => {
    const mode = await page.locator('#f-mode').inputValue();
    expect(['all','failure_only','none']).toContain(mode);
  });
  test('token status message is shown', async ({ loggedIn: page }) => {
    const status = await page.locator('#token-status').textContent();
    expect(status || '').toMatch(/configured|no token/i);
  });
  test('show/hide toggle switches input type', async ({ loggedIn: page }) => {
    await expect(page.locator('#f-token')).toHaveAttribute('type', 'password');
    await page.click('button[title="Show/hide"]');
    await expect(page.locator('#f-token')).toHaveAttribute('type', 'text');
  });
  test('export downloads a JSON file', async ({ loggedIn: page }) => {
    const [download] = await Promise.all([
      page.waitForEvent('download', { timeout: 5000 }),
      page.click('button:has-text("Export All Jobs")'),
    ]);
    expect(download.suggestedFilename()).toMatch(/^brs-jobs-.*\.json$/);
  });
  test('import preview shows job table', async ({ loggedIn: page }) => {
    const payload = JSON.stringify({ jobs: [{ job_name: 'E2E Import', backup_type: 'database_only', db_host: 'localhost', db_name: 'test', db_username: 'root', storage_target_names: ['Local Default'], retention_daily: 7, retention_weekly: 4, retention_monthly: 6 }] });
    await page.locator('#import-file').setInputFiles({ name: 'import.json', mimeType: 'application/json', buffer: Buffer.from(payload) });
    await page.waitForSelector('#import-preview', { timeout: 5000 });
    await expect(page.locator('#import-table tbody tr')).toHaveCount(1);
    await expect(page.locator('#import-table tbody td').first()).toContainText('E2E Import');
  });
});