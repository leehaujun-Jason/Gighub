<?php
declare(strict_types=1);
namespace GigHub\Mail;

final class VerificationMail
{
    // Sensitive, request-lifetime object: never serialize, persist or log it.
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $text,
        public readonly string $html,
        public readonly string $key,
        public readonly string $url,
    ) {}
}
