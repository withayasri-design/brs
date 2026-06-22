<?php
$pageTitle = 'Storage Targets';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/partials/nav.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h2><i class="bi bi-hdd-stack me-2"></i>Storage Targets</h2>
  <button class="btn btn-primary" onclick="openModal()"><i class="bi bi-plus-lg me-1"></i>Add Target</button>
</div>
<div class="card">
  <div class="card-body p-0">
    <table class="table table-hover mb-0">
      <thead class="table-light"><tr>
        <th>Name</th><th>Provider</th><th>Status</th><th>Last Tested</th><th>Active</th><th></th>
      </tr></thead>
      <tbody id="targets-body"><tr><td colspan="6" class="text-center py-4 text-muted">Loading…</td></tr></tbody>
    </table>
  </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="target-modal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modal-title">Add Storage Target</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="edit-id">
        <div class="mb-3">
          <label class="form-label">Target Name</label>
          <input type="text" class="form-control" id="f-name" placeholder="e.g. Local Default, S3 Offsite">
        </div>
        <div class="mb-3">
          <label class="form-label">Provider Type</label>
          <select class="form-select" id="f-provider" onchange="renderConfigFields()">
            <option value="local">Local Disk</option>
            <option value="nas">Network Share / NAS</option>
            <option value="s3">AWS S3 / S3-Compatible</option>
            <option value="google_drive">Google Drive</option>
            <option value="sftp">SFTP</option>
          </select>
        </div>
        <div id="config-fields"></div>
        <div id="f-active-wrap" class="form-check mt-2" style="display:none">
          <input class="form-check-input" type="checkbox" id="f-active" checked>
          <label class="form-check-label" for="f-active">Active</label>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" onclick="saveTarget()">Save</button>
      </div>
    </div>
  </div>
</div>

<script>
const PROVIDER_FIELDS = {
  local: [
    { key:'path', label:'Backup Directory Path', placeholder:'C:\\brs_backups' }
  ],
  nas: [
    { key:'path', label:'UNC Path or Mapped Drive', placeholder:'\\\\server\\share\\backups or Z:\\backups' }
  ],
  s3: [
    { key:'bucket',      label:'Bucket Name',       placeholder:'my-backup-bucket' },
    { key:'region',      label:'Region',            placeholder:'ap-southeast-1' },
    { key:'access_key',  label:'Access Key ID',     placeholder:'' },
    { key:'secret_key',  label:'Secret Access Key', placeholder:'', type:'password' },
    { key:'endpoint',    label:'Endpoint URL (optional, for S3-compatible)', placeholder:'https://s3.example.com' },
    { key:'path_prefix', label:'Path Prefix',       placeholder:'brs/' }
  ],
  google_drive: [
    { key:'folder_id',        label:'Folder ID',              placeholder:'1AbCdEf...' },
    { key:'credentials_json', label:'Service Account JSON',   placeholder:'Paste full service account JSON here', type:'textarea' }
  ],
  sftp: [
    { key:'host',     label:'Host',            placeholder:'backup.example.com' },
    { key:'port',     label:'Port',            placeholder:'22' },
    { key:'username', label:'Username',        placeholder:'' },
    { key:'password', label:'Password',        placeholder:'', type:'password' },
    { key:'path',     label:'Remote Path',     placeholder:'/backups/brs' }
  ]
};

