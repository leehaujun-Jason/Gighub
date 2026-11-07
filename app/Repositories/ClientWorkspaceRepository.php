<?php
declare(strict_types=1);

namespace GigHub\Repositories;

use PDO;

class ClientWorkspaceRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Fetch aggregated dashboard statistics for a client.
     *
     * @return array{total_jobs: int, open_jobs: int, active_projects: int, pending_reviews: int}
     */
    public function getStatistics(int $clientId): array
    {
        // 1. Total Jobs owned by this client
        $stmtTotal = $this->pdo->prepare('SELECT COUNT(*) FROM jobs WHERE client_id = ?');
        $stmtTotal->execute([$clientId]);
        $totalJobs = (int)$stmtTotal->fetchColumn();

        // 2. Open Jobs owned by this client
        $stmtOpen = $this->pdo->prepare("SELECT COUNT(*) FROM jobs WHERE client_id = ? AND status = 'open'");
        $stmtOpen->execute([$clientId]);
        $openJobs = (int)$stmtOpen->fetchColumn();

        // 3. Active Projects (awarded, in progress, under review)
        $stmtActive = $this->pdo->prepare("SELECT COUNT(*) FROM jobs WHERE client_id = ? AND status IN ('awarded', 'in_progress', 'under_review')");
        $stmtActive->execute([$clientId]);
        $activeProjects = (int)$stmtActive->fetchColumn();

        // 4. Pending Reviews: outstanding actionable work submissions on active projects owned by this client
        $stmtPending = $this->pdo->prepare(
            "SELECT COUNT(*) 
             FROM work_submissions ws 
             JOIN jobs j ON j.id = ws.job_id 
             WHERE j.client_id = ? 
               AND j.status IN ('in_progress', 'under_review') 
               AND ws.status = 'submitted'"
        );
        $stmtPending->execute([$clientId]);
        $pendingReviews = (int)$stmtPending->fetchColumn();

        return [
            'total_jobs' => $totalJobs,
            'open_jobs' => $openJobs,
            'active_projects' => $activeProjects,
            'pending_reviews' => $pendingReviews,
        ];
    }

    /**
     * Fetch the most recent jobs for a client with bid counts.
     *
     * @return array<int, array{id: int, title: string, status: string, budget: string, deadline: string|null, created_at: string, bid_count: int}>
     */
    public function getRecentJobs(int $clientId, int $limit = 5): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT 
                j.id, 
                j.title, 
                j.status, 
                j.budget, 
                j.deadline, 
                j.created_at,
                (SELECT COUNT(*) FROM bids b WHERE b.job_id = j.id) AS bid_count
             FROM jobs j
             WHERE j.client_id = ?
             ORDER BY j.id DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $clientId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
