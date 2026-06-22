<?php
// Redirect to login if not authenticated
if (empty($_SESSION['user_id'])) {
    header('Location: /brs/public/login.php');
    exit;
}
$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
?>
<div class="d-flex">
<nav class="sidebar d-flex flex-column flex-shrink-0 p-3" style="width:220px">
  <a href="/brs/public/index.php" class="navbar-brand text-white fw-bold mb-3">
    <i class="bi bi-shield-check"></i> BRS
  </a>
  <ul class="nav nav-pills flex-column mb-auto">
    <?php foreach ([
      ['index','bi-speedometer2','Dashboard'],
      ['jobs','bi-briefcase','Backup Jobs'],
      ['history','bi-clock-history','History'],
      ['restore','bi-arrow-counterclockwise','Restore'],
      ['storage-targets','bi-hdd-stack','Storage'],
      ['users','bi-people','Users'],
      ['audit-log','bi-journal-text','Audit Log'],
    ] as [$page,$icon,$label]): ?>
    <li class="nav-item">
      <a href="/brs/public/<?=$page?>.php" class="nav-link <?=$currentPage===$page?'active':''?>">
        <i class="bi <?=$icon?> me-2"></i><?=$label?>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>
  <hr class="text-secondary">
  <div class="text-secondary small">
    <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($_SESSION['user_role'] ?? '') ?>
    <a href="#" class="ms-2 text-danger" id="btn-logout"><i class="bi bi-box-arrow-right"></i></a>
  </div>
</nav>
<div class="flex-grow-1 p-4" id="main-content">
<div id="alert-container"></div>
<script>
document.getElementById('btn-logout')?.addEventListener('click', async e => {
  e.preventDefault();
  await apiFetch('POST','auth/logout');
  location.href='/brs/public/login.php';
});
</script>
