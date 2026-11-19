<?php $evidenceInputId = $evidenceInputId ?? 'dispute-evidence'; ?>
<div>
    <label for="<?= $escape($evidenceInputId) ?>">Supporting images (optional, up to 6; 5 MB and 12 megapixels each)</label>
    <div class="gh-evidence-toolbar">
        <input id="<?= $escape($evidenceInputId) ?>" class="visually-hidden" type="file" name="evidence[]" accept="image/jpeg,image/png,image/webp" multiple data-dispute-evidence aria-describedby="<?= $escape($evidenceInputId) ?>-count <?= $escape($evidenceInputId) ?>-error">
        <button type="button" class="gh-button gh-button-secondary gh-evidence-add" data-evidence-add aria-label="Add evidence images"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4m-5 5 5-5 5 5M4 16v4h16v-4"/></svg></button>
        <span id="<?= $escape($evidenceInputId) ?>-count" data-evidence-count aria-live="polite">0 / 6</span>
    </div>
    <p id="<?= $escape($evidenceInputId) ?>-error" data-evidence-error role="alert" hidden></p>
    <div class="dispute-evidence-gallery" data-evidence-previews></div>
</div>
<script src="assets/dispute-evidence.js?v=3" defer></script>
