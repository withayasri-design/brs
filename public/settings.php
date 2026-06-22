<?php
$pageTitle = 'Settings';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/partials/nav.php';
?>
<h2 class="mb-4"><i class="bi bi-gear me-2"></i>Settings</h2>

<div class="row g-4">
  <div class="col-md-7">
    <div class="card">
      <div class="card-header"><i class="bi bi-bell me-1"></i>LINE Notify</div>
      <div class="card-body">
        <div class="mb-3">
          <label class="form-label">LINE Notify Token</label>
          <div class="input-group">
            <input type="password" class="form-control" id="f-token" placeholder="Leave blank to keep current token"
              autocomplete="new-password">
            <button class="btn btn-outline-secondary" type="button" onclick="toggleTokenVisible()" title="Show/hide">
              <i class="bi bi-eye" id="token-eye"></i>
            </button>
          </div>
          <div class="form-text" id="token-status">Loading…</div>
        </div>
        <div class="mb-3">
          <label class="form-label">Notification Mode</label>
          <select class="form-select" id="f-mode">
            <option value="all">All — notify on success and failure</option>
            <option value="failure_only">Failure Only — notify only when backup fails</option>
            <option value="none">None — disable notifications</option>
          </select>
        </div>
        <div class="d-flex gap-2">
          <button class="btn btn-primary" onclick="saveSettings()"><i class="bi bi-save me-1"></i>Save</button>
          <button class="btn btn-outline-secondary" onclick="testNotify()"><i class="bi bi-send me-1"></i>Send Test Notification</button>
        </div>
      </div>
    </div>
  </div>

  <div class="col-md-5">
    <div class="card">
      <div class="card-header"><i class="bi bi-info-circle me-1"></i>How to get a LINE Notify token</div>
      <div class="card-body small text-muted">
        <ol class="ps-3 mb-0">
          <li>ไปที่ <strong>notify-bot.line.me/th</strong> แล้ว Login ด้วย LINE account</li>
          <li>เลือก <strong>My page</strong> → <strong>Generate token</strong></li>
          <li>ตั้งชื่อ token และเลือก chat ปลายทาง (ห้องแชทหรือกลุ่ม)</li>
          <li>Copy token ที่ได้ไปวางในช่องด้านซ้าย</li>
        </ol>
        <hr>
        <p class="mb-0">BRS จะส่งแจ้งเตือนเมื่อ backup สำเร็จ/ล้มเหลว และเมื่อมีการ restore เกิดขึ้น ตามโหมดที่เลือก</p>
      </div>
    </div>
  </div>
</div>

<script>
(async () => {
  try {
    const d = await apiFetch('GET', 'settings');
    document.getElementById('f-mode').value = d.notify_mode || 'failure_only';
    const statusEl = document.getElementById('token-status');
    if (d.line_notify_token_set) {
      statusEl.innerHTML = `<i class="bi bi-check-circle text-success me-1"></i>Token configured (${d.line_notify_token_masked}). Leave blank to keep current.`;
    } else {
      statusEl.textContent = 'No token configured — notifications are disabled until a token is set.';
    }
  } catch(e) { showAlert(e.message); }
})();

function toggleTokenVisible() {
  const inp = document.getElementById('f-token');
  const eye = document.getElementById('token-eye');
  if (inp.type === 'password') {
    inp.type = 'text';
    eye.className = 'bi bi-eye-slash';
  } else {
    inp.type = 'password';
    eye.className = 'bi bi-eye';
  }
}

async function saveSettings() {
  const token = document.getElementById('f-token').value.trim();
  const mode  = document.getElementById('f-mode').value;
  const body  = { notify_mode: mode };
  if (token) body.line_notify_token = token;
  try {
    await apiFetch('PUT', 'settings', body);
    showAlert('Settings saved.', 'success');
    document.getElementById('f-token').value = '';
    // Reload to refresh token status
    setTimeout(() => location.reload(), 800);
  } catch(e) { showAlert(e.message); }
}

async function testNotify() {
  const token = document.getElementById('f-token').value.trim();
  try {
    const body = {};
    if (token) body.line_notify_token = token;
    await apiFetch('POST', 'settings/test-notify', body);
    showAlert('Test notification sent — check your LINE chat.', 'success');
  } catch(e) { showAlert(e.message); }
}
</script>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
