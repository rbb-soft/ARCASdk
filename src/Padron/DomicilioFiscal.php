<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Padron;

/**
 * Domicilio fiscal del emisor devuelto por el padron A13. Todos los
 * campos son opcionales: ARCA puede omitir cualquier sub-campo cuando
 * el dato no esta disponible para el CUIT consultado.
 *
 * Inmutable: los campos se setean una sola vez en el constructor y
 * no pueden modificarse.
 */
final class DomicilioFiscal
{
    public function __construct(
        public readonly ?string $calle,
        public readonly ?string $numero,
        public readonly ?string $piso,
        public readonly ?string $departamento,
        public readonly ?string $codigoPostal,
        public readonly ?string $localidad,
        public readonly ?string $provincia,
        public readonly ?string $descripcionProvincia,
    ) {
    }

    /**
     * Construye un DomicilioFiscal a partir del sub-arreglo
     * <domicilio> de la respuesta del padron. Las claves ausentes se
     * mapean a null.
     *
     * Tolerancia de nombres: el WSDL A13 usa <idProvincia> (xs:int)
     * y <oficinaDptoLocal>, mientras que el DTO conserva los nombres
     * historicos del WSDL A5 ($provincia, $departamento) para no
     * romper la API publica. fromArray() acepta cualquiera de los
     * dos nombres, dando precedencia al nombre historico si ambos
     * estan presentes.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        // Provincia: preferir el nombre historico si esta presente;
        // si no, caer al nombre del WSDL A13 (idProvincia).
        $provincia = null;
        if (isset($data['provincia'])) {
            $provincia = (string) $data['provincia'];
        } elseif (isset($data['idProvincia'])) {
            $provincia = (string) $data['idProvincia'];
        }

        // Departamento: preferir el nombre historico si esta presente;
        // si no, caer al nombre del WSDL A13 (oficinaDptoLocal).
        $departamento = null;
        if (isset($data['departamento'])) {
            $departamento = (string) $data['departamento'];
        } elseif (isset($data['oficinaDptoLocal'])) {
            $departamento = (string) $data['oficinaDptoLocal'];
        }

        return new self(
            calle: isset($data['calle']) ? (string) $data['calle'] : null,
            numero: isset($data['numero']) ? (string) $data['numero'] : null,
            piso: isset($data['piso']) ? (string) $data['piso'] : null,
            departamento: $departamento,
            codigoPostal: isset($data['codigoPostal']) ? (string) $data['codigoPostal'] : null,
            localidad: isset($data['localidad']) ? (string) $data['localidad'] : null,
            provincia: $provincia,
            descripcionProvincia: isset($data['descripcionProvincia']) ? (string) $data['descripcionProvincia'] : null,
        );
    }
}
