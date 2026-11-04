<?php
declare(strict_types=1);
namespace GigHub\Services;
use RuntimeException;

final class WalletException extends RuntimeException
{
    public function __construct(public readonly array $errors = [], public readonly int $status = 400)
    {
        parent::__construct($errors['form'] ?? 'Wallet request could not be completed.');
    }
}
