<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Dispute Dossier #<?= (int)($dossier['dispute']['id'] ?? 0) ?> · GigHub Admin</title>
    <?php require dirname(__DIR__) . '/partials/page-shell.php'; ?>
    <link rel="stylesheet" href="assets/admin.css?v=2">
</head>
<body class="gh-shell">
<?php require dirname(__DIR__) . '/partials/header.php'; ?>

<?php
$dispute = $dossier['dispute'];
$job = $dossier['job'];
$bid = $dossier['bid'];
$client = $dossier['client'];
$freelancer = $dossier['freelancer'];
$escrow = $dossier['escrow'];
$cancellation = $dossier['cancellation'];
$chatMessages = $dossier['chat_messages'] ?? [];
$chatPagination = $dossier['chat_pagination'] ?? ['current_page' => 1, 'per_page' => 50, 'total_items' => count($chatMessages), 'total_pages' => 1];
$milestones = $dossier['milestones'] ?? [];

$escrowAmount = ($escrow !== null) ? (float)$escrow['amount'] : (float)($job['budget'] ?? 0);
$isOpen = ($dispute['status'] === 'open');

$isClientRaiser = ($client !== null && (int)$dispute['raised_by'] === (int)$client['id']);
$raiserRole = $isClientRaiser ? 'Client' : 'Freelancer';
$counterpartRole = $isClientRaiser ? 'Freelancer' : 'Client';
$counterpartName = $isClientRaiser ? ($freelancer['display_name'] ?? 'Freelancer') : ($client['display_name'] ?? 'Client');
?>

