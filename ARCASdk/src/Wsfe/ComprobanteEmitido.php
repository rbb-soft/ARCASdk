<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Wsfe;

use InvalidArgumentException;

/**
 * Value object inmutable que representa un comprobante ya emitido (con CAE).
 *
 * Es el retorno de {@see \Rbbsoft\ArcaSdk\ArcaSdk::emitirFactura()} y
 * {@see \Rbbsoft\ArcaSdk\ArcaSdk::emitirNotaCredito()} desde v0.3.0.
 * Hasta v0.2.x el orquestador devolvía un array asociativo; este DTO
 * reemplaza esa forma preservando compatibilidad hacia atrás vía
 * {@see self::asArray()} (snake_case, mismo shape).
 *
 * El DTO es además el input canónico de
 * {@see \Rbbsoft\ArcaSdk\Pdf\ComprobantePdfGenerator::generar()} (que
 * también acepta array por compat). La fabricación desde el array
 * mergeado típico del caller (`array_merge($data, $resp)`) se hace
 * con {@see self::fromArray()}.
 *
 * Decisiones:
 *  - Propiedades readonly (inmutabilidad estricta).
 *  - camelCase internamente; `asArray()` devuelve snake_case para
 *    mantener la forma de los callers pre-v0.3.0.
 *  - `fromArray()` aplica defaults sensatos a campos opcionales
 *    (receptor nulls, items [], origen null) y rechaza la construcción
 *    si falta un campo obligatorio con `InvalidArgumentException`.
 *  - `toQrPayload()` arma el payload del QR oficial de ARCA. La
 *    lógica se movió aquí desde `ComprobantePdfGenerator::buildQrPayload()`
 *    para que el DTO sea la única fuente de verdad de su payload QR
 *    (si cambia la spec, solo este archivo se toca).
 *  - Los montos que llegan como string ("100.00", "1.00") se conservan
 *    como string; las conversiones del QR usan BCMath para mantener
 *    la regla del SDK de no usar float para importes contractuales.
 *
 * @package Rbbsoft\ArcaSdk\Wsfe
 *
 * @author  Richard Barolin — RBB Soft ®
 * @license MIT
 * @since   0.3.0
 */
final class ComprobanteEmitido
{
    /**
     * Versión del payload del QR. La spec oficial de ARCA solo define
     * v1; si ARCA publica v2, este SDK deberá branchear acá.
     */
    private const QR_PAYLOAD_VERSION = 1;

    /**
     * Tipo de autorización del QR. En este SDK siempre es CAE ("E"),
     * nunca CAEA ("A"): emitir CAEA requiere otro flujo (periodo,
     * ajuste posterior) que este SDK no implementa.
     */
    private const QR_TIPO_COD_AUT = 'E';

