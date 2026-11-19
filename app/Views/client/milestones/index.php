<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Job Milestones · GigHub</title>
    <?php require dirname(__DIR__, 2) . '/partials/page-shell.php'; ?>
    <link rel="stylesheet" href="assets/milestones.css?v=<?= $assetVersion('milestones.css') ?>">
    <link rel="stylesheet" href="assets/reviews.css?v=<?= $assetVersion('reviews.css') ?>">
    <script src="assets/progressive-payments.js?v=<?= $assetVersion('progressive-payments.js') ?>" defer></script>
</head>
<body class="gh-shell">
<?php require dirname(__DIR__, 2) . '/partials/header.php'; ?>
<?php
    $isLocked = $isLocked ?? false;
    $isClient = $isClient ?? true;
    $submissions = $submissions ?? [];
    $reviews = $reviews ?? [];
    $myReview = null;
    $counterpartReview = null;
    $currentUserId = (int)($navigationUser['id'] ?? $user['id'] ?? 0);
    foreach ($reviews as $r) {
        if ((int)$r['reviewer_id'] === $currentUserId) {
            $myReview = $r;
        } else {
            $counterpartReview = $r;
        }
    }
    $escrow = $escrow ?? null;
    $escrowAmount = ($escrow !== null && isset($escrow['amount'])) ? (float)$escrow['amount'] : (float)($job['budget'] ?? 0);
    $freelancer = $freelancer ?? null;
    $freelancerName = $freelancer['display_name'] ?? 'Freelancer';
    $client = $client ?? null;
    $clientName = $job['client_name'] ?? ($client['display_name'] ?? 'Client');
    $escrowReleaseToken = $escrowReleaseToken ?? '';
    $reviewToken = $reviewToken ?? '';
    $reviewErrors = $reviewErrors ?? [];
    $reviewOld = $reviewOld ?? [];
    $finalReviewToken = $finalReviewToken ?? '';
    $finalReviewErrors = $finalReviewErrors ?? [];
    $finalReviewOld = $finalReviewOld ?? [];
    $disputeContext = $disputeContext ?? null;
    $isContractDisputed = in_array($job['status'] ?? '', ['disputed', 'closed_disputed', 'cancelled'], true);