function renderConfigFields(values = {}) {
  const provider = document.getElementById('f-provider').value;
  const fields   = PROVIDER_FIELDS[provider] || [];
  document.getElementById('config-fields').innerHTML = fields.map(f => {
    const id  = `cfg-${f.key}`;
    const val = (values[f.key] ?? '').replace(/"/g,'&quot;');
    if (f.type === 'textarea') {
      return `<div class="mb-3"><label class="form-label">${f.label}</label>
        <textarea class="form-control font-monospace" id="${id}" rows="5" placeholder="${f.placeholder}">${val}</textarea></div>`;
    }
    return `<div class="mb-3"><label class="form-label">${f.label}</label>
      <input type="${f.type||'text'}" class="form-control" id="${id}" value="${val}" placeholder="${f.placeholder||''}"></div>`;
  }).join('');
}

let modal;
window.addEventListener('DOMContentLoaded', () => {
  modal = new bootstrap.Modal(document.getElementById('target-modal'));
  renderConfigFields();
  loadTargets();
});

async function loadTargets() {
  try {
    const items = await apiFetch('GET','storage-targets');
    const tbody = document.getElementById('targets-body');
    if (!items.length) { tbody.innerHTML='<tr><td colspan="6" class="text-center text-muted py-4">No storage targets configured</td></tr>'; return; }
    tbody.innerHTML = items.map(t => `
      <tr>
        <td><strong>${t.target_name}</strong></td>
        <td><span class="badge bg-secondary">${t.provider_type}</span></td>
        <td><span class="badge bg-${t.last_test_status==='success'?'success':t.last_test_status?'danger':'secondary'}">${t.last_test_status||'Not tested'}</span></td>
        <td class="text-muted small">${t.last_test_at ? new Date(t.last_test_at).toLocaleString('th-TH') : '—'}</td>
        <td>${t.is_active ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-x-circle text-muted"></i>'}</td>
        <td>
          <button class="btn btn-sm btn-outline-info me-1" onclick="testTarget(${t.id})" title="Test Connection"><i class="bi bi-wifi"></i></button>
          <button class="btn btn-sm btn-outline-primary me-1" onclick="editTarget(${t.id})" title="Edit"><i class="bi bi-pencil"></i></button>
          <button class="btn btn-sm btn-outline-danger" onclick="deleteTarget(${t.id},'${t.target_name.replace(/'/g,"\\'")}')" title="Delete"><i class="bi bi-trash"></i></button>
        </td>
      </tr>`).join('');
  } catch(e) { showAlert(e.message); }
}

function openModal(id) {
  document.getElementById('edit-id').value = '';
  document.getElementById('modal-title').textContent = 'Add Storage Target';
  document.getElementById('f-name').value = '';
  document.getElementById('f-provider').value = 'local';
  document.getElementById('f-active-wrap').style.display = 'none';
  renderConfigFields();
  modal.show();
}

async function editTarget(id) {
  try {
    // Fetch list and find target (no single-GET endpoint exposed)
    const items = await apiFetch('GET','storage-targets');
    const t = items.find(x => x.id === id);
    if (!t) { showAlert('Target not found'); return; }
    document.getElementById('edit-id').value = id;
    document.getElementById('modal-title').textContent = 'Edit Storage Target';
    document.getElementById('f-name').value  = t.target_name;
    document.getElementById('f-provider').value = t.provider_type;
    document.getElementById('f-active-wrap').style.display = '';
    document.getElementById('f-active').checked = !!t.is_active;
    renderConfigFields({});  // config values are encrypted — cannot pre-fill
    modal.show();
  } catch(e) { showAlert(e.message); }
}

function buildConfig() {
  const provider = document.getElementById('f-provider').value;
  const fields   = PROVIDER_FIELDS[provider] || [];
  const cfg = {};
  for (const f of fields) {
    const el = document.getElementById(`cfg-${f.key}`);
    if (el && el.value.trim()) cfg[f.key] = el.value.trim();
  }
  return cfg;
}

async function saveTarget() {
  const id       = document.getElementById('edit-id').value;
  const name     = document.getElementById('f-name').value.trim();
  const provider = document.getElementById('f-provider').value;
  const config   = buildConfig();
  const isActive = document.getElementById('f-active').checked ? 1 : 0;
  if (!name) { showAlert('Target name is required'); return; }
  try {
    if (id) {
      await apiFetch('PUT',`storage-targets/${id}`,{ target_name:name, provider_type:provider, config, is_active:isActive });
    } else {
      await apiFetch('POST','storage-targets',{ target_name:name, provider_type:provider, config });
    }
    modal.hide();
    showAlert('Storage target saved.','success');
    loadTargets();
  } catch(e) { showAlert(e.message); }
}

async function testTarget(id) {
  try {
    showAlert('Testing connection…','info');
    const r = await apiFetch('POST',`storage-targets/${id}/test`);
    showAlert(`Connection ${r.status}: ${r.message}`,'success');
    loadTargets();
  } catch(e) { showAlert(e.message); }
}

async function deleteTarget(id, name) {
  if (!confirm(`Delete storage target "${name}"? This cannot be undone.`)) return;
  try {
    await apiFetch('DELETE',`storage-targets/${id}`);
    showAlert('Deleted.','success');
    loadTargets();
  } catch(e) { showAlert(e.message); }
}
</script>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
