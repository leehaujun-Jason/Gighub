<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Messages · GigHub</title>
    <?php require dirname(__DIR__) . '/partials/page-shell.php'; ?>
    <link rel="stylesheet" href="assets/messages.css?v=1">
</head>
<body class="gh-shell">
<?php require dirname(__DIR__) . '/partials/header.php'; ?>

<main id="main-content" tabindex="-1" class="gh-messages-layout">
    <div class="gh-messages-header">
        <p class="gh-messages-eyebrow">COMMUNICATION</p>
        <h1 class="gh-messages-title">Messages</h1>
        <p class="gh-messages-subtitle">Direct conversations between clients and freelancers on proposals and active contracts.</p>
    </div>

    <?php if (empty($conversations)): ?>
        <section class="gh-empty-card" aria-label="No messages">
            <div class="gh-empty-icon" aria-hidden="true">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                </svg>
            </div>
            <h2>No conversations yet</h2>
            <p>Conversations begin automatically when you or your collaborator exchange messages on a proposal or project.</p>
            <div class="gh-empty-actions">
                <?php if ($userRole === 'client'): ?>
                    <a class="gh-button gh-button-primary" href="?route=/client/jobs">Manage My Jobs</a>
                <?php else: ?>
                    <a class="gh-button gh-button-primary" href="?route=/freelancer/bids">View My Bids</a>
                <?php endif; ?>
            </div>
        </section>
    <?php else: ?>
        <div class="gh-inbox-list" role="region" aria-label="Conversations list">
            <?php foreach ($conversations as $conv): ?>
                <?php
                $hasUnread = (int)$conv['unread_count'] > 0;
                $cardClasses = 'gh-inbox-card' . ($hasUnread ? ' gh-inbox-card-unread' : '');
                ?>
                <article class="<?= $cardClasses ?>">
                    <div class="gh-inbox-card-header">
                        <div class="gh-inbox-counterpart">
                            <span class="gh-counterpart-name"><?= $escape($conv['counterpart_name']) ?></span>
                            <span class="gh-role-pill"><?= $escape(ucfirst($conv['counterpart_role'])) ?></span>
                            <span class="gh-status-pill"><?= $escape($conv['status_label']) ?></span>
                        </div>
                        <time class="gh-inbox-time" datetime="<?= $escape($conv['latest_message_time']) ?>" title="<?= $escape($conv['malaysia_time']) ?>">
                            <?= $escape($conv['relative_time']) ?>
                        </time>
                    </div>

                    <div class="gh-inbox-job">
                        <span class="gh-job-label">Job:</span>
                        <span class="gh-job-title"><?= $escape($conv['job_title']) ?></span>
                    </div>

                    <p class="gh-inbox-preview">
                        <?= $escape(mb_strimwidth((string)$conv['latest_message_body'], 0, 140, '...')) ?>
                    </p>

                    <div class="gh-inbox-footer">
                        <?php if ($hasUnread): ?>
                            <span class="gh-unread-count-pill" aria-label="<?= (int)$conv['unread_count'] ?> unread messages">
                                <?= (int)$conv['unread_count'] ?> unread
                            </span>
                        <?php else: ?>
                            <span class="gh-read-indicator">Read</span>
                        <?php endif; ?>

                        <a class="gh-button gh-button-secondary gh-open-chat-btn" href="<?= $escape($conv['thread_url']) ?>">
                            Open Chat
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
</body>
</html>
