const { test, expect, BASE } = require('./fixtures');
test.describe('Backup History', () => {
  test('without job_id shows prompt', async ({ loggedIn: page }) => {
    await page.goto(BASE + '/history.php');
    await expect(page.locator('.alert-info')).toBeVisible();
  });
  test('job name shown in header', async ({ loggedIn: page }) => {
    await page.goto(BASE + '/history.php?job_id=17');
    await page.waitForFunction(() => document.querySelector('#job-header h5') !== null, { timeout: 5000 });
    await expect(page.locator('#job-header h5')).not.toBeEmpty();
  });
  test('history table shows rows with badges', async ({ loggedIn: page }) => {
    await page.goto(BASE + '/history.php?job_id=17');
    await page.waitForFunction(() => !document.querySelector('#history-body td')?.textContent?.includes('Loading'), { timeout: 5000 });
    expect(await page.locator('#history-body tr').count()).toBeGreaterThan(0);
    await expect(page.locator('#history-body .badge').first()).toBeVisible();
  });
  test('restore button links to restore wizard', async ({ loggedIn: page }) => {
    await page.goto(BASE + '/history.php?job_id=17');
    await page.waitForFunction(() => !document.querySelector('#history-body td')?.textContent?.includes('Loading'), { timeout: 5000 });
    const link = page.locator('#history-body a[title="Restore"]').first();
    expect(await link.getAttribute('href')).toMatch(/restore\.php\?backup_log_id=\d+/);
  });
});