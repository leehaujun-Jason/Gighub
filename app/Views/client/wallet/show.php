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
<main id="main-content" tabindex="-1" class="gh-wallet-layout">
    <p class="gh-wallet-eyebrow">CLIENT WORKSPACE</p>
    <h1>Simulated Client Wallet</h1>
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
                            <th scope="col">Operation</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($entries as $entry): ?>
                        <?php $delta = (float)$entry['amount_delta']; ?>
                        <tr>
                            <td><?= $escape($entry['created_at']) ?></td>
                            <td><?= $escape(ucwords(str_replace('_', ' ', $entry['entry_type']))) ?></td>
                            <td><?= $escape(ucwords(str_replace('_', ' ', $entry['source']))) ?></td>
                            <td class="<?= $delta >= 0 ? 'gh-delta-positive' : 'gh-delta-negative' ?>">
                                <?= $delta >= 0 ? '+RM' . $escape(number_format($delta, 2)) : '-RM' . $escape(number_format(abs($delta), 2)) ?>
                            </td>
                            <td><code><?= $escape($entry['operation_key']) ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($page > 1 || $hasNext): ?>
            <nav class="gh-wallet-actions" aria-label="Wallet transaction pages">
                <?php if ($page > 1): ?>
                    <a class="gh-button gh-button-secondary" href="?route=/client/wallet&amp;page=<?= $page - 1 ?>">Previous</a>
                <?php endif; ?>
                <span>Page <?= $page ?></span>
                <?php if ($hasNext): ?>
                    <a class="gh-button gh-button-secondary" href="?route=/client/wallet&amp;page=<?= $page + 1 ?>">Next</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
</main>
<?php require dirname(__DIR__, 2) . '/partials/footer.php'; ?>
</body>
</html>
