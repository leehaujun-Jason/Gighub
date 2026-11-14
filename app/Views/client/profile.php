<?php
$title = $edit ? 'Edit Client Profile' : 'My Profile';
$fieldError = static function (string $field) use ($errors, $escape): void {
    if (isset($errors[$field])) {
        echo '<p id="' . $field . '-error" class="gh-profile-field-error">' . $escape($errors[$field]) . '</p>';
    }
};
$invalid = static fn (string $field): string => isset($errors[$field])
    ? ' aria-invalid="true" aria-describedby="' . $field . '-error"' : '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $title ?> | GigHub</title>
<?php require dirname(__DIR__) . '/partials/page-shell.php'; ?>
<link rel="stylesheet" href="assets/profile.css?v=3">
</head>
<body class="gh-shell">
<?php require dirname(__DIR__) . '/partials/header.php'; ?>
<main id="main-content" tabindex="-1" class="gh-profile-layout">
<?php if ($edit): ?>
<p class="gh-profile-eyebrow">CLIENT PROFILE</p>
<h1>Edit profile</h1>
<?php if ($errors !== []): ?>
<div class="gh-profile-errors" role="alert"><?= $escape($errors['form'] ?? 'Review the highlighted fields and try again.') ?></div>
<?php endif; ?>
<?php require dirname(__DIR__) . '/partials/avatar-edit.php'; ?>
<form class="gh-profile-card gh-profile-form" method="post" action="?route=/client/profile/edit">
<input type="hidden" name="csrf_token" value="<?= $escape($token) ?>">
<div>
<label for="display_name">Display Name</label>
<input class="gh-control" id="display_name" name="display_name" autocomplete="name" maxlength="100" required value="<?= $escape($profile['display_name']) ?>"<?= $invalid('display_name') ?>>
<?php $fieldError('display_name'); ?>
</div>
<div><label for="profile-email">Email Address <span>(read-only)</span></label>
<input class="gh-control" id="profile-email" type="email" readonly value="<?= $escape($profile['email']) ?>"></div>
<div>
<label for="account_type">Account Type</label>
<select class="gh-control" id="account_type" name="account_type"<?= $invalid('account_type') ?>>
<option value="">Not set</option>
<?php foreach (['individual' => 'Individual', 'company' => 'Company'] as $value => $label): ?>
<option value="<?= $value ?>"<?= $profile['account_type'] === $value ? ' selected' : '' ?>><?= $label ?></option>
<?php endforeach; ?>
</select>
<?php $fieldError('account_type'); ?>
</div>
<div>
<label for="company_name">Company Name <span>(optional, company accounts only)</span></label>
<input class="gh-control" id="company_name" name="company_name" maxlength="100" value="<?= $escape($profile['company_name'] ?? '') ?>"<?= $invalid('company_name') ?>>
<?php $fieldError('company_name'); ?>
</div>
<div>
<label for="state">State or federal territory <span>(optional)</span></label>
<select class="gh-control" id="state" name="state"<?= $invalid('state') ?>><option value="">Not set</option>
<?php foreach (\GigHub\Services\ClientLocation::STATES as $state): ?><option value="<?= $escape($state) ?>"<?= ($profile['state'] ?? '') === $state ? ' selected' : '' ?>><?= $escape($state) ?></option><?php endforeach; ?></select>
<?php $fieldError('state'); ?>
</div>
<div>
<label for="city">City <span>(optional)</span></label>
<input class="gh-control" id="city" name="city" maxlength="100" value="<?= $escape($profile['city'] ?? '') ?>"<?= $invalid('city') ?>>
<?php $fieldError('city'); ?>
</div>
<div>
<label for="bio">Introduction <span>(optional)</span></label>
<textarea class="gh-control" id="bio" name="bio" rows="5" maxlength="2000"<?= $invalid('bio') ?>><?= $escape($profile['bio'] ?? '') ?></textarea>
<?php $fieldError('bio'); ?>
</div>
<div class="gh-profile-actions">
<button class="gh-button gh-button-primary" type="submit">Save profile</button>
<a class="gh-button gh-button-secondary" href="?route=/client/profile">Cancel</a>
</div>
</form>
<?php else: ?>
<div class="gh-profile-heading">
        <div class="gh-profile-avatar"><?php $avatarUser = $navigationUser; require dirname(__DIR__) . '/partials/avatar.php'; ?></div>
    <div>
        <p class="gh-profile-eyebrow">CLIENT PROFILE</p>
        <h1><?= $escape($profile['display_name']) ?></h1>
    </div>
    <a class="gh-button gh-button-primary" href="?route=/client/profile/edit">Edit profile</a>
</div>
<?php if ($saved): ?>
<p class="gh-profile-notice" role="status">Your profile was saved.</p>
<?php endif; ?>
<section class="gh-profile-card" aria-labelledby="client-about">
<h2 id="client-about">About</h2>
<?php if (trim($profile['bio'] ?? '') !== ''): ?>
<p class="gh-profile-bio"><?= $escape($profile['bio']) ?></p>
<?php else: ?>
<p class="gh-profile-muted">No introduction added yet.</p>
<?php endif; ?>
</section>
<section class="gh-profile-card" aria-labelledby="client-account-details">
<h2 id="client-account-details">Account Details</h2>
<p><strong>Email Address</strong><br><?= $escape($profile['email']) ?></p>
<p><strong>Account Type</strong><br><?= match ($profile['account_type']) { 'company' => 'Company', 'individual' => 'Individual', default => 'Not set' } ?></p>
<?php if ($profile['account_type'] !== 'individual' && ($profile['company_name'] ?? '') !== ''): ?>
<p><strong>Company Name</strong><br><?= $escape($profile['company_name']) ?></p>
<?php endif; ?>
<?php if (($profile['city'] ?? '') !== '' || ($profile['state'] ?? '') !== ''): ?><p><strong>Location</strong><br><?= $escape(trim(($profile['city'] ?? '') . (($profile['city'] ?? '') !== '' && ($profile['state'] ?? '') !== '' ? ', ' : '') . ($profile['state'] ?? '')) ) ?></p><?php endif; ?>
</section>
<?php endif; ?>
</main>
<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
</body>
</html>
