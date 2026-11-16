<?php $activeStatuses = explode(',', $status ?? ''); ?>
<button type="button" class="gh-button gh-button-secondary" data-status-filter-open aria-haspopup="dialog" aria-controls="status-filter-dialog">Filter by Status</button>
<dialog id="status-filter-dialog" class="gh-status-filter" aria-labelledby="status-filter-title">
    <form method="get">
        <h2 id="status-filter-title">Filter by Status</h2>
        <input type="hidden" name="route" value="<?= $escape($filterRoute) ?>">
        <fieldset>
            <legend>Select statuses. Leave all unchecked to show everything.</legend>
            <?php foreach ($statuses ?? [] as $value => $label): ?>
                <label><input type="checkbox" name="status[]" value="<?= $escape($value) ?>"<?= in_array($value, $activeStatuses, true) ? ' checked' : '' ?>> <?= $escape($label) ?></label>
            <?php endforeach; ?>
        </fieldset>
        <div class="gh-status-filter-actions">
            <button type="button" class="gh-button gh-button-secondary" data-status-filter-cancel>Cancel</button>
            <button type="submit" class="gh-button">Confirm</button>
        </div>
    </form>
</dialog>
<script src="assets/status-filter.js" defer></script>
