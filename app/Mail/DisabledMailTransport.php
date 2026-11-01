<?php
declare(strict_types=1);
namespace GigHub\Mail;

use RuntimeException;

final class DisabledMailTransport implements MailTransport
{
    public function send(VerificationMail $mail): string
    {
        // Existing keys may still be verified; disabled mail never opens a socket.
        throw new RuntimeException('Mail transport unavailable.');
    }
}
