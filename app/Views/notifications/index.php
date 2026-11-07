<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Notifications · GigHub</title>
    <?php require dirname(__DIR__) . '/partials/page-shell.php'; ?>
    <link rel="stylesheet" href="assets/notifications.css?v=1">
</head>
<body class="gh-shell">
<?php require dirname(__DIR__) . '/partials/header.php'; ?>

<main id="main-content" tabindex="-1" class="gh-notifications-layout">
    <div class="gh-notifications-header">
        <div>
            <p class="gh-notifications-eyebrow">PERSISTENT NOTIFICATION CENTER</p>
            <h1 class="gh-notifications-title">Notifications</h1>
            <p class="gh-notifications-subtitle">Stay up to date with activity on your proposals, milestones, and payments.</p>
        </div>
        <?php if ($unreadCount > 0): ?>
            <div class="gh-notifications-header-actions">
                <form method="post" action="?route=/notifications/read-all" class="gh-mark-all-form">
                    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                    <button type="submit" class="gh-button gh-button-secondary gh-btn-compact">
                        <svg class="gh-btn-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        <span>Mark all as read</span>
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <!-- Filter Tabs -->
    <nav class="gh-notif-tabs" aria-label="Notification filters">
        <a class="gh-notif-tab <?= $filter === 'all' ? 'is-active' : '' ?>" href="?route=/notifications">
            All <span class="gh-notif-tab-count"><?= (int)$allCount ?></span>
        </a>
        <a class="gh-notif-tab <?= $filter === 'unread' ? 'is-active' : '' ?>" href="?route=/notifications&amp;filter=unread">
            Unread <?php if ($unreadCount > 0): ?><span class="gh-notif-tab-badge"><?= (int)$unreadCount ?></span><?php endif; ?>
        </a>
    </nav>

    <!-- Notification Feed -->
    <div class="gh-notif-feed">
        <?php if ($notifications === []): ?>
            <section class="gh-notif-empty-card">
                <div class="gh-notif-empty-icon" aria-hidden="true">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                    </svg>
                </div>
                <h2><?= $filter === 'unread' ? "You're all caught up!" : 'No notifications yet' ?></h2>
                <p><?= $filter === 'unread' ? 'There are no unread notifications to review right now.' : 'Activity and updates about your jobs, proposals, and milestones will appear here.' ?></p>
                <?php if (($navigationUser['role'] ?? '') === 'freelancer'): ?>
                    <a class="gh-button gh-button-primary" href="?route=/jobs">Browse Open Jobs</a>
                <?php else: ?>
                    <a class="gh-button gh-button-primary" href="?route=/client/jobs">Manage My Jobs</a>
                <?php endif; ?>
            </section>
        <?php else: ?>
            <ul class="gh-notif-list" role="list">
                <?php foreach ($notifications as $n): ?>
                    <li class="gh-notif-item <?= $n['is_unread'] ? 'is-unread' : 'is-read' ?>" id="notification-<?= (int)$n['id'] ?>">
                        <div class="gh-notif-icon-col" aria-hidden="true">
                            <?php if (str_contains($n['type'], 'payment') || str_contains($n['type'], 'escrow')): ?>
                                <span class="gh-notif-type-icon is-payment">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"></rect><line x1="2" x2="22" y1="10" y2="10"></line></svg>
                                </span>
                            <?php elseif (str_contains($n['type'], 'review')): ?>
                                <span class="gh-notif-type-icon is-review">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                                </span>
                            <?php elseif (str_contains($n['type'], 'award')): ?>
                                <span class="gh-notif-type-icon is-award">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="6"></circle><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"></path></svg>
                                </span>
                            <?php elseif (str_contains($n['type'], 'submission') || str_contains($n['type'], 'milestone') || str_contains($n['type'], 'revision')): ?>
                                <span class="gh-notif-type-icon is-milestone">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" x2="8" y1="13" y2="13"></line><line x1="16" x2="8" y1="17" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                                </span>
                            <?php else: ?>
                                <span class="gh-notif-type-icon is-default">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="gh-notif-content-col">
                            <div class="gh-notif-message-row">
                                <p class="gh-notif-message"><?= $escape($n['message']) ?></p>
                                <?php if ($n['is_unread']): ?>
                                    <span class="gh-notif-unread-dot" title="Unread notification" aria-label="Unread"></span>
                                <?php endif; ?>
                            </div>
                            <div class="gh-notif-meta-row">
                                <time class="gh-notif-time" datetime="<?= $escape($n['created_at']) ?>" title="<?= $escape($n['created_at']) ?> UTC">
                                    <?= $escape($n['relative_time']) ?>
                                </time>
                                <?php if (!empty($n['job_title'])): ?>
                                    <span class="gh-notif-dot-sep">•</span>
                                    <span class="gh-notif-job-title"><?= $escape($n['job_title']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="gh-notif-actions-col">
                            <?php if ($n['destination_url'] !== null): ?>
                                <form method="post" action="?route=/notifications/read" class="gh-notif-inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                    <input type="hidden" name="notification_id" value="<?= (int)$n['id'] ?>">
                                    <input type="hidden" name="redirect" value="target">
                                    <button type="submit" class="gh-notif-action-btn">
                                        <span><?= $escape($n['action_label']) ?></span>
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                                    </button>
                                </form>
                            <?php endif; ?>

                            <?php if ($n['is_unread']): ?>
                                <form method="post" action="?route=/notifications/read" class="gh-notif-inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                    <input type="hidden" name="notification_id" value="<?= (int)$n['id'] ?>">
                                    <input type="hidden" name="current_filter" value="<?= $escape($filter) ?>">
                                    <button type="submit" class="gh-notif-dismiss-btn" title="Mark as read" aria-label="Mark notification as read">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                        <span class="gh-sr-only">Mark as read</span>
                                    </button>
                                </form>
                            <?php else: ?>
                                <span class="gh-notif-read-status" aria-label="Already read">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                </span>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <nav class="gh-notif-pagination" aria-label="Notification pages">
            <?php if ($page > 1): ?>
                <a class="gh-button gh-button-secondary gh-btn-compact" href="?route=/notifications<?= $filter === 'unread' ? '&amp;filter=unread' : '' ?>&amp;page=<?= $page - 1 ?>">
                    &larr; Previous
                </a>
            <?php endif; ?>
            <span class="gh-notif-page-indicator">Page <?= (int)$page ?> of <?= (int)$totalPages ?></span>
            <?php if ($hasNext): ?>
                <a class="gh-button gh-button-secondary gh-btn-compact" href="?route=/notifications<?= $filter === 'unread' ? '&amp;filter=unread' : '' ?>&amp;page=<?= $page + 1 ?>">
                    Next &rarr;
                </a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</main>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
</body>
</html>
