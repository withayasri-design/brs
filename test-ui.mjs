import { chromium } from 'playwright';

const BASE = 'http://localhost/brs/public';
const PASS = '✅';
const FAIL = '❌';

let browser, page;
const results = [];

function log(label, ok, detail = '') {
  const mark = ok ? PASS : FAIL;
  console.log(`${mark} ${label}${detail ? ' — ' + detail : ''}`);
  results.push({ label, ok });
}

async function shot(name) {
  await page.screenshot({ path: `test-screenshots/${name}.png`, fullPage: false });
}

async function run() {
  browser = await chromium.launch({ headless: true });
  page    = await browser.newPage();
  page.setDefaultTimeout(10000);

  // Create screenshot dir
  const fs = await import('fs');
  fs.mkdirSync('test-screenshots', { recursive: true });

  // ── 1. Login page ──────────────────────────────────────────────
  await page.goto(`${BASE}/login.php`);
  await shot('01-login');
  log('Login page loads', await page.title().then(t => t.includes('BRS')));

  // Wrong password
  await page.fill('#username', 'admin');
  await page.fill('#password', 'wrong');
  await page.click('#btn-login');
  await page.waitForTimeout(800);
  const loginErr = await page.locator('#alert-container .alert-danger').isVisible();
  log('Bad password shows error', loginErr);

  // Correct login
  await page.fill('#password', 'Admin@1234');
  await page.click('#btn-login');
  await page.waitForURL(`${BASE}/index.php`, { timeout: 5000 });
  log('Correct login → dashboard', page.url().includes('index.php'));

  // ── 2. Dashboard ───────────────────────────────────────────────
  await page.waitForFunction(() => document.querySelector('#stat-total-jobs')?.textContent !== '—', { timeout: 5000 });
  const totalJobs = await page.locator('#stat-total-jobs').textContent();
  log('Dashboard stats loaded', totalJobs !== '—', `total_jobs=${totalJobs}`);
  await shot('02-dashboard');

  // ── 3. Jobs page ───────────────────────────────────────────────
  await page.click('a[href*="jobs.php"]');
  await page.waitForURL(`${BASE}/jobs.php`);
  await page.waitForFunction(() => !document.querySelector('#jobs-body td')?.textContent.includes('Loading'), { timeout: 5000 });
  const jobRows = await page.locator('#jobs-body tr').count();
  log('Jobs page loads', jobRows > 0, `${jobRows} job(s)`);
  await shot('03-jobs');

  // ── 4. Job Edit (new) ──────────────────────────────────────────
  await page.click('a[href="job-edit.php"]');
  await page.waitForURL(`${BASE}/job-edit.php`);
  const storageTargetSection = await page.locator('#storage-targets-list').isVisible();
  log('Job edit — storage targets section visible', storageTargetSection);
  await page.waitForTimeout(800); // wait for targets to load
  const targetCheckboxes = await page.locator('input[name="storage_targets"]').count();
  log('Job edit — storage target checkboxes rendered', targetCheckboxes > 0, `${targetCheckboxes} target(s)`);
  await shot('04-job-edit');

  // ── 5. Storage Targets page ────────────────────────────────────
  await page.click('a[href*="storage-targets.php"]');
  await page.waitForURL(`${BASE}/storage-targets.php`);
  await page.waitForSelector('#targets-body tr td', { timeout: 5000 });
  const targetRows = await page.locator('#targets-body tr').count();
  log('Storage targets page loads', targetRows > 0, `${targetRows} target(s)`);
  await shot('05-storage-targets');

  // Open Add modal
  await page.click('button:has-text("Add Target")');
  await page.waitForSelector('#target-modal.show', { timeout: 3000 });
  log('Add Storage Target modal opens', await page.locator('#target-modal').isVisible());

  // Switch provider to S3 and verify config fields render
  await page.selectOption('#f-provider', 's3');
  await page.waitForTimeout(300);
  const bucketField = await page.locator('#cfg-bucket').isVisible();
  log('S3 config fields render dynamically', bucketField);
  await shot('05b-storage-add-modal');
  await page.keyboard.press('Escape');

  // ── 6. Users page ─────────────────────────────────────────────
  await page.click('a[href*="users.php"]');
  await page.waitForURL(`${BASE}/users.php`);
  await page.waitForSelector('#users-body tr td', { timeout: 5000 });
  const userRows = await page.locator('#users-body tr').count();
  log('Users page loads', userRows > 0, `${userRows} user(s)`);
  await shot('06-users');

  // ── 7. Audit Log page ─────────────────────────────────────────
  await page.click('a[href*="audit-log.php"]');
  await page.waitForURL(`${BASE}/audit-log.php`);
  await page.waitForFunction(() => !document.querySelector('#log-body td')?.textContent.includes('Loading'), { timeout: 5000 });
  const logRows = await page.locator('#log-body tr').count();
  log('Audit log page loads', logRows > 0, `${logRows} row(s)`);
  await page.waitForFunction(() => document.querySelector('#page-info')?.textContent.includes('entries'), { timeout: 5000 });
  const pageInfo = await page.locator('#page-info').textContent();
  log('Audit log pagination info shown', pageInfo.includes('entries'), pageInfo.trim());
  await shot('07-audit-log');

  // Filter by action
  await page.fill('#f-action', 'backup');
  await page.click('button:has-text("Search")');
  await page.waitForTimeout(1000);
  const filteredRows = await page.locator('#log-body tr').count();
  log('Audit log action filter works', filteredRows > 0, `${filteredRows} result(s)`);
  await shot('07b-audit-log-filtered');

  // ── 8. Settings page ──────────────────────────────────────────
  await page.click('a[href*="settings.php"]');
  await page.waitForURL(`${BASE}/settings.php`);
  await page.waitForSelector('#f-mode', { timeout: 5000 });
  const notifyMode = await page.locator('#f-mode').inputValue();
  log('Settings page loads', true, `notify_mode=${notifyMode}`);
  await shot('08-settings');

  // ── 9. History page ────────────────────────────────────────────
  await page.goto(`${BASE}/history.php?job_id=17`);
  await page.waitForSelector('#history-body tr td', { timeout: 5000 });
  const histRows = await page.locator('#history-body tr').count();
  log('Backup history page loads', histRows > 0, `${histRows} backup(s)`);
  await shot('09-history');

  // ── Summary ───────────────────────────────────────────────────
  const passed = results.filter(r => r.ok).length;
  const total  = results.length;
  console.log(`\n${'─'.repeat(50)}`);
  console.log(`Result: ${passed}/${total} checks passed`);
  if (passed < total) {
    console.log('Failed:');
    results.filter(r => !r.ok).forEach(r => console.log(`  ${FAIL} ${r.label}`));
  }
}

run()
  .catch(e => { console.error('FATAL:', e.message); process.exit(1); })
  .finally(() => browser?.close());
