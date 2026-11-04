<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

$me = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $username = trim((string)($_POST['username'] ?? ''));
        $full = mb_substr(trim((string)($_POST['full_name'] ?? '')), 0, 120);
        $email = trim((string)($_POST['email'] ?? ''));
        $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'architect';
        $pw = (string)($_POST['password'] ?? '');
        if (!preg_match('~^[A-Za-z0-9._\-@]{3,64}$~', $username)) flash('Use 3 to 64 letters, numbers, dots, dashes or @ for the username.', 'err');
        elseif ($p = password_problem($pw, $pw)) flash($p, 'err');
        else {
            try {
                db()->prepare('INSERT INTO users (username, full_name, email, password_hash, role) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$username, $full, $email ?: null, password_hash($pw, PASSWORD_DEFAULT), $role]);
                flash("Added $username. Give them the temporary password; they can change it under their name.");
            } catch (PDOException $e) {
                flash('That username is already taken.', 'err');
            }
        }
        redirect('users.php');
    }

    $id = (int)($_POST['id'] ?? 0);
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    $target = $st->fetch();
    if (!$target) { flash('That user no longer exists.', 'err'); redirect('users.php'); }
    $self = (int)$target['id'] === (int)$me['id'];

    if ($action === 'password') {
        $pw = (string)($_POST['password'] ?? '');
        if ($p = password_problem($pw, $pw)) flash($p, 'err');
        else {
            db()->prepare('UPDATE users SET password_hash = ?, failed_logins = 0, locked_until = NULL WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
            flash('Password reset for ' . $target['username'] . '.');
        }
    } elseif ($action === 'toggle_active') {
        if ($self) flash('You can’t deactivate your own account.', 'err');
        else {
            db()->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
            flash(($target['is_active'] ? 'Deactivated ' : 'Reactivated ') . $target['username'] . '.');
        }
    } elseif ($action === 'role') {
        $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'architect';
        $admins = (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
        if ($target['role'] === 'admin' && $role !== 'admin' && $admins <= 1) flash('Keep at least one active administrator.', 'err');
        else {
            db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $id]);
            flash($target['username'] . ' is now ' . ($role === 'admin' ? 'an administrator' : 'an architect') . '.');
        }
    } elseif ($action === 'delete') {
        $n = (int)db()->query('SELECT COUNT(*) FROM projects WHERE user_id = ' . (int)$id)->fetchColumn();
        if ($self) flash('You can’t delete your own account.', 'err');
        elseif ($n > 0) flash($target['username'] . " still owns $n project(s). Deactivate the account instead, so the projects are kept.", 'err');
        else {
            db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            flash('Deleted ' . $target['username'] . '.');
        }
    }
    redirect('users.php');
}

$users = db()->query('SELECT u.*, (SELECT COUNT(*) FROM projects p WHERE p.user_id = u.id) AS projects FROM users u ORDER BY u.is_active DESC, u.full_name, u.username')->fetchAll();
$company = db()->query("SELECT filename, uploaded_at FROM bom_templates WHERE scope = 'company' ORDER BY uploaded_at DESC LIMIT 1")->fetch();

page_head('Users', $me, 'users');
?>
<header class="top">
  <div>
    <h1>Users</h1>
    <p>Architects see and edit only their own projects. Administrators can also see every project, manage users and set the company BOM template.</p>
  </div>
</header>

<section class="card">
  <h2>Add a user</h2>
  <form method="post" class="newproj">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <label class="f">Full name<input type="text" name="full_name" maxlength="120"></label>
    <label class="f">Username<input type="text" name="username" required maxlength="64" autocomplete="off"></label>
    <label class="f">Email (optional)<input type="email" name="email" maxlength="190"></label>
    <label class="f">Role<select name="role"><option value="architect">Architect</option><option value="admin">Administrator</option></select></label>
    <label class="f">Temporary password<input type="text" name="password" required minlength="10" autocomplete="off"></label>
    <button class="btn primary" type="submit">Add user</button>
  </form>
</section>

<section class="card">
  <h2>Company BOM template</h2>
  <p class="muted"><?= $company ? 'Current default: <b>' . h($company['filename']) . '</b>, uploaded ' . h(date('M j, Y', strtotime($company['uploaded_at']))) . '. Architects who haven’t uploaded their own template use it.' : 'None yet. Open any project, go to step 7 and upload the workbook with “Upload as company default for everyone” ticked.' ?></p>
</section>

<section class="card">
  <div class="tablewrap">
  <table class="list">
    <thead><tr><th>Name</th><th>Username</th><th>Role</th><th class="num">Projects</th><th>Last sign-in</th><th>Status</th><th><span class="sr">Actions</span></th></tr></thead>
    <tbody>
    <?php foreach ($users as $r): $self = (int)$r['id'] === (int)$me['id']; ?>
      <tr class="<?= $r['is_active'] ? '' : 'inactive' ?>">
        <td><?= h($r['full_name'] ?: '—') ?><?= $self ? ' <span class="muted">(you)</span>' : '' ?><?= $r['email'] ? '<br><span class="muted small">' . h($r['email']) . '</span>' : '' ?></td>
        <td><?= h($r['username']) ?></td>
        <td>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="role"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <select name="role" onchange="this.form.submit()" aria-label="Role for <?= h($r['username']) ?>"><option value="architect" <?= $r['role'] === 'architect' ? 'selected' : '' ?>>Architect</option><option value="admin" <?= $r['role'] === 'admin' ? 'selected' : '' ?>>Administrator</option></select>
          </form>
        </td>
        <td class="num"><?= (int)$r['projects'] ?></td>
        <td class="nowrap"><?= $r['last_login_at'] ? h(date('M j, Y H:i', strtotime($r['last_login_at']))) : '<span class="muted">Never</span>' ?></td>
        <td><?= $r['is_active'] ? 'Active' : 'Deactivated' ?><?= ($r['locked_until'] && strtotime($r['locked_until']) > time()) ? '<br><span class="warn small">Locked after failed sign-ins</span>' : '' ?></td>
        <td class="actions">
          <details class="pw"><summary class="btn small">Reset password</summary>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="password"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <input type="text" name="password" minlength="10" required placeholder="New password (10+ characters)" aria-label="New password" autocomplete="off"><button class="btn small primary" type="submit">Set</button></form>
          </details>
          <?php if (!$self): ?>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn small" type="submit"><?= $r['is_active'] ? 'Deactivate' : 'Reactivate' ?></button></form>
            <?php if ((int)$r['projects'] === 0): ?>
            <form method="post" class="inline" data-confirm="Delete the account <?= h($r['username']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn small ghost danger" type="submit">Delete</button></form>
            <?php endif; ?>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
<script>
document.addEventListener("submit", e => { const m = e.target.dataset.confirm; if (m && !confirm(m)) e.preventDefault(); });
</script>
<?php page_foot();
