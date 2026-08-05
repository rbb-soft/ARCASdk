<?php
declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Rbbsoft\ArcaSdk\Config\Config;
use Rbbsoft\ArcaSdk\Exceptions\ConfigException;

$errores = 0;
$total   = 0;

function assert_true(bool $cond, string $msg): void
{
    global $errores, $total;
    $total++;
    if (!$cond) {
        $errores++;
        echo "  FAIL: {$msg}\n";
    } else {
        echo "  OK:   {$msg}\n";
    }
}

function assert_throws(callable $fn, string $expected, string $msg): void
{
    global $errores, $total;
    $total++;
    try {
        $fn();
        $errores++;
        echo "  FAIL: {$msg} (no lanzo)\n";
    } catch (\Throwable $e) {
        if (str_contains(get_class($e), $expected) || str_contains($e->getMessage(), $expected)) {
            echo "  OK:   {$msg}\n";
        } else {
            $errores++;
            echo "  FAIL: {$msg} (lanzo " . get_class($e) . ": {$e->getMessage()})\n";
        }
    }
}

$baseValido = [
    'env'          => 'homo',
    'cuit'         => '20409378472',
    'punto_venta'  => 1,
    'cert_path'    => realpath(__DIR__ . '/../../../Certificados/MiCertificado.pem') ?: (__DIR__ . '/../../../Certificados/MiCertificado.pem'),
    'key_path'     => realpath(__DIR__ . '/../../../Certificados/MiClavePrivada.key') ?: (__DIR__ . '/../../../Certificados/MiClavePrivada.key'),
    'db_dsn'       => 'mysql:host=127.0.0.1;dbname=arca_facturador',
    'db_user'      => 'root',
    'db_pass'      => '',
];

echo "== Smoke Config ==\n";
$cfg = Config::fromArray($baseValido);
assert_true($cfg->cuit === '20409378472', 'CUIT valido');
assert_true($cfg->env === 'homo', 'env=homo');
assert_true($cfg->wsaaUrl === Config::URL_WSAA_HOMO, 'URL WSAA homo por env');
assert_true($cfg->wsfeUrl === Config::URL_WSFE_HOMO, 'URL WSFE homo por env');
assert_true($cfg->padronUrl === Config::URL_PADRON_HOMO, 'URL padron homo por env');

$cfgProd = Config::fromArray(['env' => 'prod'] + $baseValido);
assert_true($cfgProd->wsaaUrl === Config::URL_WSAA_PROD, 'URL WSAA prod por env');
assert_true($cfgProd->padronUrl === Config::URL_PADRON_PROD, 'URL padron prod por env');

echo "\n== Validaciones negativas ==\n";
assert_throws(
    fn() => Config::fromArray(['cuit' => '1234'] + $baseValido),
    'ConfigException',
    'CUIT de 4 digitos debe fallar',
);
assert_throws(
    fn() => Config::fromArray(['cuit' => '20-12345678-9'] + $baseValido),
    'ConfigException',
    'CUIT con guiones debe fallar',
);
assert_throws(
    fn() => Config::fromArray(['punto_venta' => 0] + $baseValido),
    'ConfigException',
    'punto_venta=0 debe fallar',
);
assert_throws(
    fn() => Config::fromArray(['punto_venta' => 99999] + $baseValido),
    'ConfigException',
    'punto_venta=99999 fuera de rango debe fallar',
);
assert_throws(
    fn() => Config::fromArray(['env' => 'staging'] + $baseValido),
    'ConfigException',
    'env desconocido debe fallar',
);
assert_throws(
    fn() => Config::fromArray(['cert_path' => '/no/existe.pem'] + $baseValido),
    'ConfigException',
    'cert_path inexistente debe fallar',
);
assert_throws(
    fn() => Config::fromArray(['soap_timeout' => 0] + $baseValido),
    'ConfigException',
    'soap_timeout=0 debe fallar (debe ser finito y > 0)',
);
assert_throws(
    fn() => Config::fromArray(['retry_max_attempts' => 0] + $baseValido),
    'ConfigException',
    'retry_max_attempts=0 debe fallar',
);
assert_throws(
    fn() => Config::fromArray(array_diff_key($baseValido, ['cuit' => 0])),
    'ConfigException',
    'CUIT ausente debe fallar',
);

echo "\n== Verificacion de extensiones ==\n";
$faltantes = Config::verificarExtensionesRequeridas();
assert_true($faltantes === [], 'Todas las extensiones requeridas cargadas: ' . implode(',', $faltantes));

echo "\n== Defaults razonables ==\n";
assert_true($cfg->wsaaTraTtl === 600, 'wsaa_tra_ttl default 600s');
assert_true($cfg->idempotenciaTtlSegundos === 300, 'idempotencia_ttl default 300s');
assert_true($cfg->wsaaGenerationSkew === 120, 'wsaa_generation_skew default 120s');
assert_true($cfg->retryMaxAttempts === 3, 'retry_max_attempts default 3');

echo "\n=== {$total} assertions, {$errores} errores ===\n";
exit($errores === 0 ? 0 : 1);
