<?php
declare(strict_types=1);
namespace GigHub\Services;
use RuntimeException;

final class MilestoneException extends RuntimeException
{
    public function __construct(public readonly array $errors = [], public readonly int $status = 400)
    {
        parent::__construct($errors['form'] ?? 'Milestone request could not be processed.');
    }
}
