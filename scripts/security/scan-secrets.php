<?php

declare(strict_types=1);

/**
 * Lightweight repository secret scan for obvious hardcoded credentials.
 * Intended for CI/pre-commit gating with low false-positive noise.
 */

$root = dirname(__DIR__, 2);
chdir($root);

$trackedFilesOutput = shell_exec('git ls-files');
if (!is_string($trackedFilesOutput) || trim($trackedFilesOutput) === '') {
    fwrite(STDERR, "Unable to enumerate tracked files.\n");
    exit(2);
}

$trackedFiles = array_values(array_filter(
    preg_split('/\r\n|\r|\n/', $trackedFilesOutput) ?: [],
    fn (string $path): bool => $path !== ''
));

$allowValues = [
    '',
    'null',
    'none',
    'changeme',
    'change-me',
    'example',
    'example-value',
    'your-value-here',
    'your-password',
    'your-secret',
    'your-token',
];

$sensitiveKeyPattern = '/\b([A-Z][A-Z0-9_]*(?:PASSWORD|SECRET|TOKEN|API_KEY|ACCESS_KEY|PRIVATE_KEY|APP_KEY))\b/';
$linePattern = '/^\s*([A-Z][A-Z0-9_]*(?:PASSWORD|SECRET|TOKEN|API_KEY|ACCESS_KEY|PRIVATE_KEY|APP_KEY))\s*[:=]\s*(.+?)\s*$/';

$violations = [];

foreach ($trackedFiles as $path) {
    if (!is_file($path)) {
        continue;
    }

    if (
        str_starts_with($path, 'apps/api/vendor/')
        || str_starts_with($path, 'apps/web/node_modules/')
    ) {
        continue;
    }

    $contents = file($path, FILE_IGNORE_NEW_LINES);
    if ($contents === false) {
        continue;
    }

    foreach ($contents as $index => $line) {
        if (!preg_match($sensitiveKeyPattern, $line)) {
            continue;
        }

        if (!preg_match($linePattern, $line, $matches)) {
            continue;
        }

        $key = strtoupper(trim((string) $matches[1]));
        $rawValue = trim((string) $matches[2]);
        $normalized = strtolower(trim($rawValue, " \t\n\r\0\x0B\"'"));

        if ($rawValue === '' || str_contains($rawValue, '${') || str_starts_with($rawValue, '$')) {
            continue;
        }
        if (in_array($normalized, $allowValues, true)) {
            continue;
        }
        if (preg_match('/^(?:your-|example-|<|xxx)/i', $normalized) === 1) {
            continue;
        }

        $violations[] = sprintf('%s:%d %s=%s', $path, $index + 1, $key, $rawValue);
    }
}

if (count($violations) > 0) {
    fwrite(STDERR, "Potential hardcoded secrets detected:\n");
    foreach ($violations as $violation) {
        fwrite(STDERR, " - {$violation}\n");
    }
    exit(1);
}

fwrite(STDOUT, "Secrets scan passed.\n");
exit(0);
