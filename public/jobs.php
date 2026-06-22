<?php
$pageTitle = 'Backup Jobs';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/partials/nav.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h2><i class="bi bi-briefcase me-2"></i>Backup Jobs</h2>
  <a href="job-edit.php" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>New Job</a>
</div>
<div class="card">
  <div class="card-body p-0">
    <table class="table table-hover mb-0" id="jobs-table">
      <thead class="table-light"><tr>
        <th>Job Name</th><th>Type</th><th>Schedule</th><th>Last Backup</th><th>Status</th><th></th>
      </tr></thead>
      <tbody id="jobs-body"><tr><td colspan="6" class="text-center py-4 text-muted">Loading…</td></tr></tbody>
    </table>
  </div>
</div>
<script>
(async () => {
  try {
    const d = await apiFetch('GET','jobs?limit=100');
    const tbody = document.getElementById('jobs-body');
    if (!d.items.length) { tbody.innerHTML='<tr><td colspan="6" class="text-center text-muted py-4">No jobs configured</td></tr>'; return; }
    tbody.innerHTML = d.items.map(j => `
      <tr>
        <td><strong>${j.job_name}</strong><br><small class="text-muted">${j.app_path||j.db_name||''}</small></td>
        <td>${j.backup_type}</td>
        <td><code>${j.schedule_cron||'Manual'}</code></td>
        <td>${j.last_backup_at ? new Date(j.last_backup_at).toLocaleString('th-TH') : '—'}</td>
        <td><span class="badge status-badge ${j.last_backup_status||''}">${j.last_backup_status||'Never'}</span></td>
        <td>
          <button class="btn btn-sm btn-outline-success me-1" onclick="runNow(${j.id})" title="Backup Now"><i class="bi bi-play-fill"></i></button>
          <a href="job-edit.php?id=${j.id}" class="btn btn-sm btn-outline-primary me-1" title="Edit"><i class="bi bi-pencil"></i></a>
          <button class="btn btn-sm btn-outline-danger" onclick="deleteJob(${j.id},'${j.job_name}')" title="Delete"><i class="bi bi-trash"></i></button>
        </td>
      </tr>`).join('');
  } catch(e) { showAlert(e.message); }
})();

async function runNow(id) {
  if (!confirm('Run backup now?')) return;
  try { await apiFetch('POST',`jobs/${id}/run`); showAlert('Backup started!','success'); }
  catch(e) { showAlert(e.message); }
}

async function deleteJob(id, name) {
  const confirm_name = prompt(`Type "${name}" to confirm deletion:`);
  if (confirm_name !== name) return;
  try { await apiFetch('DELETE',`jobs/${id}`,{confirm_name}); location.reload(); }
  catch(e) { showAlert(e.message); }
}
</script>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
