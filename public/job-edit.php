<?php
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$pageTitle = $id ? 'Edit Job' : 'New Job';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/partials/nav.php';
?>
<h2 class="mb-4"><?= $id ? 'Edit Backup Job' : 'New Backup Job' ?></h2>
<form id="job-form" class="row g-3">
  <div class="col-md-8">
    <label class="form-label">Job Name <span class="text-danger">*</span></label>
    <input type="text" class="form-control" id="job_name" required>
  </div>
  <div class="col-md-4">
    <label class="form-label">Backup Type</label>
    <select class="form-select" id="backup_type">
      <option value="both">Files + Database</option>
      <option value="files_only">Files Only</option>
      <option value="database_only">Database Only</option>
    </select>
  </div>
  <div class="col-12"><hr><h6>File Settings</h6></div>
  <div class="col-md-8">
    <label class="form-label">App Path (e.g. C:\xampp\htdocs\hr2000)</label>
    <input type="text" class="form-control" id="app_path">
  </div>
  <div class="col-md-4">
    <label class="form-label">Exclude Patterns (comma-separated)</label>
    <input type="text" class="form-control" id="exclude_patterns" placeholder="cache/*,*.log,tmp/*">
  </div>
  <div class="col-12"><hr><h6>Database Settings</h6></div>
  <div class="col-md-4"><label class="form-label">DB Host</label><input type="text" class="form-control" id="db_host" value="localhost"></div>
  <div class="col-md-2"><label class="form-label">DB Port</label><input type="number" class="form-control" id="db_port" value="3306"></div>
  <div class="col-md-3"><label class="form-label">Database Name</label><input type="text" class="form-control" id="db_name"></div>
  <div class="col-md-3"><label class="form-label">DB Username</label><input type="text" class="form-control" id="db_username"></div>
  <div class="col-12"><label class="form-label">DB Password <?= $id ? '(leave blank to keep current)' : '' ?></label><input type="password" class="form-control" id="db_password"></div>
  <div class="col-12"><hr><h6>Schedule & Retention</h6></div>
  <div class="col-md-4"><label class="form-label">Cron Schedule</label><input type="text" class="form-control" id="schedule_cron" placeholder="0 2 * * *"></div>
  <div class="col-md-2"><label class="form-label">Keep Daily</label><input type="number" class="form-control" id="retention_daily" value="7"></div>
  <div class="col-md-2"><label class="form-label">Keep Weekly</label><input type="number" class="form-control" id="retention_weekly" value="4"></div>
  <div class="col-md-2"><label class="form-label">Keep Monthly</label><input type="number" class="form-control" id="retention_monthly" value="6"></div>
  <div class="col-md-2 d-flex align-items-end">
    <div class="form-check">
      <input class="form-check-input" type="checkbox" id="encryption_enabled" checked>
      <label class="form-check-label">Encrypt</label>
    </div>
  </div>
  <div class="col-12"><hr><h6>Storage Targets</h6></div>
  <div class="col-12">
    <div id="storage-targets-list" class="row g-2">
      <div class="text-muted small">Loading storage targets…</div>
    </div>
    <div class="form-text">Select one or more destinations where backups will be stored. At least one is required.</div>
  </div>
  <div class="col-12"><hr>
    <button type="submit" class="btn btn-primary me-2">Save Job</button>
    <a href="jobs.php" class="btn btn-secondary">Cancel</a>
  </div>
</form>
<script>
const JOB_ID = <?= json_encode($id) ?>;
let assignedTargetIds = [];

async function loadStorageTargets() {
  try {
    const targets = await apiFetch('GET', 'storage-targets');
    const wrap = document.getElementById('storage-targets-list');
    if (!targets.length) {
      wrap.innerHTML = '<div class="text-warning small"><i class="bi bi-exclamation-triangle me-1"></i>No storage targets configured. <a href="storage-targets.php">Add one first.</a></div>';
      return;
    }
    wrap.innerHTML = targets.map(t => `
      <div class="col-md-4">
        <div class="form-check border rounded p-2">
          <input class="form-check-input" type="checkbox" value="${t.id}" id="st-${t.id}" name="storage_targets"
            ${assignedTargetIds.includes(Number(t.id)) ? 'checked' : ''}>
          <label class="form-check-label" for="st-${t.id}">
            <strong>${t.target_name}</strong>
            <span class="badge bg-secondary ms-1">${t.provider_type}</span>
            ${!t.is_active ? '<span class="badge bg-warning ms-1">Inactive</span>' : ''}
          </label>
        </div>
      </div>`).join('');
  } catch(e) { showAlert(e.message); }
}

if (JOB_ID) {
  apiFetch('GET',`jobs/${JOB_ID}`).then(j => {
    document.getElementById('job_name').value = j.job_name||'';
    document.getElementById('backup_type').value = j.backup_type||'both';
    document.getElementById('app_path').value = j.app_path||'';
    document.getElementById('db_host').value = j.db_host||'localhost';
    document.getElementById('db_port').value = j.db_port||3306;
    document.getElementById('db_name').value = j.db_name||'';
    document.getElementById('db_username').value = j.db_username||'';
    document.getElementById('schedule_cron').value = j.schedule_cron||'';
    document.getElementById('retention_daily').value = j.retention_daily||7;
    document.getElementById('retention_weekly').value = j.retention_weekly||4;
    document.getElementById('retention_monthly').value = j.retention_monthly||6;
    document.getElementById('encryption_enabled').checked = !!j.encryption_enabled;
    const exc = JSON.parse(j.exclude_patterns||'[]');
    document.getElementById('exclude_patterns').value = exc.join(',');
    assignedTargetIds = (j.storage_target_ids || []).map(Number);
    loadStorageTargets();
  }).catch(e => showAlert(e.message));
} else {
  loadStorageTargets();
}

document.getElementById('job-form').addEventListener('submit', async e => {
  e.preventDefault();
  const exc = document.getElementById('exclude_patterns').value.split(',').map(s=>s.trim()).filter(Boolean);
  const selectedTargets = [...document.querySelectorAll('input[name="storage_targets"]:checked')].map(el => parseInt(el.value));
  const body = {
    job_name: document.getElementById('job_name').value,
    backup_type: document.getElementById('backup_type').value,
    app_path: document.getElementById('app_path').value||null,
    exclude_patterns: exc,
    db_host: document.getElementById('db_host').value||null,
    db_port: parseInt(document.getElementById('db_port').value)||3306,
    db_name: document.getElementById('db_name').value||null,
    db_username: document.getElementById('db_username').value||null,
    schedule_cron: document.getElementById('schedule_cron').value||null,
    retention_daily: parseInt(document.getElementById('retention_daily').value)||7,
    retention_weekly: parseInt(document.getElementById('retention_weekly').value)||4,
    retention_monthly: parseInt(document.getElementById('retention_monthly').value)||6,
    encryption_enabled: document.getElementById('encryption_enabled').checked ? 1 : 0,
    storage_target_ids: selectedTargets,
  };
  const pw = document.getElementById('db_password').value;
  if (pw) body.db_password = pw;
  try {
    if (JOB_ID) { await apiFetch('PUT',`jobs/${JOB_ID}`,body); }
    else { await apiFetch('POST','jobs',body); }
    location.href = 'jobs.php';
  } catch(e) { showAlert(e.message); }
});
</script>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
