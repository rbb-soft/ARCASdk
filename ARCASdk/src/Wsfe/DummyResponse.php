<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Wsfe;

/**
 * Resultado normalizado de FEDummy. La operacion dummy no aprueba ni
 * rechaza comprobantes: expone el estado de los servidores de ARCA
 * (AppServer y DbServer).
 *
 * Estados posibles por servidor (texto libre de ARCA):
 *  - "OK"        -> operativo
 *  - "ERROR"     -> caido o con error
 *  - "UNKNOWN"   -> respuesta no reconocible
 *
 * Los flags isAppServerOk() / isDbServerOk() son helpers
 * convenientes; el caller puede inspeccionar los strings crudos para
 * diagnostico fino.
 */
final class DummyResponse
{
    public const STATUS_OK      = 'OK';
    public const STATUS_ERROR   = 'ERROR';
    public const STATUS_UNKNOWN = 'UNKNOWN';

    public function __construct(
        public readonly string $appServer,
        public readonly string $dbServer,
        public readonly ?string $authRequest = null,
        public readonly ?string $rawExcerpt = null,
    ) {
    }

    public function isAppServerOk(): bool
    {
        return $this->appServer === self::STATUS_OK;
    }

    public function isDbServerOk(): bool
    {
        return $this->dbServer === self::STATUS_OK;
    }

    public function isFullyOk(): bool
    {
        return $this->isAppServerOk() && $this->isDbServerOk();
    }
}
