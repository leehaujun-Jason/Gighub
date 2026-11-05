<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>My Wallet · GigHub</title>
    <?php require dirname(__DIR__, 2) . '/partials/page-shell.php'; ?>
    <link rel="stylesheet" href="assets/wallet.css?v=1">
</head>
<body class="gh-shell">
<?php require dirname(__DIR__, 2) . '/partials/header.php'; ?>
<?php
    $isFreelancer = ($user['role'] ?? '') === 'freelancer';
    $walletRoute = $isFreelancer ? '/freelancer/wallet' : '/client/wallet';
    $returnJobId = $returnJobId ?? null;
?>
<main id="main-content" tabindex="-1" class="gh-wallet-layout">
    <nav class="gh-back-nav" aria-label="Wallet navigation">
        <?php if ($returnJobId !== null): ?>
            <?php if ($isFreelancer): ?>
                <a href="?route=/freelancer/jobs/milestones&amp;job_id=<?= (int)$returnJobId ?>">← Back to Job Milestones</a>
                <span class="gh-back-nav-sep" aria-hidden="true">·</span>
                <a href="?route=/freelancer">Freelancer Workspace</a>
            <?php else: ?>
                <a href="?route=/client/jobs/milestones&amp;job_id=<?= (int)$returnJobId ?>">← Back to Job Milestones</a>
                <span class="gh-back-nav-sep" aria-hidden="true">·</span>
                <a href="?route=/client">Client Workspace</a>
            <?php endif; ?>
        <?php else: ?>
            <?php if ($isFreelancer): ?>
                <a href="?route=/freelancer">← Back to Freelancer Workspace</a>
            <?php else: ?>
                <a href="?route=/client">← Back to Client Workspace</a>
            <?php endif; ?>
        <?php endif; ?>
    </nav>
    <p class="gh-wallet-eyebrow"><?= $isFreelancer ? 'FREELANCER WORKSPACE' : 'CLIENT WORKSPACE' ?></p>
    <h1><?= $isFreelancer ? 'Simulated Freelancer Wallet' : 'Simulated Client Wallet' ?></h1>
    <div class="gh-wallet-disclaimer" role="note">
        <strong>Simulation Notice:</strong> This wallet operates with simulated demo funds for marketplace FYP demonstration only. No real money, banking cards, payment gateways, or financial deposits are supported.
    </div>

    <?php if ($notice !== null): ?>
        <p class="gh-wallet-notice" role="status"><?= $escape($notice) ?></p>
    <?php endif; ?>

    <section class="gh-wallet-card" aria-label="Wallet balance">
        <h2>Available Balance</h2>
        <div class="gh-wallet-balance-wrap">
            <span class="gh-wallet-balance-label">Current Balance:</span>
            <span class="gh-wallet-balance">RM<?= $escape(number_format((float)$wallet['balance'], 2)) ?></span>
        </div>

        <?php if (!$isFreelancer): ?>
            <?php if (!$hasDemoCredit): ?>
                <div class="gh-wallet-notice">
                    <p>Eligible Clients can claim a one-time simulated demo credit of <strong>RM10,000.00</strong> to fund project escrows.</p>
                    <form method="post" action="?route=/client/wallet/claim-demo-credit" class="gh-wallet-actions">
                        <input type="hidden" name="csrf_token" value="<?= $escape($token) ?>">
                        <button class="gh-button gh-button-primary" type="submit">Claim RM10,000 Demo Credit</button>
                    </form>
                </div>
            <?php else: ?>
                <p><span class="gh-wallet-badge">Demo Credit Claimed</span> One-time demo credit of RM10,000.00 has already been issued.</p>
            <?php endif; ?>
        <?php else: ?>
            <p style="margin-top: 0.75rem; color: var(--gh-muted); font-size: 0.925rem;">
                Payments earned from completed projects are deposited directly into this wallet when clients approve final deliverables and release escrow funds.
            </p>
        <?php endif; ?>
    </section>

    <section class="gh-wallet-card" aria-label="Transaction ledger">
        <h2>Transaction History</h2>
        <p>Immutable append-only ledger entries for this account.</p>

        <?php if ($entries === []): ?>
            <p>No transaction history recorded yet.</p>
        <?php else: ?>
            <div class="gh-wallet-table-wrap">
                <table class="gh-wallet-table">
                    <thead>
                        <tr>
                            <th scope="col">Date (UTC)</th>
                            <th scope="col">Type</th>
                            <th scope="col">Source</th>
                            <th scope="col">Amount</th>
                            <th scope="col">Related Job</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($entries as $entry): ?>
                        <?php
                            $delta = (float)$entry['amount_delta'];
                            $jobTitle = $entry['job_title'] ?? null;
                            $entryJobId = $entry['job_id'] ?? null;
                            $isAuthorizedJob = false;
                            $jobUrl = '';

                            if ($entryJobId !== null && !empty($jobTitle)) {
                                if ($isFreelancer && (int)($entry['job_freelancer_id'] ?? 0) === (int)$user['id']) {
                                    $isAuthorizedJob = true;
                                    $jobUrl = '?route=/freelancer/jobs/milestones&amp;job_id=' . (int)$entryJobId;
                                } elseif (!$isFreelancer && (int)($entry['job_client_id'] ?? 0) === (int)$user['id']) {
                                    $isAuthorizedJob = true;
                                    $jobUrl = '?route=/client/jobs/show&amp;id=' . (int)$entryJobId;
                                }
                            }
                        ?>
                        <tr>
                            <td><?= $escape($entry['created_at']) ?></td>
                            <td><?= $escape(ucwords(str_replace('_', ' ', $entry['entry_type']))) ?></td>
                            <td><?= $escape(ucwords(str_replace('_', ' ', $entry['source']))) ?></td>
                            <td class="<?= $delta >= 0 ? 'gh-delta-positive' : 'gh-delta-negative' ?>">
                                <?= $delta >= 0 ? '+RM' . $escape(number_format($delta, 2)) : '-RM' . $escape(number_format(abs($delta), 2)) ?>
                            </td>
                            <td class="gh-wallet-job-col">
                                <?php if ($isAuthorizedJob): ?>
                                    <a href="<?= $jobUrl ?>" class="gh-wallet-job-link"><?= $escape($jobTitle) ?></a>
                                <?php elseif (!empty($jobTitle)): ?>
                                    <span class="gh-wallet-job-text"><?= $escape($jobTitle) ?></span>
                                <?php else: ?>
                                    <span class="gh-wallet-fallback">General Wallet Activity</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($page > 1 || $hasNext): ?>
            <?php $walletPageUrl = '?route=' . $walletRoute . ($returnJobId !== null ? '&amp;job_id=' . (int)$returnJobId : '') . '&amp;page='; ?>
            <nav class="gh-wallet-actions" aria-label="Wallet transaction pages">
                <?php if ($page > 1): ?>
                    <a class="gh-button gh-button-secondary" href="<?= $walletPageUrl . ($page - 1) ?>">Previous</a>
                <?php endif; ?>
                <span>Page <?= $page ?></span>
                <?php if ($hasNext): ?>
                    <a class="gh-button gh-button-secondary" href="<?= $walletPageUrl . ($page + 1) ?>">Next</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
</main>
<?php require dirname(__DIR__, 2) . '/partials/footer.php'; ?>
</body>
</html>
