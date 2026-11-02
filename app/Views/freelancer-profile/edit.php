<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Edit Profile · GigHub</title>
<?php require dirname(__DIR__).'/partials/page-shell.php'; ?>
<link rel="stylesheet" href="assets/profile.css?v=1"></head>
<body class="gh-shell">
<?php require dirname(__DIR__).'/partials/header.php'; ?>
<main id="main-content" tabindex="-1" class="gh-profile-layout">
    <p class="gh-profile-eyebrow">YOUR PROFESSIONAL PROFILE</p><h1>Edit profile</h1><p>Introduce your expertise to potential Clients. Everything you enter here is public.</p>
    <?php if ($errors!==[]): ?><div class="gh-profile-errors" role="alert"><h2>Please check your profile</h2><ul><?php foreach ($errors as $field=>$error): ?><li><a href="#<?= $escape($field==='skills'?'profile-skills':$field) ?>"><?= $escape($error) ?></a></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form class="gh-profile-card gh-profile-form" method="post" action="?route=/freelancer/profile/edit">
        <input type="hidden" name="csrf_token" value="<?= $escape(\GigHub\Core\Csrf::token('freelancer_profile')) ?>">
        <div><label for="headline">Professional headline <span>(optional)</span></label><p id="headline-hint" class="gh-profile-muted">A short introduction to your expertise. Maximum 150 characters.</p>
        <input class="gh-control" id="headline" name="headline" type="text" maxlength="150" value="<?= $escape($values['headline']) ?>" aria-describedby="headline-hint<?= isset($errors['headline'])?' headline-error':'' ?>"<?= isset($errors['headline'])?' aria-invalid="true"':'' ?>>
        <?php if (isset($errors['headline'])): ?><p class="gh-profile-field-error" id="headline-error"><?= $escape($errors['headline']) ?></p><?php endif; ?></div>
        <div><label for="bio">Biography <span>(optional)</span></label><p id="bio-hint" class="gh-profile-muted">Describe your experience and the work you can help with. Maximum 10,000 characters. Avoid private contact information.</p>
        <textarea class="gh-control" id="bio" name="bio" rows="10" maxlength="10000" aria-describedby="bio-hint<?= isset($errors['bio'])?' bio-error':'' ?>"<?= isset($errors['bio'])?' aria-invalid="true"':'' ?>><?= $escape($values['bio']) ?></textarea>
        <?php if (isset($errors['bio'])): ?><p class="gh-profile-field-error" id="bio-error"><?= $escape($errors['bio']) ?></p><?php endif; ?></div>
        <fieldset id="profile-skills" aria-describedby="skills-hint<?= isset($errors['skills'])?' skills-error':'' ?>"><legend>Skills <span>(optional)</span></legend><p id="skills-hint" class="gh-profile-muted">Choose the skills that represent your professional work.</p>
        <?php if ($skills===[]): ?><p>No skills are currently available.</p><?php else: ?><div class="gh-profile-options"><?php foreach ($skills as $skill): ?><label><input type="checkbox" name="skills[]" value="<?= (int)$skill['id'] ?>"<?= in_array((int)$skill['id'],$values['skills'],true)?' checked':'' ?>><span><?= $escape($skill['name']) ?></span></label><?php endforeach; ?></div><?php endif; ?>
        <?php if (isset($errors['skills'])): ?><p class="gh-profile-field-error" id="skills-error"><?= $escape($errors['skills']) ?></p><?php endif; ?></fieldset>
        <div class="gh-profile-actions"><button class="gh-button gh-button-primary" type="submit">Save profile</button><a class="gh-button gh-button-secondary" href="?route=/freelancer/profile">Cancel</a></div>
    </form>
</main>
<?php require dirname(__DIR__).'/partials/footer.php'; ?>
</body></html>
