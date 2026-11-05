<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Submit Final Deliverable: <?= $escape($job['title']) ?> · GigHub</title>
    <link rel="stylesheet" href="assets/design-tokens.css?v=1">
    <link rel="stylesheet" href="assets/components.css?v=1">
    <link rel="stylesheet" href="assets/shared-shell.css?v=1">
    <link rel="stylesheet" href="assets/submissions.css?v=2">
</head>
<body class="gh-shell">
<?php require dirname(__DIR__, 2) . '/partials/header.php'; ?>

<?php
    $escrow = $escrow ?? null;
    $escrowAmount = ($escrow !== null && isset($escrow['amount'])) ? (float)$escrow['amount'] : (float)($job['budget'] ?? 0);
?>
<main id="main-content" tabindex="-1" class="gh-submission-layout">
    <p class="gh-submission-eyebrow">FREELANCER WORKSPACE</p>
    <h1>Submit Final Deliverable</h1>
    <p>Hand over the complete project deliverables for client review and final sign-off. All milestone checkpoints must be approved prior to final handover.</p>

    <div class="gh-submission-card">
        <h2>Project Overview</h2>
        <dl class="gh-submission-meta">
            <div>
                <dt>Project Title</dt>
                <dd><?= $escape($job['title']) ?></dd>
            </div>
            <div>
                <dt>Agreed Escrow Amount</dt>
                <dd>RM <?= number_format($escrowAmount, 2) ?></dd>
            </div>
            <div>
                <dt>Deadline</dt>
                <dd><?= $escape($job['deadline']) ?></dd>
            </div>
        </dl>

        <?php if (!empty($milestones)): ?>
            <div style="margin-bottom: 1.5rem; padding: 1rem 1.25rem; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 4px;">
                <strong style="color: #166534; font-size: 0.95rem;">Completed Milestone Checkpoints (<?= count($milestones) ?>/<?= count($milestones) ?> Approved):</strong>
                <ul style="margin: 0.5rem 0 0 1.25rem; padding: 0; color: #166534; font-size: 0.9rem;">
                    <?php foreach ($milestones as $ms): ?>
                        <li style="margin-bottom: 0.25rem;">
                            <strong>#<?= (int)$ms['position'] ?> <?= $escape($ms['title'] ?? 'Milestone #' . (int)$ms['position']) ?></strong>
                            — Approved<?= !empty($ms['approved_at']) ? ' on ' . $escape($ms['approved_at']) . ' UTC' : '' ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php
            $latestRevisionFeedback = null;
            if (!empty($previousSubmissions)) {
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
                <strong style="color: #92400e; font-size: 1rem;">Client Requested Revisions on Final Deliverable:</strong>
                <div style="margin: 0.5rem 0 0; color: #78350f; white-space: pre-wrap; font-size: 0.9375rem;"><?= nl2br($escape($latestRevisionFeedback['feedback'])) ?></div>
                <?php if (!empty($latestRevisionFeedback['reviewed_at'])): ?>
                    <div style="margin-top: 0.5rem; font-size: 0.75rem; color: var(--gh-muted);">Reviewed on <?= $escape($latestRevisionFeedback['reviewed_at']) ?> (UTC)</div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="?route=/freelancer/jobs/final-submit" class="gh-submission-form">
            <input type="hidden" name="csrf_token" value="<?= $escape($token) ?>">
            <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">

            <div>
                <label for="deliverable_url">Deliverable URL <span>(optional)</span></label>
                <input type="url" id="deliverable_url" name="deliverable_url" maxlength="2048" value="<?= $escape($old['deliverable_url'] ?? '') ?>" placeholder="https://github.com/your-username/repository or https://demo.example.com" aria-describedby="url-hint" <?= isset($errors['deliverable_url']) ? 'aria-invalid="true"' : '' ?>>
                <p class="hint" id="url-hint">Optional link to your full repository, production deployment, or final deliverable package. Must start with http:// or https://.</p>
                <?php if (isset($errors['deliverable_url'])): ?>
                    <p class="field-error"><?= $escape($errors['deliverable_url']) ?></p>
                <?php endif; ?>
            </div>

            <div>
                <label for="notes">Final Delivery Notes & Project Summary <span>(required)</span></label>
                <textarea id="notes" name="notes" rows="6" required maxlength="5000" placeholder="Provide a comprehensive summary of all completed work, deployment instructions, access credentials (if safe demo), or final handover details..." aria-describedby="notes-hint" <?= isset($errors['notes']) ? 'aria-invalid="true"' : '' ?>><?= $escape($old['notes'] ?? '') ?></textarea>
                <p class="hint" id="notes-hint">Provide complete handover documentation and summary for the client. Up to 5,000 characters.</p>
                <?php if (isset($errors['notes'])): ?>
                    <p class="field-error"><?= $escape($errors['notes']) ?></p>
                <?php endif; ?>
            </div>

            <div class="gh-submission-actions">
                <button type="submit" class="gh-button gh-button-primary"><?= $latestRevisionFeedback !== null ? 'Submit Revised Final Deliverable' : 'Submit Final Work for Acceptance' ?></button>
                <a href="?route=/freelancer/jobs/milestones&amp;job_id=<?= (int)$job['id'] ?>" class="gh-button gh-button-secondary">Cancel and Return</a>
            </div>
        </form>
    </div>

    <?php if (!empty($previousSubmissions)): ?>
        <?php
            $milestoneSubs = $previousSubmissions;
            $historyTitle = 'Final Submission History (' . count($previousSubmissions) . ')';
            $historyAriaLabel = 'Final deliverable submission history';
            require dirname(__DIR__, 2) . '/partials/submission-history.php';
        ?>
    <?php endif; ?>
</main>

<?php require dirname(__DIR__, 2) . '/partials/footer.php'; ?>
</body>
</html>
