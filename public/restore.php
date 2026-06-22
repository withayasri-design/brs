<?php
$pageTitle = 'Restore';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/partials/nav.php';
$backupLogId = isset($_GET['backup_log_id']) ? (int)$_GET['backup_log_id'] : null;
?>
<h2 class="mb-4"><i class="bi bi-arrow-counterclockwise me-2"></i>Restore Wizard</h2>
<?php if (!$backupLogId): ?>
<div class="alert alert-info">Select a backup from <a href="history.php">Backup History</a> to restore.</div>
<?php else: ?>
<div class="card mb-4">
  <div class="card-header">Step 1 — Validate Backup</div>
  <div class="card-body">
    <div id="validate-result" class="mb-3"><em class="text-muted">Click "Validate" to check backup integrity.</em></div>
    <button class="btn btn-outline-primary" id="btn-validate">
      <i class="bi bi-search me-1"></i>Validate Backup #<?= $backupLogId ?>
    </button>
  </div>
</div>
<div class="card" id="restore-panel" style="display:none">
  <div class="card-header bg-warning text-dark">Step 2 — Execute Restore</div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label">Mode</label>
        <select class="form-select" id="restore_mode">
          <option value="dry_run">Dry Run (safe — no changes)</option>
          <option value="real">Real Restore</option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Target</label>
        <select class="form-select" id="restore_target" onchange="toggleAltFields()">
          <option value="original">Original Location</option>
          <option value="alternate">Alternate Location</option>
        </select>
      </div>
    </div>
    <div id="alt-fields" class="row g-3 mt-1" style="display:none">
      <div class="col-md-6"><label class="form-label">Alternate Path</label><input type="text" class="form-control" id="alternate_path"></div>
      <div class="col-md-6"><label class="form-label">Alternate DB Name</label><input type="text" class="form-control" id="alternate_db_name"></div>
    </div>
    <div id="confirm-panel" class="mt-3" style="display:none">
      <div class="alert alert-danger">
        <strong>⚠️ Real Restore Warning:</strong> This will overwrite existing files and database. A pre-restore snapshot will be created automatically.
      </div>
      <label class="form-label">Type the job name to confirm:</label>
      <input type="text" class="form-control w-50" id="confirm_job_name" placeholder="Exact job name">
    </div>
    <div class="mt-3">
      <button class="btn btn-warning" id="btn-restore"><i class="bi bi-play-fill me-1"></i>Execute Restore</button>
    </div>
  </div>
</div>
<div id="restore-result" class="mt-3"></div>
<script>
const BACKUP_LOG_ID = <?= json_encode($backupLogId) ?>;
document.getElementById('btn-validate').addEventListener('click', async () => {
  try {
    const r = await apiFetch('POST','restore/validate',{backup_log_id:BACKUP_LOG_ID});
    const ok = r.checksum_valid && r.extraction_test_passed;
    document.getElementById('validate-result').innerHTML = `
      <div class="alert alert-${ok?'success':'danger'}">
        <strong>${ok?'✓ Validation Passed':'✗ Validation Failed'}</strong><br>
        Checksum: ${r.checksum_valid?'✓ OK':'✗ Mismatch'} &nbsp;|&nbsp;
        Zip Integrity: ${r.extraction_test_passed?'✓ OK':'✗ Failed'}<br>
        ${r.manifest?`Files: ${r.manifest.files_count} | Date: ${r.manifest.backup_date}`:''}
      </div>`;
    if (ok) document.getElementById('restore-panel').style.display='';
  } catch(e) { showAlert(e.message); }
});

function toggleAltFields() {
  const alt = document.getElementById('restore_target').value === 'alternate';
  document.getElementById('alt-fields').style.display = alt?'':'none';
}

document.getElementById('restore_mode').addEventListener('change', () => {
  const real = document.getElementById('restore_mode').value === 'real';
  document.getElementById('confirm-panel').style.display = real?'':'none';
});

document.getElementById('btn-restore').addEventListener('click', async () => {
  const mode   = document.getElementById('restore_mode').value;
  const target = document.getElementById('restore_target').value;
  const body   = { backup_log_id: BACKUP_LOG_ID, mode, restore_target: target };
  if (target === 'alternate') {
    body.alternate_path    = document.getElementById('alternate_path').value;
    body.alternate_db_name = document.getElementById('alternate_db_name').value;
  }
  if (mode === 'real' && target === 'original') {
    body.confirm_job_name = document.getElementById('confirm_job_name').value;
  }
  try {
    const r = await apiFetch('POST','restore/execute',body);
    document.getElementById('restore-result').innerHTML =
      `<div class="alert alert-success">✓ Restore ${mode} completed. restore_log_id=${r.restore_log_id}</div>`;
  } catch(e) { showAlert(e.message); }
});
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
