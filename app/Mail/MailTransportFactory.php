<?php
declare(strict_types=1);
namespace GigHub\Mail;

use RuntimeException;

final class MailTransportFactory
{
    public static function create(#[\SensitiveParameter] array $config, string $environment): MailTransport
    {
        SmtpDiagnostics::enable($environment === 'local' && ($config['diagnostics'] ?? false) === true);
        try {
        if (!is_bool($config['enabled'] ?? null)) { throw new RuntimeException('Mail transport unavailable.'); }
        if (!$config['enabled']) { return new DisabledMailTransport(); }
        return match ($config['transport'] ?? null) {
            'smtp' => new PhpMailerTransport($config),
            'memory' => in_array($environment, ['local', 'test'], true)
                ? new InMemoryMailTransport() : throw new RuntimeException('Mail transport unavailable.'),
            default => throw new RuntimeException('Mail transport unavailable.'),
        };
        } catch (\Throwable) {
            SmtpDiagnostics::record('configuration');
            throw new RuntimeException('Mail transport unavailable.');
        }
    }
}
