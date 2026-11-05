<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Review <?= $escape($counterpartName) ?> · GigHub</title>
    <?php require dirname(__DIR__) . '/partials/page-shell.php'; ?>
    <link rel="stylesheet" href="assets/reviews.css?v=2">
</head>
<body class="gh-shell">
<?php require dirname(__DIR__) . '/partials/header.php'; ?>

<?php
    $isClient = ($context['is_client'] ?? true);
    $jobId = (int)($job['job_id'] ?? $job['id']);
    $milestoneUrl = $isClient
        ? '?route=/client/jobs/milestones&amp;job_id=' . $jobId
        : '?route=/freelancer/jobs/milestones&amp;job_id=' . $jobId;
    $workspaceUrl = $isClient ? '?route=/client' : '?route=/freelancer';
    $workspaceLabel = $isClient ? 'Client Workspace' : 'Freelancer Workspace';
    $selectedRating = (int)($old['rating'] ?? 0);
    $commentVal = (string)($old['comment'] ?? '');
?>

<main id="main-content" tabindex="-1" class="gh-review-layout">
    <nav class="gh-back-nav" aria-label="Review breadcrumb navigation">
        <a href="<?= $milestoneUrl ?>">← Back to Job Milestones</a>
        <span class="gh-back-nav-sep" aria-hidden="true">·</span>
        <a href="<?= $workspaceUrl ?>"><?= $escape($workspaceLabel) ?></a>
    </nav>

    <p class="gh-review-eyebrow"><?= $isClient ? 'CLIENT PROJECT REVIEW' : 'FREELANCER PROJECT REVIEW' ?></p>
    <h1>Leave a Review for <?= $escape($counterpartName) ?></h1>

    <div class="gh-review-form-card">
        <h2 class="gh-review-form-title">Project: <?= $escape($job['job_title'] ?? $job['title']) ?></h2>
        <p class="gh-review-form-desc">
            Your verified review rates the collaboration quality on this completed project. Feedback is published immediately and cannot be edited.
        </p>

        <form method="post" action="?route=/jobs/review" novalidate>
            <input type="hidden" name="csrf_token" value="<?= $escape($token) ?>">
            <input type="hidden" name="job_id" value="<?= $jobId ?>">

            <div class="gh-rating-field">
                <label class="gh-rating-label" id="rating-group-label">
                    Rating <span aria-hidden="true">*</span>
                </label>
                <div class="gh-star-rating-group" role="radiogroup" aria-labelledby="rating-group-label" <?= isset($errors['rating']) ? 'aria-invalid="true" aria-describedby="rating-error"' : '' ?>>
                    <?php for ($star = 5; $star >= 1; $star--): ?>
                        <input type="radio" id="star-<?= $star ?>" name="rating" value="<?= $star ?>" <?= $selectedRating === $star ? 'checked' : '' ?> required>
                        <label for="star-<?= $star ?>" title="<?= $star ?> out of 5 stars" aria-label="<?= $star ?> stars">★</label>
                    <?php endfor; ?>
                </div>
                <span class="gh-rating-hint">Select 1 to 5 stars (5 is highest).</span>
                <?php if (isset($errors['rating'])): ?>
                    <p class="field-error" id="rating-error" role="alert" style="color: #dc2626; font-size: 0.875rem; margin-top: 0.35rem;">
                        <?= $escape($errors['rating']) ?>
                    </p>
                <?php endif; ?>
            </div>

            <div class="gh-comment-field">
                <label for="review-comment" class="gh-rating-label">
                    Feedback Comment <span style="font-weight: 400; color: var(--gh-muted); font-size: 0.8125rem;">(optional, max 1,000 characters)</span>
                </label>
                <textarea
                    id="review-comment"
                    name="comment"
                    class="gh-review-textarea"
                    maxlength="1000"
                    placeholder="Describe your experience collaborating on this project..."
                    <?= isset($errors['comment']) ? 'aria-invalid="true" aria-describedby="comment-error"' : '' ?>
                ><?= $escape($commentVal) ?></textarea>
                <?php if (isset($errors['comment'])): ?>
                    <p class="field-error" id="comment-error" role="alert" style="color: #dc2626; font-size: 0.875rem; margin-top: 0.35rem;">
                        <?= $escape($errors['comment']) ?>
                    </p>
                <?php endif; ?>
            </div>

            <div class="gh-review-actions">
                <button type="submit" class="gh-button gh-button-primary">
                    Submit Verified Review
                </button>
                <a class="gh-button gh-button-secondary" href="<?= $milestoneUrl ?>">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</main>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
</body>
</html>
