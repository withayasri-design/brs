const { test: base, expect } = require('@playwright/test');

const BASE = 'http://localhost/brs/public';

async function login(page) {
  await page.goto(`${BASE}/login.php`);
  await page.fill('#username', 'admin');
  await page.fill('#password', 'Admin@1234');
  await page.click('#btn-login');
  await page.waitForURL(`${BASE}/index.php`);
}

const test = base.extend({
  loggedIn: async ({ page }, use) => {
    await login(page);
    await use(page);
  },
});

module.exports = { test, expect, BASE };
