<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

$u = require_login();
$id = (int)($_GET['id'] ?? 0);
$p = $id ? load_project($id, $u) : null;
if (!$p) { flash('That project no longer exists or isn’t yours.', 'err'); redirect('index.php'); }

$state = $p['state'] !== null ? json_decode((string)$p['state'], true) : null;
$seed = null;
if (is_array($state) && isset($state['__seed'])) { $seed = $state['__seed']; $state = null; }

$tpl = effective_template((int)$u['id'], true);
$boot = [
    'id' => (int)$p['id'],
    'rev' => (int)$p['rev'],
    'step' => (int)$p['step'],
    'state' => $state,
    'seed' => $seed,
    'csrf' => csrf_token(),
    'user' => ['name' => display_name($u), 'admin' => $u['role'] === 'admin'],
    'owner' => (int)$p['user_id'] === (int)$u['id'] ? null : ($p['owner_name'] ?: $p['owner_username']),
    'tpl' => template_meta($tpl),
    'tplData' => $tpl ? base64_encode($tpl['data']) : null,
];
$json = json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$title = $p['project_title'] ?: ($p['customer_name'] ?: 'Untitled project');
$app = cfg('app_name', 'Solution Summary Builder');
header('Cache-Control: no-store');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · <?= h($app) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700;800&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap">
<link rel="stylesheet" href="assets/builder.css">
<link rel="stylesheet" href="assets/site.css">
</head>
<body>
<div class="appbar">
  <div class="appbar-in">
    <a class="brand" href="index.php"><?= h($app) ?></a>
    <nav>
      <a href="index.php">Projects</a>
      <?php if ($u['role'] === 'admin'): ?><a href="users.php">Users</a><?php endif; ?>
      <a href="account.php"><?= h(display_name($u)) ?></a>
      <form method="post" action="logout.php" class="inline"><?= csrf_field() ?><button class="linkbtn" type="submit">Sign out</button></form>
    </nav>
  </div>
</div>
<div class="wrap">
  <header class="top">
    <div>
      <p class="crumbs"><a href="index.php">← All projects</a><?= $boot['owner'] ? ' · Owner: ' . h($boot['owner']) : '' ?></p>
      <h1 id="projTitle"><?= h($title) ?></h1>
      <p>Capture the customer's setup and pain points, choose the services in scope and a cloud provider, and get a high-level diagram, a Solution Summary and a complete Bill of Materials that stay in sync.</p>
    </div>
    <div class="top-actions">
      <span class="savest" id="saveSt" role="status" aria-live="polite">All changes saved</span>
      <button class="btn ghost" id="btnExample" type="button">Load example</button>
      <button class="btn ghost" id="btnClear" type="button">Start blank</button>
    </div>
  </header>
  <div class="layout">
    <nav class="rail" id="rail" aria-label="Steps"></nav>
    <main class="sheet" id="sheet"></main>
  </div>
</div>
<script>window.SSB_SERVER = <?= $json ?>;</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/docx@8.5.0/build/index.umd.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.4.0/exceljs.min.js"></script>
<script src="assets/builder.js?v=<?= h((string)@filemtime(__DIR__ . '/assets/builder.js')) ?>"></script>
</body>
</html>
