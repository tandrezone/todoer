<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Log\LoggerInterface;
use Stringable;

/**
 * A PSR-3 logger that writes to the PHP error log.
 *
 * Deliberately the smallest thing that satisfies the interface: the app logs a handful of
 * events (an uncaught exception, a push delivery that failed, push being unavailable) and the
 * error log is where a `php -S` session, Apache and systemd all agree to put them. Swapping in
 * Monolog later is a one-line change in config/container.php because everything depends on the
 * interface.
 */
final class ErrorLogLogger implements LoggerInterface
{
    public function __construct(private readonly string $channel = 'todoer')
    {
    }

    public function emergency(string|Stringable $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert(string|Stringable $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical(string|Stringable $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error(string|Stringable $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice(string|Stringable $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info(string|Stringable $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $line = sprintf('%s.%s: %s', $this->channel, strtoupper((string) $level), (string) $message);
        if ($context !== []) {
            $line .= ' ' . json_encode($this->stringifyContext($context));
        }

        error_log($line);
    }

    /**
     * @param  array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function stringifyContext(array $context): array
    {
        foreach ($context as $key => $value) {
            if ($value instanceof \Throwable) {
                $context[$key] = get_class($value) . ': ' . $value->getMessage()
                    . ' in ' . $value->getFile() . ':' . $value->getLine();
            } elseif (is_object($value)) {
                $context[$key] = get_debug_type($value);
            }
        }

        return $context;
    }
}
