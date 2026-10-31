<?php
declare(strict_types=1);
namespace GigHub\Controllers;

use Closure;
use GigHub\Core\Csrf;
use GigHub\Core\RegistrationThrottle;
use GigHub\Services\RegistrationValidator;
use GigHub\Services\RegistrationValidationException;
use RuntimeException;

final class RegistrationController
{
    public function __construct(private readonly Closure $serviceFactory) {}

    public function show(): array { return $this->form(); }

    public function submit(array $input): array
    {
        if (!Csrf::valid($input['csrf_token'] ?? null)) {
            return $this->form(RegistrationValidator::oldValues($input), ['form' => 'Your form session could not be verified. Your details are kept; enter your password again and retry.'], 403);
        }
        $validation = RegistrationValidator::validate($input);
        // Password fields are never included in returned HTML or session state.
        $old = RegistrationValidator::oldValues($validation['data']);
        if (($input['intent'] ?? '') === 'update_role') {
            // No-JavaScript role switch: validate role, render only, never persist.
            $errors = isset($validation['errors']['role']) ? ['role' => $validation['errors']['role']] : [];
            return $this->form($old, $errors, $errors === [] ? 200 : 422);
        }
        if ($validation['errors'] !== []) { return $this->form($old, $validation['errors'], 422); }
        $attempt = RegistrationThrottle::consume();
        if (!$attempt['allowed']) {
            $minutes = (int) ceil($attempt['retry_after'] / 60);
            return $this->form($old, ['form' => 'Too many registration attempts. Try again in about ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . '.'], 429)
                + ['retry_after' => $attempt['retry_after']];
        }
        try {
            ($this->serviceFactory)()->register($validation['data']);
        } catch (RegistrationValidationException $error) {
            return $this->form($old, $error->errors, 422);
        } catch (RuntimeException $error) {
            return $this->form($old, ['form' => 'Registration is temporarily unavailable. Please try again.'], 503);
        }
        Csrf::rotate();
        $_SESSION['registration_notice'] = true;
        return ['status' => 303, 'type' => 'text/plain; charset=UTF-8', 'body' => '', 'location' => '?route=/register/pending'];
    }

    public function pending(): array
    {
        if (($_SESSION['registration_notice'] ?? false) !== true) {
            return ['status' => 303, 'type' => 'text/plain; charset=UTF-8', 'body' => '', 'location' => '?route=/register'];
        }
        unset($_SESSION['registration_notice']);
        ob_start(); require dirname(__DIR__) . '/Views/registration-pending.php'; $body = ob_get_clean();
        return ['status' => 200, 'type' => 'text/html; charset=UTF-8', 'body' => $body];
    }

    private function form(array $old = [], array $errors = [], int $status = 200): array
    {
        $token = Csrf::token();
        $escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        ob_start(); require dirname(__DIR__) . '/Views/register.php'; $body = ob_get_clean();
        return ['status' => $status, 'type' => 'text/html; charset=UTF-8', 'body' => $body];
    }
}
