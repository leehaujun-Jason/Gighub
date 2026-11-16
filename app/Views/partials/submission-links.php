<?php
$displayLinks = $linkAttempt['links'] ?? [];
if (is_string($linkAttempt['deliverable_url'] ?? null)) {
    $displayLinks[] = ['label' => 'Deliverable link', 'url' => $linkAttempt['deliverable_url']];
}
$displayedUrls = [];
?>
<?php foreach ($displayLinks as $link): ?>
    <?php
    $url = $link['url'];
    if (isset($displayedUrls[$url]) || !\GigHub\Services\SubmissionValidator::isHttpUrl($url)) continue;
    $displayedUrls[$url] = true;
    ?>
    <div class="gh-sub-card-url-row">
        <a href="<?= $escape($url) ?>" target="_blank" rel="noopener noreferrer" class="gh-sub-url-link">
            <?= $escape($link['label']) ?> <span aria-hidden="true">↗</span>
        </a>
    </div>
<?php endforeach; ?>
