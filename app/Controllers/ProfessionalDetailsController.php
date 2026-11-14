<?php
declare(strict_types=1);
namespace GigHub\Controllers;

use Closure;
use GigHub\Core\AuthGuard;
use GigHub\Core\Csrf;
use GigHub\Services\ProfessionalDetails;
use PDOException;
use Throwable;

final class ProfessionalDetailsController
{
    public function __construct(private readonly AuthGuard $guard, private readonly Closure $repository) {}

    public function form(array $query, ?array $input = null): array
    {
        $section = $query['section'] ?? null;
        $access = $this->guard->check('freelancer', allowOnboarding: $section === 'portfolio');
        if (!isset($access['user'])) { return $access; }
        $navigationUser = $access['user'];
        $owner = $navigationUser['id'];
        $returnRoute = ($navigationUser['onboarding_pending'] ?? false)
            ? '/profile/setup' : '/freelancer/profile/edit';
        $rawId = $query['id'] ?? null;
        if (!is_string($section) || !isset(ProfessionalDetails::SECTIONS[$section])
            || ($rawId !== null && (!is_string($rawId) || !preg_match('/\A[1-9][0-9]{0,9}\z/', $rawId) || (int) $rawId > 4294967295))) {
            return self::response(404, 'Profile entry not found.');
        }
        $id = $rawId === null ? null : (int) $rawId;
        if ($input !== null && !Csrf::valid($input['csrf_token'] ?? null, 'professional_details')) {
            return self::response(403, 'Your form expired. Reload the page and try again.');
        }
        $errors = [];
        try {
            $repository = ($this->repository)();
            $values = [];
            if ($id !== null) {
                $entries = array_column($repository->list($owner, $section), null, 'id');
                if (!isset($entries[$id])) { return self::response(404, 'Profile entry not found.'); }
                $values = $entries[$id];
            }
            if ($input !== null) {
                $action = $input['action'] ?? null;
                if ($action === 'save') {
                    [$values, $errors] = ProfessionalDetails::validate($section, $input);
                } elseif ($action !== 'delete' || $id === null) {
                    return self::response(400, 'Invalid profile action.');
                }
                if ($errors === []) {
                    try {
                        $repository->change($owner, $section, $id, $action === 'delete' ? null : $values);
                        return self::response(303, '') + ['location' => '?route=' . $returnRoute . '#professional-' . $section];
                    } catch (PDOException $exception) {
                        if ($section !== 'languages' || (int) ($exception->errorInfo[1] ?? 0) !== 1062) { throw $exception; }
                        $errors['language'] = 'This language is already on your profile.';
                    }
                }
            }
        } catch (Throwable) {
            return self::response(503, 'Professional details are temporarily unavailable.');
        }
        $escape = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        ob_start();
        require dirname(__DIR__) . '/Views/freelancer-profile/detail-form.php';
        return ['status'=>$errors === [] ? 200 : 422,'type'=>'text/html; charset=UTF-8','body'=>ob_get_clean()];
    }

    private static function response(int $status, string $body): array
    {
        return ['status'=>$status,'type'=>'text/plain; charset=UTF-8','body'=>$body];
    }
}
