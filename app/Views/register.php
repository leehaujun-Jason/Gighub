<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $errors !== [] ? 'Registration needs attention' : 'Create an account' ?> · GigHub</title><link rel="stylesheet" href="assets/registration.css?v=6">
<script src="assets/registration.js?v=5" defer></script>
<?php require __DIR__ . '/partials/page-shell.php'; ?></head>
<body class="gh-shell gh-integrated">
<?php require __DIR__ . '/partials/header.php'; ?>
<main id="main-content" tabindex="-1" class="registration-layout">
<aside class="intro"><p class="eyebrow">WORK, TOGETHER.</p><h1>Your next chapter starts here</h1><p>Find the right people. Bring your skills. Build something that matters.</p><div class="preview-notice"><strong>Development preview</strong><p>New accounts need email verification. When email delivery is disabled, no verification email is sent.</p></div></aside>
<section class="card" aria-labelledby="form-title"><p class="eyebrow">JOIN GIGHUB</p><h2 id="form-title">Create a <?= $escape($role) ?> account</h2><p><a href="?route=/register">Choose a different role</a></p>
<?php if ($errors !== []): ?>
<div class="error-summary" id="error-summary" tabindex="-1" role="alert"><strong>Registration needs attention</strong>
<?php if (isset($errors['form'])): ?><p><?= $escape($errors['form']) ?></p><?php endif; ?>
<?php $labels = ['display_name' => 'Full Name', 'email' => 'Email', 'password' => 'Password', 'password_confirmation' => 'Confirm Password']; ?>
<ul><?php foreach ($errors as $field => $message): if ($field === 'form') { continue; } ?>
<li><?php if (isset($labels[$field])): ?><a href="#<?= $escape($field) ?>"><?= $escape($labels[$field]) ?></a><?php else: ?><?= $escape($message) ?><?php endif; ?></li>
<?php endforeach; ?></ul></div>
<?php endif; ?>
<form id="registration-form" method="post" action="?route=/register/<?= $escape($role) ?>">
<input type="hidden" name="csrf_token" value="<?= $escape($token) ?>">
<?php $fieldError = static function (string $field) use ($errors, $escape): void { if (isset($errors[$field])) { echo '<p class="field-error" id="' . $escape($field) . '-error">' . $escape($errors[$field]) . '</p>'; } };
$invalid = static fn (string $field): string => isset($errors[$field]) ? ' aria-invalid="true" aria-describedby="' . $escape($field) . '-error"' : ''; ?>
<label for="display_name">Full Name <span>(required)</span></label>
<input id="display_name" name="display_name" autocomplete="name" maxlength="100" required value="<?= $escape($old['display_name'] ?? '') ?>"<?= $invalid('display_name') ?>><?php $fieldError('display_name'); ?>
<label for="email">Email <span>(required)</span></label>
<input id="email" name="email" type="email" autocomplete="email" maxlength="254" required value="<?= $escape($old['email'] ?? '') ?>"<?= $invalid('email') ?>><?php $fieldError('email'); ?>
<?php $eyeButton = static function (string $target, string $label) use ($escape): void { ?>
<button class="password-toggle" type="button" data-password-toggle aria-pressed="false" aria-controls="<?= $escape($target) ?>" aria-label="<?= $escape($label) ?>" hidden><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/><path class="eye-slash" d="m4 4 16 16"/></svg></button>
<?php }; ?>
<div class="password-fields" data-password-policy="<?= $escape(json_encode(\GigHub\Services\RegistrationValidator::passwordPolicy(), JSON_THROW_ON_ERROR)) ?>">
<div><label for="password">Password <span>(required)</span></label><div class="password-input"><input id="password" name="password" type="password" autocomplete="new-password" aria-describedby="password-help<?= isset($errors['password']) ? ' password-error' : '' ?>"<?= isset($errors['password']) ? ' aria-invalid="true"' : '' ?> required><?php $eyeButton('password', 'Show password'); ?></div>
<?php $fieldError('password'); ?><p class="hint password-feedback" id="password-help" role="status" aria-live="polite" aria-atomic="true"><?= isset($errors['password']) ? '' : 'Use at least 12 characters for your password.' ?></p></div>
<div><label for="password_confirmation">Confirm Password <span>(required)</span></label><div class="password-input"><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" aria-describedby="confirmation-help<?= isset($errors['password_confirmation']) ? ' password_confirmation-error' : '' ?>"<?= isset($errors['password_confirmation']) ? ' aria-invalid="true"' : '' ?> required><?php $eyeButton('password_confirmation', 'Show password confirmation'); ?></div>
<?php $fieldError('password_confirmation'); ?><p class="hint password-feedback" id="confirmation-help" role="status" aria-live="polite" aria-atomic="true"></p></div></div>
<button id="create-account" type="submit">Create my account <span aria-hidden="true">→</span></button>
<p class="hint">Your account stays pending until email verification is complete. When email delivery is disabled, you can request a link once delivery is available.</p>
</form><p>Already have an account? <a href="?route=/login">Log in</a></p><p>Already registered but not verified? <a href="?route=/verify-email/resend">Resend verification email</a></p></section></main>

<?php require __DIR__ . '/partials/footer.php'; ?></body></html>
