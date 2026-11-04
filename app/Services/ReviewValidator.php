<?php
declare(strict_types=1);
namespace GigHub\Services;

final class ReviewValidator
{
    public const MAX_FEEDBACK_LENGTH = 5000;

    /**
     * @param array<string, mixed> $input
     * @return array{feedback: ?string}
     * @throws SubmissionException
     */
    public static function validateApproval(array $input): array
    {
        $raw = (string)($input['feedback'] ?? '');
        $feedback = trim($raw);

        if ($feedback === '') {
            return ['feedback' => null];
        }

        if (mb_strlen($feedback, 'UTF-8') > self::MAX_FEEDBACK_LENGTH) {
            throw new SubmissionException([
                'feedback' => 'Approval note cannot exceed ' . self::MAX_FEEDBACK_LENGTH . ' characters.'
            ], 422);
        }

        return ['feedback' => $feedback];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{feedback: string}
     * @throws SubmissionException
     */
    public static function validateRevision(array $input): array
    {
        $raw = (string)($input['feedback'] ?? '');
        $feedback = trim($raw);

        if ($feedback === '') {
            throw new SubmissionException([
                'feedback' => 'Please provide feedback explaining the requested revisions.'
            ], 422);
        }

        if (mb_strlen($feedback, 'UTF-8') > self::MAX_FEEDBACK_LENGTH) {
            throw new SubmissionException([
                'feedback' => 'Revision feedback cannot exceed ' . self::MAX_FEEDBACK_LENGTH . ' characters.'
            ], 422);
        }

        return ['feedback' => $feedback];
    }
}
