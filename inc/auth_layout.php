<?php
declare(strict_types=1);

/** Shared shell for the login / sign-up cards. */
function auth_header(string $title): void
{
    ?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · <?= e(platform_name()) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=IBM+Plex+Sans+Thai:wght@300;400;500;600&family=Noto+Serif+Thai:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body class="auth">
  <main class="auth__card">
    <div class="auth__mark">✦</div>
    <p class="eyebrow"><?= e(platform_name()) ?></p>
<?php
}

function auth_footer(): void
{
    ?>
  </main>
</body>
</html>
<?php
}

/** Slow down repeated failures from the same browser session. */
function auth_throttle(): void
{
    $_SESSION['tries'] = ($_SESSION['tries'] ?? 0) + 1;
    if ($_SESSION['tries'] > 5) usleep(min(5, $_SESSION['tries'] - 5) * 600000);
}
