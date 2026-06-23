const { test, expect, BASE } = require('./fixtures');
test.describe('Login', () => {
  test('redirects unauthenticated to login', async ({ page }) => {
    await page.goto(BASE + '/index.php');
    await expect(page).toHaveURL(/login\.php/);
  });
  test('rejects wrong password', async ({ page }) => {
    await page.goto(BASE + '/login.php');
    await page.fill('#username', 'admin');
    await page.fill('#password', 'wrong');
    await page.click('#btn-login');
    await expect(page.locator('.alert-danger')).toBeVisible();
  });
  test('blocks empty submit via HTML5 required', async ({ page }) => {
    await page.goto(BASE + '/login.php');
    await page.click('#btn-login');
    // HTML5 required prevents submission — page stays on login.php, no redirect
    await expect(page).toHaveURL(/login\.php/);
    await expect(page.locator('#username:invalid')).toBeAttached();
  });
  test('accepts valid credentials', async ({ page }) => {
    await page.goto(BASE + '/login.php');
    await page.fill('#username', 'admin');
    await page.fill('#password', 'Admin@1234');
    await page.click('#btn-login');
    await page.waitForURL(BASE + '/index.php');
    await expect(page.locator('h2')).toContainText('Dashboard');
  });
  test('logout clears session', async ({ loggedIn: page }) => {
    await page.click('#btn-logout');
    await page.waitForURL(/login\.php/);
    await page.goto(BASE + '/index.php');
    await expect(page).toHaveURL(/login\.php/);
  });
});