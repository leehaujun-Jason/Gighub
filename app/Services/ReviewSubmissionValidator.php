<?php
declare(strict_types=1);

namespace GigHub\Services;

final class ReviewSubmissionValidator
{
    public static function validate(array $input): array
    {
        $errors = [];
        $ratingRaw = $input['rating'] ?? null;
        $rating = null;

        if ($ratingRaw === null || $ratingRaw === '') {
            $errors['rating'] = 'Please select a rating between 1 and 5 stars.';
        } elseif (!is_numeric($ratingRaw) || (int)$ratingRaw != $ratingRaw) {
            $errors['rating'] = 'Rating must be an integer between 1 and 5.';
        } else {
            $ratingVal = (int)$ratingRaw;
            if ($ratingVal < 1 || $ratingVal > 5) {
                $errors['rating'] = 'Rating must be between 1 and 5 stars.';
            } else {
                $rating = $ratingVal;
            }
        }

        $commentRaw = $input['comment'] ?? null;
        $comment = null;
        if ($commentRaw !== null && is_string($commentRaw)) {
            $trimmed = trim($commentRaw);
            if ($trimmed !== '') {
                if (mb_strlen($trimmed, 'UTF-8') > 1000) {
                    $errors['comment'] = 'Feedback comment cannot exceed 1,000 characters.';
                } else {
                    $comment = $trimmed;
                }
            }
        }

        return [
            'ok' => empty($errors),
            'errors' => $errors,
            'data' => [
                'rating' => $rating,
                'comment' => $comment,
            ],
        ];
    }
}
