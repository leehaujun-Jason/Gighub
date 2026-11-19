<?php
$allEvidenceImages = $evidenceImages ?? [];
foreach (['initiator' => 'Initiator Evidence', 'counterpart' => 'Counterpart Evidence'] as $evidenceSide => $evidenceHeading):
    $evidenceImages = array_values(array_filter($allEvidenceImages, static fn(array $image): bool => ($image['evidence_side'] ?? 'initiator') === $evidenceSide));
?>
<section class="gh-dispute-evidence-block" aria-label="<?= $escape($evidenceHeading) ?>">
    <h4 class="gh-statement-author"><?= $escape($evidenceHeading) ?></h4>
    <?php require __DIR__ . '/dispute-evidence.php'; ?>
</section>
<?php endforeach; $evidenceImages = $allEvidenceImages; ?>
