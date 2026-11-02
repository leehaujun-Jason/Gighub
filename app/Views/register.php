<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $errors !== [] ? 'Registration needs attention' : 'Create an account' ?> · GigHub</title><link rel="stylesheet" href="assets/registration.css?v=4">
<script src="assets/registration.js?v=4" defer></script>
<?php require __DIR__ . '/partials/page-shell.php'; ?></head>
<body class="gh-shell gh-integrated">
<?php require __DIR__ . '/partials/header.php'; ?>
<main id="main-content" tabindex="-1" class="registration-layout">
<aside class="intro"><p class="eyebrow">WORK, TOGETHER.</p><h1>Your next chapter starts here</h1><p>Find the right people. Bring your skills. Build something that matters.</p><div class="preview-notice"><strong>Development preview</strong><p>New accounts need email verification. When email delivery is disabled, no verification email is sent.</p></div></aside>
<section class="card" aria-labelledby="form-title"><p class="eyebrow">JOIN GIGHUB</p><h2 id="form-title">Create your account</h2><p class="muted">Choose how you want to take part. Your account role stays fixed.</p>
<?php if ($errors !== []): ?>
<div class="error-summary" id="error-summary" tabindex="-1" role="alert"><strong>Please check the highlighted fields.</strong>
<?php if (isset($errors['form'])): ?><p><?= $escape($errors['form']) ?></p><?php endif; ?>
<?php $labels = ['display_name' => 'Full Name', 'email' => 'Email Address', 'role' => 'Account role', 'password' => 'Password', 'password_confirmation' => 'Confirm password', 'bio' => 'About You', 'company_name' => 'Company Name']; ?>
<ul><?php foreach ($errors as $field => $message): if ($field === 'form') { continue; } ?><li><a href="#<?= $escape($field) ?>"><?= $escape($labels[$field] ?? 'Registration details') ?></a></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<form id="registration-form" method="post" action="?route=/register">
<input type="hidden" name="csrf_token" value="<?= $escape($token) ?>">
<?php $fieldError = static function (string $field) use ($errors, $escape): void { if (isset($errors[$field])) { echo '<p class="field-error" id="' . $escape($field) . '-error">' . $escape($errors[$field]) . '</p>'; } };
$invalid = static fn (string $field): string => isset($errors[$field]) ? ' aria-invalid="true" aria-describedby="' . $escape($field) . '-error"' : ''; ?>
<label for="display_name">Full Name <span>(required)</span></label>
<input id="display_name" name="display_name" autocomplete="name" maxlength="100" required value="<?= $escape($old['display_name'] ?? '') ?>"<?= $invalid('display_name') ?>><?php $fieldError('display_name'); ?>
<label for="email">Email Address <span>(required)</span></label>
<input id="email" name="email" type="email" autocomplete="email" maxlength="254" required value="<?= $escape($old['email'] ?? '') ?>"<?= $invalid('email') ?>><?php $fieldError('email'); ?>
<label for="role">I want to join as <span>(required)</span></label>
<select id="role" name="role" required<?= $invalid('role') ?>><option value="">Choose your role</option><option value="client"<?= ($old['role'] ?? '') === 'client' ? ' selected' : '' ?>>Client — hire a freelancer</option><option value="freelancer"<?= ($old['role'] ?? '') === 'freelancer' ? ' selected' : '' ?>>Freelancer — find work</option></select><?php $fieldError('role'); ?>
<noscript><p class="hint">After choosing your role, use “Update optional fields” below the Create my account button to show the matching profile fields. Other details are kept, but passwords must be entered again.</p></noscript>
<?php $eyeButton = static function (string $target, string $label) use ($escape): void { ?>
<button class="password-toggle" type="button" data-password-toggle aria-controls="<?= $escape($target) ?>" aria-label="<?= $escape($label) ?>" hidden><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/><path class="eye-slash" d="m4 4 16 16"/></svg></button>
<?php }; ?>
<div class="password-fields" data-password-policy="<?= $escape(json_encode(\GigHub\Services\RegistrationValidator::passwordPolicy(), JSON_THROW_ON_ERROR)) ?>">
<div><label for="password">Password <span>(required)</span></label><div class="password-input"><input id="password" name="password" type="password" autocomplete="new-password" aria-describedby="password-help<?= isset($errors['password']) ? ' password-error' : '' ?>"<?= isset($errors['password']) ? ' aria-invalid="true"' : '' ?> required><?php $eyeButton('password', 'Show password'); ?></div>
<?php $fieldError('password'); ?><p class="hint password-feedback" id="password-help" role="status" aria-live="polite" aria-atomic="true"><?= isset($errors['password']) ? '' : 'Use at least 12 characters for your password.' ?></p></div>
<div><label for="password_confirmation">Confirm password <span>(required)</span></label><div class="password-input"><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" aria-describedby="confirmation-help<?= isset($errors['password_confirmation']) ? ' password_confirmation-error' : '' ?>"<?= isset($errors['password_confirmation']) ? ' aria-invalid="true"' : '' ?> required><?php $eyeButton('password_confirmation', 'Show password confirmation'); ?></div>
<?php $fieldError('password_confirmation'); ?><p class="hint password-feedback" id="confirmation-help" role="status" aria-live="polite" aria-atomic="true"></p></div></div>
<?php $selectedRole = $old['role'] ?? ''; $hasRole = in_array($selectedRole, ['client', 'freelancer'], true); ?>
<fieldset id="optional-profile" class="optional-profile"<?= !$hasRole ? ' hidden disabled' : '' ?>><legend>Optional profile details</legend>
<label for="bio">About You <span>(optional)</span></label><textarea id="bio" name="bio" maxlength="2000" rows="3"<?= $invalid('bio') ?>><?= $escape($old['bio'] ?? '') ?></textarea><?php $fieldError('bio'); ?>
<fieldset id="client-profile" class="role-fields"<?= $selectedRole !== 'client' ? ' hidden disabled' : '' ?>>
<legend class="visually-hidden">Client profile</legend><label for="company_name">Company Name <span>(optional)</span></label><input id="company_name" name="company_name" maxlength="100" value="<?= $escape($old['company_name'] ?? '') ?>"<?= $invalid('company_name') ?>><?php $fieldError('company_name'); ?></fieldset>
</fieldset>
<button id="create-account" type="submit">Create my account <span aria-hidden="true">→</span></button>
<button id="role-update" class="secondary-button" type="submit" name="intent" value="update_role" formnovalidate>Update optional fields</button>
<p class="hint">Your account stays pending until email verification is complete. When email delivery is disabled, you can request a link once delivery is available.</p>
</form><p>Already have an account? <a href="?route=/login">Sign in</a></p></section></main>

<?php require __DIR__ . '/partials/footer.php'; ?></body></html>
