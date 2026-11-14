<?php
declare(strict_types=1);
namespace GigHub\Controllers;

use Closure;
use GigHub\Core\AuthGuard;
use GigHub\Core\AuthSession;
use GigHub\Core\Csrf;
use Throwable;

final class OnboardingController
{
    public function __construct(private readonly AuthGuard $guard, private readonly Closure $service, private readonly ?Closure $detailsFactory = null) {}

    public function setup(?array $input = null): array
    {
        $access = $this->guard->check(allowOnboarding: true);
        if (!isset($access['user'])) {
            return $access;
        }
        $user = $access['user'];
        $workspace = ['status' => 303, 'type' => 'text/plain; charset=UTF-8', 'body' => '',
            'location' => '?route=' . AuthSession::route($user['role'])];
        if (!($user['onboarding_pending'] ?? false)) {
            return $workspace;
        }
        $errors = [];
        $status = 200;
        try {
            $service = ($this->service)();
            [$values, $catalogue] = $service->form($user);
            if ($input !== null) {
                if (!Csrf::valid($input['csrf_token'] ?? null, 'profile_onboarding')) {
                    $errors['form'] = 'Your form session expired. Review your details and try again.';
                    $status = 403;
                } else {
                    [$values, $errors] = $service->validate($user, $input, $catalogue);
                    if ($errors === [] && $service->complete($user, $values)) {
                        Csrf::rotate('profile_onboarding');
                        return $workspace;
                    }
                    if ($errors === []) {
                        $errors['form'] = 'Your account state changed. Reload this page and try again.';
                    }
                    $status = 422;
                }
            }
        } catch (Throwable) {
            return ['status' => 503, 'type' => 'text/plain; charset=UTF-8',
                'body' => 'Profile setup is temporarily unavailable. Please try again later.'];
        }
        $professionalDetails = [];
        $portfolioUnavailable = false;
        if ($user['role'] === 'freelancer' && $this->detailsFactory !== null) {
            try {
                $professionalDetails['portfolio'] = ($this->detailsFactory)()->list($user['id'], 'portfolio');
            } catch (Throwable) {
                $portfolioUnavailable = true;
            }
        }
        $token = Csrf::token('profile_onboarding');
        $escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $navigationUser = $user;
        ob_start();
        require dirname(__DIR__) . '/Views/onboarding.php';
        return ['status' => $status, 'type' => 'text/html; charset=UTF-8', 'body' => ob_get_clean()];
    }
}
