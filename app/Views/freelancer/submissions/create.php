<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Submit Deliverable: <?= $escape($milestone['title']) ?> · GigHub</title>
    <link rel="stylesheet" href="assets/design-tokens.css?v=1">
    <link rel="stylesheet" href="assets/components.css?v=1">
    <link rel="stylesheet" href="assets/shared-shell.css?v=1">
    <link rel="stylesheet" href="assets/submissions.css?v=2">
</head>
<body class="gh-shell">
<?php require dirname(__DIR__, 2) . '/partials/header.php'; ?>

<main id="main-content" tabindex="-1" class="gh-submission-layout">
    <p class="gh-submission-eyebrow">FREELANCER WORKSPACE</p>
    <h1>Submit Deliverable</h1>
    <p>Submit your completed deliverables for this milestone checkpoint. Your client will review the work against the agreed requirements.</p>

    <div class="gh-submission-card">
        <h2>Milestone Details</h2>
        <dl class="gh-submission-meta">
            <div>
                <dt>Project</dt>
                <dd><?= $escape($job['title']) ?></dd>
            </div>
            <div>
                <dt>Checkpoint</dt>
                <dd>#<?= (int)$milestone['position'] ?> — <?= $escape($milestone['title']) ?></dd>
            </div>
            <div>
                <dt>Due Date</dt>
                <dd><?= $escape($milestone['due_date']) ?></dd>
            </div>
        </dl>

        <?php if (!empty($milestone['description'])): ?>
            <div style="margin-bottom: 1.5rem;">
                <strong>Deliverable Scope:</strong>
                <p><?= nl2br($escape($milestone['description'])) ?></p>
            </div>
        <?php endif; ?>

        <?php
            $latestRevisionFeedback = null;
            if (($milestone['status'] ?? '') === 'revisions_requested' && !empty($previousSubmissions)) {
                foreach ($previousSubmissions as $p) {
                    if ($p['status'] === 'revisions_requested' && !empty($p['feedback'])) {
                        $latestRevisionFeedback = $p;
                        break;
                    }
                }
            }
        ?>
        <?php if ($latestRevisionFeedback !== null): ?>
            <div style="margin-bottom: 1.5rem; padding: 1rem 1.25rem; background: #fffbeb; border: 1px solid #fef3c7; border-left: 4px solid #d97706; border-radius: 4px;">
                <strong style="color: #92400e; font-size: 1rem;">Client Requested Revisions:</strong>
                <div style="margin: 0.5rem 0 0; color: #78350f; white-space: pre-wrap; font-size: 0.9375rem;"><?= nl2br($escape($latestRevisionFeedback['feedback'])) ?></div>
                <?php if (!empty($latestRevisionFeedback['reviewed_at'])): ?>
                    <div style="margin-top: 0.5rem; font-size: 0.75rem; color: var(--gh-muted);">Reviewed on <?= $escape($latestRevisionFeedback['reviewed_at']) ?> (UTC)</div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="?route=/freelancer/milestones/submit" class="gh-submission-form">
            <input type="hidden" name="csrf_token" value="<?= $escape($token) ?>">
            <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
            <input type="hidden" name="milestone_id" value="<?= (int)$milestone['id'] ?>">

            <?php require dirname(__DIR__, 2) . '/partials/submission-link-fields.php'; ?>

            <div>
                <label for="notes">Submission Notes & Deliverable Summary <span>(required)</span></label>
                <textarea id="notes" name="notes" rows="6" required maxlength="5000" placeholder="Describe the work completed for this milestone, provide review instructions, or note important deliverables..." aria-describedby="notes-hint" <?= isset($errors['notes']) ? 'aria-invalid="true"' : '' ?>><?= $escape($old['notes'] ?? '') ?></textarea>
                <p class="hint" id="notes-hint">Provide clear instructions and summary for the client. Up to 5,000 characters.</p>
                <?php if (isset($errors['notes'])): ?>
                    <p class="field-error"><?= $escape($errors['notes']) ?></p>
                <?php endif; ?>
            </div>

            <div class="gh-submission-actions">
                <button type="submit" class="gh-button gh-button-primary"><?= ($milestone['status'] ?? '') === 'revisions_requested' ? 'Submit Revised Deliverable' : 'Submit Work for Review' ?></button>
                <a href="?route=/freelancer/jobs/milestones&amp;job_id=<?= (int)$job['id'] ?>" class="gh-button gh-button-secondary">Cancel and Return</a>
            </div>
        </form>
    </div>

    <?php if (!empty($previousSubmissions)): ?>
        <?php
            $milestoneSubs = $previousSubmissions;
            require dirname(__DIR__, 2) . '/partials/submission-history.php';
        ?>
    <?php endif; ?>
</main>

<?php require dirname(__DIR__, 2) . '/partials/footer.php'; ?>
</body>
</html>