?>
<main id="main-content" tabindex="-1" class="gh-milestones-layout">
    <p class="gh-milestones-eyebrow"><?= $isClient ? 'CLIENT WORKSPACE' : 'FREELANCER WORKSPACE' ?></p>
    <h1>Milestones for <?= $escape($job['title']) ?></h1>
    <?php require dirname(__DIR__, 2) . '/partials/escrow-breakdown.php'; ?>
    <?php $progressive=\GigHub\Services\PaymentMoney::progressive($job); ?>
    <?php if($progressive && !$hasEscrow): ?><p>Progressive contracts require one or two milestones before funding. Their schedule is frozen at funding.</p><?php endif; ?>

    <?php require dirname(__DIR__, 2) . '/partials/notifications.php'; ?>

    <?php if ($isLocked): ?>
        <nav class="gh-back-nav" aria-label="Milestone workspace navigation">
            <?php if ($isClient): ?>
                <a href="?route=/client/jobs/show&amp;id=<?= (int)$job['id'] ?>">← Back to Job Details</a>
                <span class="gh-back-nav-sep" aria-hidden="true">·</span>
                <a href="?route=/client">Client Workspace</a>
            <?php else: ?>
                <a href="?route=/freelancer/bids">← Back to My Bids</a>
                <span class="gh-back-nav-sep" aria-hidden="true">·</span>
                <a href="?route=/freelancer">Freelancer Workspace</a>
            <?php endif; ?>
        </nav>
        <div class="gh-milestones-notice" role="note">
            <p><strong>Milestones are locked after funding.</strong> Escrow has been funded and work is underway. Deliverable checkpoints are displayed in read-only mode.</p>
        </div>
    <?php else: ?>
        <nav class="gh-back-nav" aria-label="Milestone workspace navigation">
            <a href="?route=/client/jobs/bids&amp;id=<?= (int)$job['id'] ?>">← Back to Job Bid Review</a>
            <span class="gh-back-nav-sep" aria-hidden="true">·</span>
            <a href="?route=/client">Client Workspace</a>
        </nav>
        <p>Define task and deliverable checkpoints for this project. At least one milestone is required before escrow funding. <?= $progressive ? 'Progressive milestone approvals release scheduled payments; the remaining escrow is paid at final approval.' : 'Milestones represent deliverable scope and do not carry monetary amounts; full payment is held in escrow and settled upon project completion.' ?></p>
        <?php if (count($milestones) >= 1): ?>
            <div class="gh-panel-action-wrap">
                <a class="gh-button gh-button-primary" href="?route=/client/jobs/fund&amp;job_id=<?= (int)$job['id'] ?>">Proceed to Escrow Funding</a>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($notice !== null): ?>
        <p class="gh-milestones-notice" role="status"><?= $escape($notice) ?></p>
    <?php endif; ?>

    <?php if (isset($errors['form'])): ?>
        <div class="gh-milestones-error" role="alert"><p><?= $escape($errors['form']) ?></p></div>
    <?php endif; ?>

    <?php
        $finalSubs = array_values(array_filter($submissions, static fn($s) => ($s['submission_type'] ?? '') === 'final'));
        $latestFinalSub = $finalSubs[0] ?? null;
        $allMilestonesApproved = !empty($milestones);
        foreach ($milestones as $ms) {
            if (($ms['status'] ?? '') !== 'approved') {
                $allMilestonesApproved = false;
                break;
            }
        }
    ?>

    <?php if ($allMilestonesApproved): ?>
        <section class="gh-milestone-card" aria-label="Final Project Deliverable" style="border-top: 4px solid var(--gh-green); margin-top: 1rem; margin-bottom: 2rem;">
            <div class="gh-milestone-header">
                <div>
                    <span class="gh-milestone-pos-badge" style="background: #ecfdf5; color: #047857; border-color: #a7f3d0;">Final Project Handover</span>
                    <h2 style="margin: 0.5rem 0 0.25rem; font-size: 1.4rem;">Final Project Deliverable</h2>
                </div>
                <div>
                    <?php if ($latestFinalSub === null): ?>
                        <span class="gh-badge" style="background: #f3f4f6; color: #4b5563; padding: 0.4rem 0.75rem; border-radius: 4px; font-size: 0.875rem; font-weight: 600;">Awaiting Final Submission</span>
                    <?php elseif ($latestFinalSub['status'] === 'submitted'): ?>
                        <span class="gh-badge" style="background: #e0f2fe; color: #0369a1; padding: 0.4rem 0.75rem; border-radius: 4px; font-size: 0.875rem; font-weight: 600;">⏳ Pending Client Review</span>
                    <?php elseif ($latestFinalSub['status'] === 'revisions_requested'): ?>
                        <span class="gh-badge" style="background: #fef3c7; color: #92400e; padding: 0.4rem 0.75rem; border-radius: 4px; font-size: 0.875rem; font-weight: 600;">↺ Revisions Requested</span>
                    <?php elseif ($latestFinalSub['status'] === 'approved'): ?>
                        <?php if ($job['status'] === 'completed'): ?>
                            <span class="gh-badge" style="background: #dcfce7; color: #15803d; padding: 0.4rem 0.75rem; border-radius: 4px; font-size: 0.875rem; font-weight: 600;">✓ Completed &amp; Escrow Settled</span>
                        <?php else: ?>
                            <span class="gh-badge" style="background: #dcfce7; color: #15803d; padding: 0.4rem 0.75rem; border-radius: 4px; font-size: 0.875rem; font-weight: 600;">✓ Work Accepted — Ready for Settlement</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <p style="margin-top: 0.75rem; color: var(--gh-muted); font-size: 0.95rem;">
                All milestone checkpoints are complete. The final deliverable represents the consolidated project delivery, code repository, and handover documentation.
            </p>

            <?php if (!$isClient): ?>
                <!-- Freelancer Actions & Status -->
                <div style="margin-top: 1.25rem;">
                    <?php if ($latestFinalSub === null && $job['status'] === 'in_progress'): ?>
                        <div style="padding: 1rem 1.25rem; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 4px; margin-bottom: 1rem;">
                            <strong style="color: #166534; font-size: 1rem;">All Milestones Approved!</strong>
                            <p style="margin: 0.35rem 0 0; color: #166534; font-size: 0.925rem;">You are now eligible to submit the completed final project deliverables for client review and acceptance.</p>
                        </div>
                        <div class="gh-panel-action-wrap">
                            <a class="gh-button gh-button-primary" href="?route=/freelancer/jobs/final-submit&amp;job_id=<?= (int)$job['id'] ?>">Submit Final Work Deliverable</a>
                        </div>
                    <?php elseif ($latestFinalSub !== null && $latestFinalSub['status'] === 'submitted'): ?>
                        <div style="padding: 1rem 1.25rem; background: #e0f2fe; border: 1px solid #bae6fd; border-radius: 4px;">
                            <strong style="color: #0369a1; font-size: 1rem;">Final Deliverable Submitted</strong>
                            <p style="margin: 0.35rem 0 0; color: #075985; font-size: 0.925rem;">Your final deliverable is currently awaiting client review and acceptance.</p>
                        </div>
                    <?php elseif ($latestFinalSub !== null && $latestFinalSub['status'] === 'revisions_requested'): ?>
                        <div style="padding: 1rem 1.25rem; background: #fffbeb; border: 1px solid #fef3c7; border-left: 4px solid #d97706; border-radius: 4px; margin-bottom: 1rem;">
                            <strong style="color: #92400e; font-size: 1rem;">Client Requested Revisions on Final Deliverable:</strong>
                            <div style="margin: 0.5rem 0 0; color: #78350f; white-space: pre-wrap; font-size: 0.9375rem;"><?= nl2br($escape($latestFinalSub['feedback'] ?? '')) ?></div>
                            <?php if (!empty($latestFinalSub['reviewed_at'])): ?>
                                <div style="margin-top: 0.5rem; font-size: 0.75rem; color: var(--gh-muted);">Reviewed on <?= $escape($latestFinalSub['reviewed_at']) ?> UTC</div>
                            <?php endif; ?>
                        </div>
                        <div class="gh-panel-action-wrap">
                            <a class="gh-button gh-button-primary" href="?route=/freelancer/jobs/final-submit&amp;job_id=<?= (int)$job['id'] ?>">Submit Revised Final Deliverable</a>
                        </div>
                    <?php elseif ($latestFinalSub !== null && $latestFinalSub['status'] === 'approved'): ?>
                        <?php if ($job['status'] === 'completed'): ?>
                            <div class="gh-settlement-card">
                                <h3 class="gh-settlement-title">🎉 Project Completed — Escrow Payment Released!</h3>
                                <p class="gh-settlement-desc">Congratulations! The client has approved all work and released the escrow payment directly to your simulated wallet.</p>
                                <div class="gh-settlement-meta-grid" role="list" aria-label="Settlement details">
                                    <div class="gh-settlement-item" role="listitem">
                                        <span class="gh-settlement-label">Status:</span>
                                        <span class="gh-settlement-value">Completed;</span>
                                    </div>
                                    <div class="gh-settlement-item" role="listitem">
                                        <span class="gh-settlement-label">Payment Credited:</span>
                                        <span class="gh-settlement-value gh-settlement-value--amount">+RM <?= number_format($escrowAmount, 2) ?>;</span>
                                    </div>
                                    <?php if (!empty($job['completed_at'])): ?>
                                        <div class="gh-settlement-item" role="listitem">
                                            <span class="gh-settlement-label">Completed (UTC):</span>
                                            <span class="gh-settlement-value"><?= $escape($job['completed_at']) ?>;</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="gh-settlement-actions">
                                    <?php if ($myReview === null): ?>
                                        <a class="gh-button gh-button-primary" href="?route=/jobs/review&amp;job_id=<?= (int)$job['id'] ?>">★ Leave a Review for <?= $escape($clientName) ?></a>
                                        <a class="gh-button gh-button-secondary" href="?route=/freelancer/wallet&amp;job_id=<?= (int)$job['id'] ?>">View My Wallet</a>
                                    <?php else: ?>
                                        <a class="gh-button gh-button-primary" href="?route=/freelancer/wallet&amp;job_id=<?= (int)$job['id'] ?>">View My Wallet</a>
                                    <?php endif; ?>
                                </div>
                                <?php if ($myReview !== null || $counterpartReview !== null): ?>
                                    <div class="gh-reviews-workspace-grid">
                                        <?php if ($myReview !== null): ?>
                                            <div class="gh-review-box gh-review-box--submitted">
                                                <div class="gh-review-box-header">
                                                    <span class="gh-review-badge">✓ Your Review for <?= $escape($clientName) ?></span>
                                                    <span class="gh-review-stars"><?= str_repeat('★', (int)$myReview['rating']) . str_repeat('☆', 5 - (int)$myReview['rating']) ?> (<?= (int)$myReview['rating'] ?>/5)</span>
                                                </div>
                                                <?php if (!empty($myReview['comment'])): ?>
                                                    <p class="gh-review-comment">"<?= $escape($myReview['comment']) ?>"</p>
                                                <?php endif; ?>
                                                <span class="gh-review-date">Submitted on <?= $escape($myReview['created_at']) ?> UTC</span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($counterpartReview !== null): ?>
                                            <div class="gh-review-box gh-review-box--received">
                                                <div class="gh-review-box-header">
                                                    <span class="gh-review-badge gh-review-badge--partner">Feedback from <?= $escape($clientName) ?></span>
                                                    <span class="gh-review-stars"><?= str_repeat('★', (int)$counterpartReview['rating']) . str_repeat('☆', 5 - (int)$counterpartReview['rating']) ?> (<?= (int)$counterpartReview['rating'] ?>/5)</span>
                                                </div>
                                                <?php if (!empty($counterpartReview['comment'])): ?>
                                                    <p class="gh-review-comment">"<?= $escape($counterpartReview['comment']) ?>"</p>
                                                <?php endif; ?>
                                                <span class="gh-review-date">Submitted on <?= $escape($counterpartReview['created_at']) ?> UTC</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div style="padding: 1.25rem; background: #f0fdf4; border: 1px solid #bbf7d0; border-left: 4px solid #16a34a; border-radius: 4px;">
                                <strong style="color: #166534; font-size: 1.05rem;">✓ Final Work Deliverable Approved by Client!</strong>
                                <p style="margin: 0.5rem 0 0; color: #166534; font-size: 0.95rem;">All milestone and final deliverables have been verified and accepted. The project is awaiting financial escrow settlement.</p>
                                <p style="margin: 0.35rem 0 0; font-size: 0.85rem; color: var(--gh-muted);">Agreed Escrow Amount: RM <?= number_format($escrowAmount, 2) ?> (Held safely in escrow).</p>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <!-- Client Actions & Status -->
                <div style="margin-top: 1.25rem;">
                    <?php if ($latestFinalSub === null): ?>
                        <div style="padding: 1rem 1.25rem; background: #f3f4f6; border: 1px solid #e5e7eb; border-radius: 4px;">
                            <strong style="color: #374151; font-size: 0.95rem;">Awaiting Freelancer Final Submission</strong>
                            <p style="margin: 0.35rem 0 0; color: #4b5563; font-size: 0.925rem;">All milestone checkpoints are complete. The freelancer will hand over the final deliverable here for your review.</p>
                        </div>
                    <?php elseif ($latestFinalSub['status'] === 'submitted'): ?>
                        <section class="gh-review-panel" aria-label="Review final deliverable" style="background: var(--gh-background); border: 1px solid var(--gh-border); border-radius: var(--gh-card-radius); padding: 1.25rem; margin-top: 1rem;">
                            <h4 style="margin: 0 0 0.5rem; font-size: 1.1rem; color: var(--gh-text);">Review Final Work Deliverable (Attempt #<?= (int)count($finalSubs) ?>)</h4>
                            <p style="margin: 0 0 1rem; font-size: 0.925rem; color: var(--gh-muted);">
                                Review the completed project deliverables. You can accept the work or request revisions with specific feedback.
                            </p>

                            <?php $linkAttempt = $latestFinalSub; require dirname(__DIR__, 2) . '/partials/submission-links.php'; ?>

                            <div style="margin-bottom: 1.25rem;">
                                <strong style="font-size: 0.8125rem; text-transform: uppercase; color: var(--gh-muted); display: block; margin-bottom: 0.25rem;">Submission Notes</strong>
                                <div style="white-space: pre-wrap; background: var(--gh-surface); padding: 0.75rem 1rem; border-radius: var(--gh-control-radius); border: 1px solid rgba(119,135,123,0.2); font-size: 0.925rem;">
                                    <?= nl2br($escape($latestFinalSub['notes'])) ?>
                                </div>
                            </div>

                            <?php if ($job['status'] === 'under_review'): ?>
                            <div class="gh-review-actions-grid">
                                <!-- Approve Final Deliverable Card -->
                                <div class="gh-review-card gh-review-card--approve">
                                    <div class="gh-review-card-header">
                                        <h5 class="gh-review-card-title">✓ Approve Final Deliverable</h5>
                                        <p class="gh-review-card-desc"><?= $progressive ? 'Approval releases the exact remaining escrow and completes this project atomically.' : 'Accept the completed deliverables and mark the project ready for settlement.' ?></p>
                                    </div>
                                    <form method="post" action="?route=/client/jobs/final-review/approve" class="gh-review-form"<?= $progressive ? ' data-payment-confirm="Approve final work, release the exact remaining escrow, and complete this project? This payment is irreversible."' : '' ?>>
                                        <input type="hidden" name="csrf_token" value="<?= $escape($finalReviewToken) ?>">
                                        <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
                                        <input type="hidden" name="submission_id" value="<?= (int)$latestFinalSub['id'] ?>">

                                        <div class="gh-review-field">
                                            <label for="approve_final_feedback" class="gh-review-label">
                                                Approval Note <span>(optional)</span>
                                             </label>
                                            <textarea id="approve_final_feedback" name="feedback" rows="2" maxlength="5000" class="gh-review-textarea" placeholder="Optional comments for freelancer (e.g. Excellent work, deliverables verified!)..."><?= (($finalReviewOld['action'] ?? '') === 'approve') ? $escape($finalReviewOld['feedback'] ?? '') : '' ?></textarea>
                                        </div>

                                        <p style="font-size: 0.8rem; color: var(--gh-muted); margin: 0 0 0.75rem;">
                                            Note: Approval confirms deliverable acceptance. Escrow release occurs during financial settlement.
                                        </p>

                                        <div class="gh-review-submit-wrap">
                                            <button type="submit" class="gh-button gh-button--approve">
                                                Approve Final Deliverable
                                            </button>
                                        </div>
                                    </form>
                                </div>

                                <!-- Request Revisions Card -->
                                <?php
                                    $hasFinalRevisionError = isset($finalReviewErrors['feedback']);
                                    $finalRevisionVal = $finalReviewOld['feedback'] ?? '';
                                ?>
                                <div class="gh-review-card gh-review-card--revision">
                                    <div class="gh-review-card-header">
                                        <h5 class="gh-review-card-title">↺ Request Revisions</h5>
                                        <p class="gh-review-card-desc">Send the final deliverable back to the freelancer with required adjustments.</p>
                                    </div>
                                    <form method="post" action="?route=/client/jobs/final-review/request-revision" class="gh-review-form">
                                        <input type="hidden" name="csrf_token" value="<?= $escape($finalReviewToken) ?>">
                                        <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
                                        <input type="hidden" name="submission_id" value="<?= (int)$latestFinalSub['id'] ?>">

                                        <div class="gh-review-field">
                                            <label for="revision_final_feedback" class="gh-review-label">
                                                Revision Feedback <span>(required)</span>
                                            </label>
                                            <textarea id="revision_final_feedback" name="feedback" rows="4" maxlength="5000" required class="gh-review-textarea" placeholder="Describe specifically what needs to be revised or improved before final acceptance..." <?= $hasFinalRevisionError ? 'aria-invalid="true" aria-describedby="rev-final-err"' : '' ?>><?= $escape($finalRevisionVal) ?></textarea>
                                            <?php if ($hasFinalRevisionError): ?>
                                                <p class="field-error" id="rev-final-err" role="alert"><?= $escape($finalReviewErrors['feedback']) ?></p>
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
                            <?php else: ?>
                            <div style="padding: 0.75rem 1rem; background: var(--gh-surface-alt, #f8fafc); border: 1px solid var(--gh-border, #e2e8f0); border-radius: 4px; font-size: 0.875rem; color: var(--gh-muted, #64748b); margin-top: 1rem;">
                                <em>Final deliverable review actions are unavailable while the contract is <?= $escape($job['status']) ?>.</em>
                            </div>
                            <?php endif; ?>
                        </section>
                    <?php elseif ($latestFinalSub['status'] === 'revisions_requested'): ?>
                        <div style="padding: 1rem 1.25rem; background: #fffbeb; border: 1px solid #fef3c7; border-radius: 4px;">
                            <strong style="color: #92400e; font-size: 0.95rem;">Revisions Requested on Final Deliverable</strong>
                            <p style="margin: 0.35rem 0 0; color: #78350f; font-size: 0.925rem;">Awaiting updated deliverables from the freelancer. You can inspect the feedback in the submission history below.</p>
                        </div>
                    <?php elseif ($latestFinalSub['status'] === 'approved'): ?>
                        <?php if ($job['status'] === 'completed'): ?>
                            <div class="gh-settlement-card">
                                <h3 class="gh-settlement-title">✓ Project Completed &amp; Escrow Settled</h3>
                                <p class="gh-settlement-desc">
                                    All deliverables have been accepted and the escrow payment of <strong>RM <?= number_format($escrowAmount, 2) ?></strong> has been successfully released to <strong><?= $escape($freelancerName) ?></strong>.
                                </p>
                                <div class="gh-settlement-meta-grid" role="list" aria-label="Settlement details">
                                    <div class="gh-settlement-item" role="listitem">
                                        <span class="gh-settlement-label">Status:</span>
                                        <span class="gh-settlement-value">Completed;</span>
                                    </div>
                                    <div class="gh-settlement-item" role="listitem">
                                        <span class="gh-settlement-label">Escrow Released:</span>
                                        <span class="gh-settlement-value gh-settlement-value--amount">RM <?= number_format($escrowAmount, 2) ?>;</span>
                                    </div>
                                    <div class="gh-settlement-item" role="listitem">
                                        <span class="gh-settlement-label">Awarded Freelancer:</span>
                                        <span class="gh-settlement-value"><?= $escape($freelancerName) ?>;</span>
                                    </div>
                                    <?php if (!empty($job['completed_at'])): ?>
                                        <div class="gh-settlement-item" role="listitem">
                                            <span class="gh-settlement-label">Completed (UTC):</span>
                                            <span class="gh-settlement-value"><?= $escape($job['completed_at']) ?>;</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="gh-settlement-actions">
                                    <?php if ($myReview === null): ?>
                                        <a class="gh-button gh-button-primary" href="?route=/jobs/review&amp;job_id=<?= (int)$job['id'] ?>">★ Leave a Review for <?= $escape($freelancerName) ?></a>
                                        <a class="gh-button gh-button-secondary" href="?route=/client/wallet&amp;job_id=<?= (int)$job['id'] ?>">View Wallet Ledger</a>
                                    <?php else: ?>
                                        <a class="gh-button gh-button-secondary" href="?route=/client/wallet&amp;job_id=<?= (int)$job['id'] ?>">View Wallet Ledger</a>
                                    <?php endif; ?>
                                </div>
                                <?php if ($myReview !== null || $counterpartReview !== null): ?>
                                    <div class="gh-reviews-workspace-grid">
                                        <?php if ($myReview !== null): ?>
                                            <div class="gh-review-box gh-review-box--submitted">
                                                <div class="gh-review-box-header">
                                                    <span class="gh-review-badge">✓ Your Review for <?= $escape($freelancerName) ?></span>
                                                    <span class="gh-review-stars"><?= str_repeat('★', (int)$myReview['rating']) . str_repeat('☆', 5 - (int)$myReview['rating']) ?> (<?= (int)$myReview['rating'] ?>/5)</span>
                                                </div>
                                                <?php if (!empty($myReview['comment'])): ?>
                                                    <p class="gh-review-comment">"<?= $escape($myReview['comment']) ?>"</p>
                                                <?php endif; ?>
                                                <span class="gh-review-date">Submitted on <?= $escape($myReview['created_at']) ?> UTC</span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($counterpartReview !== null): ?>
                                            <div class="gh-review-box gh-review-box--received">
                                                <div class="gh-review-box-header">
                                                    <span class="gh-review-badge gh-review-badge--partner">Feedback from <?= $escape($freelancerName) ?></span>
                                                    <span class="gh-review-stars"><?= str_repeat('★', (int)$counterpartReview['rating']) . str_repeat('☆', 5 - (int)$counterpartReview['rating']) ?> (<?= (int)$counterpartReview['rating'] ?>/5)</span>
                                                </div>
                                                <?php if (!empty($counterpartReview['comment'])): ?>
                                                    <p class="gh-review-comment">"<?= $escape($counterpartReview['comment']) ?>"</p>
                                                <?php endif; ?>
                                                <span class="gh-review-date">Submitted on <?= $escape($counterpartReview['created_at']) ?> UTC</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($job['status'] === 'under_review'): ?>
                            <div style="padding: 1.25rem; background: #f0fdf4; border: 1px solid #bbf7d0; border-left: 4px solid #16a34a; border-radius: 4px; margin-bottom: 1.25rem;">
                                <strong style="color: #166534; font-size: 1.05rem;">✓ Final Project Deliverable Approved</strong>
                                <p style="margin: 0.5rem 0 0; color: #166534; font-size: 0.95rem;">All milestones and final deliverables have been accepted. The project is ready for final escrow settlement.</p>
                                <p style="margin: 0.35rem 0 0; font-size: 0.85rem; color: var(--gh-muted);">Escrow Status: Locked (RM <?= number_format($escrowAmount, 2) ?>).</p>
                            </div>

                            <section class="gh-review-panel gh-release-panel" aria-label="Release escrow payment">
                                <div class="gh-release-panel-header">
                                    <h3 class="gh-release-panel-title">Release Escrow Payment &amp; Complete Project</h3>
                                    <p class="gh-release-panel-desc">
                                        The final deliverable has been accepted. Confirm the simulated escrow release to transfer full payment to the winning freelancer and officially complete this project.
                                    </p>
                                </div>

                                <div class="gh-release-meta-box">
                                    <div class="gh-release-meta-grid" role="list" aria-label="Release details">
                                        <div class="gh-release-meta-item" role="listitem">
                                            <span class="gh-release-meta-label">Winning Freelancer:</span>
                                            <span class="gh-release-meta-value"><?= $escape($freelancerName) ?>;</span>
                                        </div>
                                        <div class="gh-release-meta-item" role="listitem">
                                            <span class="gh-release-meta-label">Locked Escrow Amount:</span>
                                            <span class="gh-release-meta-value gh-release-meta-value--highlight">RM <?= number_format($escrowAmount, 2) ?>;</span>
                                        </div>
                                        <div class="gh-release-meta-item" role="listitem">
                                            <span class="gh-release-meta-label">Destination:</span>
                                            <span class="gh-release-meta-value">Freelancer Simulated Wallet;</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="gh-release-notice" role="note">
                                    <strong>Simulation Notice:</strong> This release operates with simulated FYP wallet accounting. Funds will be credited to the freelancer's wallet balance. No real banking or card transactions occur.
                                </div>

                                <div class="gh-release-form-wrap">
                                    <form method="post" action="?route=/client/jobs/escrow/release" class="gh-review-form" onsubmit="return confirm('Are you sure you want to release RM <?= number_format($escrowAmount, 2) ?> to <?= $escape($freelancerName) ?> and complete this project?');">
                                        <input type="hidden" name="csrf_token" value="<?= $escape($escrowReleaseToken) ?>">
                                        <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
                                        <button type="submit" class="gh-button gh-button--approve gh-button--large">
                                            Confirm &amp; Release Escrow Payment (RM <?= number_format($escrowAmount, 2) ?>)
                                        </button>
                                    </form>
                                </div>
                            </section>
                        <?php elseif (in_array($job['status'], ['disputed', 'cancelled', 'closed_disputed'], true)): ?>
                            <div style="padding: 1rem 1.25rem; background: var(--gh-surface-alt, #f8fafc); border: 1px solid var(--gh-border, #e2e8f0); border-radius: 4px; margin-bottom: 1.25rem;">
                                <strong style="font-size: 0.95rem;">Final Deliverables Approved</strong>
                                <p style="margin: 0.35rem 0 0; font-size: 0.875rem; color: var(--gh-muted, #64748b);">Escrow release actions are frozen because the contract is currently <?= $escape($job['status']) ?>.</p>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($finalSubs)): ?>
                <?php
                    $milestoneSubs = $finalSubs;
                    $historyTitle = 'Final Submission History (' . count($finalSubs) . ')';
                    $historyAriaLabel = 'Final deliverable submission history';
                    require dirname(__DIR__, 2) . '/partials/submission-history.php';
                    unset($historyTitle, $historyAriaLabel);
                ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section aria-label="Existing milestones">
        <h2>Defined Milestones (<?= count($milestones) ?>)</h2>
        <?php if ($milestones === []): ?>
            <div class="gh-milestone-card">
                <p>No milestones defined yet. <?= $isLocked ? 'No milestones were created for this project.' : 'Use the form below to create your first milestone checkpoint before funding the escrow.' ?></p>
            </div>
        <?php else: ?>
            <?php $earlierMilestonesApproved = true; ?>
            <?php foreach ($milestones as $milestoneIndex => $m): ?>
                <?php
                    $canSubmitInOrder = $earlierMilestonesApproved;
                    $earlierMilestonesApproved = $earlierMilestonesApproved && $m['status'] === 'approved';
                ?>
                <article class="gh-milestone-card">
                    <div class="gh-milestone-header">
                        <div>
                            <span class="gh-milestone-pos-badge">Position <?= (int)$m['position'] ?></span>
                            <?php if($progressive): ?><p>Payment: <?= $milestoneIndex===0 ? '30% cumulative' : '50% cumulative (additional 20%)' ?> · <?= $m['status']==='approved' ? 'Released' : 'Not yet released' ?></p><?php endif; ?>
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
                            <dd><?= $escape(substr($m['created_at'] ?? '', 0, 10)) ?></dd>
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
                            <?php if (!$canSubmitInOrder && in_array($m['status'], ['pending', 'revisions_requested'], true)): ?>
                                <p>All earlier milestones must be approved before you can submit this deliverable.</p>
                            <?php elseif ($m['status'] === 'pending'): ?>
                                <a class="gh-button gh-button-primary" href="?route=/freelancer/milestones/submit&amp;job_id=<?= (int)$job['id'] ?>&amp;milestone_id=<?= (int)$m['id'] ?>">Submit Deliverable</a>
                            <?php elseif ($m['status'] === 'revisions_requested'): ?>
                                <a class="gh-button gh-button-primary" href="?route=/freelancer/milestones/submit&amp;job_id=<?= (int)$job['id'] ?>&amp;milestone_id=<?= (int)$m['id'] ?>">Submit Revised Deliverable</a>
                            <?php elseif ($m['status'] === 'submitted'): ?>
                                <span class="gh-badge" style="background: #e0f2fe; color: #0369a1; padding: 0.4rem 0.75rem; border-radius: 4px; font-size: 0.875rem; font-weight: 600;">Deliverable submitted — Awaiting client review</span>
                            <?php elseif ($m['status'] === 'approved'): ?>
                                <span class="gh-badge" style="background: #dcfce7; color: #15803d; padding: 0.4rem 0.75rem; border-radius: 4px; font-size: 0.875rem; font-weight: 600;">✓ Checkpoint Approved</span>
                            <?php endif; ?>
                        </div>

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

                                    <?php if ($job['status'] === 'in_progress'): ?>
                                    <div class="gh-review-actions-grid">
                                        <!-- Approve Milestone Card -->
                                        <div class="gh-review-card gh-review-card--approve">
                                            <div class="gh-review-card-header">
                                                <h5 class="gh-review-card-title">✓ Approve Milestone</h5>
                                                <p class="gh-review-card-desc"><?= $progressive ? 'Approval irreversibly releases this milestone payment to the Freelancer.' : 'Accept the deliverable and mark this milestone checkpoint as completed.' ?></p>
                                            </div>
                                            <form method="post" action="?route=/client/milestones/approve" class="gh-review-form"<?= $progressive ? ' data-payment-confirm="Approve this milestone and irreversibly release its scheduled payment?"' : '' ?>>
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
                                    <?php else: ?>
                                    <div style="padding: 0.75rem 1rem; background: var(--gh-surface-alt, #f8fafc); border: 1px solid var(--gh-border, #e2e8f0); border-radius: 4px; font-size: 0.875rem; color: var(--gh-muted, #64748b); margin-top: 1rem;">
                                        <em>Milestone review actions are unavailable while the contract is <?= $escape($job['status']) ?>.</em>
                                    </div>
                                    <?php endif; ?>
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
                        <?php
                            $historyTitle = 'Submitted Deliverables (' . count($milestoneSubs) . ')';
                            $historyAriaLabel = 'Milestone deliverable submission history';
                            require dirname(__DIR__, 2) . '/partials/submission-history.php';
                            unset($historyTitle, $historyAriaLabel);
                        ?>
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
    <?php require dirname(__DIR__, 2) . '/partials/dispute-banner.php'; ?>
</main>
<?php require dirname(__DIR__, 2) . '/partials/footer.php'; ?>
</body>
</html>