<main id="main-content" tabindex="-1" class="gh-admin-layout">
    </nav>
    <?php require dirname(__DIR__) . '/partials/notifications.php'; ?>

    <nav class="gh-back-nav" aria-label="Admin navigation">
        <a href="?route=/admin" class="gh-back-link">← Back to Dispute Queue</a>
        <span aria-hidden="true" class="gh-nav-sep">/</span>
        <span class="gh-nav-current">Case #<?= (int)$dispute['id'] ?></span>
    </nav>

    <div class="gh-dossier-header">
        <div>
            <p class="gh-admin-eyebrow">DISPUTE EVIDENCE DOSSIER</p>
            <h1 class="gh-admin-title" style="margin-bottom:0.35rem;">Case #<?= (int)$dispute['id'] ?>: <?= $escape($job['title']) ?></h1>
            <div class="gh-dossier-meta">
                <span>Job #<?= (int)$job['id'] ?></span>
                <span class="gh-meta-dot" aria-hidden="true">·</span>
                <span>Contract Status: <strong><?= $escape($job['status']) ?></strong></span>
                <span class="gh-meta-dot" aria-hidden="true">·</span>
                <span>Escrow: <strong class="gh-meta-escrow">RM<?= number_format($escrowAmount, 2) ?></strong></span>
            </div>
        </div>
        <div>
            <span class="gh-status-pill gh-status-pill--lg gh-status-<?= $isOpen ? 'open' : 'resolved' ?>">
                <?= $isOpen ? 'Pending Admin Resolution' : 'Resolved' ?>
            </span>
        </div>
    </div>

    <?php if (!$isOpen): ?>
        <div class="gh-adjudication-record">
            <div class="gh-adjudication-header">
                <div class="gh-adjudication-badge">
                    <span aria-hidden="true">⚖️</span>
                    <span>Official Adjudication Record</span>
                </div>
                <span class="gh-adjudication-date">Resolved on <?= $escape($dispute['resolved_at'] ?? '') ?> UTC</span>
            </div>
            <div class="gh-adjudication-decision">
                Binding Outcome:
                <strong><?= $dispute['decision'] === 'refund_client' ? 'Full Refund to Client' : 'Full Payout to Freelancer' ?></strong>
            </div>
            <div class="gh-adjudication-note-box">
                <div class="gh-note-label">Adjudicator Rationale:</div>
                <p class="gh-note-content"><?= $escape($dispute['decision_note'] ?? 'No rationale provided.') ?></p>
            </div>
            <div class="gh-adjudication-meta">
                Mediated by <strong><?= $escape($dispute['admin_name'] ?? 'Admin') ?></strong><?php if (!empty($dispute['admin_id'])): ?> (Admin #<?= (int)$dispute['admin_id'] ?>)<?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="gh-dossier-grid">
        <!-- Main Column: Claims, Milestones, Direct Messages -->
        <div>
            <!-- Section 1: Claims & Responses -->
            <div class="gh-admin-card">
                <h2 class="gh-dossier-section-title">
                    <span aria-hidden="true">⚖️</span>
                    <span>Grievance &amp; Formal Statements</span>
                </h2>

                <!-- Grievance filed by raiser -->
                <div class="gh-statement-box gh-statement-grievance">
                    <div class="gh-statement-header">
                        <span class="gh-statement-title">
                            Grievance filed by <?= $escape($dispute['raiser_name']) ?> (<?= $escape($raiserRole) ?>)
                        </span>
                        <span class="gh-statement-time"><?= $escape($dispute['created_at']) ?> UTC</span>
                    </div>
                    <p class="gh-statement-body"><?= $escape($dispute['reason']) ?></p>
                </div>

                <!-- Counterpart formal response -->
                <div class="gh-statement-box <?= !empty($dispute['response']) ? 'gh-statement-response' : 'gh-statement-pending' ?>">
                    <div class="gh-statement-header">
                        <span class="gh-statement-title">
                            Counterpart Response (<?= $escape($counterpartRole) ?>: <?= $escape($counterpartName) ?>)
                        </span>
                        <?php if (empty($dispute['response'])): ?>
                            <span class="gh-statement-time" style="color:#6b7280;">Optional / Pending</span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($dispute['response'])): ?>
                        <p class="gh-statement-body"><?= $escape($dispute['response']) ?></p>
                    <?php else: ?>
                        <p class="gh-statement-body" style="color:#6b7280;font-style:italic;">
                            Counterpart has not submitted a formal written response yet. (A response is optional; administrators may proceed with binding adjudication at any time based on available evidence.)
                        </p>
                    <?php endif; ?>
                </div>

                <!-- Prior mutual cancellation attempt if any -->
                <?php if ($cancellation !== null): ?>
                    <div class="gh-cancellation-callout">
                        <strong>Prior Mutual Cancellation Record:</strong>
                        Requested by <strong><?= $escape($cancellation['requester_name']) ?></strong> on <?= $escape($cancellation['created_at']) ?> (Status: <em><?= $escape($cancellation['status']) ?></em>).
                        <br>
                        Reason stated: &ldquo;<?= $escape($cancellation['reason']) ?>&rdquo;
                    </div>
                <?php endif; ?>
            </div>

            <!-- Section 2: Deliverable & Milestone Progress -->
            <div class="gh-admin-card">
                <h2 class="gh-dossier-section-title">
                    <span aria-hidden="true">📌</span>
                    <span>Milestone &amp; Submission History</span>
                </h2>
                <?php if (empty($milestones)): ?>
                    <p style="color:#6b7280;margin:0;">No milestones recorded for this job.</p>
                <?php else: ?>
                    <div class="gh-evidence-timeline">
                        <?php foreach ($milestones as $m): ?>
                            <?php
                            $mStatus = strtolower((string)($m['milestone_status'] ?? 'pending'));
                            $pillClass = match ($mStatus) {
                                'approved' => 'gh-milestone-pill-approved',
                                'submitted' => 'gh-milestone-pill-submitted',
                                default => '',
                            };
                            ?>
                            <div class="gh-evidence-item">
                                <div class="gh-milestone-header">
                                    <span>Checkpoint: <?= $escape($m['milestone_title']) ?></span>
                                    <span class="gh-milestone-pill <?= $pillClass ?>"><?= $escape($m['milestone_status']) ?></span>
                                </div>
                                <?php if (!empty($m['submission_id'])): ?>
                                    <div class="gh-submission-card">
                                        <?php if (!empty($m['notes'])): ?>
                                            <div><strong>Submission Notes:</strong> <?= $escape($m['notes']) ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($m['deliverable_url'])): ?>
                                            <div>
                                                <strong>Deliverable:</strong>
                                                <a href="<?= $escape($m['deliverable_url']) ?>" target="_blank" rel="noopener noreferrer" class="gh-deliverable-btn">
                                                    View Deliverable ↗
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($m['review_feedback'])): ?>
                                            <div class="gh-feedback-callout">
                                                <strong>Client Review Feedback:</strong> <?= $escape($m['review_feedback']) ?>
                                            </div>
                                        <?php endif; ?>
                                        <small style="color:#9ca3af;">Submitted on <?= $escape($m['submitted_at']) ?> UTC</small>
                                    </div>
                                <?php else: ?>
                                    <small style="color:#9ca3af;display:block;margin-top:0.25rem;">No deliverable submitted for this checkpoint.</small>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Section 3: Bid-Scoped Direct Messages -->
            <div class="gh-admin-card">
                <h2 class="gh-dossier-section-title">
                    <span aria-hidden="true">💬</span>
                    <span>Direct Messaging Evidence</span>
                </h2>
                <div class="gh-transcript-disclaimer">
                    <span aria-hidden="true">ℹ️</span>
                    <div>
                        <strong>Read-only transcript:</strong> Direct messages exchanged between Client and Freelancer on this contract. Admin inspection does not modify participant unread watermarks.
                    </div>
                </div>

                <?php if (empty($chatMessages)): ?>
                    <div style="text-align:center;padding:2rem 1rem;color:#9ca3af;font-size:0.95rem;">
                        No direct messages exchanged on this contract.
                    </div>
                <?php else: ?>
                    <div class="gh-chat-transcript" tabindex="0" aria-label="Direct message transcript">
                        <?php foreach ($chatMessages as $msg): ?>
                            <?php
                            $isFromClient = ($client !== null && (int)$msg['sender_id'] === (int)$client['id']);
                            ?>
                            <div class="gh-transcript-msg <?= $isFromClient ? 'gh-transcript-client' : 'gh-transcript-freelancer' ?>">
                                <div class="gh-transcript-meta">
                                    <div style="display:flex;align-items:center;gap:0.35rem;">
                                        <strong><?= $escape($msg['sender_name']) ?></strong>
                                        <span class="gh-role-tag <?= $isFromClient ? 'gh-role-tag-client' : 'gh-role-tag-freelancer' ?>">
                                            <?= $isFromClient ? 'Client' : 'Freelancer' ?>
                                        </span>
                                    </div>
                                    <span><?= $escape(substr((string)$msg['created_at'], 11, 5)) ?> UTC</span>
                                </div>
                                <div class="gh-transcript-body" style="white-space:pre-wrap;"><?= $escape($msg['body']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if (($chatPagination['total_items'] ?? 0) > 0): ?>
                        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.75rem;margin-top:0.85rem;padding-top:0.75rem;border-top:1px solid #e5e7eb;font-size:0.875rem;">
                            <span style="color:#6b7280;">
                                Showing <?= count($chatMessages) ?> of <?= (int)$chatPagination['total_items'] ?> message<?= (int)$chatPagination['total_items'] === 1 ? '' : 's' ?><?php if ((int)$chatPagination['total_pages'] > 1): ?> (Page <?= (int)$chatPagination['current_page'] ?> of <?= (int)$chatPagination['total_pages'] ?>)<?php endif; ?>
                            </span>
                            <?php if ((int)$chatPagination['total_pages'] > 1): ?>
                                <nav style="display:flex;gap:0.5rem;" aria-label="Chat pagination">
                                    <?php if ((int)$chatPagination['current_page'] > 1): ?>
                                        <a href="?route=/admin/disputes/show&amp;id=<?= (int)$dispute['id'] ?>&amp;chat_page=<?= (int)$chatPagination['current_page'] - 1 ?>" class="gh-btn" style="padding:0.25rem 0.65rem;background:#f3f4f6;color:#374151;border:1px solid #d1d5db;border-radius:4px;text-decoration:none;font-size:0.825rem;font-weight:600;">← Previous</a>
                                    <?php endif; ?>
                                    <?php if ((int)$chatPagination['current_page'] < (int)$chatPagination['total_pages']): ?>
                                        <a href="?route=/admin/disputes/show&amp;id=<?= (int)$dispute['id'] ?>&amp;chat_page=<?= (int)$chatPagination['current_page'] + 1 ?>" class="gh-btn" style="padding:0.25rem 0.65rem;background:#f3f4f6;color:#374151;border:1px solid #d1d5db;border-radius:4px;text-decoration:none;font-size:0.825rem;font-weight:600;">Next →</a>
                                    <?php endif; ?>
                                </nav>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Sidebar Column: Contract Info & Binding Resolution Form -->
        <div>
            <!-- D1: Contract Parties Card -->
            <div class="gh-dossier-parties">
                <h3 class="gh-dossier-parties-title">Contract Parties &amp; Escrow</h3>

                <!-- Client Info -->
                <div class="gh-party-block">
                    <div class="gh-party-block-label">Client</div>
                    <div class="gh-party-block-name"><?= $escape($client['display_name'] ?? 'Client') ?></div>
                    <div class="gh-party-block-email"><?= $escape($client['email'] ?? 'Unknown') ?></div>
                    <?php if (!empty($client['id'])): ?>
                        <div class="gh-party-block-id">User #<?= (int)$client['id'] ?></div>
                <a class="gh-button gh-button-secondary" style="margin-top: .5rem" data-investigation-thread href="?route=/investigation-chat/thread&amp;dispute_id=<?= (int)$dispute['id'] ?>&amp;participant_id=<?= (int)$client['id'] ?>">Message Client <span class="gh-investigation-unread" data-investigation-unread hidden></span></a>
                    <?php endif; ?>
                </div>

                <!-- Freelancer Info -->
                <div class="gh-party-block">
                    <div class="gh-party-block-label">Freelancer</div>
                    <div class="gh-party-block-name"><?= $escape($freelancer['display_name'] ?? 'Freelancer') ?></div>
                    <div class="gh-party-block-email"><?= $escape($freelancer['email'] ?? 'Unknown') ?></div>
                    <?php if (!empty($freelancer['id'])): ?>
                        <div class="gh-party-block-id">User #<?= (int)$freelancer['id'] ?></div>
                <a class="gh-button gh-button-secondary" style="margin-top: .5rem" data-investigation-thread href="?route=/investigation-chat/thread&amp;dispute_id=<?= (int)$dispute['id'] ?>&amp;participant_id=<?= (int)$freelancer['id'] ?>">Message Freelancer <span class="gh-investigation-unread" data-investigation-unread hidden></span></a>
                    <?php endif; ?>
                </div>

                <!-- Escrow Balance -->
                <div class="gh-party-block gh-escrow-block">
                    <div class="gh-party-block-label">Escrow Settlement</div>
                    <div class="gh-escrow-block-amount">RM<?= number_format($escrowAmount, 2) ?></div>
                    <div>
                        <?php
                        $escStatus = strtolower((string)($escrow['status'] ?? 'locked'));
                        if ($escStatus === 'refunded') {
                            echo '<span class="gh-escrow-badge gh-escrow-refunded">↩ Refunded to Client</span>';
                        } elseif ($escStatus === 'released') {
                            echo '<span class="gh-escrow-badge gh-escrow-released">✓ Released to Freelancer</span>';
                        } else {
                            echo '<span class="gh-escrow-badge gh-escrow-locked">🔒 Locked in Escrow</span>';
                        }
                        ?>
                    </div>
                </div>
            </div>

            <!-- D3: Resolution Card (Only visible when Open) -->
            <?php if ($isOpen): ?>
                <div class="gh-resolution-box" style="margin-top:1.5rem;">
                    <h3 class="gh-resolution-box-title">Binding Adjudication</h3>
                    <div class="gh-adjudication-notice">
                        <strong>Irreversible Settlement:</strong> As administrator, your decision executes an immediate, irreversible financial settlement and permanently closes the contract.
                    </div>

                    <form method="post" action="?route=/admin/disputes/resolve" onsubmit="if (confirm('Confirm binding dispute resolution? This will execute the financial transfer immediately and cannot be undone.')) { var btn = this.querySelector('button[type=submit]'); btn.disabled = true; btn.textContent = 'Executing Settlement...'; return true; } return false;">
                        <input type="hidden" name="csrf_token" value="<?= $escape($decisionToken) ?>">
                        <input type="hidden" name="dispute_id" value="<?= (int)$dispute['id'] ?>">

                        <!-- Option A: Refund Client -->
                        <label class="gh-radio-card" for="decision-refund">
                            <input type="radio" id="decision-refund" name="decision" value="refund_client" required>
                            <div class="gh-radio-card-content">
                                <strong class="gh-radio-card-title">Full Refund to Client</strong>
                                <span class="gh-radio-card-desc">Refund RM<?= number_format($escrowAmount, 2) ?> to client wallet. The contract will be terminated.</span>
                            </div>
                        </label>

                        <!-- Option B: Payout Freelancer -->
                        <label class="gh-radio-card" for="decision-payout">
                            <input type="radio" id="decision-payout" name="decision" value="pay_freelancer" required>
                            <div class="gh-radio-card-content">
                                <strong class="gh-radio-card-title">Full Payout to Freelancer</strong>
                                <span class="gh-radio-card-desc">Release RM<?= number_format($escrowAmount, 2) ?> to freelancer wallet. The contract will be closed.</span>
                            </div>
                        </label>

                        <div class="gh-form-field">
                            <label for="decision-note" class="gh-field-label">
                                Adjudication Rationale Note <span class="gh-char-guidance">(required, 10–1,000 chars)</span>
                            </label>
                            <p class="gh-field-hint">
                                Explain the evidence basis for this decision. This note will be recorded permanently in the contract dossier and sent to both parties.
                            </p>
                            <textarea id="decision-note" name="decision_note" rows="5" class="gh-dossier-textarea" required minlength="10" maxlength="1000" placeholder="Explain the findings, evidence considered, and rationale for this binding resolution..."></textarea>
                        </div>

                        <button type="submit" class="gh-btn-binding-decision">
                            Issue Binding Decision
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
</body>
</html>
