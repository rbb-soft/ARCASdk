<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Padron;

/**
 * Impuesto inscripto del emisor devuelto por el padron A13. idImpuesto
 * y descripcionImpuesto son obligatorios; el resto es opcional segun
 * el regimen del cual se trate.
 *
 * Inmutable: los campos se setean una sola vez en el constructor.
 */
final class Impuesto
{
    public function __construct(
        public readonly int $idImpuesto,
        public readonly string $descripcionImpuesto,
        public readonly ?string $estado,
        public readonly ?string $periodo,
        public readonly ?string $fechaDesde,
        public readonly ?string $fechaHasta,
    ) {
    }

    /**
     * Construye un Impuesto a partir de un sub-arreglo <impuesto> de
     * la respuesta del padron. idImpuesto se castea a int (viene como
     * string en el XML); las claves ausentes se mapean a null.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            idImpuesto: (int) ($data['idImpuesto'] ?? 0),
            descripcionImpuesto: (string) ($data['descripcionImpuesto'] ?? ''),
            estado: isset($data['estado']) ? (string) $data['estado'] : null,
            periodo: isset($data['periodo']) ? (string) $data['periodo'] : null,
            fechaDesde: isset($data['fechaDesde']) ? (string) $data['fechaDesde'] : null,
            fechaHasta: isset($data['fechaHasta']) ? (string) $data['fechaHasta'] : null,
        );
    }
}
