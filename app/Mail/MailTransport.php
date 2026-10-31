<?php
declare(strict_types=1);
namespace GigHub\Mail;

interface MailTransport
{
    /** Return captured, accepted or uncertain; throw on definite transport failure.
     * Accepted means transport acceptance, never proven inbox delivery.
     */
    public function send(VerificationMail $mail): string;
}
