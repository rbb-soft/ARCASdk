<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Idempotencia;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Value object inmutable que representa UNA fila de la tabla
 * `arca_emisiones_idempotencia`. Es la unica representacion que el
 * resto del SDK ve de la fila: nadie toca columnas crudas despues
 * de pasar por aca.
 *
 * Decisiones:
 *  - 18 propiedades public readonly, una por columna. Cualquier
 *    cambio de schema debe reflejarse aqui.
 *  - El constructor es "interno" por convencion: solo
 *    IdempotenciaRepository lo invoca (al leer del SELECT). Tests
 *    unitarios pueden construir instancias para verificar
 *    isOwnedBy()/toArray(); la API publica nunca devuelve esto,
 *    siempre FilaEmision.
 *  - Fechas (cbte_fch_enviado, cae_fch_vto, created_at, updated_at)
 *    expuestas como DateTimeImmutable en UTC. toArray() las
 *    serializa al formato canonico SQL ('Y-m-d' / 'Y-m-d H:i:s').
 *  - es_fallo_infra se expone como bool. El 0/1 nativo de MySQL
 *    se traduce al construir (MySQL devuelve int).
 *  - isOwnedBy() usa hash_equals (timing-safe) para no filtrar
 *    informacion sobre el lease a traves del tiempo de respuesta.
 *
 * Estados validos (verificables desde esta clase):
 *   - en_curso: lease vigente, worker trabajando
 *   - emitido:  CAE/numero confirmados, lease liberado
 *   - fallido:  rechazo funcional o infra, reabrible bajo reglas
 */
final class FilaEmision
{
    public const ESTADO_EN_CURSO = 'en_curso';
    public const ESTADO_EMITIDO  = 'emitido';
    public const ESTADO_FALLIDO  = 'fallido';

    /** Estados validos del ENUM de la tabla. */
    public const ESTADOS_VALIDOS = [
        self::ESTADO_EN_CURSO,
        self::ESTADO_EMITIDO,
        self::ESTADO_FALLIDO,
    ];

    public function __construct(
        public readonly string $externalId,
        public readonly string $cuit,
        public readonly int $puntoVenta,
        public readonly int $cbteTipo,
        public readonly string $estado,
        public readonly ?string $leaseToken,
        public readonly int $intento,
        public readonly bool $esFalloInfra,
        public readonly string $requestFingerprint,
        public readonly string $requestJson,
        public readonly ?int $cbteNroEnviado,
        public readonly ?DateTimeImmutable $cbteFchEnviado,
        public readonly ?string $cae,
        public readonly ?DateTimeImmutable $caeFchVto,
        public readonly ?int $cbteNroConfirmado,
        public readonly ?string $responseJson,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
    ) {
        // Solo validamos lo que tiene semantica enum y lo que es
        // contrato del value object. El resto se asume correcto
        // porque el repositorio es el unico consumidor.
        if (!in_array($estado, self::ESTADOS_VALIDOS, true)) {
            throw new InvalidArgumentException(
                "FilaEmision: estado invalido '{$estado}' (esperado uno de: "
                . implode(', ', self::ESTADOS_VALIDOS) . ')'
            );
        }
        if (strlen($externalId) !== 36) {
            throw new InvalidArgumentException(
                "FilaEmision: externalId debe tener 36 chars (UUID), recibio "
                . strlen($externalId)
            );
        }
        if (strlen($cuit) !== 11 || !ctype_digit($cuit)) {
            throw new InvalidArgumentException(
                "FilaEmision: cuit debe ser 11 digitos, recibio '{$cuit}'"
            );
        }
        if (strlen($requestFingerprint) !== 64 || !ctype_xdigit($requestFingerprint)) {
            throw new InvalidArgumentException(
                'FilaEmision: requestFingerprint debe ser SHA-256 hex (64 chars)'
            );
        }
    }

    /**
     * Devuelve true si el lease token de esta fila coincide con el
     * provisto. Comparacion timing-safe (hash_equals) para no
     * filtrar informacion del lease por tiempo de respuesta.
     *
     * - Si la fila no tiene lease (estado terminal, lease_token NULL)
     *   devuelve false aunque el caller pase NULL/vacio.
     * - Si el caller pasa un string de longitud distinta al lease
     *   tambien devuelve false (hash_equals retorna false sin lanzar).
     */
    public function isOwnedBy(string $leaseToken): bool
    {
        if ($this->leaseToken === null) {
            return false;
        }
        return hash_equals($this->leaseToken, $leaseToken);
    }

    /**
     * Serializa la fila a un array asociativo con claves snake_case
     * iguales a los nombres de columna. Util para:
     *  - guardar el snapshot en `response_json` antes de cerrar
     *  - diagnostico/log
     *  - construir un UPDATE con todas las columnas
     *
     * Convenciones de formato:
     *  - DATE (cbte_fch_enviado, cae_fch_vto): 'Y-m-d' en UTC
     *  - DATETIME (created_at, updated_at):   'Y-m-d H:i:s' en UTC
     *  - es_fallo_infra como int (0/1) para preservar la semantica
     *    de TINYINT(1) al re-emitir via PDO.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $utc = new DateTimeZone('UTC');
        return [
            'external_id'         => $this->externalId,
            'cuit'                => $this->cuit,
            'punto_venta'         => $this->puntoVenta,
            'cbte_tipo'           => $this->cbteTipo,
            'estado'              => $this->estado,
            'lease_token'         => $this->leaseToken,
            'intento'             => $this->intento,
            'es_fallo_infra'      => $this->esFalloInfra ? 1 : 0,
            'request_fingerprint' => $this->requestFingerprint,
            'request_json'        => $this->requestJson,
            'cbte_nro_enviado'    => $this->cbteNroEnviado,
            'cbte_fch_enviado'    => $this->cbteFchEnviado?->setTimezone($utc)->format('Y-m-d'),
            'cae'                 => $this->cae,
            'cae_fch_vto'         => $this->caeFchVto?->setTimezone($utc)->format('Y-m-d'),
            'cbte_nro_confirmado' => $this->cbteNroConfirmado,
            'response_json'       => $this->responseJson,
            'created_at'          => $this->createdAt->setTimezone($utc)->format('Y-m-d H:i:s'),
            'updated_at'          => $this->updatedAt->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }
}
