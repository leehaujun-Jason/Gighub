<?php
declare(strict_types=1);
namespace GigHub\Services;
use DateTimeImmutable;
use DateTimeZone;

final class MilestoneValidator
{
    public static function validate(array $input, string $jobDeadline, ?DateTimeImmutable $now = null): array
    {
        $errors = [];
        $data = [];

        // Title: 1 to 150 Unicode characters, non-empty when trimmed
        $rawTitle = $input['title'] ?? '';
        $title = is_string($rawTitle) && strlen($rawTitle) <= 65535 && mb_check_encoding($rawTitle, 'UTF-8') && !str_contains($rawTitle, "\0")
            ? preg_replace('/\A\s+|\s+\z/u', '', $rawTitle) : '';
        $titleLen = mb_strlen($title, 'UTF-8');
        if ($titleLen < 1 || $titleLen > 150) {
            $errors['title'] = 'Enter a milestone title between 1 and 150 characters.';
        }
        $data['title'] = $title;

        // Description: optional, up to 5,000 characters
        $rawDesc = $input['description'] ?? '';
        $desc = is_string($rawDesc) && strlen($rawDesc) <= 65535 && mb_check_encoding($rawDesc, 'UTF-8') && !str_contains($rawDesc, "\0")
            ? preg_replace('/\A\s+|\s+\z/u', '', $rawDesc) : '';
        $descLen = mb_strlen($desc, 'UTF-8');
        if ($descLen > 5000) {
            $errors['description'] = 'Milestone description cannot exceed 5,000 characters.';
        }
        $data['description'] = $descLen > 0 ? $desc : null;

        // Due date: must be YYYY-MM-DD, a valid date, >= today (Malaysia time), and <= job deadline
        $zone = new DateTimeZone('Asia/Kuala_Lumpur');
        $today = ($now ?? new DateTimeImmutable('now', $zone))->setTimezone($zone)->format('Y-m-d');
        $rawDate = $input['due_date'] ?? '';
        $dueDate = is_string($rawDate) ? substr(trim($rawDate), 0, 32) : '';
        $formatValid = preg_match('/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}\z/D', $dueDate) === 1;
        $dateObj = $formatValid ? DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate, $zone) : false;

        if (!$formatValid || !$dateObj || $dateObj->format('Y-m-d') !== $dueDate) {
            $errors['due_date'] = 'Choose a valid due date (YYYY-MM-DD).';
        } elseif ($dueDate < $today) {
            $errors['due_date'] = 'Due date cannot be in the past (' . $today . ' Malaysia time).';
        } elseif ($dueDate > $jobDeadline) {
            $errors['due_date'] = 'Due date cannot be after the job delivery deadline (' . $jobDeadline . ').';
        }
        $data['due_date'] = $dueDate;

        return ['data' => $data, 'errors' => $errors];
    }
}
