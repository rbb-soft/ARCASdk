<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Padron;

/**
 * Emisor devuelto por la operacion getPersona del padron A13. Value
 * object inmutable con los datos que el padron expone para un CUIT:
 * identidad (cuit, razon social o apellido y nombre), domicilio
 * fiscal, condicion de la clave, tipo de persona, etc.
 *
 * Campos que el WSDL A13 actual NO expone y quedan en null/[]:
 *  - $actividades: el WSDL A13 declara una sola actividad principal
 *    (<idActividadPrincipal> + <descripcionActividadPrincipal>) en
 *    lugar de una lista de actividades. Esta propiedad se conserva en
 *    el DTO por compat con la API publica (callers pre-v0.3.1 la
 *    consumen como una lista) pero queda SIEMPRE en [].
 *  - $impuestos: el WSDL A13 no expone una lista de impuestos
 *    inscriptos. Se conserva la propiedad y queda SIEMPRE en [].
 *  - $fechaInscripcion: el WSDL A13 no expone este campo. Queda en
 *    null. (El campo historico "fechaContratoSocial" del WSDL A5
 *    tampoco lo trae.)
 *  - $categoriaMonotributo: el WSDL A13 no expone este campo. Queda
 *    en null.
 *  - $condicionIva: el WSDL A13 no expone este campo. Queda en null.
 *
 * Si en una version futura del WSDL ARCA vuelve a exponer listas de
 * actividades/impuestos, estos campos se pueden volver a popular sin
 * tocar la firma del DTO.
 *
 * razonSocial y apellidoNombre son mutuamente excluyentes: para
 * personas juridicas viene razonSocial; para personas fisicas, el
 * constructor calcula apellidoNombre concatenando apellido y nombre
 * en el orden "apellido, nombre". Si el padron no trae ninguno de
 * los dos, ambos quedan null.
 *
 * @phpstan-type ActividadList list<Actividad>
 * @phpstan-type ImpuestoList list<Impuesto>
 */
final class Emisor
{
    /**
     * @param Actividad[]  $actividades   Lista de actividades declaradas (vacia con WSDL A13; ver docblock).
     * @param Impuesto[]   $impuestos     Lista de impuestos inscriptos (vacia con WSDL A13; ver docblock).
     */
    public function __construct(
        public readonly int $cuit,
        public readonly ?string $razonSocial,
        public readonly ?string $apellidoNombre,
        public readonly ?string $tipoPersona,
        public readonly string $estadoClave,
        public readonly ?string $fechaInscripcion,
        public readonly DomicilioFiscal $domicilioFiscal,
        public readonly array $actividades,
        public readonly array $impuestos,
        public readonly ?string $categoriaMonotributo,
        public readonly ?string $condicionIva,
    ) {
    }

    /**
     * Construye un Emisor a partir del sub-arreglo que PadronClient
     * extrae de la respuesta del padron A13. Las claves ausentes se
     * mapean a null (o [] para arreglos). apellidoNombre se calcula
     * cuando razonSocial no esta presente y apellido+nombre si lo estan.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $domicilio = self::childArray($data, 'domicilio');

        $razonSocial = isset($data['razonSocial'])
            ? trim((string) $data['razonSocial'])
            : null;
        if ($razonSocial === '') {
            $razonSocial = null;
        }

        $apellido = isset($data['apellido'])
            ? trim((string) $data['apellido'])
            : '';
        $nombre = isset($data['nombre'])
            ? trim((string) $data['nombre'])
            : '';
        $apellidoNombre = null;
        if ($razonSocial === null && $apellido !== '' && $nombre !== '') {
            $apellidoNombre = $apellido . ', ' . $nombre;
        }

        $tipoPersona = isset($data['tipoPersona'])
            ? (string) $data['tipoPersona']
            : null;
        $estadoClave = isset($data['estadoClave'])
            ? (string) $data['estadoClave']
            : '';

        // El WSDL A13 no expone fechaInscripcion, categoriaMonotributo
        // ni condicionIva; quedan en null por diseno. Si en el futuro
        // ARCA los vuelve a exponer, PadronClient los pasara en $data
        // y este fromArray los levantara.
        $fechaInscripcion = null;
        $categoriaMonotributo = null;
        $condicionIva = null;

        return new self(
            cuit: (int) ($data['idPersona'] ?? 0),
            razonSocial: $razonSocial,
            apellidoNombre: $apellidoNombre,
            tipoPersona: $tipoPersona,
            estadoClave: $estadoClave,
            fechaInscripcion: $fechaInscripcion,
            domicilioFiscal: DomicilioFiscal::fromArray($domicilio),
            // actividades/impuestos quedan en [] con el WSDL A13. Ver
            // docblock de la clase.
            actividades: [],
            impuestos: [],
            categoriaMonotributo: $categoriaMonotributo,
            condicionIva: $condicionIva,
        );
    }

    /**
     * Extrae un sub-arreglo de $data. Si la clave no existe o no es
     * array, devuelve [].
     *
     * @param array<string, mixed> $data
     * @return array<int|string, mixed>
     */
    private static function childArray(array $data, string $key): array
    {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            return [];
        }
        return $data[$key];
    }
}
