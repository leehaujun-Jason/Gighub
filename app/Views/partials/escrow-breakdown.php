<?php
// Shared read-only financial summary for participants and authorized Admin dossiers.
if (\GigHub\Services\PaymentMoney::progressive($escrow ?? [])):
?>
<section class="gh-payment-summary" aria-label="Progressive escrow breakdown">
    <h3>Progressive escrow payments</h3>
    <dl class="gh-payment-totals">
        <div><dt>Total funded</dt><dd>RM<?= $escape($escrow['amount']) ?></dd></div>
        <div><dt>Already released</dt><dd>RM<?= $escape($escrow['released_amount']) ?></dd></div>
        <div><dt>Refunded</dt><dd>RM<?= $escape($escrow['refunded_amount']) ?></dd></div>
        <div><dt>Remaining escrow</dt><dd>RM<?= $escape($escrow['remaining_amount']) ?></dd></div>
    </dl>
    <p>Milestone 1: cumulative 30%. <?= (int)$escrow['milestone_count_at_funding'] === 2 ? 'Milestone 2: cumulative 50%.' : '' ?> Final approval releases the exact remaining balance and completes the project.</p>
    <p>Released funds are irreversible. Cancellation and dispute decisions allocate only remaining escrow. All funds are simulated.</p>
</section>
<?php endif; ?>
