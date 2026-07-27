<?php

declare(strict_types=1);

$coverageFile = $argv[1] ?? 'build/coverage.xml';
$minimumCoverage = isset($argv[2]) ? (float) $argv[2] : 60.0;

if (!is_file($coverageFile)) {
    fwrite(STDERR, "Coverage report not found: $coverageFile\n");
    exit(1);
}

$report = simplexml_load_file($coverageFile);
if ($report === false) {
    fwrite(STDERR, "Unable to parse coverage report: $coverageFile\n");
    exit(1);
}

$metrics = $report->project->metrics;
$statements = (int) $metrics['statements'];
$coveredStatements = (int) $metrics['coveredstatements'];
$coverage = $statements === 0 ? 0.0 : ($coveredStatements / $statements) * 100;

printf("Statement coverage: %.2f%% (required: %.2f%%)\n", $coverage, $minimumCoverage);
exit($coverage >= $minimumCoverage ? 0 : 1);
