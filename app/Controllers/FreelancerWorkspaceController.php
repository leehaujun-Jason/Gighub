<?php
declare(strict_types=1);

namespace GigHub\Controllers;

use Closure;
use GigHub\Core\AuthGuard;
use GigHub\Core\Csrf;
use GigHub\Core\Notification;
use GigHub\Services\FreelancerProfileException;
use GigHub\Services\MatchingService;
use Throwable;

final class FreelancerWorkspaceController
{
    public function __construct(
        private readonly AuthGuard $guard,
        private readonly Closure $matchingFactory,
        private readonly ?Closure $profileFactory = null
    ) {}

    public function index(): array
    {
        $access = $this->guard->check('freelancer');
        if (!isset($access['user'])) {
            return $access;
        }

        $user = $access['user'];
        $freelancerId = (int)$user['id'];

        try {
            /** @var \GigHub\Repositories\JobMatchingRepository $repo */
            /** @var MatchingService $matchingService */
            [$repo, $matchingService] = ($this->matchingFactory)();

            $freelancerSkills = $repo->getFreelancerSkills($freelancerId);
            $freelancerSkillIds = array_column($freelancerSkills, 'id');

            $allSkills = [];
            if ($this->profileFactory !== null) {
                /** @var \GigHub\Services\FreelancerProfileService $profileService */
                $profileService = ($this->profileFactory)();
                $allSkills = $profileService->catalogue();
            }

            $candidateJobs = $repo->getCandidateJobsForFreelancer($freelancerSkillIds);
            $bidStatusMap = $repo->getFreelancerBidStatusMap($freelancerId);

            $recommendations = $matchingService->rankRecommendations(
                $freelancerSkillIds,
                $candidateJobs,
                10,
                $bidStatusMap
            );

            return $this->render('workspace', [
                'user' => $user,
                'navigationUser' => $user,
                'logoutToken' => Csrf::token('logout'),
                'skillsCsrfToken' => Csrf::token('freelancer_profile'),
                'freelancerSkills' => $freelancerSkills,
                'allSkills' => $allSkills,
                'recommendations' => $recommendations,
            ]);
        } catch (Throwable) {
            return [
                'status' => 503,
                'type' => 'text/plain; charset=UTF-8',
                'body' => 'Freelancer workspace is temporarily unavailable. Please try again later.',
            ];
        }
    }

    public function updateSkills(array $post): array
    {
        $access = $this->guard->check('freelancer');
        if (!isset($access['user'])) {
            return $access;
        }

        $user = $access['user'];
        $freelancerId = (int)$user['id'];

        $csrfToken = $post['csrf_token'] ?? null;
        if (!is_string($csrfToken) || !Csrf::valid($csrfToken, 'freelancer_profile')) {
            Notification::flash('error', 'Invalid security token. Please try again.');
            return [
                'status' => 303,
                'type' => 'text/html; charset=UTF-8',
                'body' => '',
                'location' => '?route=/freelancer',
                'headers' => ['Location' => '?route=/freelancer'],
            ];
        }

        try {
            if ($this->profileFactory === null) {
                Notification::flash('error', 'Profile service is temporarily unavailable.');
                return [
                    'status' => 303,
                    'type' => 'text/html; charset=UTF-8',
                    'body' => '',
                    'location' => '?route=/freelancer',
                    'headers' => ['Location' => '?route=/freelancer'],
                ];
            }

            /** @var \GigHub\Services\FreelancerProfileService $profileService */
            $profileService = ($this->profileFactory)();
            $profileService->saveSkills($freelancerId, $post);

            Notification::flash('success', 'Your skills have been updated.');
        } catch (FreelancerProfileException $e) {
            $errors = $e->errors;
            $firstError = reset($errors);
            Notification::flash('error', is_string($firstError) ? $firstError : 'Unable to update skills.');
        } catch (Throwable) {
            Notification::flash('error', 'Skills update is temporarily unavailable. Please try again later.');
        }

        return [
            'status' => 303,
            'type' => 'text/html; charset=UTF-8',
            'body' => '',
            'location' => '?route=/freelancer',
            'headers' => ['Location' => '?route=/freelancer'],
        ];
    }

    private function render(string $view, array $data, int $status = 200): array
    {
        extract($data, EXTR_SKIP);
        $escape = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $assetVersion = static function (string $name): string {
            $path = dirname(__DIR__, 2) . '/public/assets/' . $name;
            return file_exists($path) ? substr(hash_file('sha256', $path), 0, 12) : '1';
        };

        ob_start();
        try {
            require dirname(__DIR__) . '/Views/freelancer/' . $view . '.php';
            $body = (string)ob_get_contents();
        } finally {
            ob_end_clean();
        }

        return ['status' => $status, 'type' => 'text/html; charset=UTF-8', 'body' => $body];
    }
}
