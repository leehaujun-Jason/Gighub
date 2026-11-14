<?php
declare(strict_types=1);
namespace GigHub\Controllers;

use Closure;
use GigHub\Core\AuthSession;
use GigHub\Core\Csrf;
use GigHub\Services\RegistrationValidator;
use Throwable;

final class PasswordResetController
{
    public function __construct(private readonly Closure $service, private readonly AuthSession $session) {}

    public function page(string $mode, array $errors = [], string $email = '', int $status = 200): array
    {
        $token = Csrf::token('password_reset_' . ($mode === 'reset' ? 'confirm' : 'request'));
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        ob_start();
        require dirname(__DIR__) . '/Views/password-reset.php';
        return ['status' => $status, 'type' => 'text/html; charset=UTF-8', 'body' => ob_get_clean()];
    }

    public function request(array $input): array
    {
        $raw = $input['email'] ?? null;
        $email = is_string($raw) && strlen($raw) <= 254 ? strtolower(trim($raw)) : '';
        if (!Csrf::valid($input['csrf_token'] ?? null, 'password_reset_request')) {
            return $this->page('request', ['form' => 'Reload the form and try again.'], $email, 403);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->page('request', ['email' => 'Enter a valid email address.'], $email, 422);
        }
        $now = time();
        $attempts = array_values(array_filter($_SESSION['password_reset_attempts'] ?? [],
            static fn (int $at): bool => $at > $now - 900));
        if (count($attempts) < 5) {
            $attempts[] = $now;
            $_SESSION['password_reset_attempts'] = $attempts;
            try { ($this->service)()->request($email); }
            catch (Throwable) { /* Preserve the same response for unavailable delivery and unknown accounts. */ }
        }
        Csrf::rotate('password_reset_request');
        return self::redirect('/forgot-password/sent');
    }

    public function confirm(#[\SensitiveParameter] array $input): array
    {
        if (!Csrf::valid($input['csrf_token'] ?? null, 'password_reset_confirm')) {
            return $this->page('reset', ['form' => 'Reload the reset link and try again.'], status: 403);
        }
        $key = $input['reset_key'] ?? null;
        $password = $input['password'] ?? null;
        $confirmation = $input['password_confirmation'] ?? null;
        $errors = [];
        if (!is_string($key) || !preg_match('/\A[a-f0-9]{64}\z/', $key)) {
            $errors['reset_key'] = 'Open the email link again or enter the reset key.';
        }
        $error = is_string($password) ? RegistrationValidator::passwordError($password) : 'Enter a valid password.';
        if ($error !== null) {
            $errors['password'] = $error;
        }
        if (!is_string($confirmation) || $confirmation === '' || $confirmation !== $password) {
            $errors['password_confirmation'] = 'Passwords must match.';
        }
        if ($errors !== []) {
            return $this->page('reset', $errors, status: 422);
        }
        try {
            if (!(($this->service)()->reset($key, $password))) {
                return $this->page('reset', ['reset_key' => 'This reset key is invalid or expired. Request a new link.'], status: 422);
            }
        } catch (Throwable) {
            return $this->page('reset', ['form' => 'Password reset is temporarily unavailable. Try again later.'], status: 503);
        }
        $this->session->forget();
        Csrf::rotate('password_reset_confirm');
        $_SESSION['password_reset_success'] = true;
        return self::redirect('/reset-password/success');
    }

    public function success(): array
    {
        if (($_SESSION['password_reset_success'] ?? false) !== true) {
            return self::redirect('/login');
        }
        unset($_SESSION['password_reset_success']);
        return $this->page('success');
    }

    private static function redirect(string $route): array
    {
        return ['status' => 303, 'type' => 'text/plain; charset=UTF-8', 'body' => '', 'location' => '?route=' . $route];
    }
}
