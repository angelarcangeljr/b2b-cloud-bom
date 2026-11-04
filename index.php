<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

try { if (!tables_ready() || !users_exist()) redirect('setup.php'); }
catch (PDOException $e) { redirect('setup.php'); }
$u = require_login();
$admin = $u['role'] === 'admin';

/* ---------- actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = mb_substr(trim((string)($_POST['customer'] ?? '')), 0, 190);
        $title = mb_substr(trim((string)($_POST['project'] ?? '')), 0, 255);
        $mode = ($_POST['start'] ?? 'blank') === 'example' ? 'example' : 'blank';
        // Carry the architect's branding and company name from their latest project.
        $carry = new stdClass();
        $st = db()->prepare('SELECT state FROM projects WHERE user_id = ? AND state IS NOT NULL ORDER BY updated_at DESC LIMIT 1');
        $st->execute([$u['id']]);
        if ($last = $st->fetchColumn()) {
            $s = json_decode((string)$last, true);
            if (is_array($s) && empty($s['__seed'])) {
                $carry = ['brand' => $s['brand'] ?? null, 'docFormat' => $s['docFormat'] ?? null, 'company' => $s['com']['company'] ?? ''];
            }
        }
        $seed = json_encode(['__seed' => ['mode' => $mode, 'name' => $name, 'project' => $title, 'carry' => $carry]], JSON_UNESCAPED_UNICODE);
        db()->prepare('INSERT INTO projects (user_id, customer_name, project_title, state, updated_at) VALUES (?, ?, ?, ?, NOW())')
            ->execute([$u['id'], $name, $title, $seed]);
        redirect('app.php?id=' . (int)db()->lastInsertId());
    }

    $id = (int)($_POST['id'] ?? 0);
    $p = $id ? load_project($id, $u) : null;
    if (!$p) { flash('That project no longer exists or isn’t yours.', 'err'); redirect('index.php'); }

    if ($action === 'duplicate') {
        $state = $p['state'];
        $s = json_decode((string)$state, true);
        $title = $p['project_title'] . ' (copy)';
        if (is_array($s) && isset($s['customer'])) { $s['customer']['project'] = $title; $state = json_encode($s, JSON_UNESCAPED_UNICODE); }
        elseif (is_array($s) && isset($s['__seed'])) { $s['__seed']['project'] = $title; $state = json_encode($s, JSON_UNESCAPED_UNICODE); }
        db()->prepare('INSERT INTO projects (user_id, customer_name, project_title, provider, version, monthly_usd, one_time_usd, person_days, state, step, updated_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')
            ->execute([$u['id'], $p['customer_name'], $title, $p['provider'], $p['version'], $p['monthly_usd'], $p['one_time_usd'], $p['person_days'], $state, $p['step']]);
        flash('Copied “' . ($p['project_title'] ?: 'Untitled project') . '”.');
        redirect('index.php');
    }
    if ($action === 'delete') {
        db()->prepare('DELETE FROM projects WHERE id = ?')->execute([$p['id']]);
        flash('Deleted “' . ($p['project_title'] ?: 'Untitled project') . '”.');
        redirect('index.php');
    }
    redirect('index.php');
}

/* ---------- list ---------- */
$q = trim((string)($_GET['q'] ?? ''));
$all = $admin && ($_GET['all'] ?? '') === '1';
$where = [];
$args = [];
if (!$all) { $where[] = 'p.user_id = ?'; $args[] = $u['id']; }
if ($q !== '') { $where[] = '(p.customer_name LIKE ? OR p.project_title LIKE ? OR p.provider LIKE ?)'; $like = '%' . addcslashes($q, '%_\\') . '%'; array_push($args, $like, $like, $like); }
$sql = 'SELECT p.id, p.user_id, p.customer_name, p.project_title, p.provider, p.version, p.monthly_usd, p.one_time_usd, p.person_days, p.updated_at, u.full_name, u.username
        FROM projects p JOIN users u ON u.id = p.user_id' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY p.updated_at DESC LIMIT 500';
$st = db()->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();

