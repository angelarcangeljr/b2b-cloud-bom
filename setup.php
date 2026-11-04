<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

// 1. Database reachable?
try { db(); }
catch (PDOException $e) {
    page_head('Setup');
    echo '<div class="card narrow"><h1>Can’t reach the database</h1><p>Check the host, port, database name, user and password in <code>config.php</code>, and that the database exists.</p>';
    if (cfg('debug')) echo '<pre class="err">' . h($e->getMessage()) . '</pre>';
    echo '</div>';
    page_foot();
    exit;
}

// 2. Tables: create them from schema.sql if missing.
if (!tables_ready()) {
    $sql = (string)file_get_contents(__DIR__ . '/schema.sql');
    $sql = preg_replace('~^\s*--.*$~m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) db()->exec($stmt);
}

// 3. Only while there are no users: create the first administrator.
if (users_exist()) redirect('login.php');

$err = null;
$vals = ['full_name' => '', 'username' => '', 'email' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    foreach ($vals as $k => $_) $vals[$k] = trim((string)($_POST[$k] ?? ''));
    $pw = (string)($_POST['password'] ?? '');
    if (!preg_match('~^[A-Za-z0-9._\-@]{3,64}$~', $vals['username'])) $err = 'Use 3 to 64 letters, numbers, dots, dashes or @ for the username.';
    elseif ($p = password_problem($pw, (string)($_POST['password2'] ?? ''))) $err = $p;
    else {
        $st = db()->prepare("INSERT INTO users (username, full_name, email, password_hash, role) VALUES (?, ?, ?, ?, 'admin')");
        $st->execute([$vals['username'], $vals['full_name'], $vals['email'] ?: null, password_hash($pw, PASSWORD_DEFAULT)]);
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)db()->lastInsertId();
        flash('Administrator account created. Add your architects under Users.');
        redirect('index.php');
    }
}

page_head('Setup');
?>
<div class="card narrow">
  <p class="eyebrow">First-time setup</p>
  <h1>Create the administrator</h1>
  <p class="muted">The database tables are ready. This account can add architects and set the company BOM template. This page closes once an account exists.</p>
  <?php if ($err): ?><p class="flash err"><?= h($err) ?></p><?php endif; ?>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <label class="f">Full name<input type="text" name="full_name" value="<?= h($vals['full_name']) ?>" autocomplete="name"></label>
    <label class="f">Username<input type="text" name="username" value="<?= h($vals['username']) ?>" required autocomplete="username"></label>
    <label class="f">Email (optional)<input type="email" name="email" value="<?= h($vals['email']) ?>" autocomplete="email"></label>
    <label class="f">Password (at least 10 characters)<input type="password" name="password" required autocomplete="new-password"></label>
    <label class="f">Confirm password<input type="password" name="password2" required autocomplete="new-password"></label>
    <button class="btn primary" type="submit">Create administrator</button>
  </form>
</div>
<?php page_foot();
