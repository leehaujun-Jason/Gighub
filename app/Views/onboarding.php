<?php
$freelancer = $user['role'] === 'freelancer';
$title = $freelancer ? 'Freelancer Profile Setup' : 'Client Profile Setup';
$fieldError = static function (string $name) use ($errors, $escape): void {
    if (isset($errors[$name])) {
        echo '<p class="field-error" id="' . $name . '-error">' . $escape($errors[$name]) . '</p>';
    }
};
$invalid = static fn (string $name): string => isset($errors[$name])
    ? ' aria-invalid="true" aria-describedby="' . $name . '-error"' : '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $title ?> | GigHub</title>
<link rel="stylesheet" href="assets/registration.css?v=6">
<link rel="stylesheet" href="assets/onboarding.css?v=2">
<?php if ($freelancer): ?><link rel="stylesheet" href="assets/profile.css?v=5"><script src="assets/onboarding.js?v=2" defer></script><?php endif; ?>
<?php require __DIR__ . '/partials/page-shell.php'; ?>
</head>
<body class="gh-shell gh-integrated">
<?php require __DIR__ . '/partials/header.php'; ?>
<main id="main-content" tabindex="-1" class="onboarding-layout">
<section class="card">
<h1><?= $title ?></h1>
<p><?= $freelancer ? 'Describe your work and choose at least one skill.' : 'Tell freelancers who they will be working with.' ?></p>
<?php if ($errors !== []): ?>
<div class="error-summary" role="alert"><?= $escape($errors['form'] ?? 'Review the highlighted fields and try again.') ?></div>
<?php endif; ?>
<form method="post" action="?route=/profile/setup">
<input type="hidden" name="csrf_token" value="<?= $escape($token) ?>">
<?php if ($freelancer): ?>
<label for="headline">Professional Title</label>
<input id="headline" name="headline" maxlength="150" required placeholder="Full-Stack Web Developer" value="<?= $escape($values['headline'] ?? '') ?>"<?= $invalid('headline') ?>>
<?php $fieldError('headline'); ?>
<?php else: ?>
<label for="display_name">Display Name</label>
<input id="display_name" name="display_name" autocomplete="name" maxlength="100" required value="<?= $escape($values['display_name']) ?>"<?= $invalid('display_name') ?>>
<?php $fieldError('display_name'); ?>
<label for="account_type">Account Type</label>
<select id="account_type" name="account_type" required<?= $invalid('account_type') ?>>
<option value="">Choose an account type</option>
<?php foreach (['individual' => 'Individual', 'company' => 'Company'] as $type => $label): ?>
<option value="<?= $type ?>"<?= ($values['account_type'] ?? '') === $type ? ' selected' : '' ?>><?= $label ?></option>
<?php endforeach; ?>
</select>
<?php $fieldError('account_type'); ?>
<label for="company_name">Company Name <span>(optional, company accounts only)</span></label>
<input id="company_name" name="company_name" maxlength="100" value="<?= $escape($values['company_name'] ?? '') ?>"<?= $invalid('company_name') ?>>
<?php $fieldError('company_name'); ?>
<label for="state">State or federal territory <span>(optional)</span></label>
<select id="state" name="state"<?= $invalid('state') ?>><option value="">Choose a state</option>
<?php foreach (\GigHub\Services\ClientLocation::STATES as $state): ?><option value="<?= $escape($state) ?>"<?= ($values['state'] ?? '') === $state ? ' selected' : '' ?>><?= $escape($state) ?></option><?php endforeach; ?></select>
<?php $fieldError('state'); ?>
<label for="city">City <span>(optional)</span></label>
<input id="city" name="city" maxlength="100" value="<?= $escape($values['city'] ?? '') ?>"<?= $invalid('city') ?>>
<?php $fieldError('city'); ?>
<?php endif; ?>
<label for="bio"><?= $freelancer ? 'Professional Introduction' : 'Introduction (optional)' ?></label>
<textarea id="bio" name="bio" rows="5" maxlength="2000" placeholder="<?= $freelancer ? 'Describe your experience and the services you offer.' : 'Tell freelancers about yourself or your company.' ?>"<?= $freelancer ? ' required' : '' ?><?= $invalid('bio') ?>><?= $escape($values['bio'] ?? '') ?></textarea>
<?php $fieldError('bio'); ?>
<?php if ($freelancer): ?>
<fieldset aria-describedby="skill-progress<?= isset($errors['skills']) ? ' skills-error' : '' ?>">
<legend>Skills</legend>
<p id="skill-progress" role="status"><?= count($values['skills']) ?> selected (minimum 1)</p>
<?php $fieldError('skills'); ?>
<?php if (count($catalogue) < 1): ?><p>The catalogue currently has no skills. Profile setup cannot be completed yet.</p><?php endif; ?>
<div id="skill-search-controls" hidden>
<label for="skill-search">Search skills</label>
<input id="skill-search" type="search">
</div>
<ul id="selected-skills" aria-label="Selected skills" hidden></ul>
<div class="onboarding-skills">
<?php foreach ($catalogue as $skill): ?>
<label class="onboarding-skill">
<input type="checkbox" name="skills[]" value="<?= (int) $skill['id'] ?>"<?= in_array((int) $skill['id'], $values['skills'], true) ? ' checked' : '' ?>>
<span><?= $escape($skill['name']) ?></span>
</label>
<?php endforeach; ?>
</div>
</fieldset>
<?php endif; ?>
<button type="submit"<?= $freelancer && count($catalogue) < 1 ? ' disabled' : '' ?>>Complete Profile</button>
</form>
</section>
<?php if ($freelancer): ?>
<p>Portfolio is optional. Save entries separately or complete your profile and add them later.</p>
<?php if ($portfolioUnavailable ?? false): ?><p>Portfolio is temporarily unavailable. You can still complete your profile.</p><?php endif; ?>
<?php $manageDetails = true; require __DIR__ . '/freelancer-profile/details.php'; ?>
<?php endif; ?>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
</body>
</html>
