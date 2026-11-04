<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

try { if (!tables_ready() || !users_exist()) redirect('setup.php'); }
catch (PDOException $e) { redirect('setup.php'); }
if (current_user()) redirect('index.php');

$next = safe_next($_GET['next'] ?? $_POST['next'] ?? 'index.php');
$err = null;
$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $username = trim((string)($_POST['username'] ?? ''));
    $pw = (string)($_POST['password'] ?? '');
    $st = db()->prepare('SELECT id, password_hash, is_active, failed_logins, locked_until FROM users WHERE username = ?');
    $st->execute([$username]);
    $u = $st->fetch();
    $generic = 'That username and password don’t match an active account.';
    if ($u && $u['locked_until'] !== null && strtotime($u['locked_until']) > time()) {
        $err = 'Too many failed attempts. Try again in a few minutes, or ask an administrator to reset your password.';
    } elseif ($u && (int)$u['is_active'] === 1 && password_verify($pw, $u['password_hash'])) {
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $u['id']]);
        }
        db()->prepare('UPDATE users SET failed_logins = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?')->execute([$u['id']]);
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        unset($_SESSION['csrf']);
        redirect($next);
    } else {
        if ($u) {
            $fails = (int)$u['failed_logins'] + 1;
            $lock = $fails >= 5 ? date('Y-m-d H:i:s', time() + 15 * 60) : null;
            db()->prepare('UPDATE users SET failed_logins = ?, locked_until = ? WHERE id = ?')->execute([$lock ? 0 : $fails, $lock, $u['id']]);
        }
        usleep(400000);
        $err = $generic;
    }
}

page_head('Sign in');
?>
<div class="card narrow">
  <p class="eyebrow"><?= h(cfg('app_name', 'Solution Summary Builder')) ?></p>
  <h1>Sign in</h1>
  <?php if ($err): ?><p class="flash err"><?= h($err) ?></p><?php endif; ?>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <input type="hidden" name="next" value="<?= h($next) ?>">
    <label class="f">Username<input type="text" name="username" value="<?= h($username) ?>" required autofocus autocomplete="username"></label>
    <label class="f">Password<input type="password" name="password" required autocomplete="current-password"></label>
    <button class="btn primary" type="submit">Sign in</button>
  </form>
  <p class="muted small">No account yet? Ask your administrator to add you.</p>
</div>
<?php page_foot();
