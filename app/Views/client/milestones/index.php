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
<main id="main-content" tabindex="-1" class="gh-milestones-layout">
    <p class="gh-milestones-eyebrow">CLIENT WORKSPACE</p>
    <h1>Milestones for <?= $escape($job['title']) ?></h1>
    <p>Define task and deliverable checkpoints for this project. At least one milestone is required before escrow funding. Milestones represent deliverable scope and do not carry monetary amounts; full payment is held in escrow and settled upon project completion.</p>
    <p><a href="?route=/client/jobs/bids&amp;id=<?= (int)$job['id'] ?>">Back to job bid review</a></p>

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
                <p>No milestones defined yet. Use the form below to create your first milestone checkpoint before funding the escrow.</p>
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

                    <div class="gh-milestone-actions">
                        <a class="gh-button gh-button-secondary" href="?route=/client/jobs/milestones/edit&amp;job_id=<?= (int)$job['id'] ?>&amp;id=<?= (int)$m['id'] ?>">Edit milestone</a>
                        <form method="post" action="?route=/client/jobs/milestones/delete" onsubmit="return confirm('Delete this milestone? Remaining milestones will be re-indexed.');">
                            <input type="hidden" name="csrf_token" value="<?= $escape($token) ?>">
                            <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
                            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                            <button class="gh-button gh-button-secondary" type="submit">Delete</button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

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
</main>
<?php require dirname(__DIR__, 2) . '/partials/footer.php'; ?>
</body>
</html>
