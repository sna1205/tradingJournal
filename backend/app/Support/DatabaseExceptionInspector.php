<?php

namespace App\Support;

use Throwable;

final class DatabaseExceptionInspector
{
    private const CONNECTION_ERROR_CODES = [
        '2002', // MySQL: connection refused.
        '2003', // MySQL: cannot connect to server.
        '2006', // MySQL: server has gone away.
        '7',    // PostgreSQL: could not connect.
    ];

    private const CONNECTION_MESSAGE_PATTERNS = [
        'connection refused',
        'connection timed out',
        'could not connect',
        'server has gone away',
        'name or service not known',
        'no route to host',
        'php_network_getaddresses',
        'temporary failure in name resolution',
    ];

    public static function isConnectionIssue(Throwable $exception): bool
    {
        $current = $exception;
        $depth = 0;

        while ($depth < 8) {
            if (self::matchesConnectionIssue($current)) {
                return true;
            }

            $previous = $current->getPrevious();
            if ($previous === null) {
                return false;
            }

            $current = $previous;
            $depth++;
        }

        return false;
    }

    private static function matchesConnectionIssue(Throwable $exception): bool
    {
        $code = trim((string) $exception->getCode());
        if (in_array($code, self::CONNECTION_ERROR_CODES, true)) {
            return true;
        }

        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'sqlstate[08')) {
            return true;
        }

        foreach (self::CONNECTION_MESSAGE_PATTERNS as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
