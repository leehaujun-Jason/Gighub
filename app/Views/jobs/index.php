<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $errors !== [] ? 'Error: ' : '' ?>Browse jobs · GigHub</title>
    <link rel="stylesheet" href="assets/design-tokens.css?v=<?= $assetVersion('design-tokens.css') ?>">
    <link rel="stylesheet" href="assets/components.css?v=<?= $assetVersion('components.css') ?>">
    <link rel="stylesheet" href="assets/shared-shell.css?v=<?= $assetVersion('shared-shell.css') ?>">
    <link rel="stylesheet" href="assets/jobs.css?v=<?= $assetVersion('jobs.css') ?>">
    <link rel="stylesheet" href="assets/job-browsing.css?v=<?= $assetVersion('job-browsing.css') ?>">
    <link rel="stylesheet" href="assets/matching.css?v=<?= $assetVersion('matching.css') ?>">
    <script src="assets/browse-jobs.js?v=<?= $assetVersion('browse-jobs.js') ?>" defer></script>
</head>
<body class="gh-shell">
<?php require dirname(__DIR__) . '/partials/header.php'; ?>

<?php
    $isFreelancer = ($navigationUser['role'] ?? '') === 'freelancer';
    $isClient = ($navigationUser['role'] ?? '') === 'client';
    $isGuest = $navigationUser === null;

    $sortLabels = [
        'newest' => 'Newest first',
        'oldest' => 'Oldest first',
        'budget_high' => 'Highest first',
        'budget_low' => 'Lowest first',
    ];
    $currentSortLabel = $sortLabels[$filters['sort']] ?? 'Newest first';

    $activeFilterCount = ($filters['min_budget'] !== '' ? 1 : 0) +
                         ($filters['max_budget'] !== '' ? 1 : 0) +
                         count($filters['skills']);
    $totalFound = (int)($totalCount ?? count($jobs));
?>

