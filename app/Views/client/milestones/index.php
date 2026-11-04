<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Job Milestones · GigHub</title>
    <?php require dirname(__DIR__, 2) . '/partials/page-shell.php'; ?>
    <link rel="stylesheet" href="assets/milestones.css?v=2">
</head>
<body class="gh-shell">
<?php require dirname(__DIR__, 2) . '/partials/header.php'; ?>
<?php
    $isLocked = $isLocked ?? false;
    $isClient = $isClient ?? true;
    $submissions = $submissions ?? [];
    $reviewToken = $reviewToken ?? '';
    $reviewErrors = $reviewErrors ?? [];
    $reviewOld = $reviewOld ?? [];
?>
<main id="main-content" tabindex="-1" class="gh-milestones-layout">
    <p class="gh-milestones-eyebrow"><?= $isClient ? 'CLIENT WORKSPACE' : 'FREELANCER WORKSPACE' ?></p>
    <h1>Milestones for <?= $escape($job['title']) ?></h1>

    <?php if ($isLocked): ?>
        <div class="gh-milestones-notice" role="note">
            <p><strong>Milestones are locked after funding.</strong> Escrow has been funded and work is underway. Deliverable checkpoints are displayed in read-only mode.</p>
        </div>
        <p>
            <?php if ($isClient): ?>
                <a href="?route=/client/jobs/show&amp;id=<?= (int)$job['id'] ?>">Back to job details</a>
            <?php else: ?>
                <a href="?route=/freelancer/bids">Back to My Bids</a>
            <?php endif; ?>
        </p>
    <?php else: ?>
        <p>Define task and deliverable checkpoints for this project. At least one milestone is required before escrow funding. Milestones represent deliverable scope and do not carry monetary amounts; full payment is held in escrow and settled upon project completion.</p>
        <p>
            <a href="?route=/client/jobs/bids&amp;id=<?= (int)$job['id'] ?>">Back to job bid review</a>
            <?php if (count($milestones) >= 1): ?> · <a class="gh-button gh-button-primary" href="?route=/client/jobs/fund&amp;job_id=<?= (int)$job['id'] ?>">Proceed to Escrow Funding</a><?php endif; ?>
        </p>
    <?php endif; ?>

    <?php if ($notice !== null): ?>
        <p class="gh-milestones-notice" role="status"><?= $escape($notice) ?></p>
    <?php endif; ?>

    <?php if (isset($errors['form'])): ?>
        <div class="gh-milestones-error" role="alert"><p><?= $escape($errors['form']) ?></p></div>
    <?php endif; ?>

    <section aria-label="Existing milestones">
        <h2>Defined Milestones (<?= count($milestones) ?>)</h2>
        <?php if ($milestones === []): ?>
            <div class="gh-milestone-card">
                <p>No milestones defined yet. <?= $isLocked ? 'No milestones were created for this project.' : 'Use the form below to create your first milestone checkpoint before funding the escrow.' ?></p>
            </div>
        <?php else: ?>
            <?php foreach ($milestones as $m): ?>
                <article class="gh-milestone-card">
                    <div class="gh-milestone-header">
                        <div>
                            <span class="gh-milestone-pos-badge">Position <?= (int)$m['position'] ?></span>
                            <h3><?= $escape($m['title']) ?></h3>
                        </div>
                        <span class="gh-milestone-status-badge">Status: <?= $escape(ucfirst($m['status'])) ?></span>
                    </div>

                    <dl class="gh-milestone-meta">
                        <div>
                            <dt>Due Date (Malaysia)</dt>
                            <dd><?= $escape($m['due_date']) ?></dd>
                        </div>
                        <div>
                            <dt>Created (UTC)</dt>
                            <dd><?= $escape(substr($m['created_at'], 0, 10)) ?></dd>
                        </div>
                    </dl>

                    <?php if (!empty($m['description'])): ?>
                        <div class="gh-milestone-desc"><?= $escape($m['description']) ?></div>
                    <?php endif; ?>

                    <?php
                        $milestoneSubs = array_values(array_filter($submissions, static fn($s) => (int)($s['milestone_id'] ?? 0) === (int)$m['id']));
                    ?>

                    <?php if (!$isLocked && $isClient): ?>
                        <div class="gh-milestone-actions">
                            <a class="gh-button gh-button-secondary" href="?route=/client/jobs/milestones/edit&amp;job_id=<?= (int)$job['id'] ?>&amp;id=<?= (int)$m['id'] ?>">Edit milestone</a>
                            <form method="post" action="?route=/client/jobs/milestones/delete" onsubmit="return confirm('Delete this milestone? Remaining milestones will be re-indexed.');">
                                <input type="hidden" name="csrf_token" value="<?= $escape($token) ?>">
                                <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
                                <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                                <button class="gh-button gh-button-secondary" type="submit">Delete</button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <?php if (!$isClient && $job['status'] === 'in_progress'): ?>
                        <div class="gh-milestone-actions" style="margin-top: 1rem;">
                            <?php if ($m['status'] === 'pending'): ?>
                                <a class="gh-button gh-button-primary" href="?route=/freelancer/milestones/submit&amp;job_id=<?= (int)$job['id'] ?>&amp;milestone_id=<?= (int)$m['id'] ?>">Submit Deliverable</a>
                            <?php elseif ($m['status'] === 'revisions_requested'): ?>
                                <a class="gh-button gh-button-primary" href="?route=/freelancer/milestones/submit&amp;job_id=<?= (int)$job['id'] ?>&amp;milestone_id=<?= (int)$m['id'] ?>">Submit Revised Deliverable</a>
                            <?php elseif ($m['status'] === 'submitted'): ?>
                                <span class="gh-badge" style="background: #e0f2fe; color: #0369a1; padding: 0.4rem 0.75rem; border-radius: 4px; font-size: 0.875rem; font-weight: 600;">Deliverable submitted — Awaiting client review</span>
                            <?php elseif ($m['status'] === 'approved'): ?>
                                <span class="gh-badge" style="background: #dcfce7; color: #15803d; padding: 0.4rem 0.75rem; border-radius: 4px; font-size: 0.875rem; font-weight: 600;">✓ Checkpoint Approved</span>
                            <?php endif; ?>
                        </div>

                        <?php
                            $latestRevisionFeedback = null;
                            if ($m['status'] === 'revisions_requested') {
                                foreach ($milestoneSubs as $s) {
                                    if ($s['status'] === 'revisions_requested' && !empty($s['feedback'])) {
                                        $latestRevisionFeedback = $s;
                                        break;
                                    }
                                }
                            }
                        ?>
                        <?php if ($latestRevisionFeedback !== null): ?>
                            <div style="margin-top: 1rem; padding: 0.875rem 1rem; background: #fffbeb; border: 1px solid #fef3c7; border-left: 4px solid #d97706; border-radius: 4px;">
                                <strong style="color: #92400e; font-size: 0.95rem;">Client Revision Feedback:</strong>
                                <div style="margin-top: 0.35rem; color: #78350f; font-size: 0.875rem; white-space: pre-wrap;"><?= nl2br($escape($latestRevisionFeedback['feedback'])) ?></div>
                                <?php if (!empty($latestRevisionFeedback['reviewed_at'])): ?>
                                    <div style="margin-top: 0.35rem; font-size: 0.75rem; color: var(--gh-muted);">Reviewed on <?= $escape($latestRevisionFeedback['reviewed_at']) ?> (UTC)</div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($isClient): ?>
                        <?php if ($m['status'] === 'submitted'): ?>
                            <?php
                                $latestSubmittedSub = null;
                                foreach ($milestoneSubs as $s) {
                                    if ($s['status'] === 'submitted') {
                                        $latestSubmittedSub = $s;
                                        break;
                                    }
                                }
                            ?>
                            <?php if ($latestSubmittedSub !== null): ?>
                                <section class="gh-review-panel" aria-label="Milestone review actions">
                                    <h4 class="gh-review-panel-title">Review Milestone Deliverable (Attempt #<?= (int)$latestSubmittedSub['id'] ?>)</h4>
                                    <p class="gh-review-panel-desc">
                                        Review the submission notes and deliverable link below. You can approve this milestone checkpoint or request revisions with written feedback.
                                    </p>

                                    <div class="gh-review-actions-grid">
                                        <!-- Approve Milestone Card -->
                                        <div class="gh-review-card gh-review-card--approve">
                                            <div class="gh-review-card-header">
                                                <h5 class="gh-review-card-title">✓ Approve Milestone</h5>
                                                <p class="gh-review-card-desc">Accept the deliverable and mark this milestone checkpoint as completed.</p>
                                            </div>
                                            <form method="post" action="?route=/client/milestones/approve" class="gh-review-form">
                                                <input type="hidden" name="csrf_token" value="<?= $escape($reviewToken) ?>">
                                                <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
                                                <input type="hidden" name="milestone_id" value="<?= (int)$m['id'] ?>">
                                                <input type="hidden" name="submission_id" value="<?= (int)$latestSubmittedSub['id'] ?>">

                                                <div class="gh-review-field">
                                                    <label for="approve_feedback_<?= (int)$m['id'] ?>" class="gh-review-label">
                                                        Approval Note <span>(optional)</span>
                                                    </label>
                                                    <textarea id="approve_feedback_<?= (int)$m['id'] ?>" name="feedback" rows="2" maxlength="5000" class="gh-review-textarea" placeholder="Optional comments for freelancer (e.g. Looks great, verified!)..."><?= (isset($reviewOld['milestone_id']) && (int)$reviewOld['milestone_id'] === (int)$m['id'] && ($reviewOld['action'] ?? '') === 'approve') ? $escape($reviewOld['feedback'] ?? '') : '' ?></textarea>
                                                </div>

                                                <div class="gh-review-submit-wrap">
                                                    <button type="submit" class="gh-button gh-button--approve">
                                                        Approve Milestone
                                                    </button>
                                                </div>
                                            </form>
                                        </div>

                                        <!-- Request Revisions Card -->
                                        <?php
                                            $isTargetRevision = isset($reviewOld['milestone_id']) && (int)$reviewOld['milestone_id'] === (int)$m['id'];
                                            $revisionFeedbackVal = $isTargetRevision ? ($reviewOld['feedback'] ?? '') : '';
                                            $hasRevisionError = $isTargetRevision && isset($reviewErrors['feedback']);
                                        ?>
                                        <div class="gh-review-card gh-review-card--revision">
                                            <div class="gh-review-card-header">
                                                <h5 class="gh-review-card-title">↺ Request Revisions</h5>
                                                <p class="gh-review-card-desc">Send the deliverable back to the freelancer with required changes.</p>
                                            </div>
                                            <form method="post" action="?route=/client/milestones/request-revision" class="gh-review-form">
                                                <input type="hidden" name="csrf_token" value="<?= $escape($reviewToken) ?>">
                                                <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
                                                <input type="hidden" name="milestone_id" value="<?= (int)$m['id'] ?>">
                                                <input type="hidden" name="submission_id" value="<?= (int)$latestSubmittedSub['id'] ?>">

                                                <div class="gh-review-field">
                                                    <label for="revision_feedback_<?= (int)$m['id'] ?>" class="gh-review-label">
                                                        Revision Feedback <span>(required)</span>
                                                    </label>
                                                    <textarea id="revision_feedback_<?= (int)$m['id'] ?>" name="feedback" rows="4" maxlength="5000" required class="gh-review-textarea" placeholder="Describe specifically what needs to be changed or improved before this milestone can be approved..." <?= $hasRevisionError ? 'aria-invalid="true" aria-describedby="rev-err-' . (int)$m['id'] . '"' : '' ?>><?= $escape($revisionFeedbackVal) ?></textarea>
                                                    <?php if ($hasRevisionError): ?>
                                                        <p class="field-error" id="rev-err-<?= (int)$m['id'] ?>" role="alert"><?= $escape($reviewErrors['feedback']) ?></p>
                                                    <?php endif; ?>
                                                </div>

                                                <div class="gh-review-submit-wrap">
                                                    <button type="submit" class="gh-button gh-button-secondary gh-button--revision">
                                                        Request Revisions
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </section>
                            <?php endif; ?>
                        <?php elseif ($m['status'] === 'revisions_requested'): ?>
                            <div style="margin-top: 1rem; padding: 0.75rem 1rem; background: #fffbeb; border: 1px solid #fef3c7; border-radius: 4px; font-size: 0.875rem; color: #92400e;">
                                <strong>Revisions requested.</strong> Awaiting updated deliverable from freelancer.
                            </div>
                        <?php elseif ($m['status'] === 'approved'): ?>
                            <div style="margin-top: 1rem; padding: 0.75rem 1rem; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 4px; font-size: 0.875rem; color: #166534;">
                                <strong>Milestone Approved.</strong> Checkpoint completed<?= !empty($m['approved_at']) ? ' on ' . $escape($m['approved_at']) . ' (UTC)' : '' ?>.
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if (!empty($milestoneSubs)): ?>
                        <?php require dirname(__DIR__, 2) . '/partials/submission-history.php'; ?>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <?php if (!$isLocked && $isClient): ?>
        <section class="gh-milestone-card" aria-label="Add new milestone">
            <h2>Add Milestone</h2>
            <form method="post" action="?route=/client/jobs/milestones/create" class="gh-milestone-form">
                <input type="hidden" name="csrf_token" value="<?= $escape($token) ?>">
                <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">

                <div>
                    <label for="title">Milestone Title <span>(required)</span></label>
                    <input type="text" id="title" name="title" required maxlength="150" value="<?= $escape($old['title'] ?? '') ?>" placeholder="e.g. Wireframes and UI Prototypes" <?= isset($errors['title']) ? 'aria-invalid="true"' : '' ?>>
                    <?php if (isset($errors['title'])): ?>
                        <p class="field-error"><?= $escape($errors['title']) ?></p>
                    <?php endif; ?>
                </div>

                <div>
                    <label for="due_date">Due Date <span>(required)</span></label>
                    <input type="date" id="due_date" name="due_date" required max="<?= $escape($job['deadline']) ?>" value="<?= $escape($old['due_date'] ?? '') ?>" aria-describedby="due-date-hint" <?= isset($errors['due_date']) ? 'aria-invalid="true"' : '' ?>>
                    <p class="hint" id="due-date-hint">Must be on or before the job delivery deadline (<?= $escape($job['deadline']) ?>).</p>
                    <?php if (isset($errors['due_date'])): ?>
                        <p class="field-error"><?= $escape($errors['due_date']) ?></p>
                    <?php endif; ?>
                </div>

                <div>
                    <label for="description">Deliverable Description <span>(optional)</span></label>
                    <textarea id="description" name="description" rows="4" maxlength="5000" placeholder="Describe the expected deliverables and completion criteria for this milestone."><?= $escape($old['description'] ?? '') ?></textarea>
                    <?php if (isset($errors['description'])): ?>
                        <p class="field-error"><?= $escape($errors['description']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="gh-milestone-actions">
                    <button class="gh-button gh-button-primary" type="submit">Add Milestone</button>
                </div>
            </form>
        </section>
    <?php endif; ?>
</main>
<?php require dirname(__DIR__, 2) . '/partials/footer.php'; ?>
</body>
</html>
