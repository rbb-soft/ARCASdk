<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Padron;

/**
 * Actividad declarada por el emisor en el padron A13. idActividad y
 * descripcionActividad son obligatorios (ARCA no omite esos campos);
 * el resto es opcional porque depende de la condicion del emisor.
 *
 * Inmutable: los campos se setean una sola vez en el constructor.
 */
final class Actividad
{
    public function __construct(
        public readonly string $idActividad,
        public readonly string $descripcionActividad,
        public readonly ?string $nomenclador,
        public readonly ?string $categoria,
        public readonly ?string $idCategoria,
        public readonly ?string $periodo,
        public readonly ?string $fechaInicio,
        public readonly ?string $fechaFin,
    ) {
    }

    /**
     * Construye una Actividad a partir de un sub-arreglo <actividad>
     * de la respuesta del padron. Las claves ausentes se mapean a
     * null; idActividad y descripcionActividad caen a string vacio
     * si la respuesta no los trae (no deberia ocurrir segun el WSDL
     * del padron, pero es defensivo).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            idActividad: (string) ($data['idActividad'] ?? ''),
            descripcionActividad: (string) ($data['descripcionActividad'] ?? ''),
            nomenclador: isset($data['nomenclador']) ? (string) $data['nomenclador'] : null,
            categoria: isset($data['categoria']) ? (string) $data['categoria'] : null,
            idCategoria: isset($data['idCategoria']) ? (string) $data['idCategoria'] : null,
            periodo: isset($data['periodo']) ? (string) $data['periodo'] : null,
            fechaInicio: isset($data['fechaInicio']) ? (string) $data['fechaInicio'] : null,
            fechaFin: isset($data['fechaFin']) ? (string) $data['fechaFin'] : null,
        );
    }
}
