<?php
// Content fingerprints invalidate stale browser CSS without changing Apache configuration.
$assetVersion = static fn(string $name): string => substr(hash_file('sha256', dirname(__DIR__, 2) . '/public/assets/' . $name), 0, 12);
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>GigHub &middot; Find your next project</title><link rel="stylesheet" href="assets/design-tokens.css?v=<?= $assetVersion('design-tokens.css') ?>"><link rel="stylesheet" href="assets/components.css?v=<?= $assetVersion('components.css') ?>"><link rel="stylesheet" href="assets/shared-shell.css?v=<?= $assetVersion('shared-shell.css') ?>"><link rel="stylesheet" href="assets/home.css?v=<?= $assetVersion('home.css') ?>"></head>
<body class="gh-shell">
<?php require __DIR__ . '/partials/header.php'; ?>
<main id="main-content" class="gh-main" tabindex="-1">
<section class="gh-hero" aria-labelledby="home-title">
    <div class="gh-hero-content">
    <p class="gh-eyebrow">IDEAS MEET OPPORTUNITY</p>
    <h1 id="home-title">Your next project starts with a connection</h1>
    <p class="gh-lead">Explore freelance projects and the skills behind them. Bring your ideas to GigHub, or discover work that fits what you do.</p>
    <?php $role = $navigationUser['role'] ?? null; ?>
    <?php if ($role === 'client'): ?>
        <p>Welcome back. Manage your jobs or share your next project brief.</p>
    <?php elseif ($role === 'freelancer'): ?>
        <p>Welcome back. Explore open jobs by keyword, budget and skills.</p>
    <?php elseif ($role === 'admin'): ?>
        <p>Explore the public marketplace or return to your Admin Workspace.</p>
    <?php else: ?>
        <p>Browse freely. Create a Client account to post a job, or join as a Freelancer to begin your journey.</p>
    <?php endif; ?>
    <div class="gh-home-actions">
        <a class="gh-button" href="?route=/jobs">Browse Jobs</a>
        <?php if ($role === null || $role === 'client'): ?>
            <a class="gh-button gh-button-secondary" href="?route=<?= $role === 'client' ? '/client/jobs/create' : '/register' ?>">Post a Job</a>
        <?php else: ?>
            <a class="gh-button gh-button-secondary" href="?route=<?= $role === 'admin' ? '/admin' : '/freelancer' ?>"><?= $role === 'admin' ? 'Admin Workspace' : 'My Workspace' ?></a>
        <?php endif; ?>
    </div>
    <form method="get" action="index.php" class="gh-home-search">
        <input type="hidden" name="route" value="/jobs">
        <label for="home-search">Search jobs by keyword</label>
        <div class="gh-search-row"><input class="gh-control" type="search" id="home-search" name="q" value="<?= $escape($query) ?>" placeholder="Try PHP, design or writing" aria-describedby="home-search-hint<?= $searchError !== null ? ' home-search-error' : '' ?>"<?= $searchError !== null ? ' aria-invalid="true"' : '' ?>><button class="gh-button" type="submit">Search Jobs</button></div>
        <p class="gh-home-hint" id="home-search-hint">Search titles and descriptions. Up to 100 characters.</p>
        <?php if ($searchError !== null): ?><p class="gh-home-error gh-field-error" id="home-search-error" role="alert"><?= $escape($searchError) ?></p><?php endif; ?>
    </form>
    </div>
    <aside class="gh-hero-visual" aria-label="How to get started">
        <p class="gh-visual-label">A place for your next project</p>
        <div class="gh-visual-card"><span class="gh-visual-number">01</span><div><strong>Clients</strong><p>Describe the work and publish a job.</p></div></div>
        <div class="gh-visual-card"><span class="gh-visual-number">02</span><div><strong>Freelancers</strong><p>Explore open jobs and find skills that fit.</p></div></div>
        <p class="gh-visual-note">Start with an idea. Find your next opportunity.</p>
    </aside>
</section>
<section class="gh-home-section" aria-labelledby="home-skills-title">
    <h2 id="home-skills-title">Explore Skills</h2><p>Start with a skill to find related open jobs.</p>
    <?php if ($skillsUnavailable): ?><p>Skills are temporarily unavailable. You can still browse jobs.</p>
    <?php elseif ($skills === []): ?><p>No skills are listed yet. You can still search by keyword.</p>
    <?php else: ?><ul class="gh-skill-links"><?php foreach ($skills as $skill): ?><li><a class="gh-skill-chip" href="<?= $escape($skillUrl((int)$skill['id'])) ?>"><?= $escape($skill['name']) ?></a></li><?php endforeach; ?></ul><?php endif; ?>
</section>
<section class="gh-home-section" aria-labelledby="home-jobs-title">
    <div class="gh-section-heading"><h2 id="home-jobs-title">Recent Jobs</h2><a class="gh-button gh-button-secondary gh-compact" href="?route=/jobs">View all jobs</a></div>
    <p>Open projects, newest by creation date.</p>
    <?php if ($jobsUnavailable): ?><div class="gh-empty-card"><h3>Jobs are temporarily unavailable</h3><p>Please try again later.</p></div>
    <?php elseif ($jobs === []): ?><div class="gh-empty-card"><h3>No open jobs yet</h3><p>Check back for new projects.</p><a class="gh-button gh-button-secondary" href="?route=/jobs">Explore all jobs &rarr;</a></div>
    <?php else: ?><ul class="gh-home-jobs"><?php foreach ($jobs as $job): ?>
        <li class="gh-job-card"><h3><a class="gh-job-title" href="?route=/jobs/show&amp;id=<?= (int)$job['id'] ?>"><?= $escape($job['title']) ?></a></h3>
            <p><?= $escape(mb_substr($job['description'], 0, 180, 'UTF-8') . (mb_strlen($job['description'], 'UTF-8') > 180 ? '…' : '')) ?></p>
            <p class="gh-job-budget">Advertised budget: RM<?= $escape(number_format((float)$job['budget'], 2)) ?></p>
            <p>Delivery deadline (Malaysia): <?= $escape($job['deadline']) ?></p>
            <?php if ($job['deadline'] < $today): ?><p class="gh-deadline-warning">Delivery deadline has passed. This job remains open.</p><?php endif; ?>
            <p class="gh-home-hint">Created (UTC): <?= $escape(substr($job['created_at'], 0, 10)) ?></p>
            <ul class="gh-job-skills" aria-label="Required skills"><?php foreach ($job['skills'] as $skill): ?><li><?= $escape($skill['name']) ?></li><?php endforeach; ?></ul>
        </li>
    <?php endforeach; ?></ul><?php endif; ?>
</section>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
</body>
</html>
