<?php
declare(strict_types=1);
namespace GigHub\Services;

final class SubmissionValidator
{
    public const MAX_NOTES_LENGTH = 5000;
    public const MAX_URL_LENGTH = 2048;

    /**
     * @param array<string, mixed> $input
     * @return array{notes: string, deliverable_url: ?string}
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
        $rawUrl = (string)($input['deliverable_url'] ?? '');
        $trimmedUrl = trim($rawUrl);
        $deliverableUrl = null;

        if ($trimmedUrl !== '') {
            if (mb_strlen($trimmedUrl, 'UTF-8') > self::MAX_URL_LENGTH) {
                $errors['deliverable_url'] = 'Deliverable URL cannot exceed ' . self::MAX_URL_LENGTH . ' characters.';
            } elseif (!preg_match('~^https?://~i', $trimmedUrl) || filter_var($trimmedUrl, FILTER_VALIDATE_URL) === false) {
                $errors['deliverable_url'] = 'Deliverable URL must be a valid HTTP or HTTPS address.';
            } else {
                $deliverableUrl = $trimmedUrl;
            }
        }

        if ($errors !== []) {
            throw new SubmissionException($errors, 422);
        }

        return [
            'notes' => $notes,
            'deliverable_url' => $deliverableUrl,
        ];
    }
}
