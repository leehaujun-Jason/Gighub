<?php
use GigHub\Services\ProfessionalDetails;
$title = ($id === null ? 'Add' : 'Edit') . ' profile entry';
$url = '?route=/freelancer/profile/details&section=' . $section . ($id === null ? '' : '&id=' . $id);
$back = '?route=' . $returnRoute . '#professional-' . $section;
$token = $escape(\GigHub\Core\Csrf::token('professional_details'));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $title ?> | GigHub</title>
<?php require dirname(__DIR__) . '/partials/page-shell.php'; ?>
<link rel="stylesheet" href="assets/profile.css?v=4">
<?php if ($section === 'employment'): ?><script src="assets/professional-details.js" defer></script><?php endif; ?>
</head>
<body class="gh-shell">
<?php require dirname(__DIR__) . '/partials/header.php'; ?>
<main class="gh-profile-layout" id="main-content" tabindex="-1">
<p class="gh-profile-eyebrow"><?= $escape(ProfessionalDetails::SECTIONS[$section]) ?></p>
<h1><?= $title ?></h1>
<?php if ($errors !== []): ?><p class="gh-profile-errors" role="alert">Review the highlighted fields.</p><?php endif; ?>
<form class="gh-profile-card gh-profile-form" method="post" action="<?= $escape($url) ?>">
<input type="hidden" name="csrf_token" value="<?= $token ?>">
<?php foreach (ProfessionalDetails::FIELDS[$section] as $field => [$label, $type, $limit, $required]):
    $value = (string) ($values[$field] ?? '');
    $attributes = ($required ? ' required' : '') . (isset($errors[$field]) ? ' aria-invalid="true" aria-describedby="' . $field . '-error"' : '');
?>
<?php if ($field === 'start_year'): ?><div class="gh-detail-year-row"><?php endif; ?>
<div>
<label for="<?= $field ?>"><?= $escape($label) ?><?= !$required && $type !== 'checkbox' ? ' <span>(optional)</span>' : '' ?></label>
<?php if ($type === 'select'):
    $options = $field === 'language' ? ProfessionalDetails::LANGUAGES : array_combine(ProfessionalDetails::PROFICIENCY, ProfessionalDetails::PROFICIENCY); ?>
<select class="gh-control" id="<?= $field ?>" name="<?= $field ?>"<?= $attributes ?>>
<option value="">Choose <?= $field === 'language' ? 'a language' : 'a level' ?></option>
<?php foreach ($options as $key => $name): ?><option value="<?= $escape($key) ?>"<?= $value === $key ? ' selected' : '' ?>><?= $escape($name) ?></option><?php endforeach; ?>
</select>
<?php elseif ($type === 'textarea'): ?>
<textarea class="gh-control" id="<?= $field ?>" name="<?= $field ?>" maxlength="<?= $limit ?>"<?= $attributes ?>><?= $escape($value) ?></textarea>
<?php elseif ($type === 'checkbox'): ?>
<input type="checkbox" id="<?= $field ?>" name="<?= $field ?>" value="1"<?= $value === '1' ? ' checked' : '' ?><?= $attributes ?>>
<?php else: ?>
<input class="gh-control" type="<?= $type ?>" id="<?= $field ?>" name="<?= $field ?>" value="<?= $escape($value) ?>"<?= in_array($type, ['text','url'], true) ? ' maxlength="' . $limit . '"' : '' ?><?= $attributes ?>>
<?php endif; ?>
<?php if (isset($errors[$field])): ?><p class="gh-profile-field-error" id="<?= $field ?>-error"><?= $escape($errors[$field]) ?></p><?php endif; ?>
</div>
<?php if ($field === 'end_year'): ?></div><?php endif; ?>
<?php endforeach; ?>
<div class="gh-profile-actions">
<button class="gh-button gh-button-primary" name="action" value="save" type="submit">Save entry</button>
<a class="gh-button gh-button-secondary" href="<?= $escape($back) ?>">Cancel</a>
</div>
</form>
<?php if ($id !== null): ?>
<details class="gh-profile-card" id="delete-entry"<?= ($query['confirm'] ?? null) === 'delete' ? ' open' : '' ?>>
<summary>Delete entry</summary>
<form method="post" action="<?= $escape($url) ?>">
<input type="hidden" name="csrf_token" value="<?= $token ?>">
<p>Delete this entry from your profile?</p>
<button class="gh-button gh-button-secondary" name="action" value="delete" type="submit">Confirm deletion</button>
</form>
</details>
<?php endif; ?>
</main>
<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
</body>
</html>
