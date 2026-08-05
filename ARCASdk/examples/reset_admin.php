<?php
/**
 * Ejemplo: operaciones administrativas.
 *
 *  - reconciliar(): barre las filas en_curso cuyo TTL venció.
 *  - resetExternalId(): borra una fila (con auditoría) en casos administrativos.
 *
 * Uso:
 *   php examples/reset_admin.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Psr\Log\AbstractLogger;
use Rbbsoft\ArcaSdk\ArcaSdk;
use Rbbsoft\ArcaSdk\Config\Config;
use Rbbsoft\ArcaSdk\Exceptions\EmisionEnCursoException;
use Rbbsoft\ArcaSdk\Exceptions\IdempotencyStateException;
use Rbbsoft\ArcaSdk\Exceptions\ValidationException;

/** Logger PSR-3 simple que escribe a STDERR con prefijo [AUDIT] */
class StderrAuditLogger extends AbstractLogger
{
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        fwrite(STDERR, "[AUDIT][{$level}] {$message}\n");
        if (!empty($context)) {
            fwrite(STDERR, "  context: " . json_encode($context, JSON_UNESCAPED_UNICODE) . "\n");
        }
    }
}

function loadEnv(string $path): array
{
    $vars = [];
    if (!is_readable($path)) {
        return $vars;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$k, $v] = array_map('trim', explode('=', $line, 2) + [1 => '']);
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
            $v = substr($v, 1, -1);
        }
        $vars[$k] = $v;
    }
    return $vars;
}

$env = loadEnv(__DIR__ . '/../.env');

$config = Config::fromArray([
    'env'         => $env['ARCA_ENV'] ?? 'homo',
    'cuit'        => $env['ARCA_CUIT'] ?? '',
    'punto_venta' => (int) ($env['ARCA_PUNTO_VENTA'] ?? 1),
    'cert_path'   => $env['ARCA_CERT_PATH'] ?? '',
    'key_path'    => $env['ARCA_KEY_PATH'] ?? '',
    'db_dsn'      => $env['ARCA_DB_DSN'] ?? '',
    'db_user'     => $env['ARCA_DB_USER'] ?? '',
    'db_pass'     => $env['ARCA_DB_PASS'] ?? '',
    'logger'      => new StderrAuditLogger(),
]);

$arca = ArcaSdk::getInstance($config);

// 1) Reconciliación: barre hasta 100 filas en_curso con TTL vencido.
echo "=== Reconciliación ===\n";
$count = $arca->reconciliar(limit: 100);
echo "Filas reconciliadas: {$count}\n\n";

// 2) Reset de una fila fallida (operación admin normal).
// Reemplaza con el externalId real que querés resetear.
$externalIdFallido = 'c3d4e5f6-a7b8-4c9d-0e1f-2a3b4c5d6e7f';

echo "=== Reset de fila fallida ===\n";
try {
    $arca->resetExternalId(
        externalId: $externalIdFallido,
        operator:   'admin@example.com',
        motivo:     'Limpieza post-mortem tras proceso zombie'
    );
    echo "Reset OK (auditado vía PSR-3 logger)\n";
} catch (IdempotencyStateException $e) {
    echo "NO EXISTE: la fila no está en la tabla\n";
} catch (EmisionEnCursoException $e) {
    echo "EN CURSO DENTRO DE TTL: rechazar y esperar\n";
} catch (ValidationException $e) {
    echo "VALIDACIÓN: {$e->getMessage()}\n";
}

// 3) Reset de una fila emitida (PELIGROSO — requiere force_emitido=true).
$externalIdEmitido = 'd4e5f6a7-b8c9-4d0e-1f2a-3b4c5d6e7f8a';

echo "\n=== Reset de fila emitida (requiere force_emitido) ===\n";
try {
    // Sin force_emitido: lanza IdempotencyStateException explicando el riesgo.
    $arca->resetExternalId(
        externalId: $externalIdEmitido,
        operator:   'admin@example.com',
        motivo:     'Re-emisión manual con nuevo CAE'
    );
} catch (IdempotencyStateException $e) {
    echo "Rechazado como esperado: {$e->getMessage()}\n";

    // Con force_emitido: borra la fila. ADVERTENCIA: el comprobante sigue
    // existiendo en ARCA; reemitir con el mismo UUID crea un duplicado.
    echo "\nForzando el reset (asumiendo que sabés lo que hacés)...\n";
    $arca->resetExternalId(
        externalId: $externalIdEmitido,
        operator:   'admin@example.com',
        motivo:     'Re-emisión manual con nuevo CAE',
        forceEmitido: true
    );
    echo "Reset forzado OK\n";
}
