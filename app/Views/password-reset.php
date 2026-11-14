<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer">
<?php $title = match ($mode) {
    'sent' => 'Check your email',
    'reset' => 'Choose a new password',
    'success' => 'Password updated',
    default => 'Forgot password',
}; ?>
<title><?= $escape($title) ?> | GigHub</title>
<link rel="stylesheet" href="assets/registration.css?v=6">
<link rel="stylesheet" href="assets/login.css?v=2">
<?php if ($mode === 'reset'): ?>
<script src="assets/registration.js?v=5" defer></script>
<script src="assets/password-reset.js" defer></script>
<?php endif; ?>
<?php require __DIR__ . '/partials/page-shell.php'; ?>
</head>
<body class="gh-shell gh-integrated">
<?php require __DIR__ . '/partials/header.php'; ?>
<main id="main-content" tabindex="-1" class="login-layout">
<section class="card login-card">
<h1><?= $escape($title) ?></h1>
<?php if ($mode === 'sent'): ?>
<p>If the address belongs to an eligible account, a password reset link will be sent. Check your inbox and spam folder.</p>
<?php elseif ($mode === 'success'): ?>
<p>Sign in with your new password.</p>
<?php else: ?>
<?php if ($errors !== []): ?>
<div class="error-summary" id="error-summary" role="alert" tabindex="-1">
<?= $escape($errors['form'] ?? 'Check the highlighted fields and try again.') ?>
</div>
<?php endif; ?>
<?php
$fieldError = static function (string $name) use ($errors, $escape): void {
    if (isset($errors[$name])) {
        echo '<p class="field-error" id="' . $name . '-error">' . $escape($errors[$name]) . '</p>';
    }
};
$invalid = static fn (string $name): string => isset($errors[$name])
    ? ' aria-invalid="true" aria-describedby="' . $name . '-error"' : '';
?>
<form method="post" action="?route=<?= $mode === 'reset' ? '/reset-password' : '/forgot-password' ?>">
<input type="hidden" name="csrf_token" value="<?= $escape($token) ?>">
<?php if ($mode === 'request'): ?>
<label for="email">Email</label>
<input id="email" name="email" type="email" autocomplete="email" maxlength="254" required value="<?= $escape($email) ?>"<?= $invalid('email') ?>>
<?php $fieldError('email'); ?>
<?php else: ?>
<div id="reset-key-field">
<label for="reset_key">Reset key from your email</label>
<input id="reset_key" name="reset_key" type="text" autocomplete="off" spellcheck="false" maxlength="64" required<?= $invalid('reset_key') ?>>
</div>
<?php $fieldError('reset_key'); ?>
<p class="hint">Use at least 12 characters for your new password.</p>
<?php foreach (['password' => 'New Password', 'password_confirmation' => 'Confirm New Password'] as $name => $label): ?>
<label for="<?= $name ?>"><?= $label ?></label>
<div class="password-input">
<input id="<?= $name ?>" name="<?= $name ?>" type="password" autocomplete="new-password" required<?= $invalid($name) ?>>
<button type="button" class="password-toggle" data-password-toggle aria-pressed="false" aria-controls="<?= $name ?>" aria-label="<?= $name === 'password' ? 'Show password' : 'Show password confirmation' ?>" hidden>
<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/><path class="eye-slash" d="m4 4 16 16"/></svg>
</button>
</div>
<?php $fieldError($name); ?>
<?php endforeach; ?>
<?php endif; ?>
<button type="submit"><?= $mode === 'reset' ? 'Update password' : 'Send reset link' ?></button>
</form>
<?php endif; ?>
<?php if ($mode === 'reset' || $mode === 'sent'): ?><p><a href="?route=/forgot-password">Request a new link</a></p><?php endif; ?>
<p><a class="gh-button gh-button-secondary" href="?route=/login">Back to Login</a></p>
</section>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
</body>
</html>
