<?php
declare(strict_types=1);
namespace GigHub\Services;

final class SubmissionValidator
{
    public const MAX_NOTES_LENGTH = 5000;
    public const MAX_URL_LENGTH = 2048;
    public const MAX_LINKS = 100;

    /**
     * @param array<string, mixed> $input
     * @return array{notes: string, deliverable_url: ?string, links: list<array{label: string, url: string}>}
     * @throws SubmissionException
     */
    public static function validate(array $input): array
    {
        $errors = [];

        // 1. Notes Validation
        $rawNotes = (string)($input['notes'] ?? '');
        $notes = trim($rawNotes);

        if ($notes === '') {
            $errors['notes'] = 'Submission notes are required.';
        } elseif (mb_strlen($notes, 'UTF-8') > self::MAX_NOTES_LENGTH) {
            $errors['notes'] = 'Submission notes cannot exceed ' . self::MAX_NOTES_LENGTH . ' characters.';
        }

        // 2. Deliverable URL Validation (Optional)
        $rawUrl = $input['deliverable_url'] ?? '';
        $trimmedUrl = is_string($rawUrl) ? trim($rawUrl) : '';
        $deliverableUrl = null;

        if (!is_string($rawUrl)) {
            $errors['deliverable_url'] = 'Deliverable URL must be a valid HTTP or HTTPS address.';
        } elseif ($trimmedUrl !== '') {
            if (mb_strlen($trimmedUrl, 'UTF-8') > self::MAX_URL_LENGTH) {
                $errors['deliverable_url'] = 'Deliverable URL cannot exceed ' . self::MAX_URL_LENGTH . ' characters.';
            } elseif (!self::isHttpUrl($trimmedUrl)) {
                $errors['deliverable_url'] = 'Deliverable URL must be a valid HTTP or HTTPS address.';
            } else {
                $deliverableUrl = $trimmedUrl;
            }
        }

        $links = [];
        $seen = [];
        $rows = $input['links'] ?? [];
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > self::MAX_LINKS) {
            $errors['links'] = 'Submit at most 100 deliverable links per attempt.';
        } else {
            $bytes = 0;
            foreach ($rows as $index => $row) {
                $number = $index + 1;
                if (!is_array($row) || !is_string($row['label'] ?? null) || !is_string($row['url'] ?? null)) {
                    $errors['links_' . $index] = "Link $number is malformed.";
                    continue;
                }
                $bytes += strlen($row['label']) + strlen($row['url']);
                if ($bytes > 65536) {
                    $errors['links'] = 'Deliverable links exceed the combined size limit of 64 KB.';
                    break;
                }
                $label = trim($row['label']);
                $url = trim($row['url']);
                if ($label === '' && $url === '') continue;
                if ($label === '' || !mb_check_encoding($label, 'UTF-8') || mb_strlen($label, 'UTF-8') > 120) {
                    $errors['links_' . $index] = "Link $number needs a label of 1–120 characters.";
                } elseif (!self::isHttpUrl($url)) {
                    $errors['links_' . $index] = "Link $number needs a valid HTTP or HTTPS URL of at most 2,048 characters.";
                } elseif (isset($seen[$url])) {
                    $errors['links_' . $index] = "Link $number duplicates an earlier URL.";
                } else {
                    $seen[$url] = true;
                    $links[] = ['label' => $label, 'url' => $url];
                }
            }
        }

        if ($errors !== []) {
            throw new SubmissionException($errors, 422);
        }

        return [
            'notes' => $notes,
            'deliverable_url' => $deliverableUrl,
            'links' => $links,
        ];
    }

    public static function isHttpUrl(string $url): bool
    {
        return strlen($url) <= self::MAX_URL_LENGTH
            && preg_match('~\Ahttps?://[\x21-\x7e]+\z~iD', $url) === 1
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
