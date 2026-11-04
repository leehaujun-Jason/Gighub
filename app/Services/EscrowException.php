<?php
declare(strict_types=1);
namespace GigHub\Services;

class EscrowException extends \Exception
{
    public function __construct(private readonly array $errors = [], int $code = 422)
    {
        $message = $errors['form'] ?? reset($errors) ?: 'Escrow operation failed.';
        parent::__construct((string)$message, $code);
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
