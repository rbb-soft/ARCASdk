<?php
/**
 * Emisión de Factura A (cbte_tipo=1) con warmup y retry.
 *
 * La Factura A se emite entre Responsables Inscriptos y discrimina
 * IVA en el comprobante. El receptor debe estar identificado con CUIT
 * y su condicion frente al IVA tiene que ser RI.
 *
 * En homologacion ARCA NO valida el regimen del emisor: un monotributo
 * puede emitir Factura A sin problema (probado 2026-08-04 con CAE
 * real 86310728641360). En produccion, en cambio, monotributo queda
 * rechazado con el codigo 10017 ("El tipo de comprobante no es valido
 * para el CUIT"). Si tu regimen real es monotributo, este ejemplo
 * sirve para smoke test en homo, no para emision productiva.
 *
 * Uso:
 *   php examples/factura_a_basica.php                    [warmup 600s, 3 intentos, 60s entre]
 *   php examples/factura_a_basica.php 0                  [sin warmup, 3 intentos, 60s entre]
 *   php examples/factura_a_basica.php 0 1 0              [sin warmup, 1 intento]
 *
 * Comportamiento:
 *   1. Warmup: espera $warmupSeconds (default 600s, el lock del CEE
 *      de ARCA en homo tras una emision reciente).
 *   2. Emite hasta $maxAttempts veces. Entre intentos espera
 *      $retrySleepSeconds (default 60s).
 *   3. Cada intento usa un UUID v4 fresco.
 *   4. Solo reintenta excepciones transitorias (CEE bloqueado, red,
 *      SOAP transitorio). Rechazos funcionales (CbteRechazado) y de
 *      estado (MaxIdempotency, Conflict, State, EmisionEnCurso,
 *      Validation) no se reintentan.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Rbbsoft\ArcaSdk\ArcaSdk;
use Rbbsoft\ArcaSdk\Config\Config;
use Rbbsoft\ArcaSdk\Exceptions\ArcaException;
use Rbbsoft\ArcaSdk\Exceptions\CbteRechazadoException;
use Rbbsoft\ArcaSdk\Exceptions\EmisionEnCursoException;
use Rbbsoft\ArcaSdk\Exceptions\IdempotencyConflictException;
use Rbbsoft\ArcaSdk\Exceptions\IdempotencyStateException;
use Rbbsoft\ArcaSdk\Exceptions\MaxIdempotencyAttemptsException;
use Rbbsoft\ArcaSdk\Exceptions\ValidationException;
use Rbbsoft\ArcaSdk\Exceptions\WsaaCeeYaPoseeTaException;
use Rbbsoft\ArcaSdk\Exceptions\WsaaException;
use Rbbsoft\ArcaSdk\Exceptions\WsfeException;
use Rbbsoft\ArcaSdk\Wsfe\TiposComprobante;

function requireCuit(string $raw, string $label): string
{
    $trimmed = trim($raw);
    if ($trimmed === '' || !ctype_digit($trimmed) || strlen($trimmed) !== 11) {
        throw new \RuntimeException(sprintf(
            "Debe configurar %s con un CUIT valido de 11 digitos (en .env como ARCA_%s o como variable de entorno) antes de ejecutar este script.",
            $label,
            strtoupper(str_replace(' ', '_', $label))
        ));
    }
    return $trimmed;
}

$warmupSeconds     = (int) ($argv[1] ?? 600);
$maxAttempts       = (int) ($argv[2] ?? 3);
$retrySleepSeconds = (int) ($argv[3] ?? 60);

function loadEnv(string $path): array
{
    $vars = [];
    if (!is_readable($path)) {
        return $vars;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2) + [1 => '']);
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
            $v = substr($v, 1, -1);
        }
        $vars[$k] = $v;
    }
    return $vars;
}

function freshUuidV4(): string
{
    return sprintf(
        '%08x-%04x-4%03x-%04x-%012x',
        random_int(0, 0xffffffff),
        random_int(0, 0xffff),
        random_int(0, 0xfff),
        random_int(0x8000, 0xbfff),
        random_int(0, 0xffffffffffff)
    );
}

function countdown(int $seconds, string $label): void
{
    $start = time();
    $end   = $start + $seconds;
    echo "{$label}: {$seconds}s ...\n";
    while (time() < $end) {
        $remaining = $end - time();
        $mins = intdiv($remaining, 60);
        $secs = $remaining % 60;
        printf("\r  %02d:%02d restantes (Ctrl+C para abortar) ", $mins, $secs);
        sleep(min(5, $remaining));
    }
    printf("\r  %02d:00 restantes - listo.\n", 0);
}

$env = loadEnv(__DIR__ . '/../.env');

$cuitEmisor   = requireCuit($env['ARCA_CUIT']         ?? getenv('ARCA_CUIT')         ?: '', 'CUIT emisor');
$cuitReceptor = requireCuit($env['ARCA_CUIT_RECEPTOR'] ?? getenv('ARCA_CUIT_RECEPTOR') ?: '', 'CUIT receptor');

$config = Config::fromArray([
    'env'         => $env['ARCA_ENV'] ?? 'homo',
    'cuit'        => $cuitEmisor,
    'punto_venta' => (int) ($env['ARCA_PUNTO_VENTA'] ?? 1),
    'cert_path'   => $env['ARCA_CERT_PATH'] ?? '',
    'key_path'    => $env['ARCA_KEY_PATH'] ?? '',
    'db_dsn'      => $env['ARCA_DB_DSN'] ?? '',
    'db_user'     => $env['ARCA_DB_USER'] ?? '',
    'db_pass'     => $env['ARCA_DB_PASS'] ?? '',
]);

$arca = ArcaSdk::getInstance($config);

$data = [
    'cbte_tipo'   => TiposComprobante::FACTURA_A,    // 1 - requiere emisor RI
    'concepto'    => 1,                               // 1 Productos
    'receptor_documento_tipo' => 80,                   // 80=CUIT (obligatorio en A)
    'receptor_documento_nro'  => $cuitReceptor,
    'receptor_condicion_iva'  => 'RI',                  // receptor tambien RI
    'mon_id'      => 'PES',
    'mon_cotiz'   => '1.00',
    // La A discrimina IVA: el item lleva gravado + alicuota.
    'items' => [
        ['importe_gravado' => '100.00', 'alicuota_iva' => '21'],
    ],
];

echo "=========================================\n";
echo " Emision Factura A (SDK con warmup+retry)\n";
echo "=========================================\n";
echo "entorno:           {$config->env}\n";
echo "cuit:              {$config->cuit}\n";
echo "punto_venta:       {$config->puntoVenta}\n";
echo "cbte_tipo:         1 (FACTURA_A)\n";
echo "warmup:            {$warmupSeconds}s\n";
echo "max_intentos:      {$maxAttempts}\n";
echo "sleep_retry:       {$retrySleepSeconds}s\n\n";

if ($warmupSeconds > 0) {
    countdown($warmupSeconds, 'Warmup (esperando que ARCA libere el lock del CEE)');
}

$intentos = [];

for ($i = 1; $i <= $maxAttempts; $i++) {
    $externalId = freshUuidV4();

    echo "\n--- Intento {$i}/{$maxAttempts} ---\n";
    echo "external_id: {$externalId}\n";

    $start = microtime(true);
    try {
        $resp = $arca->emitirFactura($externalId, $data);
        $elapsed = (microtime(true) - $start) * 1000;
        echo "\nOK ({$elapsed} ms)\n";
        echo "  CAE:             {$resp->cae}\n";
        echo "  Vencimiento CAE: {$resp->caeFchVto}\n";
        echo "  Numero:          {$resp->cbteNro}\n";
        echo "  Fecha:           {$resp->cbteFch}\n";
        echo "  Total:           {$resp->montoTotal}\n";
        echo "  Origen:          " . ($resp->origen ?? '(sin campo)') . "\n";
        echo "\nEmision exitosa.\n";
        exit(0);
    } catch (WsaaCeeYaPoseeTaException $e) {
        $intentos[] = ['i' => $i, 'ext' => $externalId, 'cls' => 'WsaaCeeYaPoseeTa', 'msg' => $e->getMessage()];
        echo "FALLO transitorio: CEE de ARCA sigue bloqueado.\n";
    } catch (WsfeException $e) {
        $intentos[] = ['i' => $i, 'ext' => $externalId, 'cls' => get_class($e), 'msg' => $e->getMessage()];
        echo "FALLO transitorio: " . get_class($e) . ".\n";
    } catch (WsaaException $e) {
        $intentos[] = ['i' => $i, 'ext' => $externalId, 'cls' => get_class($e), 'msg' => $e->getMessage()];
        echo "FALLO transitorio: " . get_class($e) . ".\n";
    } catch (CbteRechazadoException $e) {
        echo "\nRECHAZADO POR ARCA (definitivo, no se reintenta):\n";
        foreach ($e->observaciones as $obs) {
            echo "  codigo {$obs['codigo']}: {$obs['mensaje']}\n";
        }
        echo "external_id: {$externalId}\n";
        echo "\nCorregir los datos y reemitir con un external_id NUEVO.\n";
        exit(2);
    } catch (MaxIdempotencyAttemptsException $e) {
        echo "\nMAX INTENTOS (definitivo): {$e->getMessage()}\n";
        echo "external_id: {$externalId}\n";
        echo "Reset sugerido: \$arca->resetExternalId('{$externalId}', operator: 'tu@email', motivo: 'limpieza')\n";
        exit(3);
    } catch (IdempotencyConflictException $e) {
        echo "\nCONFLICTO IDEMPOTENCIA (definitivo): {$e->getMessage()}\n";
        echo "external_id: {$externalId}\n";
        exit(4);
    } catch (IdempotencyStateException $e) {
        echo "\nESTADO INCOHERENTE (definitivo): {$e->getMessage()}\n";
        echo "external_id: {$externalId}\n";
        exit(5);
    } catch (EmisionEnCursoException $e) {
        echo "\nEMISION EN CURSO (definitivo): {$e->getMessage()}\n";
        echo "Otro worker tiene el lease. Esperar o reconciliar.\n";
        exit(6);
    } catch (ValidationException $e) {
        echo "\nVALIDACION (definitivo): {$e->getMessage()}\n";
        echo "Corregir datos de la factura y reemitir con external_id NUEVO.\n";
        exit(7);
    } catch (ArcaException $e) {
        echo "\nERROR ARCA (definitivo): " . get_class($e) . ": {$e->getMessage()}\n";
        echo "external_id: {$externalId}\n";
        exit(8);
    } catch (\Throwable $e) {
        echo "\nERROR INESPERADO (definitivo): " . get_class($e) . ": {$e->getMessage()}\n";
        echo "Archivo: {$e->getFile()}:{$e->getLine()}\n";
        echo "external_id: {$externalId}\n";
        exit(9);
    }

    if ($i < $maxAttempts) {
        countdown($retrySleepSeconds, "Sleep antes del intento " . ($i + 1));
    }
}

echo "\n=========================================\n";
echo " TODOS LOS INTENTOS FALLARON\n";
echo "=========================================\n";
foreach ($intentos as $it) {
    echo sprintf(
        "  intento %d  external_id=%s  clase=%s\n    msg: %s\n",
        $it['i'], $it['ext'], $it['cls'],
        substr($it['msg'], 0, 200) . (strlen($it['msg']) > 200 ? '...' : '')
    );
}
echo "\nSi el problema persiste tras esperar el lock del CEE, revisar la causa\n";
echo "estructural (cert, autorizacion del DN, datos del comprobante).\n";
exit(1);
