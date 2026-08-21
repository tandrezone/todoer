<?php
/**
 * The error page. Deliberately plain: it renders a status and a message that was written for a
 * person, never an exception, a file path or a query.
 *
 * @var callable $e
 * @var \App\Http\UrlGenerator $url
 * @var int    $status
 * @var string $message
 */
$headings = [
    400 => 'That request did not work',
    401 => 'Please sign in',
    403 => 'Not allowed',
    404 => 'Nothing here',
    405 => 'Wrong method',
    409 => 'Too slow',
];
?>
<div class="auth-card">
  <h1><?= $e($headings[$status] ?? 'Something went wrong') ?></h1>
  <p class="error"><?= $e($message) ?></p>
  <p class="hint"><a href="<?= $e($url->path('/')) ?>">Back to your lists</a></p>
</div>
