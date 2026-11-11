<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Admin Workspace · Disputes · GigHub</title>
    <?php require dirname(__DIR__) . '/partials/page-shell.php'; ?>
    <link rel="stylesheet" href="assets/admin.css?v=2">
</head>
<body class="gh-shell">
<?php require dirname(__DIR__) . '/partials/header.php'; ?>

<main id="main-content" tabindex="-1" class="gh-admin-layout">
    <?php require dirname(__DIR__) . '/partials/notifications.php'; ?>

    <p class="gh-admin-eyebrow">ADMINISTRATIVE MEDIATION</p>
    <h1 class="gh-admin-title">Dispute Adjudication Queue</h1>

    <div class="gh-admin-tabs" role="tablist">
        <a href="?route=/admin&amp;status=open" class="gh-admin-tab <?= ($currentStatus ?? 'open') === 'open' ? 'active' : '' ?>">
            Open Disputes
            <?php if (($openCount ?? 0) > 0): ?>
                <span class="gh-admin-badge"><?= (int)$openCount ?></span>
            <?php endif; ?>
        </a>
        <a href="?route=/admin&amp;status=resolved" class="gh-admin-tab <?= ($currentStatus ?? '') === 'resolved' ? 'active' : '' ?>">
            Resolved History
        </a>
    </div>

    <div class="gh-admin-card" style="padding:0;overflow:hidden;">
        <?php if (empty($disputes)): ?>
            <div class="gh-admin-empty">
                <span class="gh-admin-empty-icon" aria-hidden="true">🛡️</span>
                <p class="gh-admin-empty-title">No <?= $escape($currentStatus ?? 'open') ?> disputes found</p>
                <p class="gh-admin-empty-desc">All contracts are operating normally or have already been mediated by administrators.</p>
            </div>
        <?php else: ?>
            <div class="gh-admin-table-container">
                <table class="gh-admin-table">
                    <thead>
                        <tr>
                            <th class="gh-col-id">ID</th>
                            <th class="gh-col-job">Contract / Job</th>
                            <th class="gh-col-parties">Parties</th>
                            <th class="gh-col-raiser">Raised By</th>
                            <th class="gh-col-escrow">Escrow</th>
                            <th class="gh-col-status">Status</th>
                            <th class="gh-col-date">Filed Date</th>
                            <th class="gh-col-action">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($disputes as $d): ?>
                            <tr>
                                <td class="gh-col-id">
                                    <span class="gh-dispute-id">#<?= (int)$d['id'] ?></span>
                                </td>
                                <td class="gh-col-job">
                                    <div class="gh-job-title-clamp" title="<?= $escape($d['job_title']) ?>">
                                        <?= $escape($d['job_title']) ?>
                                    </div>
                                    <div class="gh-job-subtext">Job #<?= (int)$d['job_id'] ?></div>
                                </td>
                                <td class="gh-col-parties">
                                    <div class="gh-party-cell">
                                        <div class="gh-party-row">
                                            <span class="gh-party-role">Client:</span>
                                            <strong class="gh-party-name"><?= $escape($d['client_name']) ?></strong>
                                        </div>
                                        <div class="gh-party-row">
                                            <span class="gh-party-role">Freelancer:</span>
                                            <strong class="gh-party-name"><?= $escape($d['freelancer_name'] ?? 'Assigned') ?></strong>
                                        </div>
                                    </div>
                                </td>
                                <td class="gh-col-raiser">
                                    <span class="gh-raiser-badge"><?= $escape($d['raiser_name']) ?></span>
                                </td>
                                <td class="gh-col-escrow">
                                    <strong class="gh-escrow-amount">RM<?= number_format((float)($d['escrow_amount'] ?? 0), 2) ?></strong>
                                </td>
                                <td class="gh-col-status">
                                    <span class="gh-status-pill gh-status-<?= $d['status'] === 'open' ? 'open' : 'resolved' ?>">
                                        <?= $escape($d['status']) ?>
                                    </span>
                                </td>
                                <td class="gh-col-date">
                                    <span class="gh-date-text"><?= $escape(substr((string)$d['created_at'], 0, 10)) ?></span>
                                </td>
                                <td class="gh-col-action">
                                    <a href="?route=/admin/disputes/show&amp;id=<?= (int)$d['id'] ?>" class="gh-btn-review">
                                        Review Case
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
</body>
</html>
