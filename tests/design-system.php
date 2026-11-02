<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__); $count = 0;
$check = static function(bool $ok, string $label) use (&$count): void { if (!$ok) { throw new RuntimeException('FAIL: '.$label); } ++$count; };
$check(is_file($root.'/public/assets/design-tokens.css'), 'shared design tokens exist');
$tokens = file_get_contents($root.'/public/assets/design-tokens.css');
$components = file_get_contents($root.'/public/assets/components.css');
$shell = file_get_contents($root.'/public/assets/shared-shell.css');
$home = file_get_contents($root.'/app/Views/home.php');
$check(str_contains($home, "hash_file('sha256'") && substr_count($home, '.css?v=') === 4, 'all homepage styles use content-based cache versions');
$check(str_contains($shell, '.gh-main { background: var(--gh-background);'), 'main has explicit approved background');
foreach (['#174f40','#103d31','#0b3027','#eaf1e8','#f5f7f3','#ffffff','#142c25','#172b28','#52685e','#77877b'] as $color) { $check(str_contains($tokens,$color), 'approved token '.$color); }
$check(str_contains($tokens,'--gh-control-radius: .5rem') && str_contains($tokens,'--gh-card-radius: .75rem'), 'control and card radii');
$check(str_contains($tokens,'--gh-font-size: 1rem'), '16px default body token');
foreach (['design-tokens.css','components.css','shared-shell.css','home.css'] as $asset) { $check(str_contains($home,'assets/'.$asset), 'homepage loads '.$asset); }
$check(strpos($home,'design-tokens.css') < strpos($home,'components.css') && strpos($home,'components.css') < strpos($home,'home.css'), 'stylesheet layering');
foreach (['.gh-button','.gh-nav-link','.gh-skill-chip','.gh-job-card','.gh-footer-link','.gh-control',':hover',':active',':focus-visible',':disabled','[aria-disabled="true"]','[aria-invalid="true"]','[readonly]','prefers-reduced-motion','forced-colors'] as $state) { $check(str_contains($components,$state), 'component/state '.$state); }
$check(str_contains($components,'outline: 3px solid') && str_contains($components,'text-decoration: underline'), 'visible focus and recognizable inline links');
$check(str_contains($shell,'.gh-mobile-menu { display: none; }') && str_contains($shell,'.gh-desktop-nav { display: none; }') && str_contains($shell,'.gh-mobile-menu { display: block; }'), 'mutually exclusive navigation display rules');
$header = file_get_contents($root.'/app/Views/partials/header.php');
$check(substr_count($header,"require __DIR__ . '/navigation-links.php'") === 2, 'same navigation definition rendered for both viewport modes');
$check(str_contains($header,'class="gh-mobile-menu"') && !str_contains($header,'class="gh-mobile-menu" open'), 'native mobile menu starts collapsed');
// Validate contrast of proposed core token pairs using WCAG relative luminance.
$luminance = static function(string $hex): float {
    $rgb = array_map(static function(string $v): float { $s = hexdec($v)/255; return $s <= .04045 ? $s/12.92 : (($s+.055)/1.055)**2.4; },str_split(ltrim($hex,'#'),2));
    return .2126*$rgb[0]+.7152*$rgb[1]+.0722*$rgb[2];
};
foreach ([['#ffffff','#174f40',4.5],['#172b28','#f5f7f3',4.5],['#52685e','#ffffff',4.5],['#f5f7f3','#142c25',4.5],['#174f40','#eaf1e8',4.5],['#77877b','#ffffff',3],['#77877b','#f5f7f3',3]] as [$fg,$bg,$minimum]) {
    $a=$luminance($fg);$b=$luminance($bg);$check((max($a,$b)+.05)/(min($a,$b)+.05)>=$minimum,'token contrast '.$fg.' / '.$bg);
}
echo "PASS: {$count} design-system offline checks; rendered browser behavior remains unverified.\n";
