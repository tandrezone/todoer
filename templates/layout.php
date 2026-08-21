<?php
/**
 * The site layout.
 *
 * @var callable $e          Escaper -- every interpolation goes through it.
 * @var \App\Http\UrlGenerator $url
 * @var string   $csrfToken
 * @var string   $basePath
 * @var string   $title
 * @var string   $bodyClass
 * @var string   $content    Already-rendered page markup.
 * @var list<string> $scripts Asset paths, relative to public/.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= $e($csrfToken) ?>">
<?php /* The front-end builds every URL from this, so the app works from a sub-directory too. */ ?>
<meta name="base-path" content="<?= $e($basePath) ?>">
<title><?= $e($title) ?></title>
<link rel="icon" href="<?= $e($url->path('/favicon.ico')) ?>" sizes="32x32">
<link rel="icon" href="<?= $e($url->asset('assets/favicon.svg')) ?>" type="image/svg+xml">
<link rel="apple-touch-icon" href="<?= $e($url->asset('assets/icon-180.png')) ?>">
<link rel="manifest" href="<?= $e($url->path('/site.webmanifest')) ?>">
<meta name="theme-color" content="#3559b8">
<link rel="stylesheet" href="<?= $e($url->asset('assets/css/style.css')) ?>">
</head>
<body<?= $bodyClass !== '' ? ' class="' . $e($bodyClass) . '"' : '' ?>>
<?= $content ?>
<?php foreach ($scripts as $script): ?>
<script src="<?= $e($url->asset($script)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
