<?php
$pageTitle = 'Users';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/partials/nav.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h2><i class="bi bi-people me-2"></i>Users</h2>
  <button class="btn btn-primary" onclick="openCreate()"><i class="bi bi-plus-lg me-1"></i>Add User</button>
</div>
<div class="card">
  <div class="card-body p-0">
    <table class="table table-hover mb-0">
      <thead class="table-light"><tr>
        <th>Username</th><th>Full Name</th><th>Role</th><th>Active</th><th>Last Login</th><th></th>
      </tr></thead>
      <tbody id="users-body"><tr><td colspan="6" class="text-center py-4 text-muted">Loading…</td></tr></tbody>
    </table>
  </div>
</div>

<!-- Create/Edit User Modal -->
<div class="modal fade" id="user-modal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modal-title">Add User</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="edit-id">
        <div class="mb-3" id="username-wrap">
          <label class="form-label">Username</label>
          <input type="text" class="form-control" id="f-username" autocomplete="off">
        </div>
        <div class="mb-3">
          <label class="form-label">Full Name</label>
          <input type="text" class="form-control" id="f-fullname">
        </div>
        <div class="mb-3">
          <label class="form-label">Role</label>
          <select class="form-select" id="f-role">
            <option value="admin">Admin</option>
            <option value="operator">Operator</option>
            <option value="viewer" selected>Viewer</option>
          </select>
        </div>
        <div class="mb-3" id="password-wrap">
          <label class="form-label" id="password-label">Password</label>
          <input type="password" class="form-control" id="f-password" autocomplete="new-password">
        </div>
        <div class="form-check" id="active-wrap" style="display:none">
          <input class="form-check-input" type="checkbox" id="f-active" checked>
          <label class="form-check-label" for="f-active">Active</label>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" onclick="saveUser()">Save</button>
      </div>
    </div>
  </div>
</div>

<!-- Reset Password Modal -->
<div class="modal fade" id="reset-modal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Reset Password</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="reset-id">
        <p class="text-muted small">Setting new password for: <strong id="reset-username"></strong></p>
        <div class="mb-3">
          <label class="form-label">New Password</label>
          <input type="password" class="form-control" id="f-new-password" autocomplete="new-password">
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-warning" onclick="doResetPassword()">Reset Password</button>
      </div>
    </div>
  </div>
</div>

<script>
let userModal, resetModal;
window.addEventListener('DOMContentLoaded', () => {
  userModal  = new bootstrap.Modal(document.getElementById('user-modal'));
  resetModal = new bootstrap.Modal(document.getElementById('reset-modal'));
  loadUsers();
});

async function loadUsers() {
  try {
    const items = await apiFetch('GET','users');
    const tbody = document.getElementById('users-body');
    if (!items.length) { tbody.innerHTML='<tr><td colspan="6" class="text-center text-muted py-4">No users found</td></tr>'; return; }
    tbody.innerHTML = items.map(u => `
      <tr>
        <td><strong>${u.username}</strong></td>
        <td>${u.full_name || '—'}</td>
        <td><span class="badge bg-${u.role==='admin'?'danger':u.role==='operator'?'warning':'secondary'}">${u.role}</span></td>
        <td>${u.is_active ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-x-circle text-muted"></i>'}</td>
        <td class="text-muted small">${u.last_login_at ? new Date(u.last_login_at).toLocaleString('th-TH') : 'Never'}</td>
        <td>
          <button class="btn btn-sm btn-outline-primary me-1" onclick="openEdit(${u.id},'${u.username.replace(/'/g,"\\'")}','${(u.full_name||'').replace(/'/g,"\\'")}','${u.role}',${u.is_active})" title="Edit"><i class="bi bi-pencil"></i></button>
          <button class="btn btn-sm btn-outline-warning me-1" onclick="openReset(${u.id},'${u.username.replace(/'/g,"\\'")}')  " title="Reset Password"><i class="bi bi-key"></i></button>
          <button class="btn btn-sm btn-outline-danger" onclick="deleteUser(${u.id},'${u.username.replace(/'/g,"\\'")}')  " title="Delete"><i class="bi bi-trash"></i></button>
        </td>
      </tr>`).join('');
  } catch(e) { showAlert(e.message); }
}

function openCreate() {
  document.getElementById('edit-id').value = '';
  document.getElementById('modal-title').textContent = 'Add User';
  document.getElementById('f-username').value = '';
  document.getElementById('f-fullname').value = '';
  document.getElementById('f-role').value = 'viewer';
  document.getElementById('f-password').value = '';
  document.getElementById('password-label').textContent = 'Password';
  document.getElementById('username-wrap').style.display = '';
  document.getElementById('password-wrap').style.display = '';
  document.getElementById('active-wrap').style.display = 'none';
  userModal.show();
}

function openEdit(id, username, fullName, role, isActive) {
  document.getElementById('edit-id').value = id;
  document.getElementById('modal-title').textContent = 'Edit User';
  document.getElementById('f-username').value = username;
  document.getElementById('f-fullname').value = fullName;
  document.getElementById('f-role').value = role;
  document.getElementById('f-password').value = '';
  document.getElementById('password-label').textContent = 'New Password (leave blank to keep current)';
  document.getElementById('username-wrap').style.display = 'none';
  document.getElementById('password-wrap').style.display = '';
  document.getElementById('active-wrap').style.display = '';
  document.getElementById('f-active').checked = !!isActive;
  userModal.show();
}

async function saveUser() {
  const id       = document.getElementById('edit-id').value;
  const username = document.getElementById('f-username').value.trim();
  const fullName = document.getElementById('f-fullname').value.trim();
  const role     = document.getElementById('f-role').value;
  const password = document.getElementById('f-password').value;
  const isActive = document.getElementById('f-active').checked ? 1 : 0;

  try {
    if (id) {
      const body = { full_name: fullName, role, is_active: isActive };
      await apiFetch('PUT', `users/${id}`, body);
      if (password) {
        await apiFetch('POST', `users/${id}/reset-password`, { password });
      }
    } else {
      if (!username) { showAlert('Username is required'); return; }
      if (!password) { showAlert('Password is required for new users'); return; }
      await apiFetch('POST', 'users', { username, full_name: fullName, role, password });
    }
    userModal.hide();
    showAlert('User saved.', 'success');
    loadUsers();
  } catch(e) { showAlert(e.message); }
}

function openReset(id, username) {
  document.getElementById('reset-id').value = id;
  document.getElementById('reset-username').textContent = username;
  document.getElementById('f-new-password').value = '';
  resetModal.show();
}

async function doResetPassword() {
  const id  = document.getElementById('reset-id').value;
  const pwd = document.getElementById('f-new-password').value;
  if (!pwd) { showAlert('New password cannot be empty'); return; }
  try {
    await apiFetch('POST', `users/${id}/reset-password`, { password: pwd });
    resetModal.hide();
    showAlert('Password reset successfully.', 'success');
  } catch(e) { showAlert(e.message); }
}

async function deleteUser(id, username) {
  if (!confirm(`Delete user "${username}"? This cannot be undone.`)) return;
  try {
    await apiFetch('DELETE', `users/${id}`);
    showAlert('User deleted.', 'success');
    loadUsers();
  } catch(e) { showAlert(e.message); }
}
</script>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
