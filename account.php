<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$u = require_login();
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (($_POST['action'] ?? '') === 'profile') {
        $full = mb_substr(trim((string)($_POST['full_name'] ?? '')), 0, 120);
        $email = trim((string)($_POST['email'] ?? ''));
        db()->prepare('UPDATE users SET full_name = ?, email = ? WHERE id = ?')->execute([$full, $email ?: null, $u['id']]);
        flash('Profile saved.');
        redirect('account.php');
    }
    if (($_POST['action'] ?? '') === 'password') {
        $st = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $st->execute([$u['id']]);
        if (!password_verify((string)($_POST['current'] ?? ''), (string)$st->fetchColumn())) $err = 'Your current password is not correct.';
        elseif ($p = password_problem((string)($_POST['password'] ?? ''), (string)($_POST['password2'] ?? ''))) $err = $p;
        else {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash((string)$_POST['password'], PASSWORD_DEFAULT), $u['id']]);
            session_regenerate_id(true);
            flash('Password changed.');
            redirect('account.php');
        }
    }
}

page_head('Your account', $u, 'account');
?>
<header class="top"><div><h1>Your account</h1><p>Signed in as <b><?= h($u['username']) ?></b> · <?= $u['role'] === 'admin' ? 'Administrator' : 'Architect' ?></p></div></header>
<div class="two">
  <section class="card">
    <h2>Profile</h2>
    <form method="post" class="stack">
      <?= csrf_field() ?><input type="hidden" name="action" value="profile">
      <label class="f">Full name<input type="text" name="full_name" value="<?= h($u['full_name']) ?>" maxlength="120" autocomplete="name"></label>
      <label class="f">Email<input type="email" name="email" value="<?= h($u['email'] ?? '') ?>" maxlength="190" autocomplete="email"></label>
      <button class="btn primary" type="submit">Save profile</button>
    </form>
  </section>
  <section class="card">
    <h2>Change password</h2>
    <?php if ($err): ?><p class="flash err"><?= h($err) ?></p><?php endif; ?>
    <form method="post" class="stack">
      <?= csrf_field() ?><input type="hidden" name="action" value="password">
      <label class="f">Current password<input type="password" name="current" required autocomplete="current-password"></label>
      <label class="f">New password (at least 10 characters)<input type="password" name="password" required autocomplete="new-password"></label>
      <label class="f">Confirm new password<input type="password" name="password2" required autocomplete="new-password"></label>
      <button class="btn primary" type="submit">Change password</button>
    </form>
  </section>
</div>
<?php page_foot();