<main id="main-content" class="jobs-layout" tabindex="-1">
    <div class="jobs-heading">
        <p class="eyebrow">FIND YOUR NEXT PROJECT</p>
        <h1>Browse jobs</h1>
        <p>Explore projects that match your skills.</p>
    </div>

    <!-- Compact Toolbar -->
    <div class="gh-browse-toolbar" aria-label="Job search and toolbar">
        <form method="get" action="index.php" id="gh-browse-form" class="gh-browse-form">
            <input type="hidden" name="route" value="/jobs">
            <input type="hidden" name="page" id="gh-input-page" value="<?= (int)$filters['page'] ?>">
            <input type="hidden" name="min_budget" id="gh-input-min-budget" value="<?= $escape($filters['min_budget']) ?>">
            <input type="hidden" name="max_budget" id="gh-input-max-budget" value="<?= $escape($filters['max_budget']) ?>">
            <input type="hidden" name="sort" id="gh-input-sort" value="<?= $escape($filters['sort']) ?>">
            <div id="gh-hidden-skills-container">
                <?php foreach ($filters['skills'] as $skillId): ?>
                    <input type="hidden" name="skills[]" value="<?= (int)$skillId ?>">
                <?php endforeach; ?>
            </div>

            <div class="gh-search-wrapper">
                <span class="gh-search-icon" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                </span>
                <label for="q" class="visually-hidden">Search jobs</label>
                <input type="search" id="q" name="q" value="<?= $escape($filters['q']) ?>" placeholder="Search jobs..." maxlength="100" autocomplete="off" aria-label="Search jobs by title and description">
                <button type="submit" class="visually-hidden" tabindex="-1">Search</button>
            </div>
        </form>

        <div class="gh-toolbar-actions">
            <!-- Filter Button -->
            <button type="button" class="gh-button-toolbar<?= $activeFilterCount > 0 ? ' is-active' : '' ?>" id="gh-open-filter-modal" aria-haspopup="dialog" aria-controls="gh-filter-modal">
                <svg class="gh-toolbar-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" y1="21" x2="4" y2="14"></line><line x1="4" y1="10" x2="4" y2="3"></line><line x1="12" y1="21" x2="12" y2="12"></line><line x1="12" y1="8" x2="12" y2="3"></line><line x1="20" y1="21" x2="20" y2="16"></line><line x1="20" y1="12" x2="20" y2="3"></line><line x1="1" y1="14" x2="7" y2="14"></line><line x1="9" y1="8" x2="15" y2="8"></line><line x1="17" y1="16" x2="23" y2="16"></line></svg>
                <span>Filter</span>
                <span class="gh-filter-badge<?= $activeFilterCount > 0 ? '' : ' gh-hidden' ?>" id="gh-filter-badge">(<?= $activeFilterCount ?>)</span>
            </button>

            <!-- Sort Dropdown Menu Wrapper -->
            <div class="gh-sort-wrapper">
                <button type="button" class="gh-button-toolbar" id="gh-sort-btn" aria-haspopup="true" aria-expanded="false" aria-controls="gh-sort-menu">
                    <svg class="gh-toolbar-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 16 4 4 4-4"></path><path d="M7 20V4"></path><path d="m21 8-4-4-4 4"></path><path d="M17 4v16"></path></svg>
                    <span>Sort</span>
                    <svg class="gh-chevron-icon" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"></path></svg>
                </button>

                <div class="gh-sort-menu" id="gh-sort-menu" role="menu" aria-label="Sort options" hidden>
                    <div class="gh-sort-group">
                        <span class="gh-sort-group-title">Date posted</span>
                        <label class="gh-sort-option">
                            <input type="radio" name="sort_option" value="newest"<?= $filters['sort'] === 'newest' ? ' checked' : '' ?>>
                            <span>Newest first</span>
                        </label>
                        <label class="gh-sort-option">
                            <input type="radio" name="sort_option" value="oldest"<?= $filters['sort'] === 'oldest' ? ' checked' : '' ?>>
                            <span>Oldest first</span>
                        </label>
                    </div>
                    <div class="gh-sort-divider"></div>
                    <div class="gh-sort-group">
                        <span class="gh-sort-group-title">Budget</span>
                        <label class="gh-sort-option">
                            <input type="radio" name="sort_option" value="budget_high"<?= $filters['sort'] === 'budget_high' ? ' checked' : '' ?>>
                            <span>Highest first</span>
                        </label>
                        <label class="gh-sort-option">
                            <input type="radio" name="sort_option" value="budget_low"<?= $filters['sort'] === 'budget_low' ? ' checked' : '' ?>>
                            <span>Lowest first</span>
                        </label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Subheader Stats Bar -->
    <div class="gh-browse-stats-bar">
        <span id="browse-count" class="gh-browse-count" aria-live="polite">
            <?= $totalFound ?> job<?= $totalFound === 1 ? '' : 's' ?> found
        </span>
        <span id="browse-sort-label" class="gh-browse-sort-label">
            <?= $escape($currentSortLabel) ?>
        </span>
    </div>

    <!-- Job Results Container -->
    <section id="browse-results" aria-label="Job results" tabindex="-1">
        <?php if ($errors !== []): ?>
            <div class="error-summary" role="alert">
                <h2>Check your search filters</h2>
                <ul>
                    <?php foreach ($errors as $field => $message): ?>
                        <li><?= $escape($message) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php elseif ($jobs === []): ?>
            <div class="browse-empty">
                <h2>No jobs found</h2>
                <p>Try changing your search or clearing the filters.</p>
            </div>
        <?php else: ?>
            <div class="gh-recommendations-list" aria-label="Browse jobs list">
                <?php foreach ($jobs as $job): ?>
                    <article class="card jobs-card gh-job-card gh-recommendation-card gh-browse-card" data-job-id="<?= (int)$job['id'] ?>">
                        <div class="gh-recommendation-header">
                            <div class="gh-recommendation-title-wrap">
                                <h3 class="gh-recommendation-title">
                                    <a class="gh-job-title" href="?route=/jobs/show&amp;id=<?= (int)$job['id'] ?>"><?= $escape($job['title']) ?></a>
                                </h3>
                                <p class="gh-recommendation-client">Posted by <?= $escape($job['client_name'] ?? 'Client') ?></p>
                            </div>
                            <?php if ($isFreelancer && isset($job['match'])): ?>
                                <?php
                                    $matchVal = (float)$job['match']['percentage_value'];
                                    $tier = $job['match']['meets_threshold'] ? ($matchVal >= 70.0 ? 'high' : ($matchVal >= 40.0 ? 'med' : 'low')) : 'sub';
                                ?>
                                <div class="gh-recommendation-badge-wrap">
                                    <span class="gh-match-pill gh-match-pill--<?= $tier ?>" title="Jaccard similarity: <?= $escape($job['match']['percentage']) ?>">
                                        <?= $escape($job['match']['percentage']) ?> Match
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <p class="gh-recommendation-desc">
                            <?= $escape(mb_substr($job['description'], 0, 180, 'UTF-8') . (mb_strlen($job['description'], 'UTF-8') > 180 ? '…' : '')) ?>
                        </p>

                        <dl class="gh-recommendation-meta">
                            <div>
                                <dt>Advertised Budget</dt>
                                <dd>RM<?= $escape(number_format((float)$job['budget'], 2)) ?></dd>
                            </div>
                            <div>
                                <dt>Delivery Deadline</dt>
                                <dd>
                                    <?= $escape($job['deadline']) ?>
                                    <?php if ($job['deadline'] < $today): ?>
                                        <span class="browse-warning-tag">Delivery deadline has passed</span>
                                    <?php endif; ?>
                                </dd>
                            </div>
                        </dl>

                        <div class="gh-recommendation-skills">
                            <ul class="gh-skills-tags" aria-label="Required skills">
                                <?php foreach ($job['skills'] as $skill): ?>
                                    <?php if ($isFreelancer && ($skill['is_matched'] ?? false)): ?>
                                        <li class="gh-skill-tag--matched" title="You have this skill">✓ <?= $escape($skill['name']) ?></li>
                                    <?php else: ?>
                                        <li class="gh-skill-tag--unmatched"><?= $escape($skill['name']) ?></li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                        </div>

                        <div class="gh-recommendation-footer">
                            <?php if ($isFreelancer): ?>
                                <?php if (!empty($job['bid_status'])): ?>
                                    <span class="gh-bid-badge gh-bid-badge--<?= $escape($job['bid_status']) ?>">
                                        [Bid Placed: <?= $escape(ucfirst($job['bid_status'])) ?>]
                                    </span>
                                    <a class="gh-button gh-button-secondary" href="?route=/jobs/show&amp;id=<?= (int)$job['id'] ?>">View Job</a>
                                <?php else: ?>
                                    <a class="gh-button gh-button-primary" href="?route=/jobs/show&amp;id=<?= (int)$job['id'] ?>">View Job &amp; Submit Bid</a>
                                <?php endif; ?>
                            <?php elseif ($isClient): ?>
                                <a class="gh-button gh-button-secondary" href="?route=/jobs/show&amp;id=<?= (int)$job['id'] ?>">View Job Details</a>
                            <?php else: /* Guest */ ?>
                                <button type="button" class="gh-button gh-button-primary gh-guest-bid-btn" data-job-id="<?= (int)$job['id'] ?>">View Job &amp; Submit Bid</button>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php if ($jobs !== [] || $filters['page'] > 1): ?>
                <nav class="job-pagination" aria-label="Job pages">
                    <?php if ($filters['page'] > 1): ?>
                        <a href="<?= $escape($pageUrl($filters['page'] - 1)) ?>" data-page="<?= $filters['page'] - 1 ?>">Previous</a>
                    <?php endif; ?>
                    <span>Page <?= $filters['page'] ?></span>
                    <?php if ($hasNext): ?>
                        <a href="<?= $escape($pageUrl($filters['page'] + 1)) ?>" data-page="<?= $filters['page'] + 1 ?>">Next</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</main>

