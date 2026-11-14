<?php
use GigHub\Services\ProfessionalDetails;
$manageDetails = $manageDetails ?? false;
$icons = [
    'add' => 'M12 5v14M5 12h14',
    'edit' => 'm15 5 4 4M4 20l4-1L21 6l-3-3L5 16z',
    'delete' => 'M3 6h18M9 6V3h6v3M6 6l1 14h10l1-14M10 10v6m4-6v6',
];
$icon = static function (string $name) use ($icons): void { ?>
<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="<?= $icons[$name] ?>"/></svg>
<?php };
?>
<?php foreach ($professionalDetails ?? [] as $section => $entries): ?>
<section class="gh-profile-card" id="professional-<?= $escape($section) ?>">
    <div class="gh-profile-heading">
        <h2><?= $escape(ProfessionalDetails::SECTIONS[$section]) ?></h2>
        <?php if ($manageDetails):
            $label = 'Add ' . match ($section) { 'education'=>'education', 'employment'=>'employment', 'languages'=>'language', 'portfolio'=>'project' }; ?>
        <a class="gh-button gh-detail-icon gh-detail-add" href="?route=/freelancer/profile/details&amp;section=<?= $escape($section) ?>" title="<?= $escape($label) ?>" aria-label="<?= $escape($label) ?>"><?php $icon('add'); ?></a>
        <?php endif; ?>
    </div>
    <?php if ($entries === []): ?>
    <p class="gh-profile-muted"><?= match ($section) {
        'education' => 'No education added yet.',
        'employment' => 'No employment history added yet.',
        'languages' => 'No languages added yet.',
        'portfolio' => 'No projects added yet.',
    } ?></p>
    <?php endif; ?>
    <?php foreach ($entries as $entry): ?>
    <?php $label = match ($section) {
        'education' => $entry['qualification'],
        'employment' => $entry['job_title'],
        'portfolio' => $entry['project_title'],
        default => ProfessionalDetails::LANGUAGES[$entry['language']] ?? $entry['language'],
    };
    $entryUrl = '?route=/freelancer/profile/details&section=' . $section . '&id=' . (int) $entry['id']; ?>
    <article class="gh-detail-entry gh-detail-record">
    <div>
    <?php if ($section === 'education'): ?>
        <h3><?= $escape($entry['qualification']) ?></h3>
        <p><?= $escape($entry['institution']) ?><?= $entry['field_of_study'] !== null ? ' | ' . $escape($entry['field_of_study']) : '' ?></p>
        <?php if ($entry['start_year'] !== null || $entry['end_year'] !== null): ?>
        <p class="gh-profile-muted"><?= $escape((string) ($entry['start_year'] ?? $entry['end_year'])) ?><?= $entry['start_year'] !== null ? ' &ndash; ' . $escape((string) ($entry['end_year'] ?? 'Ongoing')) : '' ?></p>
        <?php endif; ?>
    <?php elseif ($section === 'employment'): ?>
        <h3><?= $escape($entry['job_title']) ?></h3>
        <p><?= $escape($entry['company']) ?></p>
        <p class="gh-profile-muted"><?= $escape($entry['start_date']) ?><?= (int) $entry['current_job'] === 1 ? ' &ndash; Present' : ($entry['end_date'] !== null ? ' &ndash; ' . $escape($entry['end_date']) : '') ?></p>
        <?php if ($entry['description'] !== null): ?><p class="gh-profile-bio"><?= $escape($entry['description']) ?></p><?php endif; ?>
    <?php elseif ($section === 'portfolio'): ?>
        <h3><?= $escape($entry['project_title']) ?></h3>
        <p class="gh-profile-bio"><?= $escape($entry['description']) ?></p>
        <?php if (ProfessionalDetails::safeProjectUrl($entry['project_url'])): ?>
        <a href="<?= $escape($entry['project_url']) ?>" target="_blank" rel="noopener noreferrer">View project (opens in a new tab)</a>
        <?php endif; ?>
    <?php else: ?>
        <p><strong><?= $escape(ProfessionalDetails::LANGUAGES[$entry['language']] ?? $entry['language']) ?></strong> &middot; <?= $escape($entry['proficiency']) ?></p>
    <?php endif; ?>
    </div>
    <?php if ($manageDetails): ?>
    <div class="gh-profile-actions gh-detail-actions">
        <a class="gh-button gh-detail-icon gh-detail-edit" href="<?= $escape($entryUrl) ?>" title="Edit entry" aria-label="<?= $escape('Edit ' . $label) ?>"><?php $icon('edit'); ?></a>
        <a class="gh-button gh-detail-icon gh-detail-delete" href="<?= $escape($entryUrl . '&confirm=delete#delete-entry') ?>" data-detail-delete title="Delete entry" aria-label="<?= $escape('Delete ' . $label) ?>"><?php $icon('delete'); ?></a>
        <dialog class="gh-profile-card gh-avatar-dialog" aria-label="Delete profile entry">
            <h3>Delete entry?</h3>
            <p><?= $escape($label) ?> will be removed from your profile.</p>
            <form method="post" action="<?= $escape($entryUrl) ?>">
                <input type="hidden" name="csrf_token" value="<?= $escape(\GigHub\Core\Csrf::token('professional_details')) ?>">
                <div class="gh-profile-actions">
                    <button class="gh-button gh-button-secondary" type="button" data-detail-cancel autofocus>Cancel</button>
                    <button class="gh-button gh-button-primary" name="action" value="delete" type="submit">Delete entry</button>
                </div>
            </form>
        </dialog>
    </div>
    <?php endif; ?>
    </article>
    <?php endforeach; ?>
</section>
<?php endforeach; ?>
<?php if ($manageDetails): ?><script src="assets/detail-delete.js" defer></script><?php endif; ?>
