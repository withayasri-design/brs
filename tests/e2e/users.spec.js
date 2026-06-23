const { test, expect, BASE } = require('./fixtures');
test.describe('Users', () => {
  test.beforeEach(async ({ loggedIn: page }) => {
    await page.goto(BASE + '/users.php');
    await page.waitForFunction(() => !document.querySelector('#users-body td')?.textContent?.includes('Loading'), { timeout: 5000 });
  });
  test('lists users', async ({ loggedIn: page }) => {
    await expect(page.locator('#users-body td strong').filter({ hasText: 'admin' })).toBeVisible();
  });
  test('admin badge is red', async ({ loggedIn: page }) => {
    await expect(page.locator('#users-body .badge.bg-danger').filter({ hasText: 'admin' })).toBeVisible();
  });
  test('Add User modal opens', async ({ loggedIn: page }) => {
    await page.click('button:has-text("Add User")');
    await expect(page.locator('#user-modal')).toBeVisible({ timeout: 3000 });
    await expect(page.locator('#f-username')).toBeVisible();
    await page.keyboard.press('Escape');
  });
  test('Reset Password modal shows username', async ({ loggedIn: page }) => {
    await page.locator('#users-body button[title="Reset Password"]').first().click();
    await expect(page.locator('#reset-modal')).toBeVisible({ timeout: 3000 });
    await expect(page.locator('#reset-username')).not.toBeEmpty();
    await page.keyboard.press('Escape');
  });
});