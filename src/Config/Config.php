<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Config;

use Rbbsoft\ArcaSdk\Exceptions\ConfigException;

/**
 * Configuracion inmutable del SDK.
 *
 * Toda validacion se ejecuta en Config::fromArray(). Ninguna modificacion
 * posterior; un cambio requiere construir otra instancia.
 *
 * v1: un CUIT por proceso PHP-FPM. El Singleton rechaza una segunda
 * configuracion incompatible.
 */
final class Config
{
    public const ENV_HOMO = 'homo';
    public const ENV_PROD = 'prod';

    public const URL_WSAA_HOMO = 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms';
    public const URL_WSAA_PROD = 'https://wsaa.afip.gov.ar/ws/services/LoginCms';
    public const URL_WSFE_HOMO = 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx';
    public const URL_WSFE_PROD = 'https://servicios1.afip.gov.ar/wsfev1/service.asmx';
    public const URL_PADRON_HOMO = 'https://awshomo.afip.gov.ar/sr-padron/webservices/personaServiceA13?WSDL';
    public const URL_PADRON_PROD = 'https://aws.afip.gov.ar/sr-padron/webservices/personaServiceA13?WSDL';

    /** Lista de extensiones PHP requeridas. */
    public const REQUIRED_EXTENSIONS = ['soap', 'openssl', 'simplexml', 'libxml', 'pdo_mysql', 'bcmath', 'gd'];

    private const DEFAULT_SOAP_TIMEOUT         = 30;
    private const DEFAULT_WSAA_LOCK_TIMEOUT     = 10;
    private const DEFAULT_EMIT_LOCK_TIMEOUT     = 15;
    private const DEFAULT_WSAA_TRA_TTL          = 600;   // 10 min, dentro de limites ARCA
    private const DEFAULT_WSAA_GENERATION_SKEW  = 120;   // 2 min de margen hacia atras
    private const DEFAULT_WSAA_EXPIRY_MARGIN    = 300;   // 5 min de margen de seguridad
    private const DEFAULT_RETRY_MAX_ATTEMPTS    = 3;
    private const DEFAULT_RETRY_BASE_BACKOFF_MS = 200;
    private const DEFAULT_RETRY_MAX_BACKOFF_MS  = 2000;
    private const DEFAULT_IDEMPOTENCIA_MAX_INTENTOS = 5;
    private const DEFAULT_IDEMPOTENCIA_TTL_SEGUNDOS = 300;

    private function __construct(
        public readonly string $env,
        public readonly string $cuit,
        public readonly int $puntoVenta,
        public readonly string $certPath,
        public readonly string $keyPath,
        public readonly string $dbDsn,
        public readonly string $dbUser,
        public readonly string $dbPass,
        public readonly bool $dbPersistent,
        public readonly int $soapTimeout,
        public readonly int $wsaaLockTimeout,
        public readonly int $emitLockTimeout,
        public readonly int $wsaaTraTtl,
        public readonly int $wsaaGenerationSkew,
        public readonly int $wsaaExpiryMargin,
        public readonly int $retryMaxAttempts,
        public readonly int $retryBaseBackoffMs,
        public readonly int $retryMaxBackoffMs,
        public readonly int $idempotenciaMaxIntentos,
        public readonly int $idempotenciaTtlSegundos,
        public readonly string $wsaaUrl,
        public readonly string $wsfeUrl,
        public readonly string $padronUrl,
    ) {
    }

