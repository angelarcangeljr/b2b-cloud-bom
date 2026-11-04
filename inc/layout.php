<?php
declare(strict_types=1);

function page_head(string $title, ?array $user = null, string $active = ''): void
{
    $app = cfg('app_name', 'Solution Summary Builder');
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title === $app ? $app : "$title · $app") ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700;800&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap">
<link rel="stylesheet" href="assets/builder.css">
<link rel="stylesheet" href="assets/site.css">
</head>
<body>
<?php if ($user): ?>
<div class="appbar">
  <div class="appbar-in">
    <a class="brand" href="index.php"><?= h($app) ?></a>
    <nav>
      <a href="index.php" <?= $active === 'projects' ? 'aria-current="page"' : '' ?>>Projects</a>
      <?php if ($user['role'] === 'admin'): ?><a href="users.php" <?= $active === 'users' ? 'aria-current="page"' : '' ?>>Users</a><?php endif; ?>
      <a href="account.php" <?= $active === 'account' ? 'aria-current="page"' : '' ?>><?= h(display_name($user)) ?></a>
      <form method="post" action="logout.php" class="inline"><?= csrf_field() ?><button class="linkbtn" type="submit">Sign out</button></form>
    </nav>
  </div>
</div>
<?php endif; ?>
<div class="wrap">
<?php
    $f = flash();
    if ($f) echo '<p class="flash ' . h($f[1]) . '" role="status">' . h($f[0]) . '</p>';
}

function page_foot(): void
{
    echo "</div>\n</body>\n</html>\n";
}
