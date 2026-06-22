<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/partials/nav.php';
?>
<h2 class="mb-4"><i class="bi bi-speedometer2 me-2"></i>Dashboard</h2>
<div class="row g-3 mb-4" id="stat-cards">
  <div class="col-md-3"><div class="card card-stat success p-3"><div class="text-muted small">Total Jobs</div><div class="fs-3 fw-bold" id="stat-total-jobs">—</div></div></div>
  <div class="col-md-3"><div class="card card-stat warning p-3"><div class="text-muted small">Active Jobs</div><div class="fs-3 fw-bold" id="stat-active-jobs">—</div></div></div>
  <div class="col-md-3"><div class="card card-stat danger p-3"><div class="text-muted small">Failed (24h)</div><div class="fs-3 fw-bold" id="stat-failed">—</div></div></div>
  <div class="col-md-3"><div class="card card-stat success p-3"><div class="text-muted small">Total Backup Size</div><div class="fs-3 fw-bold" id="stat-size">—</div></div></div>
</div>
<div class="row g-3">
  <div class="col-md-6">
    <div class="card"><div class="card-header">Storage Usage</div>
    <div class="card-body" id="storage-table"><div class="text-muted">Loading…</div></div></div>
  </div>
  <div class="col-md-6">
    <div class="card"><div class="card-header">Upcoming Jobs</div>
    <div class="card-body" id="upcoming-table"><div class="text-muted">Loading…</div></div></div>
  </div>
</div>
<script>
(async () => {
  try {
    const d = await apiFetch('GET','dashboard/summary');
    document.getElementById('stat-total-jobs').textContent = d.total_jobs;
    document.getElementById('stat-active-jobs').textContent = d.active_jobs;
    document.getElementById('stat-failed').textContent = d.jobs_failed_last_24h;
    document.getElementById('stat-size').textContent = formatBytes(d.total_backup_size_bytes||0);
    document.getElementById('storage-table').innerHTML = (d.storage_usage||[]).map(s =>
      `<div class="d-flex justify-content-between"><span>${s.target_name}</span><span>${s.free_bytes!=null?formatBytes(s.free_bytes):'N/A'} free</span></div>`
    ).join('') || '<em class="text-muted">No storage targets</em>';
    document.getElementById('upcoming-table').innerHTML = (d.upcoming_scheduled_jobs||[]).map(j =>
      `<div class="d-flex justify-content-between"><span>${j.job_name}</span><span class="text-muted small">${j.schedule_cron}</span></div>`
    ).join('') || '<em class="text-muted">No scheduled jobs</em>';
  } catch(e) { showAlert(e.message); }
})();
</script>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
