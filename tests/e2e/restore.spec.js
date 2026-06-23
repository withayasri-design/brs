const { test, expect, BASE } = require('./fixtures');
test.describe('Restore Wizard', () => {
  test('without id shows prompt', async ({ loggedIn: page }) => {
    await page.goto(BASE + '/restore.php');
    await expect(page.locator('.alert-info')).toBeVisible();
  });
  test('shows backup info card', async ({ loggedIn: page }) => {
    await page.goto(BASE + '/restore.php?backup_log_id=17');
    await page.waitForFunction(() => document.querySelector('#backup-info-card strong') !== null, { timeout: 5000 });
    await expect(page.locator('#backup-info-card strong')).not.toBeEmpty();
    await expect(page.locator('#backup-info-card a').filter({ hasText: 'Back to history' })).toBeVisible();
  });
  test('validate triggers integrity check', async ({ loggedIn: page }) => {
    await page.goto(BASE + '/restore.php?backup_log_id=17');
    await page.waitForSelector('#btn-validate', { timeout: 5000 });
    await page.click('#btn-validate');
    await page.waitForSelector('#validate-result .alert', { timeout: 10000 });
    await expect(page.locator('#validate-result .alert')).toContainText('Checksum');
  });
  test('restore panel appears after validation', async ({ loggedIn: page }) => {
    await page.goto(BASE + '/restore.php?backup_log_id=17');
    await page.waitForSelector('#btn-validate', { timeout: 5000 });
    await page.click('#btn-validate');
    await page.waitForSelector('#validate-result .alert-success', { timeout: 10000 });
    await expect(page.locator('#restore-panel')).toBeVisible();
  });
  test('real restore mode shows confirm field', async ({ loggedIn: page }) => {
    await page.goto(BASE + '/restore.php?backup_log_id=17');
    await page.waitForSelector('#btn-validate', { timeout: 5000 });
    await page.click('#btn-validate');
    await page.waitForSelector('#restore-panel', { timeout: 10000 });
    await page.selectOption('#restore_mode', 'real');
    await expect(page.locator('#confirm-panel')).toBeVisible();
  });
});