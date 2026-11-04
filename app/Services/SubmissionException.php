<?php
declare(strict_types=1);
namespace GigHub\Services;

use RuntimeException;

final class SubmissionException extends RuntimeException
{
    public function __construct(public readonly array $errors = [], public readonly int $status = 400)
    {
        $message = $errors['form'] ?? reset($errors) ?: 'Work submission request could not be processed.';
        parent::__construct((string)$message, $status);
    }
}
