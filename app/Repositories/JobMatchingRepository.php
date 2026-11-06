<?php
declare(strict_types=1);

namespace GigHub\Repositories;

use PDO;

class JobMatchingRepository
{
    private const CANDIDATE_SELECT = 'SELECT j.id, j.title, j.description, j.budget, j.deadline, j.created_at, u.display_name AS client_name ' .
        'FROM jobs j ' .
        'JOIN client_profiles cp ON cp.user_id = j.client_id ' .
        'JOIN users u ON u.id = cp.user_id ' .
        'WHERE j.status = \'open\' AND u.role = \'client\' AND u.status = \'active\' AND u.email_verified_at IS NOT NULL';

    public function __construct(protected readonly PDO $pdo) {}

    /**
     * Checks if a freelancer account is active, verified, and has a profile.
     */
    public function isEligibleFreelancer(int $userId): bool
    {
        if (!isset($this->pdo)) {
            return false;
        }
        $q = $this->pdo->prepare("SELECT u.id FROM users u JOIN freelancer_profiles p ON p.user_id = u.id WHERE u.id = ? AND u.role = 'freelancer' AND u.status = 'active' AND u.email_verified_at IS NOT NULL");
        $q->execute([$userId]);
        return $q->fetchColumn() !== false;
    }

    /**
     * Retrieves all registered skills for a freelancer.
     *
     * @return array<array{id: int, name: string}>
     */
    public function getFreelancerSkills(int $freelancerId): array
    {
        if (!isset($this->pdo)) {
            return [];
        }
        $q = $this->pdo->prepare('SELECT s.id, s.name FROM skills s JOIN user_skills us ON us.skill_id = s.id WHERE us.freelancer_id = ? ORDER BY s.name ASC, s.id ASC');
        $q->execute([$freelancerId]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn(array $r): array => ['id' => (int)$r['id'], 'name' => (string)$r['name']], $rows);
    }

    /**
     * Retrieves existing bids submitted by this freelancer.
     *
     * @return array<int, string> Map of job_id => bid_status
     */
    public function getFreelancerBidStatusMap(int $freelancerId): array
    {
        if (!isset($this->pdo)) {
            return [];
        }
        $q = $this->pdo->prepare('SELECT job_id, status FROM bids WHERE freelancer_id = ?');
        $q->execute([$freelancerId]);
        $map = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int)$row['job_id']] = (string)$row['status'];
        }
        return $map;
    }

    /**
     * Retrieves all eligible candidate open jobs sharing at least one skill with the freelancer.
     * Does not prematurely limit results so that full Jaccard ranking can occur across the entire pool.
     *
     * @param int[] $freelancerSkillIds
     * @return array Candidate jobs with 'skills' populated
     */
    public function getCandidateJobsForFreelancer(array $freelancerSkillIds): array
    {
        if (!isset($this->pdo) || $freelancerSkillIds === []) {
            return [];
        }

        $cleanSkillIds = array_values(array_unique(array_map('intval', $freelancerSkillIds)));
        if ($cleanSkillIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($cleanSkillIds), '?'));
        $sql = self::CANDIDATE_SELECT .
            " AND EXISTS (SELECT 1 FROM job_skills js WHERE js.job_id = j.id AND js.skill_id IN ($placeholders)) " .
            'ORDER BY j.created_at DESC, j.id DESC';

        $q = $this->pdo->prepare($sql);
        foreach ($cleanSkillIds as $idx => $id) {
            $q->bindValue($idx + 1, $id, PDO::PARAM_INT);
        }
        $q->execute();
        $jobs = $q->fetchAll(PDO::FETCH_ASSOC);

        return $this->populateJobSkillsBatched($jobs);
    }

    /**
     * Batched skill retrieval for a list of jobs in bounded chunks to avoid N+1 queries.
     *
     * @param array $jobs
     * @return array Jobs with 'skills' => [['id' => ..., 'name' => ...], ...]
     */
    public function populateJobSkillsBatched(array $jobs): array
    {
        if (!isset($this->pdo) || $jobs === []) {
            return [];
        }

        $jobIds = array_column($jobs, 'id');
        $skillMap = [];

        // Bounded chunks of 100 job IDs per query
        $chunks = array_chunk($jobIds, 100);
        foreach ($chunks as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $sql = "SELECT js.job_id, s.id, s.name " .
                   "FROM job_skills js " .
                   "JOIN skills s ON s.id = js.skill_id " .
                   "WHERE js.job_id IN ($placeholders) " .
                   "ORDER BY s.name ASC, s.id ASC";

            $q = $this->pdo->prepare($sql);
            foreach ($chunk as $idx => $jobId) {
                $q->bindValue($idx + 1, (int)$jobId, PDO::PARAM_INT);
            }
            $q->execute();

            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $jId = (int)$row['job_id'];
                $skillMap[$jId][] = [
                    'id' => (int)$row['id'],
                    'name' => (string)$row['name'],
                ];
            }
        }

        foreach ($jobs as &$job) {
            $job['id'] = (int)$job['id'];
            $job['skills'] = $skillMap[$job['id']] ?? [];
        }
        unset($job);

        return $jobs;
    }
}
