<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
foreach (['Core/Csrf','Core/AuthSession','Core/PublicIdentity','Services/JobBrowseValidator','Repositories/PublicJobRepository','Controllers/HomeController'] as $class) {
    require_once $root . '/app/' . $class . '.php';
}
$count = 0;
$check = static function(bool $ok, string $label) use (&$count): void {
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$count;
};
$check((new ReflectionMethod(GigHub\Controllers\HomeController::class, '__construct'))->getNumberOfParameters() >= 2, 'homepage accepts isolated repository factory');
$jobs = new class {
    public array $filters = [];
    public bool $fail = false;
    public function browse(array $filters): array {
        $this->filters = $filters;
        if ($this->fail) { throw new RuntimeException('SECRET INTERNAL DETAIL'); }
        return array_map(static fn(int $id): array => ['id'=>$id,'title'=>'<unsafe title>','description'=>'<unsafe description>','budget'=>'123.45','deadline'=>'2020-01-01','created_at'=>'2026-10-01 12:00:00','skills'=>[['id'=>29,'name'=>'<unsafe skill>']]], range(8,1));
    }
};
$skills = new class {
    public bool $fail = false;
    public function all(): array {
        if ($this->fail) { throw new RuntimeException('SECRET INTERNAL DETAIL'); }
        return [['id'=>29,'name'=>'<unsafe skill>']];
    }
};
$_SESSION = [];
$home = new GigHub\Controllers\HomeController(null, static fn(): array => [$jobs,$skills]);
$response = $home->show(['q'=>'PHP & HTML']); $html = $response['body'];
$check($response['status'] === 200, 'public homepage renders');
$check(str_contains($html, '<form method="get" action="index.php"') && str_contains($html, 'name="route" value="/jobs"'), 'GET search uses existing route');
$check(str_contains($html, 'name="q" value="PHP &amp; HTML"'), 'search query safely retained');
$check(str_contains($html, '<label for="home-search">'), 'search labelled');
$check(str_contains($html, 'skills%5B0%5D=29') && str_contains($html, '&lt;unsafe skill&gt;'), 'real skill IDs and escaped names');
$check(substr_count($html, 'class="gh-job-card"') === 6, 'at most six jobs');
$check(strpos($html, '/jobs/show&amp;id=8') < strpos($html, '/jobs/show&amp;id=7'), 'repository ordering preserved');
$check(!str_contains($html, '/jobs/show&amp;id=2'), 'extra results omitted');
$check($jobs->filters === ['q'=>'','min_budget'=>'','max_budget'=>'','skills'=>[],'sort'=>'newest','page'=>1], 'recent jobs reuse default public browse filters');
foreach (['title','description','skill'] as $field) {
    $check(str_contains($html, '&lt;unsafe '.$field.'&gt;') && !str_contains($html, '<unsafe '.$field.'>'), 'escaped '.$field);
}
$check(str_contains($html, 'Delivery deadline has passed'), 'overdue remains visible with warning');
$check(str_contains($html, 'Created (UTC)') && !str_contains($html, 'Published today'), 'creation date not claimed as publication timestamp');
$check(str_contains($html, 'href="?route=/register"'), 'guest posting uses registration');
foreach (['client'=>'/client/jobs/create','freelancer'=>'/freelancer','admin'=>'/admin'] as $role=>$target) {
    $_SESSION = ['auth'=>['user_id'=>7,'issued_at'=>time(),'last_activity'=>time()]];
    $identity = new GigHub\Core\PublicIdentity(new GigHub\Core\AuthSession(), static fn(): array => ['id'=>7,'role'=>$role,'display_name'=>'Synthetic']);
    $body = (new GigHub\Controllers\HomeController($identity, static fn(): array => [$jobs,$skills]))->show()['body'];
    $check(str_contains($body, 'href="?route='.$target.'"'), $role.' CTA');
    $check(!str_contains($body, 'href="?route=/register"'), 'no role switching registration CTA for '.$role);
}
$jobs->fail = true;
$html = $home->show()['body'];
$check(str_contains($html, 'Jobs are temporarily unavailable') && !str_contains($html, 'No open jobs yet'), 'job failure distinct from empty');
$check(str_contains($html, 'skills%5B0%5D=29') && !str_contains($html, 'SECRET INTERNAL DETAIL'), 'independent skills and sanitized failures');
$jobs->fail = false; $skills->fail = true;
$html = $home->show()['body'];
$check(str_contains($html, 'Skills are temporarily unavailable') && substr_count($html, 'class="gh-job-card"') === 6, 'skills failure preserves jobs');
$html = (new GigHub\Controllers\HomeController(null, static fn(): array => [new class { public function browse(array $f): array { return []; } },new class { public function all(): array { return []; } }]))->show()['body'];
$check(str_contains($html, 'No open jobs yet') && str_contains($html, 'No skills are listed yet'), 'honest empty states');
$html = (new GigHub\Controllers\HomeController(null, static function(): never { throw new RuntimeException('SECRET'); }))->show()['body'];
$check(str_contains($html, 'Jobs are temporarily unavailable') && str_contains($html, 'Skills are temporarily unavailable') && !str_contains($html, 'SECRET'), 'factory failure keeps public shell');
foreach ([['q'=>[]],['q'=>str_repeat('a',101)],['q'=>"\xff"]] as $input) {
    $check($home->show($input)['status'] === 422, 'invalid search safely rejected');
}
// Execute the actual public repository from the homepage using an offline PDO double.
$pdo = new class extends PDO {
    public array $queries = [];
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $this->queries[] = $query;
        return new class extends PDOStatement {
            public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool { return true; }
            public function execute(?array $params = null): bool { return true; }
            public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return []; }
        };
    }
};
$realRepository = new GigHub\Repositories\PublicJobRepository($pdo);
(new GigHub\Controllers\HomeController(null, static fn(): array => [$realRepository,$skills]))->show();
foreach (["j.status='open'", "u.role='client'", "u.status='active'", 'u.email_verified_at IS NOT NULL', 'j.created_at DESC,j.id DESC'] as $clause) {
    $check(str_contains($pdo->queries[0], $clause), 'homepage actual repository SQL '.$clause);
}
$check(!str_contains($pdo->queries[0], 'SELECT *') && !str_contains($pdo->queries[0], 'u.email,'), 'homepage uses public projection');
$css = file_get_contents($root.'/public/assets/home.css');
$check(str_contains($css, 'max-width: 900px') && str_contains($css, 'max-width: 600px') && str_contains($css, 'minmax(0, 1fr)'), 'responsive grids are defined; browser acceptance remains pending');
echo "PASS: {$count} homepage offline checks; no database/network access.\n";
