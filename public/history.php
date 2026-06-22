<?php
$pageTitle = 'Backup History';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/partials/nav.php';
$jobId = isset($_GET['job_id']) ? (int)$_GET['job_id'] : null;
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h2><i class="bi bi-clock-history me-2"></i>Backup History</h2>
  <a href="jobs.php" class="btn btn-outline-secondary btn-sm">← Jobs</a>
</div>
<?php if (!$jobId): ?>
<div class="alert alert-info">Select a job from <a href="jobs.php">Jobs</a> to view its history.</div>
<?php else: ?>
<div id="job-header" class="mb-3 text-muted small"></div>
<div class="card">
  <div class="card-body p-0">
    <table class="table table-hover mb-0">
      <thead class="table-light"><tr>
        <th>ID</th><th>Started</th><th>Duration</th><th>Size</th><th>Status</th><th>Verified</th><th>Pinned</th><th></th>
      </tr></thead>
      <tbody id="history-body"><tr><td colspan="8" class="text-center py-4 text-muted">Loading…</td></tr></tbody>
    </table>
  </div>
</div>
<script>
const JOB_ID = <?= json_encode($jobId) ?>;
(async () => {
  try {
    const d = await apiFetch('GET',`jobs/${JOB_ID}/history?limit=50`);
    const tbody = document.getElementById('history-body');
    if (!d.items.length) { tbody.innerHTML='<tr><td colspan="8" class="text-center text-muted py-4">No backups yet</td></tr>'; return; }
    tbody.innerHTML = d.items.map(b => `
      <tr>
        <td>${b.id}</td>
        <td>${new Date(b.started_at).toLocaleString('th-TH')}</td>
        <td>—</td>
        <td>${b.total_size_bytes ? formatBytes(b.total_size_bytes) : '—'}</td>
        <td><span class="badge status-badge ${b.status}">${b.status}</span></td>
        <td><span class="badge bg-${b.verification_status==='passed'?'success':'secondary'}">${b.verification_status}</span></td>
        <td>${b.is_pinned ? '<i class="bi bi-pin-fill text-warning"></i>' : ''}</td>
        <td>
          ${b.status==='success'?`<a href="restore.php?backup_log_id=${b.id}" class="btn btn-sm btn-outline-warning me-1" title="Restore"><i class="bi bi-arrow-counterclockwise"></i></a>`:''}
          <button class="btn btn-sm btn-outline-secondary" onclick="pinBackup(${b.id})" title="Pin"><i class="bi bi-pin"></i></button>
        </td>
      </tr>`).join('');
  } catch(e) { showAlert(e.message); }
})();

async function pinBackup(id) {
  try { await apiFetch('POST',`backup-logs/${id}/pin`); showAlert('Backup pinned — it will not be deleted by retention policy.','success'); }
  catch(e) { showAlert(e.message); }
}
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
