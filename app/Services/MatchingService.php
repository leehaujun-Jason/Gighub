<?php
declare(strict_types=1);

namespace GigHub\Services;

class MatchingService
{
    /**
     * Compute Jaccard Similarity between two sets of canonical skill IDs:
     * J(A, B) = |A ∩ B| / |A ∪ B|
     *
     * Threshold is evaluated using exact integer arithmetic:
     * |A ∩ B| / |A ∪ B| >= 1/5 <=> 5 * |A ∩ B| >= |A ∪ B| (for |A ∪ B| > 0).
     */
    public function computeJaccard(array $freelancerSkillIds, array $jobSkillIds): array
    {
        $setA = array_values(array_unique(array_map('intval', $freelancerSkillIds)));
        $setB = array_values(array_unique(array_map('intval', $jobSkillIds)));

        $intersection = array_values(array_intersect($setA, $setB));
        $union = array_values(array_unique(array_merge($setA, $setB)));

        $interCount = count($intersection);
        $unionCount = count($union);

        if ($unionCount === 0 || $interCount === 0) {
            return [
                'score' => 0.0,
                'percentage' => '0.0%',
                'percentage_value' => 0.0,
                'intersection_ids' => [],
                'union_ids' => $union,
                'intersection_count' => 0,
                'union_count' => $unionCount,
                'meets_threshold' => false,
            ];
        }

        // Exact integer-ratio comparison against 20% (1/5)
        $meetsThreshold = ($interCount * 5 >= $unionCount);
        $score = (float)$interCount / (float)$unionCount;
        $percentageValue = round($score * 100, 1);
        $percentage = number_format($percentageValue, 1) . '%';

        return [
            'score' => $score,
            'percentage' => $percentage,
            'percentage_value' => $percentageValue,
            'intersection_ids' => $intersection,
            'union_ids' => $union,
            'intersection_count' => $interCount,
            'union_count' => $unionCount,
            'meets_threshold' => $meetsThreshold,
        ];
    }

    /**
     * Ranks open candidate jobs by Jaccard score (>= 0.20), created_at DESC, id DESC.
     * Truncates to $limit only AFTER deterministic ranking.
     *
     * @param int[] $freelancerSkillIds
     * @param array $jobsWithSkills List of jobs, each containing 'skills' => [['id' => ..., 'name' => ...], ...]
     * @param int $limit Maximum number of top recommendations to return (default 10)
     * @param array<int, string> $bidStatusMap Map of job_id => bid_status for existing bids by this freelancer
     * @return array Ranked qualified recommendations
     */
    public function rankRecommendations(
        array $freelancerSkillIds,
        array $jobsWithSkills,
        int $limit = 10,
        array $bidStatusMap = []
    ): array {
        if ($freelancerSkillIds === [] || $jobsWithSkills === []) {
            return [];
        }

        $qualified = [];

        foreach ($jobsWithSkills as $job) {
            $jobSkills = $job['skills'] ?? [];
            $jobSkillIds = array_column($jobSkills, 'id');

            $match = $this->computeJaccard($freelancerSkillIds, $jobSkillIds);

            // Filter by 20% threshold before ranking
            if (!$match['meets_threshold']) {
                continue;
            }

            $matchedSkills = [];
            $unmatchedSkills = [];
            foreach ($jobSkills as $skill) {
                if (in_array((int)$skill['id'], $match['intersection_ids'], true)) {
                    $matchedSkills[] = $skill;
                } else {
                    $unmatchedSkills[] = $skill;
                }
            }

            $jobId = (int)$job['id'];
            $job['match'] = $match;
            $job['matched_skills'] = $matchedSkills;
            $job['unmatched_skills'] = $unmatchedSkills;
            $job['bid_status'] = $bidStatusMap[$jobId] ?? null;

            $qualified[] = $job;
        }

        // Multi-tier deterministic sorting:
        // 1. Highest similarity score first
        // 2. Newest job created_at first (tie-breaker 1)
        // 3. Highest job ID first (tie-breaker 2)
        usort($qualified, static function (array $a, array $b): int {
            if ($a['match']['score'] !== $b['match']['score']) {
                return $b['match']['score'] <=> $a['match']['score'];
            }
            $timeCmp = strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
            if ($timeCmp !== 0) {
                return $timeCmp;
            }
            return (int)$b['id'] <=> (int)$a['id'];
        });

        return array_slice($qualified, 0, $limit);
    }

    /**
     * Enriches browsed public jobs with similarity score and matched-skill flags.
     * Preserves original search/pagination order and does not filter by threshold.
     *
     * @param int[] $freelancerSkillIds
     * @param array $browsedJobs
     * @return array
     */
    public function enrichBrowsedJobs(array $freelancerSkillIds, array $browsedJobs): array
    {
        if ($browsedJobs === []) {
            return [];
        }

        foreach ($browsedJobs as &$job) {
            $jobSkills = $job['skills'] ?? [];
            $jobSkillIds = array_column($jobSkills, 'id');

            $match = $this->computeJaccard($freelancerSkillIds, $jobSkillIds);

            // Annotate individual skills with whether they match the freelancer
            foreach ($jobSkills as &$skill) {
                $skill['is_matched'] = in_array((int)$skill['id'], $match['intersection_ids'], true);
            }
            unset($skill);

            $job['skills'] = $jobSkills;
            $job['match'] = $match;
        }
        unset($job);

        return $browsedJobs;
    }
}
