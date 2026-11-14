<?php
declare(strict_types=1);
namespace GigHub\Repositories;

use PDO;

final class ProfessionalDetailsRepository
{
    private const TABLES = [
        'education' => ['freelancer_education', 'start_year DESC, id DESC', ['institution','qualification','field_of_study','start_year','end_year']],
        'employment' => ['freelancer_employment', 'start_date DESC, id DESC', ['job_title','company','start_date','end_date','current_job','description']],
        'portfolio' => ['freelancer_portfolio', 'id DESC', ['project_title','project_url','description']],
        'languages' => ['freelancer_languages', 'language ASC', ['language','proficiency']],
    ];

    public function __construct(private readonly PDO $pdo) {}

    public function list(int $owner, string $section): array
    {
        [$table, $order, $columns] = self::TABLES[$section];
        $query = $this->pdo->prepare('SELECT id, ' . implode(', ', $columns) . " FROM $table WHERE freelancer_id = ? ORDER BY $order");
        $query->execute([$owner]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function change(int $owner, string $section, ?int $id, ?array $values): void
    {
        [$table, , $columns] = self::TABLES[$section];
        if ($values === null) {
            $query = $this->pdo->prepare("DELETE FROM $table WHERE freelancer_id = ? AND id = ?");
            $query->execute([$owner, $id]);
        } elseif ($id === null) {
            $names = implode(', ', $columns);
            $markers = implode(', ', array_fill(0, count($columns), '?'));
            $query = $this->pdo->prepare("INSERT INTO $table (freelancer_id, $names) VALUES (?, $markers)");
            $query->execute([$owner, ...array_values($values)]);
        } else {
            $assignments = implode(' = ?, ', $columns) . ' = ?';
            $query = $this->pdo->prepare("UPDATE $table SET $assignments WHERE freelancer_id = ? AND id = ?");
            $query->execute([...array_values($values), $owner, $id]);
        }
    }
}
