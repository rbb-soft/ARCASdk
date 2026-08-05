<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\Lock;

use Closure;
use PDO;
use Rbbsoft\ArcaSdk\Lock\LockManager;

/**
 * Test double de LockManager. NO usa MySQL: simula la contratacion
 * de acquire/release en memoria para que los tests del orquestador
 * (Phase 6) puedan:
 *  - verificar que acquire=true -> release=true (caminos felices)
 *  - simular contention: acquire retorna false a la primera llamada
 *  - verificar que NO se llama a release cuando acquire devolvio false
 *  - contar acquire/release para asserts
 *
 * Mantiene la API publica de LockManager (extiende la clase) y
 * override acquire() y release() para no tocar MySQL.
 *
 * NO es thread-safe; tests del orquestador corren en el mismo
 * proceso y por lo tanto este doble tambien.
 */
final class LockManagerDouble extends LockManager
{
    public int $acquireCallCount = 0;
    public int $releaseCallCount = 0;

    /** @var array<string, int> nombre => cantidad de acquires exitosos. */
    public array $acquiredNames = [];

    /** @var array<string, int> nombre => cantidad de releases. */
    public array $releasedNames = [];

    /** Cola de respuestas para acquire(): true|false. Si vacia, default true. */
    private array $acquireQueue = [];

    public function __construct()
    {
        // No usamos la factoria; pasamos una factoria "dummy" que el
        // padre nunca invocara porque overrideamos acquire().
        parent::__construct(static fn(): PDO => throw new \RuntimeException(
            'LockManagerDouble no deberia crear conexiones PDO'
        ));
    }

    /**
     * Proxima respuesta de acquire().
     */
    public function setNextAcquireResult(bool $ok): void
    {
        $this->acquireQueue[] = $ok;
    }

    public function acquire(string $name, int $timeoutSeconds): bool
    {
        $this->acquireCallCount++;
        $result = count($this->acquireQueue) > 0
            ? array_shift($this->acquireQueue)
            : true;
        if ($result) {
            $this->acquiredNames[$name] = ($this->acquiredNames[$name] ?? 0) + 1;
        }
        return $result;
    }

    public function release(string $name): bool
    {
        $this->releaseCallCount++;
        $this->releasedNames[$name] = ($this->releasedNames[$name] ?? 0) + 1;
        return true;
    }

    public function isHeld(string $name): bool
    {
        // Simulamos "held" como que el acquire mas reciente tuvo exito
        // Y el release mas reciente NO lo ha liberado.
        $a = $this->acquiredNames[$name] ?? 0;
        $r = $this->releasedNames[$name] ?? 0;
        return $a > $r;
    }

    public function resetCounters(): void
    {
        $this->acquireCallCount = 0;
        $this->releaseCallCount = 0;
        $this->acquiredNames = [];
        $this->releasedNames = [];
        $this->acquireQueue = [];
    }
}