$tot = db()->prepare('SELECT COUNT(*) n, COALESCE(SUM(monthly_usd),0) m, COALESCE(SUM(one_time_usd),0) o FROM projects' . ($all ? '' : ' WHERE user_id = ?'));
$tot->execute($all ? [] : [$u['id']]);
$t = $tot->fetch();

page_head('Projects', $u, 'projects');
?>
<header class="top">
  <div>
    <h1><?= $all ? 'All projects' : 'Your projects' ?></h1>
    <p>Each project holds one customer’s inputs, Solution Summary and Bill of Materials. Changes save to the database as you work.</p>
  </div>
</header>

<div class="kpis">
  <div class="kpi"><span>Projects</span><b><?= (int)$t['n'] ?></b></div>
  <div class="kpi"><span>Monthly recurring, all projects</span><b><?= h(money($t['m'])) ?></b></div>
  <div class="kpi"><span>One-time, all projects</span><b><?= h(money($t['o'])) ?></b></div>
</div>

<section class="card">
  <h2>New project</h2>
  <form method="post" class="newproj">
    <?= csrf_field() ?><input type="hidden" name="action" value="create">
    <label class="f">Customer name<input type="text" name="customer" maxlength="190" placeholder="e.g. Northwind Retail"></label>
    <label class="f">Project title<input type="text" name="project" maxlength="255" placeholder="e.g. Cloud migration of ERP"></label>
    <label class="f">Start from
      <select name="start"><option value="blank">A blank project</option><option value="example">The worked example</option></select>
    </label>
    <button class="btn primary" type="submit">Create and open</button>
  </form>
</section>

<section class="card">
  <form method="get" class="filters">
    <label class="f grow">Search<input type="text" name="q" value="<?= h($q) ?>" placeholder="Customer, project or provider"></label>
    <?php if ($admin): ?>
      <label class="check"><input type="checkbox" name="all" value="1" <?= $all ? 'checked' : '' ?>> Show every architect’s projects</label>
    <?php endif; ?>
    <button class="btn" type="submit">Filter</button>
    <?php if ($q !== '' || $all): ?><a class="btn ghost" href="index.php">Clear</a><?php endif; ?>
  </form>

  <?php if (!$rows): ?>
    <p class="empty"><?= $q !== '' ? 'No projects match that search.' : 'No projects yet. Create one above to get started.' ?></p>
  <?php else: ?>
  <div class="tablewrap">
    <table class="list">
      <thead><tr>
        <th>Customer</th><th>Project</th><th>Provider</th>
        <th class="num">Monthly (USD)</th><th class="num">One-time (USD)</th><th class="num">Person-days</th>
        <?php if ($all): ?><th>Owner</th><?php endif; ?>
        <th>Updated</th><th><span class="sr">Actions</span></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><a href="app.php?id=<?= (int)$r['id'] ?>"><?= h($r['customer_name'] ?: 'Unnamed customer') ?></a></td>
          <td><?= h($r['project_title'] ?: 'Untitled project') ?><?= $r['version'] !== '' ? ' <span class="muted">v' . h($r['version']) . '</span>' : '' ?></td>
          <td><?= h($r['provider'] ?: '—') ?></td>
          <td class="num"><?= h(money($r['monthly_usd'])) ?></td>
          <td class="num"><?= h(money($r['one_time_usd'])) ?></td>
          <td class="num"><?= h(rtrim(rtrim(number_format((float)$r['person_days'], 1), '0'), '.')) ?></td>
          <?php if ($all): ?><td><?= h($r['full_name'] ?: $r['username']) ?></td><?php endif; ?>
          <td class="nowrap"><?= h(date('M j, Y H:i', strtotime($r['updated_at']))) ?></td>
          <td class="actions">
            <a class="btn small primary" href="app.php?id=<?= (int)$r['id'] ?>">Open</a>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="duplicate"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn small" type="submit">Duplicate</button></form>
            <form method="post" class="inline" data-confirm="Delete “<?= h($r['project_title'] ?: 'Untitled project') ?>” for <?= h($r['customer_name'] ?: 'this customer') ?>? This can’t be undone."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn small ghost danger" type="submit">Delete</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<script>
document.addEventListener("submit", e => { const m = e.target.dataset.confirm; if (m && !confirm(m)) e.preventDefault(); });
</script>
<?php page_foot();
