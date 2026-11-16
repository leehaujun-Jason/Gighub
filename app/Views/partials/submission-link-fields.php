<?php
$oldLinks = $old['links'] ?? [];
$oldLinks = is_array($oldLinks) && array_is_list($oldLinks) ? array_slice($oldLinks, 0, 100) : [];
if ($oldLinks === []) $oldLinks = [[]];
?>
<fieldset class="gh-deliverable-link-fields" data-deliverable-links>
    <legend>Deliverable Links <span>(optional)</span></legend>
    <p>Use HTTP or HTTPS URLs. Give each link a label, such as GitHub Repository or Live Demo.</p>
    <?php if (isset($errors['links'])): ?><p class="field-error" role="alert"><?= $escape($errors['links']) ?></p><?php endif; ?>
    <div data-link-rows>
        <?php foreach ($oldLinks as $index => $row): ?>
            <fieldset data-link-row>
                <legend>Link <?= $index + 1 ?></legend>
                <?php $error = $errors['links_' . $index] ?? null; ?>
                <label>Label
                    <input type="text" name="links[<?= $index ?>][label]" maxlength="120" value="<?= $escape(is_string($row['label'] ?? null) ? substr($row['label'], 0, 480) : '') ?>"<?= $error ? ' aria-describedby="link-error-' . $index . '"' : '' ?>>
                </label>
                <label>URL
                    <input type="url" name="links[<?= $index ?>][url]" maxlength="2048" value="<?= $escape(is_string($row['url'] ?? null) ? substr($row['url'], 0, 2048) : '') ?>"<?= $error ? ' aria-describedby="link-error-' . $index . '"' : '' ?>>
                </label>
                <?php if ($error): ?><p class="field-error" id="link-error-<?= $index ?>" role="alert"><?= $escape($error) ?></p><?php endif; ?>
                <button class="gh-button gh-button-secondary" type="button" data-link-remove hidden>Remove link</button>
            </fieldset>
        <?php endforeach; ?>
    </div>
    <template data-link-template>
        <fieldset data-link-row>
            <legend>Link</legend>
            <label>Label <input type="text" maxlength="120"></label>
            <label>URL <input type="url" maxlength="2048"></label>
            <button class="gh-button gh-button-secondary" type="button" data-link-remove>Remove link</button>
        </fieldset>
    </template>
    <button class="gh-button gh-button-secondary" type="button" data-link-add hidden>Add Another Link</button>
</fieldset>
<script src="assets/submission-links.js" defer></script>
