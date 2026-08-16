<?php

declare(strict_types=1);

use Rokn\Tooling\RepositorySecretScanner;

require_once __DIR__.'/RepositorySecretScanner.php';

$root = dirname(__DIR__);
$scanHistory = false;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--root=')) {
        $root = substr($argument, strlen('--root='));
    }

    if ($argument === '--history') {
        $scanHistory = true;
    }
}

/**
 * @param  list<string>  $arguments
 */
$runGit = static function (array $arguments) use ($root): string {
    $process = proc_open(
        array_merge(['git', '-C', $root], $arguments),
        [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );

    if (! is_resource($process)) {
        fwrite(STDERR, "Repository secret scan could not start Git.\n");
        exit(2);
    }

    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0 || $output === false) {
        fwrite(STDERR, "Repository secret scan could not enumerate files.\n");

        if (is_string($error) && trim($error) !== '') {
            fwrite(STDERR, trim($error)."\n");
        }

        exit(2);
    }

    return $output;
};

$output = $runGit(['ls-files', '--cached', '--others', '--exclude-standard', '-z']);
$paths = array_values(array_filter(explode("\0", $output), static fn (string $path): bool => $path !== ''));
$scanner = new RepositorySecretScanner();
$issues = $scanner->scanFiles($root, $paths);

if ($scanHistory) {
    $historyOutput = $runGit(['log', '--all', '--format=', '--name-only', '--', '.']);
    $historyPaths = preg_split('/\R/', $historyOutput) ?: [];
    $historyPaths = array_values(array_filter($historyPaths, static fn (string $path): bool => trim($path) !== ''));

    foreach ($scanner->scanPathNames($historyPaths) as $issue) {
        $issues[] = [
            'path' => 'history:'.$issue['path'],
            'rule' => $issue['rule'],
        ];
    }

    $commits = preg_split('/\R/', trim($runGit(['rev-list', '--all']))) ?: [];
    $commits = array_values(array_filter(
        $commits,
        static fn (string $commit): bool => preg_match('/^[0-9a-f]{40}$/', $commit) === 1
    ));

    foreach ($scanner->historyContentPatterns() as $rule => $pattern) {
        foreach (array_chunk($commits, 50) as $commitChunk) {
            $process = proc_open(
                array_merge(
                    ['git', '-C', $root, 'grep', '-l', '-E', '-e', $pattern],
                    $commitChunk,
                    ['--']
                ),
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes
            );

            if (! is_resource($process)) {
                fwrite(STDERR, "Repository secret history scan could not start Git.\n");
                exit(2);
            }

            $matches = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            if ($exitCode === 1) {
                continue;
            }

            if ($exitCode !== 0 || $matches === false) {
                fwrite(STDERR, "Repository secret history scan could not inspect blobs.\n");

                if (is_string($error) && trim($error) !== '') {
                    fwrite(STDERR, trim($error)."\n");
                }

                exit(2);
            }

            foreach (preg_split('/\R/', trim($matches)) ?: [] as $match) {
                if ($match === '') {
                    continue;
                }

                if (preg_match('/^([0-9a-f]{40}):(.*)$/', $match, $parts) !== 1) {
                    fwrite(STDERR, "Repository secret history scan returned an invalid blob path.\n");
                    exit(2);
                }

                $commit = $parts[1];
                $path = $parts[2];

                if ($rule === 'named_secret_assignment') {
                    $blob = $runGit(['cat-file', 'blob', $commit.':'.$path]);

                    if (in_array('non_placeholder_secret_assignment', $scanner->scanContents($blob), true)) {
                        $issues[] = [
                            'path' => 'history:'.$path,
                            'rule' => 'non_placeholder_secret_assignment',
                        ];
                    }

                    continue;
                }

                $issues[] = [
                    'path' => 'history:'.$path,
                    'rule' => $rule,
                ];
            }
        }
    }
}

if ($issues !== []) {
    $deduplicatedIssues = [];

    foreach ($issues as $issue) {
        $deduplicatedIssues[$issue['path'].'|'.$issue['rule']] = $issue;
    }

    $issues = array_values($deduplicatedIssues);
    usort($issues, static fn (array $left, array $right): int => [$left['path'], $left['rule']] <=> [$right['path'], $right['rule']]);

    fwrite(STDERR, "Repository secret scan failed. Values are intentionally redacted.\n");

    foreach ($issues as $issue) {
        fwrite(STDERR, sprintf("- %s [%s]\n", $issue['path'], $issue['rule']));
    }

    exit(1);
}

fwrite(STDOUT, sprintf("Repository secret scan passed (%d current files%s).\n", count($paths), $scanHistory ? ' plus history paths' : ''));
