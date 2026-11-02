<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= $escape($profile['display_name']) ?> · Freelancer Profile · GigHub</title>
<?php require dirname(__DIR__).'/partials/page-shell.php'; ?>
<link rel="stylesheet" href="assets/profile.css?v=1"></head>
<body class="gh-shell">
<?php require dirname(__DIR__).'/partials/header.php'; ?>
<main id="main-content" tabindex="-1" class="gh-profile-layout">
    <div class="gh-profile-heading"><div><p class="gh-profile-eyebrow">FREELANCER PROFILE</p><h1><?= $escape($profile['display_name']) ?></h1></div>
    <?php if ($owner): ?><a class="gh-button gh-button-primary" href="?route=/freelancer/profile/edit">Edit profile</a><?php endif; ?></div>
    <?php if ($saved): ?><p class="gh-profile-notice" role="status">Your profile has been updated.</p><?php endif; ?>
    <?php if ($owner): ?><p>This professional information is visible publicly. <a href="?route=/freelancers/show&amp;id=<?= (int)$profile['id'] ?>">View public profile</a></p><?php endif; ?>
    <section class="gh-profile-card" aria-labelledby="about-heading"><h2 id="about-heading">About</h2>
        <?php if (trim($profile['headline']??'')!==''): ?><p class="gh-profile-headline"><?= $escape($profile['headline']) ?></p><?php else: ?><p class="gh-profile-muted">No professional headline added yet.</p><?php endif; ?>
        <?php if (trim($profile['bio']??'')!==''): ?><div class="gh-profile-bio"><?= $escape($profile['bio']) ?></div><?php else: ?><p class="gh-profile-muted">This freelancer has not added a biography yet.</p><?php endif; ?>
    </section>
    <section class="gh-profile-card" aria-labelledby="skills-heading"><h2 id="skills-heading">Skills</h2>
        <?php if ($profile['skills']===[]): ?><p class="gh-profile-muted">No skills selected yet.</p><?php else: ?><ul class="gh-profile-skills"><?php foreach ($profile['skills'] as $skill): ?><li><span class="gh-skill-chip"><?= $escape($skill['name']) ?></span></li><?php endforeach; ?></ul><?php endif; ?>
    </section>
</main>
<?php require dirname(__DIR__).'/partials/footer.php'; ?>
</body></html>
