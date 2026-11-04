<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Submit Deliverable: <?= $escape($milestone['title']) ?> · GigHub</title>
    <link rel="stylesheet" href="assets/design-tokens.css?v=1">
    <link rel="stylesheet" href="assets/components.css?v=1">
    <link rel="stylesheet" href="assets/shared-shell.css?v=1">
    <link rel="stylesheet" href="assets/submissions.css?v=1">
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

        <form method="post" action="?route=/freelancer/milestones/submit" class="gh-submission-form">
            <input type="hidden" name="csrf_token" value="<?= $escape($token) ?>">
            <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
            <input type="hidden" name="milestone_id" value="<?= (int)$milestone['id'] ?>">

            <div>
                <label for="deliverable_url">Deliverable URL <span>(optional)</span></label>
                <input type="url" id="deliverable_url" name="deliverable_url" maxlength="2048" value="<?= $escape($old['deliverable_url'] ?? '') ?>" placeholder="https://github.com/your-username/repository or https://figma.com/..." aria-describedby="url-hint" <?= isset($errors['deliverable_url']) ? 'aria-invalid="true"' : '' ?>>
                <p class="hint" id="url-hint">Optional link to your repository, live demo, or shared cloud deliverable. Must start with http:// or https://.</p>
                <?php if (isset($errors['deliverable_url'])): ?>
                    <p class="field-error"><?= $escape($errors['deliverable_url']) ?></p>
                <?php endif; ?>
            </div>

            <div>
                <label for="notes">Submission Notes & Deliverable Summary <span>(required)</span></label>
                <textarea id="notes" name="notes" rows="6" required maxlength="5000" placeholder="Describe the work completed for this milestone, provide review instructions, or note important deliverables..." aria-describedby="notes-hint" <?= isset($errors['notes']) ? 'aria-invalid="true"' : '' ?>><?= $escape($old['notes'] ?? '') ?></textarea>
                <p class="hint" id="notes-hint">Provide clear instructions and summary for the client. Up to 5,000 characters.</p>
                <?php if (isset($errors['notes'])): ?>
                    <p class="field-error"><?= $escape($errors['notes']) ?></p>
                <?php endif; ?>
            </div>

            <div class="gh-submission-actions">
                <button type="submit" class="gh-button gh-button-primary">Submit Work for Review</button>
                <a href="?route=/freelancer/jobs/milestones&amp;job_id=<?= (int)$job['id'] ?>" class="gh-button gh-button-secondary">Cancel and Return</a>
            </div>
        </form>
    </div>

    <?php if (!empty($previousSubmissions)): ?>
        <section class="gh-submission-history-section" aria-label="Previous submissions">
            <h2>Submission History (<?= count($previousSubmissions) ?>)</h2>
            <?php foreach ($previousSubmissions as $prev): ?>
                <div class="gh-submission-history-item">
                    <div class="gh-submission-history-header">
                        <span class="gh-badge">Status: <?= $escape(ucwords(str_replace('_', ' ', $prev['status']))) ?></span>
                        <span class="gh-submission-history-date">Submitted (UTC): <?= $escape($prev['submitted_at'] ?? $prev['created_at']) ?></span>
                    </div>
                    <?php if (!empty($prev['deliverable_url'])): ?>
                        <div class="gh-submission-history-url">
                            <strong>Deliverable Link:</strong>
                            <a href="<?= $escape($prev['deliverable_url']) ?>" target="_blank" rel="noopener noreferrer"><?= $escape($prev['deliverable_url']) ?></a>
                        </div>
                    <?php endif; ?>
                    <div class="gh-submission-history-notes">
                        <strong>Notes:</strong><br>
                        <?= nl2br($escape($prev['notes'])) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</main>

<?php require dirname(__DIR__, 2) . '/partials/footer.php'; ?>
</body>
</html>