    /**
     * Construye Config desde un array (tipicamente el resultado de parsear .env).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $cuit = self::stringOrFail($data, 'cuit', 'CUIT');
        if (!preg_match('/^\d{11}$/', $cuit)) {
            throw new ConfigException('CUIT debe tener 11 digitos sin guiones (recibido: ' . $cuit . ')');
        }

        $puntoVenta = self::intOrFail($data, 'punto_venta', 'Punto de venta');
        if ($puntoVenta < 1 || $puntoVenta > 99998) {
            throw new ConfigException('punto_venta fuera de rango ARCA [1, 99998]: ' . $puntoVenta);
        }

        $certPath = self::stringOrFail($data, 'cert_path', 'cert_path');
        $keyPath  = self::stringOrFail($data, 'key_path', 'key_path');
        if (!is_readable($certPath)) {
            throw new ConfigException("cert_path no legible: {$certPath}");
        }
        if (!is_readable($keyPath)) {
            throw new ConfigException("key_path no legible: {$keyPath}");
        }

        $env = strtolower(self::stringOrFail($data, 'env', 'env'));
        if (!in_array($env, [self::ENV_HOMO, self::ENV_PROD], true)) {
            throw new ConfigException('env debe ser "homo" o "prod" (recibido: ' . $env . ')');
        }

        $soapTimeout = self::intOrDefault($data, 'soap_timeout', self::DEFAULT_SOAP_TIMEOUT);
        if ($soapTimeout <= 0) {
            throw new ConfigException('soap_timeout debe ser > 0 y finito (recibido: ' . $soapTimeout . ')');
        }

        $wsaaLockTimeout = self::intOrDefault($data, 'wsaa_lock_timeout', self::DEFAULT_WSAA_LOCK_TIMEOUT);
        if ($wsaaLockTimeout <= 0) {
            throw new ConfigException('wsaa_lock_timeout debe ser > 0 y finito');
        }

        $emitLockTimeout = self::intOrDefault($data, 'emit_lock_timeout', self::DEFAULT_EMIT_LOCK_TIMEOUT);
        if ($emitLockTimeout <= 0) {
            throw new ConfigException('emit_lock_timeout debe ser > 0 y finito');
        }

        $wsaaTraTtl = self::intOrDefault($data, 'wsaa_tra_ttl', self::DEFAULT_WSAA_TRA_TTL);
        if ($wsaaTraTtl <= 0) {
            throw new ConfigException('wsaa_tra_ttl debe ser > 0');
        }

        $wsaaGenerationSkew = self::intOrDefault($data, 'wsaa_generation_skew', self::DEFAULT_WSAA_GENERATION_SKEW);
        if ($wsaaGenerationSkew < 0) {
            throw new ConfigException('wsaa_generation_skew debe ser >= 0');
        }

        $wsaaExpiryMargin = self::intOrDefault($data, 'wsaa_expiry_margin', self::DEFAULT_WSAA_EXPIRY_MARGIN);
        if ($wsaaExpiryMargin < 0) {
            throw new ConfigException('wsaa_expiry_margin debe ser >= 0');
        }

        $retryMax = self::intOrDefault($data, 'retry_max_attempts', self::DEFAULT_RETRY_MAX_ATTEMPTS);
        if ($retryMax < 1) {
            throw new ConfigException('retry_max_attempts debe ser >= 1');
        }

        $retryBase = self::intOrDefault($data, 'retry_base_backoff_ms', self::DEFAULT_RETRY_BASE_BACKOFF_MS);
        $retryMaxBack = self::intOrDefault($data, 'retry_max_backoff_ms', self::DEFAULT_RETRY_MAX_BACKOFF_MS);
        if ($retryBase <= 0 || $retryMaxBack < $retryBase) {
            throw new ConfigException('retry_base_backoff_ms/retry_max_backoff_ms invalidos');
        }

        $idempMax = self::intOrDefault($data, 'idempotencia_max_intentos', self::DEFAULT_IDEMPOTENCIA_MAX_INTENTOS);
        if ($idempMax < 1) {
            throw new ConfigException('idempotencia_max_intentos debe ser >= 1');
        }

        $idempTtl = self::intOrDefault($data, 'idempotencia_ttl_segundos', self::DEFAULT_IDEMPOTENCIA_TTL_SEGUNDOS);
        if ($idempTtl <= 0) {
            throw new ConfigException('idempotencia_ttl_segundos debe ser > 0');
        }

        return new self(
            env: $env,
            cuit: $cuit,
            puntoVenta: $puntoVenta,
            certPath: $certPath,
            keyPath: $keyPath,
            dbDsn: self::stringOrFail($data, 'db_dsn', 'db_dsn'),
            dbUser: self::stringOrFail($data, 'db_user', 'db_user'),
            dbPass: (string) ($data['db_pass'] ?? ''),
            dbPersistent: (bool) ($data['db_persistent'] ?? false),
            soapTimeout: $soapTimeout,
            wsaaLockTimeout: $wsaaLockTimeout,
            emitLockTimeout: $emitLockTimeout,
            wsaaTraTtl: $wsaaTraTtl,
            wsaaGenerationSkew: $wsaaGenerationSkew,
            wsaaExpiryMargin: $wsaaExpiryMargin,
            retryMaxAttempts: $retryMax,
            retryBaseBackoffMs: $retryBase,
            retryMaxBackoffMs: $retryMaxBack,
            idempotenciaMaxIntentos: $idempMax,
            idempotenciaTtlSegundos: $idempTtl,
            wsaaUrl: $env === self::ENV_PROD ? self::URL_WSAA_PROD : self::URL_WSAA_HOMO,
            wsfeUrl: $env === self::ENV_PROD ? self::URL_WSFE_PROD : self::URL_WSFE_HOMO,
            padronUrl: $env === self::ENV_PROD ? self::URL_PADRON_PROD : self::URL_PADRON_HOMO,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function stringOrFail(array $data, string $key, string $label): string
    {
        if (!isset($data[$key]) || !is_string($data[$key]) || trim($data[$key]) === '') {
            throw new ConfigException("Configuracion requerida ausente o vacia: {$label} ({$key})");
        }
        return trim($data[$key]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function intOrFail(array $data, string $key, string $label): int
    {
        if (!isset($data[$key])) {
            throw new ConfigException("Configuracion requerida ausente: {$label} ({$key})");
        }
        if (!is_numeric($data[$key])) {
            throw new ConfigException("Configuracion invalida (no numerica): {$label} ({$key})");
        }
        return (int) $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function intOrDefault(array $data, string $key, int $default): int
    {
        if (!isset($data[$key]) || $data[$key] === '' || $data[$key] === null) {
            return $default;
        }
        if (!is_numeric($data[$key])) {
            throw new ConfigException("Configuracion invalida (no numerica): {$key}");
        }
        return (int) $data[$key];
    }

    /**
     * Valida que todas las extensiones requeridas esten cargadas. Llamado
     * por el Singleton antes de construir cualquier cliente.
     *
     * @return array<int, string> Lista de extensiones faltantes (vacia si OK).
     */
    public static function verificarExtensionesRequeridas(): array
    {
        $faltantes = [];
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            if (!extension_loaded($ext)) {
                $faltantes[] = $ext;
            }
        }
        return $faltantes;
    }
}
