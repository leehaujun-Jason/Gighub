<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $escape($profile['display_name']) ?> · Freelancer Profile · GigHub</title>
    <?php require dirname(__DIR__) . '/partials/page-shell.php'; ?>
    <link rel="stylesheet" href="assets/profile.css?v=1">
    <link rel="stylesheet" href="assets/reviews.css?v=1">
</head>
<body class="gh-shell">
<?php require dirname(__DIR__) . '/partials/header.php'; ?>

<?php
    $rep = $profile['reputation'] ?? ['count' => 0, 'average' => null];
    $reviewsList = $profile['reviews'] ?? [];
?>

<main id="main-content" tabindex="-1" class="gh-profile-layout">
    <div class="gh-profile-heading">
        <div>
            <p class="gh-profile-eyebrow">FREELANCER PROFILE</p>
            <h1><?= $escape($profile['display_name']) ?></h1>
            <?php if ($rep['count'] > 0): ?>
                <div class="gh-profile-reputation">
                    <span class="gh-reputation-stars">★</span>
                    <strong class="gh-reputation-score"><?= number_format((float)$rep['average'], 1) ?></strong>
                    <span class="gh-reputation-count">(<?= (int)$rep['count'] ?> verified <?= (int)$rep['count'] === 1 ? 'review' : 'reviews' ?>)</span>
                </div>
            <?php else: ?>
                <div class="gh-profile-reputation">
                    <span class="gh-profile-muted">★ No reviews yet</span>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($owner): ?>
            <a class="gh-button gh-button-primary" href="?route=/freelancer/profile/edit">Edit profile</a>
        <?php endif; ?>
    </div>

    <?php if ($saved): ?>
        <p class="gh-profile-notice" role="status">Your profile has been updated.</p>
    <?php endif; ?>

    <?php if ($owner): ?>
        <p>This professional information is visible publicly. <a href="?route=/freelancers/show&amp;id=<?= (int)$profile['id'] ?>">View public profile</a></p>
    <?php endif; ?>

    <section class="gh-profile-card" aria-labelledby="about-heading">
        <h2 id="about-heading">About</h2>
        <?php if (trim($profile['headline'] ?? '') !== ''): ?>
            <p class="gh-profile-headline"><?= $escape($profile['headline']) ?></p>
        <?php else: ?>
            <p class="gh-profile-muted">No professional headline added yet.</p>
        <?php endif; ?>
        <?php if (trim($profile['bio'] ?? '') !== ''): ?>
            <div class="gh-profile-bio"><?= $escape($profile['bio']) ?></div>
        <?php else: ?>
            <p class="gh-profile-muted">This freelancer has not added a biography yet.</p>
        <?php endif; ?>
    </section>

    <section class="gh-profile-card" aria-labelledby="skills-heading">
        <h2 id="skills-heading">Skills</h2>
        <?php if ($profile['skills'] === []): ?>
            <p class="gh-profile-muted">No skills selected yet.</p>
        <?php else: ?>
            <ul class="gh-profile-skills">
                <?php foreach ($profile['skills'] as $skill): ?>
                    <li><span class="gh-skill-chip"><?= $escape($skill['name']) ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="gh-profile-card" aria-labelledby="reviews-heading">
        <h2 id="reviews-heading">Client Reviews &amp; Ratings</h2>
        <?php if ($reviewsList === []): ?>
            <p class="gh-profile-muted">No client reviews yet. Reviews will appear here once projects are completed.</p>
        <?php else: ?>
            <div class="gh-reviews-list">
                <?php foreach ($reviewsList as $rev): ?>
                    <article class="gh-review-card">
                        <div class="gh-review-card-top">
                            <span class="gh-review-stars"><?= str_repeat('★', (int)$rev['rating']) . str_repeat('☆', 5 - (int)$rev['rating']) ?> <strong><?= (int)$rev['rating'] ?>.0</strong></span>
                            <span class="gh-review-date"><?= $escape(substr((string)$rev['created_at'], 0, 10)) ?></span>
                        </div>
                        <?php if (!empty($rev['comment'])): ?>
                            <blockquote class="gh-review-body">"<?= nl2br($escape($rev['comment'])) ?>"</blockquote>
                        <?php endif; ?>
                        <footer class="gh-review-meta">
                            <span>Project: <strong><?= $escape($rev['job_title']) ?></strong></span>
                            <span aria-hidden="true">·</span>
                            <span>Client: <strong><?= $escape($rev['client_name']) ?></strong></span>
                        </footer>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
</body>
</html>
