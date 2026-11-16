<?php
declare(strict_types=1);
/**
 * Shared Deliverable Submission History Component
 *
 * @var array $milestoneSubs Submissions for this milestone (ordered id DESC)
 * @var callable $escape HTML escaper
 */
$milestoneSubs = $milestoneSubs ?? [];
$escape = $escape ?? static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$historyTitle = $historyTitle ?? ('Submitted Deliverables (' . count($milestoneSubs) . ')');
$historyAriaLabel = $historyAriaLabel ?? 'Submitted deliverables history';

if (empty($milestoneSubs)) {
    return;
}

$milestoneSubs = array_values($milestoneSubs);
$attemptMap = [];
$chronological = $milestoneSubs;
usort($chronological, static function (array $a, array $b): int {
    $timeA = (string)($a['submitted_at'] ?? $a['created_at'] ?? '');
    $timeB = (string)($b['submitted_at'] ?? $b['created_at'] ?? '');
    if ($timeA !== $timeB) {
        return strcmp($timeA, $timeB);
    }
    return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
});

$seqCounter = 1;
foreach ($chronological as $item) {
    if (isset($item['id'])) {
        $attemptMap[$item['id']] = $seqCounter++;
    }
}
?>
<div class="gh-sub-history-list" aria-label="<?= $escape($historyAriaLabel) ?>">
    <h4 class="gh-sub-history-title"><?= $escape($historyTitle) ?></h4>
    <?php foreach ($milestoneSubs as $sub): ?>
        <?php
            $attemptNum = $sub['attempt_number'] ?? ($attemptMap[$sub['id']] ?? $sub['id']);
            $statusClass = match ($sub['status']) {
                'approved' => 'gh-sub-card--approved',
                'revisions_requested' => 'gh-sub-card--revision',
                default => 'gh-sub-card--submitted',
            };
            $badgeClass = match ($sub['status']) {
                'approved' => 'gh-badge--approved',
                'revisions_requested' => 'gh-badge--revision',
                default => 'gh-badge--submitted',
            };
            $statusLabel = match ($sub['status']) {
                'approved' => '✓ Approved',
                'revisions_requested' => '↺ Revisions Requested',
                default => '⏳ Pending Review',
            };
            $submittedTime = $sub['submitted_at'] ?? $sub['created_at'];
            $noteLength = mb_strlen($sub['notes'] ?? '');
        ?>
        <article class="gh-sub-card <?= $statusClass ?>">
            <header class="gh-sub-card-header">
                <div class="gh-sub-card-identity">
                    <span class="gh-sub-attempt-badge">Attempt #<?= (int)$attemptNum ?></span>
                    <span class="gh-badge <?= $badgeClass ?>"><?= $statusLabel ?></span>
                </div>
                <time class="gh-sub-card-time" datetime="<?= $escape($submittedTime) ?>">
                    Submitted: <?= $escape($submittedTime) ?> UTC
                </time>
            </header>

            <?php $linkAttempt = $sub; require __DIR__ . '/submission-links.php'; ?>

            <div class="gh-sub-card-notes-row">
                <span class="gh-sub-row-label">Submission Notes</span>
                <?php if ($noteLength > 300): ?>
                    <div class="gh-sub-notes-expandable">
                        <div class="gh-sub-notes-preview"><?= nl2br($escape(mb_substr($sub['notes'], 0, 240))) ?>…</div>
                        <details class="gh-sub-notes-details">
                            <summary class="gh-sub-notes-summary">
                                <span class="gh-summary-more">Read full notes (<?= $noteLength ?> characters)</span>
                                <span class="gh-summary-less">Collapse notes</span>
                            </summary>
                            <div class="gh-sub-notes-body"><?= nl2br($escape($sub['notes'])) ?></div>
                        </details>
                    </div>
                <?php else: ?>
                    <div class="gh-sub-notes-body"><?= nl2br($escape($sub['notes'])) ?></div>
                <?php endif; ?>
            </div>

            <?php if (!empty($sub['feedback'])): ?>
                <div class="gh-sub-review-box <?= $sub['status'] === 'approved' ? 'gh-sub-review-box--approved' : 'gh-sub-review-box--revision' ?>">
                    <div class="gh-sub-review-header">
                        <span class="gh-sub-review-title">
                            <?= $sub['status'] === 'approved' ? '✓ Client Approval Note' : '↺ Client Revision Feedback' ?>
                        </span>
                        <?php if (!empty($sub['reviewed_at'])): ?>
                            <time class="gh-sub-review-time" datetime="<?= $escape($sub['reviewed_at']) ?>">
                                Reviewed: <?= $escape($sub['reviewed_at']) ?> UTC
                            </time>
                        <?php endif; ?>
                    </div>
                    <div class="gh-sub-review-body"><?= $escape($sub['feedback']) ?></div>
                </div>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</div>