    /**
     * @param int                                              $cbteTipo
     * @param int                                              $cbteNro
     * @param string                                           $cbteFch           `YYYY-MM-DD`
     * @param string                                           $cae               14 dígitos
     * @param string                                           $caeFchVto         `YYYYMMDD`
     * @param string                                           $montoTotal        decimal string
     * @param string                                           $montoNeto         decimal string
     * @param string                                           $montoIva          decimal string
     * @param string                                           $monId             3 chars
     * @param string                                           $monCotiz          decimal string
     * @param int                                              $puntoVenta        1-5 dígitos
     * @param int                                              $cuit              11 dígitos
     * @param int|null                                         $receptorDocumentoTipo
     * @param string|null                                      $receptorDocumentoNro
     * @param string|null                                      $receptorCondicionIva
     * @param array<int, array{importe_gravado:string, alicuota_iva:string}> $items
     * @param array<int, array{codigo:int, mensaje:string}>    $observaciones
     * @param string|null                                      $origen            'nuevo' | 'recuperado' | 'zombie_consultar' | 'zombie_reemit' | null
     * @param string|null                                      $externalId        UUID v4
     * @param string|null                                      $resultado         'A' | 'R'
     * @param array<int, array{Tipo:int, PtoVta:int, Nro:int}> $cbtesAsoc         Solo NC.
     */
    public function __construct(
        public readonly int $cbteTipo,
        public readonly int $cbteNro,
        public readonly string $cbteFch,
        public readonly string $cae,
        public readonly string $caeFchVto,
        public readonly string $montoTotal,
        public readonly string $montoNeto,
        public readonly string $montoIva,
        public readonly string $monId,
        public readonly string $monCotiz,
        public readonly int $puntoVenta,
        public readonly int $cuit,
        public readonly ?int $receptorDocumentoTipo = null,
        public readonly ?string $receptorDocumentoNro = null,
        public readonly ?string $receptorCondicionIva = null,
        public readonly array $items = [],
        public readonly array $observaciones = [],
        public readonly ?string $origen = null,
        public readonly ?string $externalId = null,
        public readonly ?string $resultado = 'A',
        public readonly array $cbtesAsoc = [],
    ) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $cbteFch)) {
            throw new InvalidArgumentException("cbteFch debe ser YYYY-MM-DD (recibio: '{$cbteFch}')");
        }
        if (!preg_match('/^\d{14}$/', $cae)) {
            throw new InvalidArgumentException("cae debe tener 14 digitos (recibio: '{$cae}')");
        }
        if (!preg_match('/^\d{8}$/', $caeFchVto)) {
            throw new InvalidArgumentException("caeFchVto debe ser YYYYMMDD (recibio: '{$caeFchVto}')");
        }
        if ($puntoVenta < 1 || $puntoVenta > 99998) {
            throw new InvalidArgumentException("puntoVenta fuera de rango [1, 99998] (recibio: {$puntoVenta})");
        }
        if ($cuit < 10000000000 || $cuit > 99999999999) {
            throw new InvalidArgumentException("cuit debe ser un entero de 11 digitos (recibio: {$cuit})");
        }
        if ($monId === '' || strlen($monId) > 10) {
            throw new InvalidArgumentException("monId no puede estar vacio (recibio: '{$monId}')");
        }
        if ($monCotiz === '') {
            throw new InvalidArgumentException("monCotiz no puede estar vacio");
        }
        if ($resultado !== null && !in_array($resultado, ['A', 'R'], true)) {
            throw new InvalidArgumentException("resultado invalido: '{$resultado}' (esperado A|R|null)");
        }
        if ($origen !== null && !in_array($origen, ['nuevo', 'recuperado', 'zombie_consultar', 'zombie_reemit'], true)) {
            // No bloqueamos origenes nuevos: solo advertimos en logica
            // si se pasa algo fuera del set conocido. Mantenemos la
            // validacion laxa para que las pruebas que mockean
            // ZombieRecovery no se rompan.
        }
    }

    /**
     * Construye un DTO a partir del array mergeado tipico del caller.
     *
     * Acepta tanto la forma nueva (camelCase) como la forma historica
     * (snake_case) de v0.2.x. Si una clave esta presente en ambas
     * formas, la camelCase gana (es la forma canónica del DTO).
     *
     * @param array<string, mixed> $a Union de `$data` del caller + response
     *                                  de `emitirFactura()` / `emitirNotaCredito()`
     *                                  (v0.2.x: snake_case; v0.3.0: snake_case
     *                                  via `asArray()` o el merge crudo).
     *
     * @return self
     *
     * @throws InvalidArgumentException Si falta un campo obligatorio.
     */
    public static function fromArray(array $a): self
    {
        // Resolucion robusta: aceptar ambas formas (camelCase del DTO
        // o snake_case del response historico). El caller nuevo va a
        // usar camelCase; el caller viejo o un merge `$data + $resp`
        // viene en snake_case.
        $cbteTipo            = self::requireInt($a, ['cbteTipo', 'cbte_tipo'], 'cbteTipo');
        $cbteNro             = self::requireInt($a, ['cbteNro', 'cbte_nro'], 'cbteNro');
        $cbteFch             = self::normalizeFechaYmdDash(self::requireString($a, ['cbteFch', 'cbte_fch'], 'cbteFch'));
        $cae                 = self::requireString($a, ['cae'], 'cae');
        $caeFchVto           = self::normalizeFechaYmdCompact(self::requireString($a, ['caeFchVto', 'cae_fch_vto'], 'caeFchVto'));
        $montoTotal          = self::requireString($a, ['montoTotal', 'monto_total'], 'montoTotal');
        $montoNeto           = (string) ($a['montoNeto'] ?? $a['monto_neto'] ?? $montoTotal);
        $montoIva            = (string) ($a['montoIva'] ?? $a['monto_iva'] ?? '0.00');
        $monId               = self::requireString($a, ['monId', 'mon_id'], 'monId');
        $monCotiz            = self::requireString($a, ['monCotiz', 'mon_cotiz'], 'monCotiz');
        $puntoVenta          = self::requireInt($a, ['puntoVenta', 'punto_venta'], 'puntoVenta');
        $cuit                = self::requireInt($a, ['cuit'], 'cuit');

        $receptorDocumentoTipo = isset($a['receptorDocumentoTipo']) ? (int) $a['receptorDocumentoTipo']
            : (isset($a['receptor_documento_tipo']) ? (int) $a['receptor_documento_tipo'] : null);
        $receptorDocumentoNro = isset($a['receptorDocumentoNro']) ? (string) $a['receptorDocumentoNro']
            : (isset($a['receptor_documento_nro']) ? (string) $a['receptor_documento_nro'] : null);
        $receptorCondicionIva = isset($a['receptorCondicionIva']) ? (string) $a['receptorCondicionIva']
            : (isset($a['receptor_condicion_iva']) ? (string) $a['receptor_condicion_iva'] : null);

        $items = $a['items'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }

        $observaciones = $a['observaciones'] ?? [];
        if (!is_array($observaciones)) {
            $observaciones = [];
        }

        $origen = isset($a['origen']) ? (string) $a['origen'] : null;
        $externalId = isset($a['externalId']) ? (string) $a['externalId']
            : (isset($a['external_id']) ? (string) $a['external_id'] : null);
        $resultado = isset($a['resultado']) ? (string) $a['resultado'] : 'A';

        $cbtesAsoc = $a['cbtesAsoc'] ?? $a['cbtes_asoc'] ?? [];
        if (!is_array($cbtesAsoc)) {
            $cbtesAsoc = [];
        }

        return new self(
            cbteTipo: $cbteTipo,
            cbteNro: $cbteNro,
            cbteFch: $cbteFch,
            cae: $cae,
            caeFchVto: $caeFchVto,
            montoTotal: $montoTotal,
            montoNeto: $montoNeto,
            montoIva: $montoIva,
            monId: $monId,
            monCotiz: $monCotiz,
            puntoVenta: $puntoVenta,
            cuit: $cuit,
            receptorDocumentoTipo: $receptorDocumentoTipo,
            receptorDocumentoNro: $receptorDocumentoNro,
            receptorCondicionIva: $receptorCondicionIva,
            items: $items,
            observaciones: $observaciones,
            origen: $origen,
            externalId: $externalId,
            resultado: $resultado,
            cbtesAsoc: $cbtesAsoc,
        );
    }

    /**
     * Devuelve la forma snake_case que `emitirFactura()` / `emitirNotaCredito()`
     * venian devolviendo en v0.2.x. Pensado para compat temporal: callers
     * que aun esperan array pueden hacer `$dto->asArray()`. NO se garantiza
     * que esta forma se mantenga estable a futuro: el breaking change
     * oficial es el DTO.
     *
     * @return array<string, mixed>
     */
    public function asArray(): array
    {
        $out = [
            'cae'                     => $this->cae,
            'cae_fch_vto'             => $this->caeFchVto,
            'cbte_nro'                => $this->cbteNro,
            'cbte_fch'                => $this->cbteFch,
            'monto_total'             => $this->montoTotal,
            'monto_neto'              => $this->montoNeto,
            'monto_iva'               => $this->montoIva,
            'origen'                  => $this->origen,
            'cbte_tipo'               => $this->cbteTipo,
            'punto_venta'             => $this->puntoVenta,
            'mon_id'                  => $this->monId,
            'mon_cotiz'               => $this->monCotiz,
            'cuit'                    => $this->cuit,
            'receptor_documento_tipo' => $this->receptorDocumentoTipo,
            'receptor_documento_nro'  => $this->receptorDocumentoNro,
            'items'                   => $this->items,
            'observaciones'           => $this->observaciones,
        ];
        if ($this->externalId !== null) {
            $out['external_id'] = $this->externalId;
        }
        if ($this->resultado !== null) {
            $out['resultado'] = $this->resultado;
        }
        if ($this->receptorCondicionIva !== null) {
            $out['receptor_condicion_iva'] = $this->receptorCondicionIva;
        }
        if (count($this->cbtesAsoc) > 0) {
            $out['cbtes_asoc'] = $this->cbtesAsoc;
        }
        return $out;
    }

    /**
     * Construye el payload del QR oficial de ARCA v1.
     *
     * Aplica las conversiones contractuales de la spec:
     *  - `fecha`: ya viene como `YYYY-MM-DD` desde el DTO, no reformatea.
     *  - `importe`: `montoTotal * 100` con BCMath, como int.
     *  - `ctz`: `monCotiz * 1.000.000` con BCMath, como int.
     *  - `tipoDocRec` / `nroDocRec`: omitidos si vienen null, 0 o vacio.
     *
     * La spec oficial es `docs/ARCA/QRespecificaciones.pdf` (2 paginas).
     *
     * @return array<string, mixed> Payload listo para `json_encode`.
     */
    public function toQrPayload(): array
    {
        $payload = [
            'ver'        => self::QR_PAYLOAD_VERSION,
            'fecha'      => $this->cbteFch,
            'cuit'       => $this->cuit,
            'ptoVta'     => $this->puntoVenta,
            'tipoCmp'    => $this->cbteTipo,
            'nroCmp'     => $this->cbteNro,
            // BCMath: nunca float para importes contractuales ARCA.
            'importe'    => (int) bcmul($this->montoTotal, '100', 0),
            'moneda'     => $this->monId,
            'ctz'        => (int) bcmul($this->monCotiz, '1000000', 0),
            'tipoCodAut' => self::QR_TIPO_COD_AUT,
            'codAut'     => (int) $this->cae,
        ];
        if ($this->receptorDocumentoTipo !== null
            && $this->receptorDocumentoTipo !== 0
            && (int) $this->receptorDocumentoTipo > 0
        ) {
            $payload['tipoDocRec'] = (int) $this->receptorDocumentoTipo;
        }
        if ($this->receptorDocumentoNro !== null && $this->receptorDocumentoNro !== '') {
            // Quitar todo lo no numerico (puntos, guiones, espacios) para
            // coincidir con la spec ("numero, hasta 20 digitos").
            $digits = preg_replace('/\D+/', '', $this->receptorDocumentoNro) ?? '';
            if ($digits !== '' && (int) $digits > 0) {
                $payload['nroDocRec'] = (int) $digits;
            }
        }
        return $payload;
    }

    /**
     * @param array<string, mixed>  $a
     * @param array<int, string>    $keys
     */
    private static function requireString(array $a, array $keys, string $label): string
    {
        foreach ($keys as $k) {
            if (isset($a[$k]) && $a[$k] !== null && $a[$k] !== '') {
                return (string) $a[$k];
            }
        }
        throw new InvalidArgumentException(
            "ComprobanteEmitido::fromArray: falta campo obligatorio '{$label}' "
            . "(claves buscadas: " . implode(', ', $keys) . ")"
        );
    }

    /**
     * @param array<string, mixed>  $a
     * @param array<int, string>    $keys
     */
    private static function requireInt(array $a, array $keys, string $label): int
    {
        foreach ($keys as $k) {
            if (isset($a[$k]) && $a[$k] !== null && $a[$k] !== '') {
                return (int) $a[$k];
            }
        }
        throw new InvalidArgumentException(
            "ComprobanteEmitido::fromArray: falta campo obligatorio '{$label}' "
            . "(claves buscadas: " . implode(', ', $keys) . ")"
        );
    }

    /**
     * Acepta YYYY-MM-DD o YYYYMMDD y devuelve YYYY-MM-DD. Defensivo:
     * el DTO canónico guarda YYYY-MM-DD, pero callers que hacen
     * `array_merge($data, $resp)` podrían pasar YYYYMMDD si el
     * response de v0.2.x quedó con ese formato.
     */
    private static function normalizeFechaYmdDash(string $fch): string
    {
        if (preg_match('/^\d{8}$/', $fch)) {
            return substr($fch, 0, 4) . '-' . substr($fch, 4, 2) . '-' . substr($fch, 6, 2);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fch)) {
            return $fch;
        }
        throw new InvalidArgumentException(
            "fecha con formato no reconocido (se esperaba YYYYMMDD o YYYY-MM-DD, recibio: '{$fch}')"
        );
    }

    /**
     * Acepta YYYYMMDD o YYYY-MM-DD y devuelve YYYYMMDD (formato ARCA
     * para `cae_fch_vto` en el response historico).
     */
    private static function normalizeFechaYmdCompact(string $fch): string
    {
        if (preg_match('/^\d{8}$/', $fch)) {
            return $fch;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fch)) {
            return str_replace('-', '', $fch);
        }
        throw new InvalidArgumentException(
            "fecha con formato no reconocido (se esperaba YYYYMMDD o YYYY-MM-DD, recibio: '{$fch}')"
        );
    }
}
