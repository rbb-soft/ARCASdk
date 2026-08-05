<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Wsfe;

/**
 * Resultado normalizado de FECAESolicitar para un UNICO comprobante.
 *
 * ARCA NO lanza SoapFault para un rechazo funcional: devuelve
 * Resultado='R' con Observaciones. Esta clase es un value object
 * puro: no decide si la emision es exitosa o no. La decision de
 * lanzar CbteRechazadoException vive en el orquestador (Phase 6).
 *
 * El WsfeClient SIEMPRE normaliza la respuesta, incluso en 'R'.
 *
 * Estructura:
 *  - resultado:     'A' (aprobado) | 'R' (rechazado)
 *  - cae:           string|null  presente solo en 'A'
 *  - caeFchVto:     string|null  formato YYYYMMDD, presente solo en 'A'
 *  - cbteNro:       int          echo del input
 *  - observaciones: array{codigo:int, mensaje:string}
 */
final class ComprobanteResponse
{
    public const RESULTADO_APROBADO = 'A';
    public const RESULTADO_RECHAZADO = 'R';

    /**
     * @param array<int, array{codigo: int, mensaje: string}> $observaciones
     */
    public function __construct(
        public readonly string $resultado,
        public readonly ?string $cae,
        public readonly ?string $caeFchVto,
        public readonly int $cbteNro,
        public readonly array $observaciones = [],
        public readonly ?string $rawExcerpt = null,
    ) {
        if (!in_array($resultado, [self::RESULTADO_APROBADO, self::RESULTADO_RECHAZADO], true)) {
            throw new \InvalidArgumentException("resultado invalido: {$resultado} (esperado A|R)");
        }
        if ($resultado === self::RESULTADO_APROBADO) {
            if ($cae === null || $cae === '') {
                throw new \InvalidArgumentException('ComprobanteResponse aprobado requiere CAE');
            }
            if ($caeFchVto === null || !preg_match('/^\d{8}$/', $caeFchVto)) {
                throw new \InvalidArgumentException("ComprobanteResponse aprobado requiere caeFchVto YYYYMMDD (recibido: " . var_export($caeFchVto, true) . ')');
            }
        }
    }

    public function isAprobado(): bool
    {
        return $this->resultado === self::RESULTADO_APROBADO;
    }

    public function isRechazado(): bool
    {
        return $this->resultado === self::RESULTADO_RECHAZADO;
    }

    /**
     * @return array<int, array{codigo: int, mensaje: string}>
     */
    public function observacionesComoExcepcion(): array
    {
        return $this->observaciones;
    }
}
