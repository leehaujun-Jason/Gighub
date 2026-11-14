<?php
declare(strict_types=1);
namespace GigHub\Controllers;

use Closure;
use GigHub\Core\Csrf;
use GigHub\Core\RegistrationThrottle;
use GigHub\Services\RegistrationValidator;
use GigHub\Services\RegistrationValidationException;
use RuntimeException;
use Throwable;

final class RegistrationController
{
    public function __construct(private readonly Closure $serviceFactory, private readonly ?Closure $verificationFactory = null) {}

    public function show(?string $role = null): array { return $this->form(role: $role); }

    public function submit(array $input, ?string $role = null): array
    {
        $old = RegistrationValidator::oldValues($input);
        $formRole = $role ?? ($old['role'] !== '' ? $old['role'] : null);
        if (!Csrf::valid($input['csrf_token'] ?? null)) {
            return $this->form($old, ['form' => 'Your form session could not be verified. Please try again.'], 403, $formRole);
        }
        if ($role !== null) {
            if (array_key_exists('role', $input) && $input['role'] !== $role) {
                return $this->form($old, ['form' => 'The submitted account role does not match this form.'], 422, $role);
            }
            $input = array_intersect_key($input, array_flip(['display_name', 'email', 'password', 'password_confirmation']));
            $input['role'] = $role;
        }
        $validation = RegistrationValidator::validate($input);
        // Password fields are never included in returned HTML or session state.
        $old = RegistrationValidator::oldValues($validation['data']);
        if (($input['intent'] ?? '') === 'update_role') {
            // No-JavaScript role switch: validate role, render only, never persist.
            $errors = isset($validation['errors']['role']) ? ['role' => $validation['errors']['role']] : [];
            return $this->form($old, $errors, $errors === [] ? 200 : 422, $formRole);
        }
        if ($validation['errors'] !== []) { return $this->form($old, $validation['errors'], 422, $formRole); }
        $attempt = RegistrationThrottle::consume();
        if (!$attempt['allowed']) {
            $minutes = (int) ceil($attempt['retry_after'] / 60);
            return $this->form($old, ['form' => 'Too many registration attempts. Try again in about ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . '.'], 429, $formRole)
                + ['retry_after' => $attempt['retry_after']];
        }
        try {
            $committed = ($this->serviceFactory)()->register($validation['data']);
        } catch (RegistrationValidationException $error) {
            return $this->form($old, $error->errors, 422, $formRole);
        } catch (RuntimeException $error) {
            return $this->form($old, ['form' => 'Registration is temporarily unavailable. Please try again.'], 503, $formRole);
        }
        if ($this->verificationFactory !== null) {
            $deliveryStatus = 'unavailable';
            if ($committed) {
                try { $deliveryStatus = ($this->verificationFactory)()->request($validation['data']['email']); }
                catch (Throwable $error) { /* Account is committed and remains recoverable. Never print driver/mail details. */ }
            }
            $_SESSION['registration_delivery_status'] = in_array($deliveryStatus, ['captured','accepted','uncertain','failed','not_sent'], true) ? $deliveryStatus : 'unavailable';
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
        $deliveryStatus = $_SESSION['registration_delivery_status'] ?? 'not_configured';
        unset($_SESSION['registration_delivery_status']);
        ob_start(); require dirname(__DIR__) . '/Views/registration-pending.php'; $body = ob_get_clean();
        return ['status' => 200, 'type' => 'text/html; charset=UTF-8', 'body' => $body];
    }

    private function form(array $old = [], array $errors = [], int $status = 200, ?string $role = null): array
    {
        $token = Csrf::token();
        $escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $view = $role === null ? 'register-role.php' : 'register.php';
        ob_start(); require dirname(__DIR__) . '/Views/' . $view; $body = ob_get_clean();
        return ['status' => $status, 'type' => 'text/html; charset=UTF-8', 'body' => $body];
    }
}
