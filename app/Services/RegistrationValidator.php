<?php
declare(strict_types=1);
namespace GigHub\Services;

final class RegistrationValidator
{
    // Small local demo safeguard, not comprehensive leaked-password detection.
    private const COMMON_PASSWORDS = [
        '123456789012', '1234567890123', '12345678901234', '123456789012345',
        '1234567890123456', 'passwordpassword', 'password1234', 'password12345',
        'password123456', 'password12345678', 'qwertyuiop123', 'qwertyuiopasdfgh',
        'letmeinletmein', 'letmeinletmeinletmein', 'welcome12345', 'welcome123456',
    ];

    public static function passwordPolicy(): array
    {
        // Public UI policy only: no user input or secret configuration.
        return ['min' => 12, 'max' => 128, 'max_bytes' => 1024, 'common' => self::COMMON_PASSWORDS,
            'messages' => [
                'min' => 'Use at least 12 characters.',
                'max' => 'Use no more than 128 characters.',
                'common' => 'This password is too easy to guess. Try a longer, less predictable password.',
                'repeated' => 'Avoid repeating the same character for the whole password.',
                'blank' => 'Choose a password that is not only spaces.',
                'encoding' => 'Use valid text without null characters for your password.',
                'bytes' => 'Use a password within the 1024-byte safety limit.',
                'confirm_empty' => 'Confirm your password.',
                'mismatch' => 'Passwords do not match.',
            ]];
    }

    public static function passwordError(string $password): ?string
    {
        $policy = self::passwordPolicy(); $messages = $policy['messages'];
        if (!mb_check_encoding($password, 'UTF-8') || str_contains($password, "\0")) { return $messages['encoding']; }
        $length = mb_strlen($password, 'UTF-8');
        if ($length < $policy['min']) { return $messages['min']; }
        if ($length > $policy['max']) { return $messages['max']; }
        if (strlen($password) > $policy['max_bytes']) { return $messages['bytes']; }
        if (trim($password) === '') { return $messages['blank']; }
        if (preg_match('/\A(.)\1+\z/us', $password)) { return $messages['repeated']; }
        if (in_array(strtolower($password), $policy['common'], true)) { return $messages['common']; }
        return null;
    }

    public static function oldValues(array $input): array
    {
        $old = [];
        foreach (['display_name' => 100, 'email' => 254, 'role' => 10, 'bio' => 2000, 'company_name' => 100] as $key => $limit) {
            $value = $input[$key] ?? '';
            $value = is_string($value) && mb_check_encoding($value, 'UTF-8') ? $value : '';
            $old[$key] = mb_substr(trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value)), 0, $limit, 'UTF-8');
        }
        if (!in_array($old['role'], ['client', 'freelancer'], true)) { $old['role'] = ''; }
        if ($old['role'] !== 'client') { $old['company_name'] = ''; }
        return $old;
    }

    public static function validate(array $input): array
    {
        $data = []; $errors = [];
        foreach (['display_name', 'email', 'role', 'password', 'password_confirmation', 'bio', 'company_name'] as $key) {
            $value = $input[$key] ?? '';
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
                $errors[$key] = 'Enter a valid text value.'; $value = '';
            }
            $data[$key] = in_array($key, ['password', 'password_confirmation'], true) ? $value : trim($value);
        }
        $data['email'] = strtolower($data['email']);
        if ($data['display_name'] === '' || mb_strlen($data['display_name']) > 100
            || preg_match('/[\x00-\x1F\x7F]/u', $data['display_name'])) {
            $errors['display_name'] = 'Enter your full name using 1–100 characters without control characters.';
        }
        if (strlen($data['email']) > 254 || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address of at most 254 characters.';
        }
        if (!in_array($data['role'], ['client', 'freelancer'], true)) {
            $errors['role'] = 'Choose Client or Freelancer.';
        }
        $password = $data['password'];
        $passwordError = self::passwordError($password);
        if ($passwordError !== null) { $errors['password'] = $passwordError; }
        if ($data['password_confirmation'] !== $password || $data['password_confirmation'] === '') {
            $errors['password_confirmation'] = self::passwordPolicy()['messages'][$data['password_confirmation'] === '' ? 'confirm_empty' : 'mismatch'];
        }
        if (mb_strlen($data['bio']) > 2000) { $errors['bio'] = 'Use at most 2000 characters.'; }
        if ($data['role'] !== 'client') {
            // Ignore irrelevant fields even if manually posted or left stale by a client.
            $data['company_name'] = '';
            unset($errors['company_name']);
        } elseif (mb_strlen($data['company_name']) > 100) {
            $errors['company_name'] = 'Use at most 100 characters.';
        }
        return ['data' => $data, 'errors' => $errors];
    }
}
