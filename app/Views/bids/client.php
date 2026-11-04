<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Job Bids · GigHub</title><?php require dirname(__DIR__).'/partials/page-shell.php'; ?><link rel="stylesheet" href="assets/bids.css?v=1"></head>
<body class="gh-shell"><?php require dirname(__DIR__).'/partials/header.php'; ?>
<main id="main-content" tabindex="-1" class="gh-bids-layout">
<p class="gh-bids-eyebrow">CLIENT WORKSPACE</p><h1>Bids for <?= $escape($job['title']) ?></h1>
<p>Select one eligible proposal for this job. Selection closes bidding but does not transfer funds or authorize work to begin. Escrow and payments are not available yet.</p>
<a href="?route=/client/jobs/show&amp;id=<?= (int)$job['id'] ?>">Back to your job</a>
<?php if (($awardNotice??false)===true): ?><p class="gh-bids-notice" role="status">Your bid selection has been saved. No funds have been transferred.</p><?php endif; ?>
<?php if ($job['status']==='awarded' && $job['awarded_bid_id']!==null): ?>
<section class="gh-bids-notice" aria-label="Award result"><h2>A bid has been selected — awaiting funding</h2><p>This job is closed to further bids. Selection is not payment; work has not started.</p><?php if (!empty($job['awarded_at'])): ?><p>Selected (UTC): <?= $escape($job['awarded_at']) ?></p><?php endif; ?><p><a class="gh-button gh-button-secondary" href="?route=/client/jobs/milestones&amp;job_id=<?= (int)$job['id'] ?>">Set Up Milestones</a> <a class="gh-button gh-button-primary" href="?route=/client/jobs/fund&amp;job_id=<?= (int)$job['id'] ?>">Fund Escrow</a></p></section>
<?php endif; ?>
<?php if ($job['status']==='in_progress'): ?>
<section class="gh-bids-notice" aria-label="Award result"><h2>Job is funded and in progress</h2><p>Work has started with the selected freelancer. Escrow funds are locked.</p><p><a class="gh-button gh-button-secondary" href="?route=/client/jobs/milestones&amp;job_id=<?= (int)$job['id'] ?>">View Milestones</a></p></section>
<?php endif; ?>
<?php if ($bids===[]): ?><section class="gh-bid-card"><h2>No bids <?= $page>1?'on this page':'yet' ?></h2><p>Freelancer proposals will appear here when submitted.</p></section><?php endif; ?>
<?php foreach ($bids as $row): ?>
<?php $selected=$job['status']==='awarded' && $job['awarded_bid_id']!==null && (int)$job['awarded_bid_id']===(int)$row['id']; ?>
<article class="gh-bid-card"><p class="gh-bid-status"><?= $selected?'Selected — awaiting funding':$escape(ucfirst($row['status'])) ?></p>
<h2><a href="?route=/freelancers/show&amp;id=<?= (int)$row['freelancer_id'] ?>"><?= $escape($row['display_name']) ?></a></h2>
<dl class="gh-bid-meta"><div><dt>Proposed amount</dt><dd>RM<?= $escape($row['amount']) ?></dd></div><div><dt>Estimated delivery</dt><dd><?= (int)$row['delivery_days'] ?> days</dd></div><div><dt>Submitted (UTC)</dt><dd><?= $escape(substr($row['created_at'],0,10)) ?></dd></div></dl>
<h3>Proposal</h3><div class="gh-bid-proposal"><?= $escape($row['cover_letter']) ?></div>
<?php if ($job['status']==='open' && $job['awarded_bid_id']===null && $row['status']==='pending' && (int)($row['bidder_eligible']??0)===1): ?>
<form method="post" action="?route=/client/jobs/award" class="gh-bid-actions">
<input type="hidden" name="csrf_token" value="<?= $escape(\GigHub\Core\Csrf::token('bid_award')) ?>">
<input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>"><input type="hidden" name="bid_id" value="<?= (int)$row['id'] ?>">
<button class="gh-button gh-button-primary" type="submit" aria-label="Award bid from <?= $escape($row['display_name']) ?>">Award bid</button>
</form>
<?php elseif ($selected): ?><p>Selected for this job. Awaiting funding; no payment has been made.</p>
<?php elseif ($job['status']==='awarded' && $row['status']==='pending'): ?><p>Another proposal has been selected. This bid remains pending and read-only until funding.</p>
<?php endif; ?>
</article><?php endforeach; ?>
<?php if ($page>1 || $hasNext): ?><nav class="gh-bid-actions" aria-label="Job bid pages"><?php if ($page>1): ?><a href="?route=/client/jobs/bids&amp;id=<?= (int)$job['id'] ?>&amp;page=<?= $page-1 ?>">Previous</a><?php endif; ?><span>Page <?= $page ?></span><?php if ($hasNext): ?><a href="?route=/client/jobs/bids&amp;id=<?= (int)$job['id'] ?>&amp;page=<?= $page+1 ?>">Next</a><?php endif; ?></nav><?php endif; ?>
</main><?php require dirname(__DIR__).'/partials/footer.php'; ?></body></html>
