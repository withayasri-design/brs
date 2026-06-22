<?php
$pageTitle = 'Login';
require_once __DIR__ . '/partials/header.php';
if (!empty($_SESSION['user_id'])) { header('Location: /brs/public/index.php'); exit; }
?>
<div class="min-vh-100 d-flex align-items-center justify-content-center bg-dark">
  <div class="card shadow" style="width:380px">
    <div class="card-body p-4">
      <h4 class="text-center mb-1"><i class="bi bi-shield-check text-primary"></i> BRS</h4>
      <p class="text-center text-muted small mb-4">Backup & Restore System</p>
      <div id="alert-container"></div>
      <form id="login-form">
        <div class="mb-3">
          <label class="form-label">Username</label>
          <input type="text" class="form-control" id="username" name="username" autofocus required>
        </div>
        <div class="mb-3">
          <label class="form-label">Password</label>
          <input type="password" class="form-control" id="password" name="password" required>
        </div>
        <button type="submit" class="btn btn-primary w-100" id="btn-login">
          <span class="spinner-border spinner-border-sm d-none" id="spinner"></span>
          Login
        </button>
      </form>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/brs/public/assets/js/api.js"></script>
<script>
document.getElementById('login-form').addEventListener('submit', async e => {
  e.preventDefault();
  document.getElementById('spinner').classList.remove('d-none');
  try {
    const data = await apiFetch('POST', 'auth/login', {
      username: document.getElementById('username').value,
      password: document.getElementById('password').value,
    });
    window.CSRF_TOKEN = data.csrf_token;
    location.href = '/brs/public/index.php';
  } catch (err) {
    showAlert(err.message);
  } finally {
    document.getElementById('spinner').classList.add('d-none');
  }
});
</script>
</body></html>
