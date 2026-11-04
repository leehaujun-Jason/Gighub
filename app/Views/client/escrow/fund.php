<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Fund Escrow · <?= $escape($job['title']) ?> · GigHub</title>
    <?php require dirname(__DIR__, 2) . '/partials/page-shell.php'; ?>
    <link rel="stylesheet" href="assets/escrow.css?v=1">
</head>
<body class="gh-shell">
<?php require dirname(__DIR__, 2) . '/partials/header.php'; ?>
<main id="main-content" tabindex="-1" class="gh-escrow-layout">
    <p class="gh-escrow-eyebrow">CLIENT WORKSPACE · ESCROW FUNDING</p>
    <h1>Fund Escrow for <?= $escape($job['title']) ?></h1>
    <p class="gh-escrow-sub">Lock funds in escrow to authorize work commencement with your selected freelancer.</p>

    <div class="gh-escrow-nav">
        <a href="?route=/client/jobs/show&amp;id=<?= (int)$job['id'] ?>">Back to Job Details</a> ·
        <a href="?route=/client/jobs/milestones&amp;job_id=<?= (int)$job['id'] ?>">Review Milestones</a>
    </div>

    <div class="gh-escrow-simulation-banner" role="note">
        <strong>Simulated Demonstration Environment:</strong> This action uses simulated wallet funds only. No real money, payment gateways, credit cards, or external bank accounts are used. Deducted funds are held in a locked escrow record and released only upon milestone completion and approval.
    </div>

    <?php if ($error !== null): ?>
        <div class="gh-escrow-error" role="alert">
            <p><?= $escape($error) ?></p>
        </div>
    <?php endif; ?>

    <div class="gh-escrow-grid">
        <section class="gh-escrow-card" aria-label="Agreed contract terms">
            <h2>Contract Summary</h2>
            <dl class="gh-escrow-dl">
                <div>
                    <dt>Project</dt>
                    <dd><strong><?= $escape($job['title']) ?></strong></dd>
                </div>
                <div>
                    <dt>Selected Freelancer</dt>
                    <dd><?= $escape($bid['freelancer_name'] ?? 'Awarded Freelancer') ?></dd>
                </div>
                <div>
                    <dt>Agreed Bid Price</dt>
                    <dd class="gh-escrow-highlight">RM<?= $escape(number_format((float)$amount, 2)) ?></dd>
                </div>
                <div>
                    <dt>Proposed Delivery</dt>
                    <dd><?= (int)$bid['delivery_days'] ?> days (Deadline: <?= $escape($job['deadline']) ?>)</dd>
                </div>
                <div>
                    <dt>Defined Milestones</dt>
                    <dd><?= count($milestones) ?> deliverable checkpoint<?= count($milestones) === 1 ? '' : 's' ?></dd>
                </div>
            </dl>

            <h3>Deliverable Checkpoints</h3>
            <ul class="gh-escrow-milestone-list">
                <?php foreach ($milestones as $m): ?>
                    <li>
                        <span class="gh-milestone-pos">#<?= (int)$m['position'] ?></span>
                        <div>
                            <strong><?= $escape($m['title']) ?></strong>
                            <span class="gh-milestone-date">Due: <?= $escape($m['due_date']) ?></span>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="gh-escrow-card gh-escrow-finance-card" aria-label="Financial summary">
            <h2>Financial Breakdown</h2>
            <div class="gh-finance-row">
                <span>Current Available Wallet Balance</span>
                <strong>RM<?= $escape(number_format((float)$balance, 2)) ?></strong>
            </div>
            <div class="gh-finance-row gh-finance-deduct">
                <span>Escrow Deposit (Agreed Bid Amount)</span>
                <strong>- RM<?= $escape(number_format((float)$amount, 2)) ?></strong>
            </div>
            <hr class="gh-finance-divider">
            <div class="gh-finance-row gh-finance-total">
                <span>Estimated Wallet Balance After Funding</span>
                <strong>RM<?= $escape(number_format((float)$remaining, 2)) ?></strong>
            </div>

            <?php if ($isSufficient): ?>
                <div class="gh-escrow-status-box gh-status-sufficient">
                    <p>Your wallet has sufficient demo funds to cover this escrow commitment.</p>
                </div>
                <form method="post" action="?route=/client/jobs/fund/confirm" class="gh-escrow-form" onsubmit="return confirm('Confirm locking RM<?= $escape(number_format((float)$amount, 2)) ?> in escrow? Work will begin immediately.');">
                    <input type="hidden" name="csrf_token" value="<?= $escape($token) ?>">
                    <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
                    <button type="submit" class="gh-button gh-button-primary gh-button-full">Lock Funds in Escrow &amp; Start Work</button>
                </form>
            <?php else: ?>
                <div class="gh-escrow-status-box gh-status-insufficient" role="alert">
                    <p><strong>Insufficient Balance:</strong> Your current wallet balance (RM<?= $escape(number_format((float)$balance, 2)) ?>) cannot cover the agreed bid amount (RM<?= $escape(number_format((float)$amount, 2)) ?>).</p>
                    <p><a class="gh-button gh-button-primary" href="?route=/client/wallet">Go to Wallet to Claim Demo Credit</a></p>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>
<?php require dirname(__DIR__, 2) . '/partials/footer.php'; ?>
</body>
</html>
