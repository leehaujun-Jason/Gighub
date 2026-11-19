<?php if (empty($evidenceImages)): ?>
<p>No supporting images were uploaded.</p>
<?php else: ?>
<div class="dispute-evidence-gallery" data-evidence-gallery>
<?php foreach ($evidenceImages as $image): ?>
<a href="?route=/jobs/dispute/evidence&amp;dispute_id=<?= (int)$dispute['id'] ?>&amp;id=<?= (int)$image['id'] ?>" aria-haspopup="dialog">
<img src="?route=/jobs/dispute/evidence&amp;dispute_id=<?= (int)$dispute['id'] ?>&amp;id=<?= (int)$image['id'] ?>" alt="<?= $escape($image['original_name']) ?>" width="120" height="100" loading="lazy">
<?php if (isset($image['uploader_id'])): ?>
<span class="gh-evidence-uploader"><?= $escape($image['uploader_name'] ?? 'User') ?> · User #<?= (int)$image['uploader_id'] ?></span>
<?php endif; ?>
</a>
<?php endforeach; ?>
</div>
<dialog class="gh-evidence-viewer" aria-label="Dispute evidence image" data-evidence-viewer>
    <div class="gh-evidence-viewer-header"><h3>Dispute evidence</h3><button type="button" class="gh-button gh-button-secondary" data-evidence-viewer-close aria-label="Close evidence preview">Close</button></div>
    <p data-evidence-viewer-name></p>
    <p data-evidence-viewer-error role="alert" hidden>Unable to load this image. Please close and try again.</p>
    <img data-evidence-viewer-image alt="">
</dialog>
<script src="assets/dispute-evidence-viewer.js?v=1" defer></script>
<?php endif; ?>
