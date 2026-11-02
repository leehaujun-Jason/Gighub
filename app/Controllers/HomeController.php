<?php
declare(strict_types=1);

namespace GigHub\Controllers;

use GigHub\Core\Csrf;
use GigHub\Core\PublicIdentity;
use GigHub\Services\JobBrowseValidator;
use Closure;
use Throwable;

final class HomeController
{
    public function __construct(private readonly ?PublicIdentity $identity = null, private readonly ?Closure $repositoryFactory = null) {}

    public function show(array $input = []): array
    {
        $navigationUser = $this->identity?->current();
        $logoutToken = $navigationUser === null ? null : Csrf::token('logout');
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $search = JobBrowseValidator::validate(array_intersect_key($input, ['q'=>true]));
        $query = $search['data']['q'];
        $searchError = $search['errors']['q'] ?? null;
        $skills = []; $jobs = []; $skillsUnavailable = true; $jobsUnavailable = true;
        if ($this->repositoryFactory !== null) {
            try {
                [$repository, $catalogue] = ($this->repositoryFactory)();
                try { $skills = $catalogue->all(); $skillsUnavailable = false; } catch (Throwable) { /* Safe section fallback. */ }
                try {
                    // Reuse the same public projection, eligibility rules, ordering and batch skills as /jobs.
                    $jobs = array_slice($repository->browse(JobBrowseValidator::validate([])['data']), 0, 6);
                    $jobsUnavailable = false;
                } catch (Throwable) { /* Do not reveal database diagnostics. */ }
            } catch (Throwable) { /* Homepage navigation and search remain available. */ }
        }
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kuala_Lumpur')))->format('Y-m-d');
        $skillUrl = static fn(int $id): string => '?' . http_build_query(['route'=>'/jobs','skills'=>[$id]], '', '&', PHP_QUERY_RFC3986);
        ob_start();
        try { require dirname(__DIR__) . '/Views/home.php'; $body = ob_get_contents(); } finally { ob_end_clean(); }
        return ['status' => $searchError === null ? 200 : 422, 'type' => 'text/html; charset=UTF-8', 'body' => $body];
    }
}
