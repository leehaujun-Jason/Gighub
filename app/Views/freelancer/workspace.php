<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Freelancer Workspace · GigHub</title>
    <?php require dirname(__DIR__) . '/partials/page-shell.php'; ?>
    <link rel="stylesheet" href="assets/matching.css?v=<?= $assetVersion('matching.css') ?>">
    <script src="assets/workspace.js?v=<?= $assetVersion('workspace.js') ?>" defer></script>
</head>
<body class="gh-shell">
<?php require dirname(__DIR__) . '/partials/header.php'; ?>

<?php
    $freelancerSkillIds = array_column($freelancerSkills, 'id');
    $allSkills = $allSkills ?? [];
    $skillsCsrfToken = $skillsCsrfToken ?? '';
?>

<main id="main-content" tabindex="-1" class="gh-workspace-layout">
    <?php require dirname(__DIR__) . '/partials/notifications.php'; ?>

    <div class="gh-workspace-header">
        <p class="gh-workspace-eyebrow">FREELANCER WORKSPACE</p>
        <h1>Welcome, <?= $escape($user['display_name']) ?></h1>
        <p class="gh-workspace-intro">Explore deterministic skill-matched job recommendations, track your bids, and manage your freelance career.</p>
    </div>

    <nav class="gh-workspace-nav" aria-label="Freelancer shortcuts">
        <a class="gh-workspace-nav-item" href="?route=/freelancer/bids">My Bids</a>
        <a class="gh-workspace-nav-item" href="?route=/freelancer/wallet">My Wallet</a>
        <a class="gh-workspace-nav-item" href="?route=/freelancer/profile">View Profile</a>
        <a class="gh-workspace-nav-item" href="?route=/freelancer/profile/edit">Edit Profile &amp; Skills</a>
        <a class="gh-workspace-nav-item" href="?route=/jobs">Browse All Jobs</a>
    </nav>

    <section class="gh-workspace-section gh-skills-section" aria-labelledby="skills-summary-heading">
        <div class="gh-section-header">
            <h2 id="skills-summary-heading">Your Registered Skills (<?= count($freelancerSkills) ?>)</h2>
            <button type="button" class="gh-link-subtle gh-button-reset" id="gh-open-skills-modal" aria-haspopup="dialog" aria-controls="gh-skills-modal">Update skills</button>
        </div>
        <?php if ($freelancerSkills === []): ?>
            <div class="gh-skills-empty">
                <p>You have not registered any skills on your profile yet. Add your professional skills to enable personalized job recommendations.</p>
                <button type="button" class="gh-button gh-button-primary" id="gh-open-skills-modal-empty" aria-haspopup="dialog" aria-controls="gh-skills-modal">+ Add Skills to Profile</button>
            </div>
        <?php else: ?>
            <ul class="gh-skills-pill-list" aria-label="Registered skills list">
                <?php foreach ($freelancerSkills as $skill): ?>
                    <li class="gh-skill-pill"><?= $escape($skill['name']) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="gh-workspace-section" aria-labelledby="recommendations-heading">
        <div class="gh-section-header">
            <div>
                <h2 id="recommendations-heading">Recommended Jobs for You</h2>
                <p class="gh-workspace-section-desc">Deterministically ranked by Jaccard similarity between your registered skills and open job requirements (&ge; 20% match threshold).</p>
            </div>
        </div>

        <?php if ($freelancerSkills === []): ?>
            <div class="gh-match-empty-card" aria-label="Skills required for recommendations">
                <h3>Add skills to unlock recommendations</h3>
                <p>Our matching engine compares your registered skills against open client jobs. Once you add skills to your profile, jobs with at least 20% skill similarity will be recommended here.</p>
                <div class="gh-empty-actions">
                    <button type="button" class="gh-button gh-button-primary" id="gh-open-skills-modal-rec" aria-haspopup="dialog" aria-controls="gh-skills-modal">+ Add Skills to Profile</button>
                    <a class="gh-button gh-button-secondary" href="?route=/jobs">Browse All Open Jobs</a>
                </div>
            </div>
        <?php elseif ($recommendations === []): ?>
            <div class="gh-match-empty-card" aria-label="No matches found">
                <h3>No matching open jobs found</h3>
                <p>There are currently no open jobs meeting the 20% skill similarity threshold with your registered skills.</p>
                <p>Check back regularly as new projects are posted, or update your registered skills above to explore different matches.</p>
                <div class="gh-empty-actions">
                    <a class="gh-button gh-button-primary" href="?route=/jobs">Browse All Open Jobs</a>
                </div>
            </div>
        <?php else: ?>
            <div class="gh-recommendations-list" aria-label="Recommended jobs list">
                <?php foreach ($recommendations as $job): ?>
                    <?php
                        $matchVal = (float)$job['match']['percentage_value'];
                        $tier = $matchVal >= 70.0 ? 'high' : ($matchVal >= 40.0 ? 'med' : 'low');
                    ?>
                    <article class="gh-recommendation-card">
                        <div class="gh-recommendation-header">
                            <div class="gh-recommendation-title-wrap">
                                <h3 class="gh-recommendation-title">
                                    <a href="?route=/jobs/show&amp;id=<?= (int)$job['id'] ?>"><?= $escape($job['title']) ?></a>
                                </h3>
                                <p class="gh-recommendation-client">Posted by <?= $escape($job['client_name'] ?? 'Client') ?></p>
                            </div>
                            <div class="gh-recommendation-badge-wrap">
                                <span class="gh-match-pill gh-match-pill--<?= $tier ?>" title="Jaccard similarity: <?= $escape($job['match']['percentage']) ?> (|Intersection|=<?= (int)$job['match']['intersection_count'] ?>, |Union|=<?= (int)$job['match']['union_count'] ?>)">
                                    <?= $escape($job['match']['percentage']) ?> Match
                                </span>
                            </div>
                        </div>

                        <p class="gh-recommendation-desc">
                            <?= $escape(mb_substr((string)$job['description'], 0, 180, 'UTF-8') . (mb_strlen((string)$job['description'], 'UTF-8') > 180 ? '…' : '')) ?>
                        </p>

                        <dl class="gh-recommendation-meta">
                            <div>
                                <dt>Advertised Budget</dt>
                                <dd>RM<?= $escape(number_format((float)$job['budget'], 2)) ?></dd>
                            </div>
                            <div>
                                <dt>Delivery Deadline</dt>
                                <dd><?= $escape($job['deadline']) ?></dd>
                            </div>
                        </dl>

                        <div class="gh-recommendation-skills">
                            <div class="gh-skills-group">
                                <span class="gh-skills-label">Matched Skills (<?= count($job['matched_skills']) ?>):</span>
                                <ul class="gh-skills-tags" aria-label="Matched skills">
                                    <?php foreach ($job['matched_skills'] as $s): ?>
                                        <li class="gh-skill-tag--matched" title="You have this skill">✓ <?= $escape($s['name']) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <?php if ($job['unmatched_skills'] !== []): ?>
                                <div class="gh-skills-group">
                                    <span class="gh-skills-label">Other Required Skills (<?= count($job['unmatched_skills']) ?>):</span>
                                    <ul class="gh-skills-tags" aria-label="Other required skills">
                                        <?php foreach ($job['unmatched_skills'] as $s): ?>
                                            <li class="gh-skill-tag--unmatched"><?= $escape($s['name']) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="gh-recommendation-footer">
                            <?php if ($job['bid_status'] !== null): ?>
                                <span class="gh-bid-badge gh-bid-badge--<?= $escape($job['bid_status']) ?>">
                                    [Bid Submitted: <?= $escape(ucfirst($job['bid_status'])) ?>]
                                </span>
                                <a class="gh-button gh-button-secondary" href="?route=/jobs/show&amp;id=<?= (int)$job['id'] ?>">View Job</a>
                            <?php else: ?>
                                <a class="gh-button gh-button-primary" href="?route=/jobs/show&amp;id=<?= (int)$job['id'] ?>">View Job &amp; Submit Bid</a>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>

