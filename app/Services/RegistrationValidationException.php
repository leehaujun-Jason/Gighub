<?php
declare(strict_types=1);
namespace GigHub\Services;

use RuntimeException;

final class RegistrationValidationException extends RuntimeException
{
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Registration details could not be accepted.');
    }
}
