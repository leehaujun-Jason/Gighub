<?php
declare(strict_types=1);
namespace GigHub\Controllers;

use Closure;
use GigHub\Core\AuthGuard;
use GigHub\Core\Csrf;
use GigHub\Services\ClientLocation;
use Throwable;

final class ClientProfileController
{
    public function __construct(private readonly AuthGuard $guard, private readonly Closure $repository) {}

    public function show(bool $edit = false, ?array $input = null): array
    {
        $access = $this->guard->check('client');
        if (!isset($access['user'])) {
            return $access;
        }
        $navigationUser = $access['user'];
        $id = $navigationUser['id'];
        $errors = [];
        $status = 200;
        $saved = false;
        try {
            $repository = ($this->repository)();
            $profile = $repository->find($id);
            if ($profile === false) {
                return ['status' => 404, 'type' => 'text/plain; charset=UTF-8', 'body' => 'Client profile unavailable.'];
            }
            if ($input !== null) {
                if (!Csrf::valid($input['csrf_token'] ?? null, 'client_profile')) {
                    $errors['form'] = 'Your form session expired. Review your details and try again.';
                    $status = 403;
                } else {
                    [$values, $errors] = $this->validate($input, $profile);
                    $profile = array_replace($profile, $values);
                    $status = 422;
                    if ($errors === []) {
                        $repository->update($id, $values);
                        Csrf::rotate('client_profile');
                        $_SESSION['client_profile_saved'] = $id;
                        return ['status' => 303, 'type' => 'text/plain; charset=UTF-8', 'body' => '',
                            'location' => '?route=/client/profile'];
                    }
                }
            } elseif (!$edit) {
                $saved = ($_SESSION['client_profile_saved'] ?? null) === $id;
                unset($_SESSION['client_profile_saved']);
            }
        } catch (Throwable) {
            return ['status' => 503, 'type' => 'text/plain; charset=UTF-8', 'body' => 'Client profile is temporarily unavailable.'];
        }

        $token = $edit ? Csrf::token('client_profile') : '';
        $escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        ob_start();
        require dirname(__DIR__) . '/Views/client/profile.php';
        return ['status' => $status, 'type' => 'text/html; charset=UTF-8', 'body' => ob_get_clean()];
    }

    private function validate(array $input, array $profile): array
    {
        $values = [];
        $errors = [];
        foreach (['display_name' => 100, 'account_type' => 10, 'company_name' => 100, 'state' => 50, 'city' => 100, 'bio' => 2000] as $field => $limit) {
            $raw = $input[$field] ?? '';
            if (!is_string($raw) || !mb_check_encoding($raw, 'UTF-8')
                || preg_match($field === 'bio' ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/', $raw)) {
                $errors[$field] = 'Enter a valid text value.';
                $raw = '';
            }
            $value = trim($raw);
            $values[$field] = mb_substr($value, 0, $limit, 'UTF-8');
            if (mb_strlen($value, 'UTF-8') > $limit) {
                $errors[$field] ??= "Use no more than $limit characters.";
            }
        }
        if ($values['display_name'] === '') {
            $errors['display_name'] ??= 'Enter your display name.';
        }
        if (!in_array($values['account_type'], ['individual', 'company'], true)
            && !($values['account_type'] === '' && $profile['account_type'] === null)) {
            $errors['account_type'] ??= 'Choose Individual or Company.';
        }
        if ($values['account_type'] === 'individual') {
            $values['company_name'] = '';
            unset($errors['company_name']);
        }
        if ($values['state'] !== '' && !ClientLocation::validState($values['state'])) {
            $errors['state'] = 'Choose a valid Malaysian state or federal territory.';
        }
        return [$values, $errors];
    }
}
