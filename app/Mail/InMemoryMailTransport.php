<?php
declare(strict_types=1);
namespace GigHub\Mail;

use InvalidArgumentException;
use RuntimeException;

final class InMemoryMailTransport implements MailTransport
{
    /** @var list<VerificationMail> Sensitive test capture; never output/persist. */
    public array $messages = [];
    public int $attempts = 0;

    public function __construct(private readonly string $mode = 'captured')
    {
        if (!in_array($mode, ['captured', 'failure', 'uncertain', 'accepted'], true)) {
            throw new InvalidArgumentException('Invalid capture mode.');
        }
    }

    public function send(VerificationMail $mail): string
    {
        $this->attempts++;
        if ($this->mode === 'failure') { throw new RuntimeException('Mail transport unavailable.'); }
        $this->messages[] = $mail;
        return $this->mode;
    }
}
