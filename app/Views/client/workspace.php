<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Client Workspace · GigHub</title>
    <?php require dirname(__DIR__) . '/partials/page-shell.php'; ?>
    <link rel="stylesheet" href="assets/client-workspace.css?v=<?= $assetVersion('client-workspace.css') ?>">
</head>
<body class="gh-shell">
<?php require dirname(__DIR__) . '/partials/header.php'; ?>

<main id="main-content" tabindex="-1" class="gh-workspace-layout">
    <?php require dirname(__DIR__) . '/partials/notifications.php'; ?>

    <header class="gh-workspace-header">
        <div class="gh-workspace-header-content">
            <p class="gh-workspace-eyebrow">CLIENT WORKSPACE</p>
            <h1>Welcome back, <?= $escape($user['display_name']) ?></h1>
            <p class="gh-workspace-intro">Manage your projects and collaborate with freelancers.</p>
        </div>
        <div class="gh-workspace-header-icon" aria-hidden="true">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="3" width="7" height="7"></rect>
                <rect x="14" y="3" width="7" height="7"></rect>
                <rect x="14" y="14" width="7" height="7"></rect>
                <rect x="3" y="14" width="7" height="7"></rect>
            </svg>
        </div>
    </header>

    <!-- 4 Dashboard Statistics Cards (Informational) -->
    <section class="gh-workspace-section gh-stats-section" aria-label="Dashboard statistics">
        <div class="gh-stats-grid">
            <!-- Total Jobs -->
            <div class="gh-stat-card">
                <div class="gh-stat-header">
                    <span class="gh-stat-label">Total Jobs</span>
                    <div class="gh-stat-icon-wrap" aria-hidden="true">
                        <svg class="gh-stat-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect>
                            <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>
                        </svg>
                    </div>
                </div>
                <div class="gh-stat-value"><?= (int)$statistics['total_jobs'] ?></div>
            </div>

            <!-- Open Jobs -->
            <div class="gh-stat-card">
                <div class="gh-stat-header">
                    <span class="gh-stat-label">Open Jobs</span>
                    <div class="gh-stat-icon-wrap" aria-hidden="true">
                        <svg class="gh-stat-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path>
                        </svg>
                    </div>
                </div>
                <div class="gh-stat-value"><?= (int)$statistics['open_jobs'] ?></div>
            </div>

            <!-- Active Projects -->
            <div class="gh-stat-card">
                <div class="gh-stat-header">
                    <span class="gh-stat-label">Active Projects</span>
                    <div class="gh-stat-icon-wrap" aria-hidden="true">
                        <svg class="gh-stat-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="22 7 13.5 15.5 8.5 10.5 2 17"></polyline>
                            <polyline points="16 7 22 7 22 13"></polyline>
                        </svg>
                    </div>
                </div>
                <div class="gh-stat-value"><?= (int)$statistics['active_projects'] ?></div>
            </div>

            <!-- Pending Reviews -->
            <div class="gh-stat-card">
                <div class="gh-stat-header">
                    <span class="gh-stat-label">Pending Reviews</span>
                    <div class="gh-stat-icon-wrap" aria-hidden="true">
                        <svg class="gh-stat-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="9 11 12 14 22 4"></polyline>
                            <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
                        </svg>
                    </div>
                </div>
                <div class="gh-stat-value"><?= (int)$statistics['pending_reviews'] ?></div>
            </div>
        </div>
    </section>

    <!-- Quick Actions (Interactive) -->
    <section class="gh-workspace-section" aria-labelledby="quick-actions-heading">
        <h2 id="quick-actions-heading" class="gh-workspace-section-title">Quick Actions</h2>
        <div class="gh-action-grid">
            <a class="gh-action-card" href="?route=/client/jobs/create">
                <div class="gh-action-icon-wrap" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="16"></line>
                        <line x1="8" y1="12" x2="16" y2="12"></line>
                    </svg>
                </div>
                <div class="gh-action-body">
                    <div class="gh-action-title">Post a Job</div>
                    <div class="gh-action-desc">Create a new project</div>
                </div>
            </a>

            <a class="gh-action-card" href="?route=/client/jobs">
                <div class="gh-action-icon-wrap" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="8" y1="6" x2="21" y2="6"></line>
                        <line x1="8" y1="12" x2="21" y2="12"></line>
                        <line x1="8" y1="18" x2="21" y2="18"></line>
                        <line x1="3" y1="6" x2="3.01" y2="6"></line>
                        <line x1="3" y1="12" x2="3.01" y2="12"></line>
                        <line x1="3" y1="18" x2="3.01" y2="18"></line>
                    </svg>
                </div>
                <div class="gh-action-body">
                    <div class="gh-action-title">My Jobs</div>
                    <div class="gh-action-desc">Manage posted jobs</div>
                </div>
            </a>

            <a class="gh-action-card" href="?route=/client/wallet">
                <div class="gh-action-icon-wrap" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"></path>
                        <path d="M3 5v14a2 2 0 0 0 2 2h16v-5"></path>
                        <path d="M18 12a2 2 0 0 0 0 4h4v-4z"></path>
                    </svg>
                </div>
                <div class="gh-action-body">
                    <div class="gh-action-title">My Wallet</div>
                    <div class="gh-action-desc">Manage balance &amp; payments</div>
                </div>
            </a>
        </div>
    </section>

    <!-- Recent Jobs Section (Compact & Information-Dense) -->
    <section class="gh-workspace-section" aria-labelledby="recent-jobs-heading">
        <div class="gh-section-header">
            <div>
                <h2 id="recent-jobs-heading" class="gh-workspace-section-title">Recent Jobs</h2>
                <p class="gh-workspace-section-desc">Manage your recent project postings, proposals, and deliverables.</p>
            </div>
            <?php if ($recentJobs !== []): ?>
                <a href="?route=/client/jobs" class="gh-link-subtle">View all jobs &rarr;</a>
            <?php endif; ?>
        </div>

        <?php if ($recentJobs === []): ?>
            <div class="gh-empty-card">
                <p class="gh-empty-title">No jobs posted yet</p>
                <p class="gh-empty-desc">You have not published any projects yet. Post your first job to start receiving bids from qualified freelancers.</p>
                <a href="?route=/client/jobs/create" class="gh-button gh-button-compact">+ Post Your First Job</a>
            </div>
        <?php else: ?>
            <div class="gh-jobs-grid">
                <?php foreach ($recentJobs as $job): ?>
                    <?php
                    $jobId = (int)$job['id'];
                    $status = (string)$job['status'];
                    $statusLabel = match ($status) {
                        'draft' => 'Draft',
                        'open' => 'Open',
                        'awarded' => 'Awarded',
                        'in_progress' => 'In Progress',
                        'under_review' => 'Under Review',
                        'completed' => 'Completed',
                        'cancelled' => 'Cancelled',
                        default => ucwords(str_replace('_', ' ', $status)),
                    };
                    $statusClass = match ($status) {
                        'open' => 'gh-badge-open',
                        'awarded', 'in_progress', 'under_review' => 'gh-badge-active',
                        'completed' => 'gh-badge-completed',
                        'cancelled' => 'gh-badge-cancelled',
                        default => 'gh-badge-draft',
                    };
                    $bidCount = (int)$job['bid_count'];
                    ?>
                    <article class="gh-client-job-card" aria-label="<?= $escape($job['title']) ?>">
                        <div class="gh-client-job-header">
                            <h3 class="gh-job-title">
                                <a href="?route=/client/jobs/show&amp;id=<?= $jobId ?>"><?= $escape($job['title']) ?></a>
                            </h3>
                            <span class="gh-status-badge <?= $statusClass ?>"><?= $escape($statusLabel) ?></span>
                        </div>

                        <div class="gh-client-job-meta">
                            <span class="gh-client-meta-item">
                                <span class="gh-client-meta-label">Budget:</span>
                                <span class="gh-client-meta-val">RM<?= $escape(number_format((float)$job['budget'], 2)) ?></span>
                            </span>
                            <?php if (!empty($job['deadline'])): ?>
                                <span class="gh-client-meta-sep" aria-hidden="true">&bull;</span>
                                <span class="gh-client-meta-item">
                                    <span class="gh-client-meta-label">Deadline:</span>
                                    <span class="gh-client-meta-val"><?= $escape((string)$job['deadline']) ?></span>
                                </span>
                            <?php endif; ?>
                            <span class="gh-client-meta-sep" aria-hidden="true">&bull;</span>
                            <span class="gh-client-meta-item">
                                <span class="gh-client-meta-label">Proposals:</span>
                                <span class="gh-client-proposal-pill"><?= $bidCount ?> <?= $bidCount === 1 ? 'bid' : 'bids' ?></span>
                            </span>
                        </div>

                        <div class="gh-client-job-actions">
                            <?php if ($status === 'open'): ?>
                                <a href="?route=/client/jobs/bids&amp;id=<?= $jobId ?>" class="gh-button gh-button-compact">Review Bids (<?= $bidCount ?>)</a>
                                <a href="?route=/client/jobs/show&amp;id=<?= $jobId ?>" class="gh-button gh-button-secondary gh-button-compact">View Job</a>
                            <?php elseif ($status === 'draft'): ?>
                                <a href="?route=/client/jobs/edit&amp;id=<?= $jobId ?>" class="gh-button gh-button-compact">Edit Draft</a>
                                <a href="?route=/client/jobs/show&amp;id=<?= $jobId ?>" class="gh-button gh-button-secondary gh-button-compact">View Draft</a>
                            <?php elseif ($status === 'awarded'): ?>
                                <a href="?route=/client/jobs/fund&amp;job_id=<?= $jobId ?>" class="gh-button gh-button-compact">Fund Escrow</a>
                                <a href="?route=/client/jobs/milestones&amp;job_id=<?= $jobId ?>" class="gh-button gh-button-secondary gh-button-compact">Milestones</a>
                            <?php elseif (in_array($status, ['in_progress', 'under_review'], true)): ?>
                                <a href="?route=/client/jobs/milestones&amp;job_id=<?= $jobId ?>" class="gh-button gh-button-compact">Manage Milestones</a>
                                <a href="?route=/client/jobs/show&amp;id=<?= $jobId ?>" class="gh-button gh-button-secondary gh-button-compact">View Job</a>
                            <?php else: ?>
                                <a href="?route=/client/jobs/show&amp;id=<?= $jobId ?>" class="gh-button gh-button-secondary gh-button-compact">View Details</a>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
</body>
</html>
