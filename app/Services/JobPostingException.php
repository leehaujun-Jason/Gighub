<?php
declare(strict_types=1);
namespace GigHub\Services;
final class JobPostingException extends \RuntimeException
{
    public function __construct(public readonly array $errors, public readonly int $status=422)
    {
        parent::__construct('Job request could not be completed.');
    }
}
