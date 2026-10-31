<?php
declare(strict_types=1);
namespace GigHub\Controllers;
use Closure;
use GigHub\Core\Csrf;
use GigHub\Core\VerificationThrottle;
use RuntimeException;

final class EmailVerificationController
{
    public function __construct(private readonly Closure $serviceFactory) {}
    public function show(): array { return $this->page('confirm'); }
    public function resendForm(): array { return $this->page('resend'); }
    public function confirm(array $input): array
    {
        if (!Csrf::valid($input['csrf_token'] ?? null, 'verification')) {
            return $this->page('confirm', ['form' => 'Your form session could not be verified. Open your verification link again or paste the key below, then retry.'], 403);
        }
        if (($limited = $this->limited('confirm')) !== null) { return $limited; }
        $key = $input['verification_key'] ?? null; $outcome = 'invalid';
        if (is_string($key) && preg_match('/\A([1-9][0-9]{0,9})\.[0-9a-f]{64}\z/D', $key, $match)
            && (strlen($match[1]) < 10 || strcmp($match[1], '4294967295') <= 0)) {
            try { $outcome = ($this->serviceFactory)()->verify($key) === 'verified' ? 'verified' : 'invalid'; }
            catch (RuntimeException) { $outcome = 'unavailable'; }
        }
        // Public outcomes only; never store the key or an account identity.
        $_SESSION['verification_result_notice'] = $outcome;
        return $this->redirect('/verify-email/result');
    }
    public function resend(array $input): array
    {
        $email = is_string($input['email'] ?? null) ? strtolower(trim($input['email'])) : '';
        $oldEmail = substr($email, 0, 254);
        if (!Csrf::valid($input['csrf_token'] ?? null, 'verification')) {
            return $this->page('resend', ['form' => 'Your form session could not be verified. Please retry.'], 403, $oldEmail);
        }
        if (($limited = $this->limited('resend', $oldEmail)) !== null) { return $limited; }
        if ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->page('resend', ['email' => 'Enter a valid email address using no more than 254 characters.'], 422, $oldEmail);
        }
        try { ($this->serviceFactory)()->request($email); }
        catch (RuntimeException) { /* Keep every account and transport outcome generic. */ }
        $_SESSION['verification_result_notice'] = 'resent';
        return $this->redirect('/verify-email/result');
    }
    public function result(): array
    {
        $notice = $_SESSION['verification_result_notice'] ?? null;
        unset($_SESSION['verification_result_notice']);
        if (!in_array($notice, ['verified', 'invalid', 'resent', 'unavailable'], true)) { return $this->redirect('/verify-email'); }
        return $this->page($notice);
    }
    private function limited(string $mode, string $oldEmail = ''): ?array
    {
        $attempt = VerificationThrottle::consume();
        if ($attempt['allowed']) { return null; }
        $minutes = (int) ceil($attempt['retry_after'] / 60);
        return $this->page($mode, ['form' => 'Too many verification requests. Try again in about ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . '.'], 429, $oldEmail) + ['retry_after' => $attempt['retry_after']];
    }
    private function redirect(string $route): array
    {
        return ['status' => 303, 'type' => 'text/plain; charset=UTF-8', 'body' => '', 'location' => '?route=' . $route];
    }
    private function page(string $mode, array $errors = [], int $status = 200, string $oldEmail = ''): array
    {
        $token = Csrf::token('verification');
        $escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        ob_start(); require dirname(__DIR__) . '/Views/verification.php'; $body = ob_get_clean();
        return ['status' => $status, 'type' => 'text/html; charset=UTF-8', 'body' => $body];
    }
}
