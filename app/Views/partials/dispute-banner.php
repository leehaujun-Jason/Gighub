<?php
// Partial: dispute and cancellation banner for job / milestone views.
// Expected context: $job, $disputeContext (optional), $escape
if (!isset($escape)) {
    $escape = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$cancellation = $disputeContext['cancellation'] ?? null;
$dispute = $disputeContext['dispute'] ?? null;
$evidenceImages = $disputeContext['evidence'] ?? [];
$canCancel = $disputeContext['can_cancel'] ?? false;
$canDispute = $disputeContext['can_dispute'] ?? false;
$canRespondCancellation = $disputeContext['can_respond_cancellation'] ?? false;
$canRespondDispute = $disputeContext['can_respond_dispute'] ?? false;
$isClient = $disputeContext['is_client'] ?? ($isClient ?? false);
$cancellationActionToken = $cancellationActionToken ?? \GigHub\Core\Csrf::token('cancellation_action');
$disputeActionToken = $disputeActionToken ?? \GigHub\Core\Csrf::token('dispute_action');
$jobId = (int)($job['id'] ?? 0);
$resolutionError = $_SESSION['resolution_error'][$jobId] ?? [];
unset($_SESSION['resolution_error'][$jobId]);
$cancelError = ($resolutionError['dialog'] ?? '') === 'cancel-dialog';
$disputeError = ($resolutionError['dialog'] ?? '') === 'dispute-dialog';
?>

<div class="gh-dispute-banner" id="dispute-section">
    <?php $settlementEscrow=$escrow ?? null; $escrow=$disputeContext['escrow'] ?? $settlementEscrow; if($dispute!==null || $cancellation!==null) require __DIR__.'/escrow-breakdown.php'; $escrow=$settlementEscrow; ?>
    <?php if (($cancelError && !$canCancel) || ($disputeError && !$canDispute)): ?>
        <p role="alert"><?= $escape($resolutionError['message']) ?></p>
    <?php endif; ?>
    <?php if ($dispute !== null && $dispute['status'] === 'open'): ?>
        <section class="gh-resolution-alert gh-resolution-alert--warning" role="alert" aria-label="Formal dispute status">
            <span class="gh-resolution-icon" aria-hidden="true">⚠️</span>
            <div class="gh-resolution-content">
                <h3 class="gh-resolution-title">Contract Under Formal Dispute</h3>
                <p class="gh-resolution-desc">
                    Work progress, milestone submissions, and escrow release are <strong>frozen</strong> pending administrative review and binding adjudication.
                </p>

                <div class="gh-resolution-statement-box">
                    <div class="gh-statement-header">
                        <span class="gh-statement-author">Claim filed by <?= $escape($dispute['raiser_name']) ?></span>
                        <span class="gh-statement-time"><?= $escape($dispute['created_at']) ?> UTC</span>
                    </div>
                    <p class="gh-statement-body"><?= $escape($dispute['reason']) ?></p>
                    <section class="gh-dispute-evidence-block" aria-label="Dispute evidence">
                        <?php require __DIR__ . '/dispute-evidence-groups.php'; ?>
                    </section>
                </div>

                <?php if (!empty($dispute['response'])): ?>
                    <div class="gh-resolution-statement-box" style="border-left: 4px solid #16a34a; background: #f0fdf4;">
                        <div class="gh-statement-header">
                            <span class="gh-statement-author" style="color: #166534;">Counterpart Formal Response</span>
                            <span class="gh-statement-time">Submitted for mediation</span>
                        </div>
                        <p class="gh-statement-body" style="color: #14532d;"><?= $escape($dispute['response']) ?></p>
                    </div>
                    <p style="margin: 0.5rem 0 0; color: #92400e; font-weight: 600; font-size: 0.9rem;">
                        Status: Awaiting administrative review and binding decision.
                    </p>
                <?php elseif ($canRespondDispute): ?>
                    <div style="margin-top: 1.25rem; background: #ffffff; border: 1px solid #fde68a; padding: 1.25rem; border-radius: var(--gh-card-radius);">
                        <h4 style="margin: 0 0 0.35rem; color: #92400e; font-size: 1.05rem;">Submit Your Formal Response</h4>
                        <p style="margin: 0 0 1rem; color: var(--gh-muted); font-size: 0.875rem;">
                            An administrator will examine your statement and evidence alongside contract milestones, deliverables, and private investigation conversations.
                        </p>
                        <form method="post" action="?route=/jobs/dispute/respond" enctype="multipart/form-data" class="gh-resolution-form">
                            <input type="hidden" name="csrf_token" value="<?= $escape($disputeActionToken) ?>">
                            <input type="hidden" name="job_id" value="<?= $jobId ?>">
                            <input type="hidden" name="dispute_id" value="<?= (int)$dispute['id'] ?>">

                            <div>
                                <label for="dispute-response" class="gh-resolution-label">
                                    Your Formal Statement <span class="gh-resolution-hint">(10–1,000 characters)</span>
                                </label>
                                <textarea id="dispute-response" name="response" class="gh-control gh-dispute-textarea" required minlength="10" maxlength="1000" placeholder="State your facts, timeline, deliverables, and agreement details clearly for the administrator..." oninput="const c = document.getElementById('dispute-resp-count'); if(c) c.textContent = this.value.length;"></textarea>
                                <div class="gh-char-counter"><span id="dispute-resp-count">0</span> / 1,000</div>
                            </div>

                            <?php $evidenceInputId = 'counterpart-evidence'; require __DIR__ . '/dispute-evidence-uploader.php'; ?>
                            <p class="gh-resolution-hint">Your statement and images are submitted together and cannot be changed after submission.</p>
                            <div class="gh-resolution-actions">
                                <button type="submit" class="gh-button gh-btn-dispute-response">Submit Official Response</button>
                            </div>
                        </form>
                    </div>
                <?php else: ?>
                    <p style="margin: 0.5rem 0 0; color: #92400e; font-size: 0.9rem;">
                        Awaiting written response from counterpart before administrator adjudication.
                    </p>
                <?php endif; ?>
            </div>
        </section>

    <?php elseif ($dispute !== null && $dispute['status'] === 'resolved'): ?>
        <section class="gh-resolution-alert gh-resolution-alert--info" role="status" aria-label="Dispute resolution details">
            <span class="gh-resolution-icon" aria-hidden="true">⚖️</span>
            <div class="gh-resolution-content">
                <h3 class="gh-resolution-title">Dispute Adjudicated &amp; Contract Closed</h3>
                <p class="gh-resolution-desc">
                    Binding Administrator Decision: <strong><?= $dispute['decision'] === 'refund_client' ? 'Refund Available Escrow to Client' : 'Release Available Escrow to Freelancer' ?></strong>
                </p>

                <div class="gh-resolution-statement-box" style="border-left: 4px solid #2563eb; background: #ffffff;">
                    <div class="gh-statement-header"><span class="gh-statement-author">Original Dispute Reason</span></div>
                    <p class="gh-statement-body"><?= $escape($dispute['reason']) ?></p>
                    <section class="gh-dispute-evidence-block" aria-label="Dispute evidence">
                        <?php require __DIR__ . '/dispute-evidence-groups.php'; ?>
                    </section>
                </div>
                <div class="gh-resolution-statement-box" style="border-left: 4px solid #2563eb; background: #ffffff;">
                    <div class="gh-statement-header">
                        <span class="gh-statement-author" style="color: #1e40af;">Administrator Rationale</span>
                        <span class="gh-statement-time"><?= $escape($dispute['resolved_at']) ?> UTC by <?= $escape($dispute['admin_name'] ?? 'Admin') ?></span>
                    </div>
                    <p class="gh-statement-body"><?= $escape($dispute['decision_note'] ?? 'Mediation finalized.') ?></p>
                </div>
            </div>
        </section>

    <?php elseif ($cancellation !== null && $cancellation['status'] === 'accepted'): ?>
        <section class="gh-resolution-alert gh-resolution-alert--neutral gh-cancellation-agreed" aria-label="Completed mutual cancellation">
            <span class="gh-resolution-icon" aria-hidden="true">✓</span>
            <div class="gh-resolution-content">
                <h3 class="gh-resolution-title">Mutual Cancellation Agreed</h3>
                <p class="gh-resolution-desc">This job has been cancelled by mutual agreement between the Client and Freelancer.</p>
                <div class="gh-resolution-statement-box"><h4 class="gh-statement-author">Cancellation Reason</h4><p class="gh-statement-body"><?= $escape($cancellation['reason']) ?></p></div>
                <dl class="gh-cancellation-metadata">
                    <div><dt>Requested By</dt><dd><strong><?= $escape($cancellation['requester_name']) ?></strong><span><?= $escape($cancellation['created_at']) ?> UTC</span></dd></div>
                    <div><dt>Accepted By</dt><dd><strong><?= $escape($cancellation['responder_name']) ?></strong><span><?= $escape($cancellation['responded_at']) ?> UTC</span></dd></div>
                </dl>
            </div>
        </section>
    <?php elseif ($cancellation !== null && $cancellation['status'] === 'pending'): ?>
        <section class="gh-resolution-alert gh-resolution-alert--warning" role="alert" aria-label="Mutual cancellation pending">
            <span class="gh-resolution-icon" aria-hidden="true">⏱️</span>
            <div class="gh-resolution-content">
                <h3 class="gh-resolution-title">Mutual Cancellation Request Pending</h3>
                <p class="gh-resolution-desc">
                    <strong><?= $escape($cancellation['requester_name']) ?></strong> has requested to cancel this contract by mutual agreement.
                </p>

                <div class="gh-resolution-statement-box">
                    <div class="gh-statement-header">
                        <span class="gh-statement-author">Stated Reason</span>
                        <span class="gh-statement-time"><?= $escape($cancellation['created_at']) ?> UTC</span>
                    </div>
                    <p class="gh-statement-body"><?= $escape($cancellation['reason']) ?></p>
                </div>

                <?php if ($canRespondCancellation): ?>
                    <div class="gh-resolution-actions">
                        <form method="post" action="?route=/jobs/cancellation/respond" data-payment-confirm="Accept mutual cancellation? Only remaining escrow will be refunded. Earlier payouts are irreversible.">
                            <input type="hidden" name="csrf_token" value="<?= $escape($cancellationActionToken) ?>">
                            <input type="hidden" name="job_id" value="<?= $jobId ?>">
                            <input type="hidden" name="action" value="accept">
                            <button type="submit" class="gh-button gh-btn-accept">Agree &amp; Cancel Contract (Refund Escrow)</button>
                        </form>
                        <form method="post" action="?route=/jobs/cancellation/respond" onsubmit="return confirm('Decline mutual cancellation? Contract work will remain active.');">
                            <input type="hidden" name="csrf_token" value="<?= $escape($cancellationActionToken) ?>">
                            <input type="hidden" name="job_id" value="<?= $jobId ?>">
                            <input type="hidden" name="action" value="decline">
                            <button type="submit" class="gh-button gh-button-secondary gh-btn-decline">Decline Cancellation</button>
                        </form>
                    </div>
                <?php else: ?>
                    <p style="margin: 0.5rem 0 0; color: #92400e; font-size: 0.9rem;">
                        You submitted this cancellation request. Awaiting response from counterpart.
                    </p>
                <?php endif; ?>
            </div>
        </section>

    <?php elseif ($cancellation !== null && $cancellation['status'] === 'rejected'): ?>
        <section class="gh-resolution-alert gh-resolution-alert--declined" role="status" aria-label="Cancellation request status">
            <span class="gh-resolution-icon-circle" aria-hidden="true">i</span>
            <div class="gh-resolution-content">
                <h3 class="gh-declined-title">Mutual Cancellation Declined</h3>
                <p class="gh-declined-desc">
                    A prior mutual cancellation request was declined on <?= $escape($cancellation['responded_at']) ?> UTC. Contract milestones remain active. If mutual agreement cannot be reached, either party may escalate to formal administrative dispute below.
                </p>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($canCancel || $canDispute): ?>
        <section class="gh-milestone-card" aria-labelledby="job-issues-title">
            <h2 id="job-issues-title">Job Issues &amp; Resolution</h2>
            <p>Need to cancel the project or resolve an issue with the other party?</p>
            <div class="gh-milestone-actions">
                <?php if ($canCancel): ?><button type="button" class="gh-button gh-button-secondary" data-resolution-open="cancel-dialog">Request Mutual Cancellation</button><?php endif; ?>
                <?php if ($canDispute): ?><button type="button" class="gh-button gh-button-secondary" data-resolution-open="dispute-dialog">Raise Formal Dispute</button><?php endif; ?>
            </div>
            <?php if ($canCancel): ?>
                <dialog id="cancel-dialog" class="gh-resolution-dialog gh-resolution-card gh-resolution-card--cancel" aria-labelledby="cancel-dialog-title"<?= $cancelError ? ' data-resolution-reopen' : '' ?>>
                    <h4 id="cancel-dialog-title" class="gh-resolution-card-title">
                        <span>🤝</span> Request Mutual Cancellation
                    </h4>
                    <p class="gh-resolution-card-desc">
                        If both parties agree to cancel, the locked escrow is safely refunded to the client wallet and the contract closes without administrative dispute fees.
                    </p>
                    <form method="post" action="?route=/jobs/cancellation" class="gh-resolution-form" onsubmit="return confirm('Submit a mutual cancellation request? Your counterpart will be asked to review and accept.');">
                        <?php if ($cancelError): ?><p role="alert"><?= $escape($resolutionError['message']) ?></p><?php endif; ?>
                        <input type="hidden" name="csrf_token" value="<?= $escape($cancellationActionToken) ?>">
                        <input type="hidden" name="job_id" value="<?= $jobId ?>">

                        <div>
                            <label for="cancellation-reason" class="gh-resolution-label">
                                Reason for Cancellation <span class="gh-resolution-hint">(10–1,000 characters)</span>
                            </label>
                            <textarea id="cancellation-reason" name="reason" class="gh-control gh-resolution-textarea" required minlength="10" maxlength="1000" placeholder="Explain clearly why mutual cancellation is proposed..." oninput="const c = document.getElementById('cancel-char-count'); if(c) c.textContent = this.value.length;"><?= $cancelError ? $escape($resolutionError['reason']) : '' ?></textarea>
                            <div class="gh-char-counter"><span id="cancel-char-count">0</span> / 1,000</div>
                        </div>

                        <div class="gh-resolution-actions">
                            <button type="submit" class="gh-button gh-btn-cancel-req">Send Cancellation Request</button>
                            <button type="button" class="gh-button gh-button-secondary" data-resolution-close>Cancel</button>
                        </div>
                    </form>
                </dialog>
            <?php endif; ?>

            <?php if ($canDispute): ?>
                <dialog id="dispute-dialog" class="gh-resolution-dialog gh-dispute-action-card" aria-labelledby="dispute-dialog-title"<?= $disputeError ? ' data-resolution-reopen' : '' ?>>
                    <div class="gh-dispute-action-header">
                        <span class="gh-dispute-icon-badge" aria-hidden="true">⚖️</span>
                        <div class="gh-dispute-header-text">
                            <h4 id="dispute-dialog-title" class="gh-dispute-title">Raise Formal Dispute</h4>
                            <p class="gh-dispute-subtitle">
                                Escalate to GigHub administrative mediation. Work and escrow will be <strong>frozen immediately</strong> until an administrator reviews all evidence and issues a binding decision.
                            </p>
                        </div>
                    </div>
                    <form method="post" action="?route=/jobs/dispute" enctype="multipart/form-data" class="gh-resolution-form" onsubmit="return confirm('Are you sure you want to open a formal dispute? This will freeze all contract actions and submit the case for administrative adjudication.');">
                        <?php if ($disputeError): ?><p role="alert"><?= $escape($resolutionError['message']) ?></p><?php endif; ?>
                        <input type="hidden" name="csrf_token" value="<?= $escape($disputeActionToken) ?>">
                        <input type="hidden" name="job_id" value="<?= $jobId ?>">

                        <div>
                            <label for="dispute-reason" class="gh-resolution-label">
                                Dispute Details &amp; Grievance <span class="gh-resolution-hint">(10–1,000 characters)</span>
                            </label>
                            <?php $evidenceInputId = 'dispute-evidence'; require __DIR__ . '/dispute-evidence-uploader.php'; ?>
                            <textarea id="dispute-reason" name="reason" class="gh-control gh-dispute-textarea" required minlength="10" maxlength="1000" placeholder="Provide a clear and concise description of the dispute, e.g., the issue, affected milestones, and your requested resolution..." oninput="const c = document.getElementById('dispute-char-count'); if(c) c.textContent = this.value.length;"><?= $disputeError ? $escape($resolutionError['reason']) : '' ?></textarea>
                            <div class="gh-char-counter"><span id="dispute-char-count">0</span> / 1,000</div>
                        </div>

                        <div class="gh-resolution-actions">
                            <button type="submit" class="gh-button gh-btn-dispute-submit">
                                <span aria-hidden="true">✈</span> Escalate to Administrator
                            </button>
                            <button type="button" class="gh-button gh-button-secondary gh-btn-dispute-cancel" data-resolution-close>
                                Cancel
                            </button>
                        </div>
                    </form>
                </dialog>
            <?php endif; ?>
        </section>
        <script src="assets/resolution-dialogs.js" defer></script>
    <?php endif; ?>
</div>
