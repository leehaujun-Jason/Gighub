<?php
declare(strict_types=1);
namespace GigHub\Mail;

final class SmtpDiagnostics
{
    private static ?array $last = null;
    private static bool $enabled = false;
    public static function enable(bool $enabled): void { self::$enabled = $enabled; self::reset(); }
    public static function reset(): void { self::$last = null; }
    public static function last(): ?array { return self::$last; }
    public static function record(string $category, mixed $code = null): void
    {
        if (!in_array($category, ['configuration','connection','tls','authentication','recipient','delivery_uncertain','unknown'], true)) { $category = 'unknown'; }
        $code = (is_int($code) || is_string($code)) && preg_match('/\A[2-5][0-9]{2}\z/D', (string) $code) ? (int) $code : null;
        self::$last = ['category' => $category, 'code' => $code];
        // Explicit local opt-in only; fixed fields, no raw exception or SMTP text.
        if (self::$enabled) {
            try { @error_log('[GigHub SMTP] category=' . $category . ' code=' . ($code ?? 'none')); }
            catch (\Throwable) { /* Diagnostic sink failure must not change delivery classification. */ }
        }
    }
}
