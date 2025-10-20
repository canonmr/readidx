<?php

declare(strict_types=1);

namespace Readidx\Backend\Support;

final class Logger
{
    private const DEFAULT_FILENAME = 'application.log';

    /**
     * @param array<string, mixed> $context
     */
    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function write(string $level, string $message, array $context): void
    {
        $logDir = dirname(__DIR__, 3) . '/logs';

        if (!is_dir($logDir) && !mkdir($logDir, 0775, true) && !is_dir($logDir)) {
            error_log(sprintf('[readidx] failed to create log directory: %s', $logDir));
            return;
        }

        $payload = [
            'timestamp' => date('c'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            $encoded = json_encode([
                'timestamp' => date('c'),
                'level' => $level,
                'message' => $message,
                'context' => '[unserializable]',
            ]);
        }

        if ($encoded === false) {
            error_log(sprintf('[readidx] failed to encode log payload for message: %s', $message));
            return;
        }

        $logFile = $logDir . '/' . self::DEFAULT_FILENAME;

        $result = @file_put_contents($logFile, $encoded . PHP_EOL, FILE_APPEND | LOCK_EX);
        if ($result === false) {
            error_log(sprintf('[readidx] failed to write log entry into file: %s', $logFile));
        }
    }
}
