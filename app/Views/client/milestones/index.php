<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Job Milestones · GigHub</title>
    <?php require dirname(__DIR__, 2) . '/partials/page-shell.php'; ?>
    <link rel="stylesheet" href="assets/milestones.css?v=1">
</head>
<body class="gh-shell">
<?php require dirname(__DIR__, 2) . '/partials/header.php'; ?>
<?php
    $isLocked = $isLocked ?? false;
    $isClient = $isClient ?? true;
    $submissions = $submissions ?? [];
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
                            <?php if (in_array($m['status'], ['pending', 'revisions_requested'], true)): ?>
                                <a class="gh-button gh-button-primary" href="?route=/freelancer/milestones/submit&amp;job_id=<?= (int)$job['id'] ?>&amp;milestone_id=<?= (int)$m['id'] ?>">Submit Deliverable</a>
                            <?php elseif ($m['status'] === 'submitted'): ?>
                                <span class="gh-badge" style="background: #e0f2fe; color: #0369a1; padding: 0.4rem 0.75rem; border-radius: 4px; font-size: 0.875rem; font-weight: 600;">Deliverable submitted — Awaiting client review</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($isClient && $m['status'] === 'submitted'): ?>
                        <div style="margin-top: 1rem; padding: 0.75rem 1rem; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 4px; font-size: 0.875rem; color: #166534;">
                            <strong>Deliverable submitted by freelancer.</strong> Client review and milestone approval will be available in the next module.
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($milestoneSubs)): ?>
                        <div class="gh-submission-history-section" style="margin-top: 1.25rem; border-top: 1px solid var(--gh-border); padding-top: 1rem;">
                            <h4 style="margin: 0 0 0.75rem; font-size: 0.95rem; color: var(--gh-text);">Submitted Deliverables (<?= count($milestoneSubs) ?>)</h4>
                            <?php foreach ($milestoneSubs as $sub): ?>
                                <div class="gh-submission-history-item" style="background: var(--gh-header-background); padding: 0.875rem 1rem; border-radius: 4px; margin-bottom: 0.75rem; border-left: 3px solid var(--gh-primary);">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem; font-size: 0.8125rem;">
                                        <span style="font-weight: 600;">Attempt #<?= (int)$sub['id'] ?> · Status: <?= $escape(ucwords(str_replace('_', ' ', $sub['status']))) ?></span>
                                        <span style="color: var(--gh-muted);">Submitted (UTC): <?= $escape($sub['submitted_at'] ?? $sub['created_at']) ?></span>
                                    </div>
                                    <?php if (!empty($sub['deliverable_url'])): ?>
                                        <div style="font-size: 0.875rem; margin: 0.35rem 0; word-break: break-all;">
                                            <strong>Deliverable URL:</strong>
                                            <a href="<?= $escape($sub['deliverable_url']) ?>" target="_blank" rel="noopener noreferrer"><?= $escape($sub['deliverable_url']) ?></a>
                                        </div>
                                    <?php endif; ?>
                                    <div style="font-size: 0.875rem; margin-top: 0.35rem; white-space: pre-wrap; color: var(--gh-text);">
                                        <strong>Notes:</strong><br><?= nl2br($escape($sub['notes'])) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
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
