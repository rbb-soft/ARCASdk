<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\ArcaSdk;

use Psr\Log\AbstractLogger;

/**
 * Logger PSR-3 in-memory que captura todos los entries para
 * inspeccion en tests.
 *
 * Cada entry es `{ level, message, context }`. Provee helpers
 * `infoCount()`, `lastInfo()`, `warningCount()`, `lastWarning()`
 * para los assertions tipicos.
 */
final class CapturingLogger extends AbstractLogger
{
    /** @var list<array{level:string, message:string, context:array<string, mixed>}> */
    private array $entries = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->entries[] = [
            'level'   => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    public function entries(): array
    {
        return $this->entries;
    }

    public function entriesByLevel(string $level): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (array $e): bool => $e['level'] === $level
        ));
    }

    public function infoCount(): int
    {
        return count($this->entriesByLevel('info'));
    }

    public function warningCount(): int
    {
        return count($this->entriesByLevel('warning'));
    }

    /**
     * @return array{level:string, message:string, context:array<string, mixed>}|null
     */
    public function lastInfo(): ?array
    {
        $list = $this->entriesByLevel('info');
        return empty($list) ? null : $list[count($list) - 1];
    }

    /**
     * @return array{level:string, message:string, context:array<string, mixed>}|null
     */
    public function lastWarning(): ?array
    {
        $list = $this->entriesByLevel('warning');
        return empty($list) ? null : $list[count($list) - 1];
    }

    public function reset(): void
    {
        $this->entries = [];
    }
}
