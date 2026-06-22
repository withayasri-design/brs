<?php
$pageTitle = 'Audit Log';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/partials/nav.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h2><i class="bi bi-journal-text me-2"></i>Audit Log</h2>
</div>

<!-- Filters -->
<div class="card mb-3">
  <div class="card-body py-2">
    <div class="row g-2 align-items-end">
      <div class="col-md-3">
        <label class="form-label form-label-sm mb-1">User</label>
        <input type="text" class="form-control form-control-sm" id="f-user" placeholder="username…">
      </div>
      <div class="col-md-3">
        <label class="form-label form-label-sm mb-1">Action</label>
        <input type="text" class="form-control form-control-sm" id="f-action" placeholder="e.g. restore.execute">
      </div>
      <div class="col-md-2">
        <label class="form-label form-label-sm mb-1">From</label>
        <input type="date" class="form-control form-control-sm" id="f-from">
      </div>
      <div class="col-md-2">
        <label class="form-label form-label-sm mb-1">To</label>
        <input type="date" class="form-control form-control-sm" id="f-to">
      </div>
      <div class="col-md-2 d-flex gap-2">
        <button class="btn btn-sm btn-primary w-100" onclick="search(1)"><i class="bi bi-search me-1"></i>Search</button>
        <button class="btn btn-sm btn-outline-secondary" onclick="clearFilters()" title="Clear"><i class="bi bi-x-lg"></i></button>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-body p-0">
    <table class="table table-hover table-sm mb-0">
      <thead class="table-light"><tr>
        <th style="width:160px">Time</th>
        <th style="width:120px">User</th>
        <th style="width:180px">Action</th>
        <th>Target</th>
        <th style="width:120px">IP Address</th>
        <th>Detail</th>
      </tr></thead>
      <tbody id="log-body"><tr><td colspan="6" class="text-center py-4 text-muted">Loading…</td></tr></tbody>
    </table>
  </div>
  <div class="card-footer d-flex justify-content-between align-items-center py-2">
    <span class="text-muted small" id="page-info">—</span>
    <div class="d-flex gap-2">
      <button class="btn btn-sm btn-outline-secondary" id="btn-prev" onclick="changePage(-1)" disabled><i class="bi bi-chevron-left"></i></button>
      <button class="btn btn-sm btn-outline-secondary" id="btn-next" onclick="changePage(1)" disabled><i class="bi bi-chevron-right"></i></button>
    </div>
  </div>
</div>

<script>
const LIMIT = 50;
let currentPage = 1;
let totalItems  = 0;

function buildQuery(page) {
  const params = new URLSearchParams({ page, limit: LIMIT });
  const user   = document.getElementById('f-user').value.trim();
  const action = document.getElementById('f-action').value.trim();
  const from   = document.getElementById('f-from').value;
  const to     = document.getElementById('f-to').value;
  if (user)   params.set('user', user);
  if (action) params.set('action', action);
  if (from)   params.set('from', from);
  if (to)     params.set('to', to);
  return `audit-logs?${params}`;
}

async function search(page = 1) {
  currentPage = page;
  document.getElementById('log-body').innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted">Loading…</td></tr>';
  try {
    const d    = await apiFetch('GET', buildQuery(page));
    totalItems = d.total ?? 0;
    const tbody = document.getElementById('log-body');
    if (!d.items.length) {
      tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">No log entries found</td></tr>';
      updatePager();
      return;
    }
    tbody.innerHTML = d.items.map(e => {
      const detail = typeof e.detail === 'object' ? JSON.stringify(e.detail) : (e.detail || '—');
      const target = e.target_type ? `${e.target_type}${e.target_id ? ' #'+e.target_id : ''}` : '—';
      const badgeColor = e.action?.includes('delete') || e.action?.includes('restore.execute') ? 'danger'
                       : e.action?.includes('create') || e.action?.includes('login') ? 'success' : 'secondary';
      return `<tr>
        <td class="text-muted small">${new Date(e.created_at).toLocaleString('th-TH')}</td>
        <td><strong>${e.user || '—'}</strong></td>
        <td><span class="badge bg-${badgeColor}">${e.action || '—'}</span></td>
        <td class="text-muted small">${target}</td>
        <td class="text-muted small font-monospace">${e.ip_address || '—'}</td>
        <td class="text-muted small font-monospace" style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${detail.replace(/"/g,'&quot;')}">${detail}</td>
      </tr>`;
    }).join('');
    updatePager();
  } catch(e) { showAlert(e.message); }
}

function updatePager() {
  const totalPages = Math.ceil(totalItems / LIMIT) || 1;
  document.getElementById('page-info').textContent = `Page ${currentPage} of ${totalPages} (${totalItems} entries)`;
  document.getElementById('btn-prev').disabled = currentPage <= 1;
  document.getElementById('btn-next').disabled = currentPage >= totalPages;
}

function changePage(delta) {
  search(currentPage + delta);
}

function clearFilters() {
  document.getElementById('f-user').value   = '';
  document.getElementById('f-action').value = '';
  document.getElementById('f-from').value   = '';
  document.getElementById('f-to').value     = '';
  search(1);
}

window.addEventListener('DOMContentLoaded', () => search(1));

// Allow pressing Enter in filter fields to trigger search
['f-user','f-action','f-from','f-to'].forEach(id => {
  document.getElementById(id)?.addEventListener('keydown', e => { if (e.key==='Enter') search(1); });
});
</script>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