<!-- Modal Dialog: Update Your Skills -->
<dialog id="gh-skills-modal" class="gh-modal" aria-labelledby="gh-modal-title" aria-modal="true">
    <div class="gh-modal-card">
        <div class="gh-modal-header">
            <h2 id="gh-modal-title" class="gh-modal-title">Update Your Skills</h2>
            <button type="button" class="gh-modal-close" id="gh-modal-close-btn" aria-label="Close dialog">&times;</button>
        </div>

        <form method="post" action="?route=/freelancer/skills" id="gh-skills-form">
            <input type="hidden" name="csrf_token" value="<?= $escape($skillsCsrfToken) ?>">

            <div class="gh-modal-body">
                <div class="gh-modal-search-row">
                    <div class="gh-modal-search-box">
                        <label for="gh-skill-search-input" class="visually-hidden">Search skills</label>
                        <input type="search" id="gh-skill-search-input" class="gh-modal-search-input" placeholder="Search available skills..." autocomplete="off">
                    </div>
                    <div class="gh-modal-counter-wrap">
                        <span id="gh-selected-counter" class="gh-selected-counter" aria-live="polite">
                            <strong><?= count($freelancerSkillIds) ?></strong> selected
                        </span>
                    </div>
                </div>

                <div class="gh-modal-skills-scroll" id="gh-modal-skills-list" role="group" aria-label="Skill catalogue choices">
                    <?php if ($allSkills === []): ?>
                        <p class="gh-modal-empty-catalogue">No catalogue skills are currently available.</p>
                    <?php else: ?>
                        <div class="gh-modal-skills-grid">
                            <?php foreach ($allSkills as $skill): ?>
                                <?php
                                    $sId = (int)$skill['id'];
                                    $isSel = in_array($sId, $freelancerSkillIds, true);
                                    $sNameLower = mb_strtolower((string)$skill['name'], 'UTF-8');
                                ?>
                                <label class="gh-modal-skill-item" data-skill-name="<?= $escape($sNameLower) ?>">
                                    <input type="checkbox" name="skills[]" value="<?= $sId ?>"<?= $isSel ? ' checked' : '' ?>>
                                    <span><?= $escape($skill['name']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p id="gh-modal-no-filter-match" class="gh-modal-no-match" style="display: none;">No skills match your search.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="gh-modal-footer">
                <button type="button" class="gh-button gh-button-secondary" id="gh-skills-cancel-btn">Cancel</button>
                <button type="submit" class="gh-button gh-button-primary" id="gh-skills-submit-btn">Update Skills</button>
            </div>
        </form>
    </div>
</dialog>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
</body>
</html>
