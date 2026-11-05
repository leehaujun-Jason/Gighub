<?php
declare(strict_types=1);

namespace GigHub\Services;

use RuntimeException;

final class ReviewException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $errors = []
    ) {
        parent::__construct($message, $status);
    }
}
