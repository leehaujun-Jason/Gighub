<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Edit Milestone · GigHub</title>
    <?php require dirname(__DIR__, 2) . '/partials/page-shell.php'; ?>
    <link rel="stylesheet" href="assets/milestones.css?v=<?= $assetVersion('milestones.css') ?>">
</head>
<body class="gh-shell">
<?php require dirname(__DIR__, 2) . '/partials/header.php'; ?>
<main id="main-content" tabindex="-1" class="gh-milestones-layout">
    <p class="gh-milestones-eyebrow">CLIENT WORKSPACE</p>
    <h1>Edit Milestone (Position <?= (int)$milestone['position'] ?>)</h1>
    <p>Editing milestone for <strong><?= $escape($job['title']) ?></strong>.</p>
    <p><a href="?route=/client/jobs/milestones&amp;job_id=<?= (int)$job['id'] ?>">Back to milestones list</a></p>

    <?php if (isset($errors['form'])): ?>
        <div class="gh-milestones-error" role="alert"><p><?= $escape($errors['form']) ?></p></div>
    <?php endif; ?>

    <section class="gh-milestone-card" aria-label="Edit milestone form">
        <form method="post" action="?route=/client/jobs/milestones/update" class="gh-milestone-form">
            <input type="hidden" name="csrf_token" value="<?= $escape($token) ?>">
            <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
            <input type="hidden" name="id" value="<?= (int)$milestone['id'] ?>">

            <div>
                <label for="title">Milestone Title <span>(required)</span></label>
                <input type="text" id="title" name="title" required maxlength="150" value="<?= $escape($old['title'] ?? $milestone['title']) ?>" <?= isset($errors['title']) ? 'aria-invalid="true"' : '' ?>>
                <?php if (isset($errors['title'])): ?>
                    <p class="field-error"><?= $escape($errors['title']) ?></p>
                <?php endif; ?>
            </div>

            <div>
                <label for="due_date">Due Date <span>(required)</span></label>
                <input type="date" id="due_date" name="due_date" required max="<?= $escape($job['deadline']) ?>" value="<?= $escape($old['due_date'] ?? $milestone['due_date']) ?>" aria-describedby="due-date-hint" <?= isset($errors['due_date']) ? 'aria-invalid="true"' : '' ?>>
                <p class="hint" id="due-date-hint">Must be on or before the job delivery deadline (<?= $escape($job['deadline']) ?>).</p>
                <?php if (isset($errors['due_date'])): ?>
                    <p class="field-error"><?= $escape($errors['due_date']) ?></p>
                <?php endif; ?>
            </div>

            <div>
                <label for="description">Deliverable Description <span>(optional)</span></label>
                <textarea id="description" name="description" rows="4" maxlength="5000"><?= $escape($old['description'] ?? ($milestone['description'] ?? '')) ?></textarea>
                <?php if (isset($errors['description'])): ?>
                    <p class="field-error"><?= $escape($errors['description']) ?></p>
                <?php endif; ?>
            </div>

            <div class="gh-milestone-actions">
                <button class="gh-button gh-button-primary" type="submit">Save Changes</button>
                <a class="gh-button gh-button-secondary" href="?route=/client/jobs/milestones&amp;job_id=<?= (int)$job['id'] ?>">Cancel</a>
            </div>
        </form>
    </section>
</main>
<?php require dirname(__DIR__, 2) . '/partials/footer.php'; ?>
</body>
</html>