<!-- Filter Modal Dialog -->
<dialog id="gh-filter-modal" class="gh-modal" aria-labelledby="gh-filter-modal-title" aria-modal="true">
    <div class="gh-modal-card">
        <div class="gh-modal-header">
            <h2 id="gh-filter-modal-title" class="gh-modal-title">Filter Jobs</h2>
            <button type="button" class="gh-modal-close" id="gh-filter-close-btn" aria-label="Close dialog">&times;</button>
        </div>

        <div class="gh-modal-body">
            <!-- Budget Range -->
            <div class="gh-modal-section">
                <h3 class="gh-modal-section-title">Budget Range</h3>
                <div class="gh-modal-budget-grid">
                    <div>
                        <label for="modal-min-budget">Minimum budget (RM)</label>
                        <input type="text" inputmode="decimal" id="modal-min-budget" placeholder="100.00" value="<?= $escape($filters['min_budget']) ?>" autocomplete="off">
                    </div>
                    <div>
                        <label for="modal-max-budget">Maximum budget (RM)</label>
                        <input type="text" inputmode="decimal" id="modal-max-budget" placeholder="10000.00" value="<?= $escape($filters['max_budget']) ?>" autocomplete="off">
                    </div>
                </div>
                <p class="field-error" id="modal-budget-error" style="display: none;"></p>
            </div>

            <!-- Skills Section -->
            <div class="gh-modal-section">
                <div class="gh-modal-skills-header">
                    <h3 class="gh-modal-section-title">Skills</h3>
                    <span class="gh-selected-counter" id="modal-skills-counter"><strong><?= count($filters['skills']) ?></strong>/10 selected</span>
                </div>
                <p class="gh-modal-hint">Choose up to 10 skills. Jobs matching any selected skill are included.</p>

                <div class="gh-modal-skills-scroll" role="group" aria-label="Select skills to filter">
                    <div class="gh-modal-skills-grid">
                        <?php foreach ($skills as $skill): ?>
                            <?php
                                $sId = (int)$skill['id'];
                                $isSel = in_array($sId, $filters['skills'], true);
                            ?>
                            <label class="gh-modal-skill-item">
                                <input type="checkbox" name="modal_skills[]" value="<?= $sId ?>"<?= $isSel ? ' checked' : '' ?>>
                                <span><?= $escape($skill['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <p class="field-error" id="modal-skills-error" style="display: none;"></p>
            </div>
        </div>

        <div class="gh-modal-footer">
            <button type="button" class="gh-link-button" id="gh-filter-clear-all-btn">Clear all</button>
            <div class="gh-modal-footer-actions">
                <button type="button" class="gh-button gh-button-secondary" id="gh-filter-cancel-btn">Cancel</button>
                <button type="button" class="gh-button gh-button-primary" id="gh-filter-apply-btn">Apply Filters</button>
            </div>
        </div>
    </div>
</dialog>

<!-- Guest Login Prompt Modal Dialog -->
<dialog id="gh-guest-login-modal" class="gh-modal" aria-labelledby="gh-guest-modal-title" aria-modal="true">
    <div class="gh-modal-card gh-modal-card--narrow">
        <div class="gh-modal-header">
            <h2 id="gh-guest-modal-title" class="gh-modal-title">Sign In Required</h2>
            <button type="button" class="gh-modal-close" id="gh-guest-close-btn" aria-label="Close dialog">&times;</button>
        </div>
        <div class="gh-modal-body">
            <p class="gh-guest-modal-msg">Please sign in as a freelancer to view bids or submit a bid.</p>
        </div>
        <div class="gh-modal-footer">
            <button type="button" class="gh-button gh-button-secondary" id="gh-guest-cancel-btn">Cancel</button>
            <a class="gh-button gh-button-primary" href="?route=/login">Log In</a>
        </div>
    </div>
</dialog>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
</body>
</html>
