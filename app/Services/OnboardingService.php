<?php
declare(strict_types=1);
namespace GigHub\Services;

use GigHub\Repositories\OnboardingRepository;
use GigHub\Repositories\SkillRepository;
use PDO;
use Throwable;

final class OnboardingService
{
    public function __construct(private readonly PDO $pdo, private readonly OnboardingRepository $profiles,
        private readonly SkillRepository $skills) {}

    public function form(array $user): array
    {
        $freelancer = $user['role'] === 'freelancer';
        return [
            $this->profiles->profile($user['id'], $user['role']) + [
                'display_name' => $user['display_name'],
                'skills' => $freelancer ? $this->profiles->skillIds($user['id']) : [],
            ],
            $freelancer ? $this->skills->all() : [],
        ];
    }

    public function validate(array $user, array $input, array $catalogue): array
    {
        $freelancer = $user['role'] === 'freelancer';
        $fields = $freelancer ? ['headline' => [150, true], 'bio' => [2000, true]]
            : ['display_name' => [100, true], 'account_type' => [10, true], 'company_name' => [100, false], 'state' => [50, false], 'city' => [100, false], 'bio' => [2000, false]];
        $data = ['display_name' => $user['display_name'], 'skills' => []];
        $errors = [];
        foreach ($fields as $field => [$max, $required]) {
            $raw = $input[$field] ?? '';
            if (!is_string($raw) || !mb_check_encoding($raw, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $raw)
                || ($field !== 'bio' && preg_match('/[\r\n\t]/', $raw))) {
                $errors[$field] = 'Enter a valid text value.';
                $raw = '';
            }
            $data[$field] = mb_substr(trim($raw), 0, $max);
            if (mb_strlen(trim($raw)) > $max || ($required && $data[$field] === '')) {
                $errors[$field] ??= "Enter a value within the $max-character limit.";
            }
        }
        if (!$freelancer) {
            if (!in_array($data['account_type'], ['individual', 'company'], true)) {
                $errors['account_type'] = 'Choose Individual or Company.';
            }
            if ($data['account_type'] === 'individual') {
                $data['company_name'] = '';
            }
            if ($data['state'] !== '' && !ClientLocation::validState($data['state'])) {
                $errors['state'] = 'Choose a valid Malaysian state or federal territory.';
            }
        } else {
            $valid = array_fill_keys(array_column($catalogue, 'id'), true);
            $selected = $input['skills'] ?? [];
            if (!is_array($selected) || count($selected) > count($valid)) {
                $errors['skills'] = 'Select distinct skills from the catalogue.';
                $selected = [];
            }
            foreach ($selected as $id) {
                if (!is_string($id) || !isset($valid[$id]) || isset($data['skills'][$id])) {
                    $errors['skills'] = 'Select distinct skills from the catalogue.';
                    continue;
                }
                $data['skills'][$id] = (int) $id;
            }
            $data['skills'] = array_values($data['skills']);
            if (count($data['skills']) < 1) {
                $errors['skills'] ??= 'Select at least one skill.';
            }
        }
        return [$data, $errors];
    }

    public function complete(array $user, array $data): bool
    {
        $this->pdo->beginTransaction();
        try {
            $completed = $this->profiles->complete($user['id'], $user['role'], $data);
            $this->pdo->commit();
            return $completed;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
