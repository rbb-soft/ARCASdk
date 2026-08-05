<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Wsfe;

use Rbbsoft\ArcaSdk\Exceptions\ValidationException;
use Rbbsoft\ArcaSdk\Support\Money;

/**
 * Value object inmutable que representa una solicitud de comprobante ARCA.
 *
 * - Se construye con Comprobante::fromArray($data, $defaultPuntoVenta).
 * - El schema es estricto por tipo: claves desconocidas lanzan ValidationException.
 * - Los metadatos internos del caller deben viajar FUERA de $data; no participan
 *   del fingerprint ni del payload ARCA.
 * - canonicalJson() produce la representacion canonica (claves ordenadas,
 *   importes ya normalizados, alicuotas en orden determinista) usada por
 *   request_fingerprint y por la persistencia inmutable de request_json.
 *   NO incluye CbteNro ni CbteFch.
 */
final class Comprobante
{
    /** Claves permitidas en $data por tipo. El resto -> ValidationException. */
    private const SCHEMA_POR_TIPO = [
        'comun' => [
            'punto_venta', 'concepto', 'receptor_documento_tipo', 'receptor_documento_nro',
            'receptor_condicion_iva', 'mon_id', 'mon_cotiz', 'items',
            'importe_no_gravado', 'importe_exento', 'importe_otros_tributos',
        ],
        'servicios' => ['servicio_desde', 'servicio_hasta', 'vencimiento_pago'],
        'nota_credito' => ['cbtes_asoc'],
    ];

    /**
     * @param array<int, array{Tipo: int, PtoVta: int, Nro: int}> $cbtesAsoc
     * @param array<int, array{importe_gravado: string, alicuota_iva: string}> $items
     */
    public function __construct(
        public readonly int $cbteTipo,
        public readonly int $puntoVenta,
        public readonly int $concepto,
        public readonly int $receptorDocumentoTipo,
        public readonly string $receptorDocumentoNro,
        public readonly string $receptorCondicionIva,
        public readonly string $monId,
        public readonly string $monCotiz,
        public readonly array $items,
        public readonly string $importeNoGravado = '0.00',
        public readonly string $importeExento = '0.00',
        public readonly string $importeOtrosTributos = '0.00',
        public readonly ?int $servicioDesde = null,
        public readonly ?int $servicioHasta = null,
        public readonly ?int $vencimientoPago = null,
        public readonly array $cbtesAsoc = [],
    ) {
    }

    /**
     * Construye un Comprobante a partir de un array.
     *
     * @param array<string, mixed> $data
     * @param int $defaultPuntoVenta Usado si $data no incluye 'punto_venta'.
     */
    public static function fromArray(array $data, int $defaultPuntoVenta, ?int $cbteTipo = null): self
    {
        $tipo = $cbteTipo ?? (isset($data['cbte_tipo']) ? (int) $data['cbte_tipo'] : null);
        if ($tipo === null) {
            throw new ValidationException('cbte_tipo es obligatorio (en $data o como parametro)');
        }
        if (!TiposComprobante::esValido($tipo)) {
            throw new ValidationException("cbte_tipo no soportado: {$tipo}");
        }

        // Schema estricto: union de claves permitidas
        $permitidas = self::SCHEMA_POR_TIPO['comun'];
        $concepto = (int) ($data['concepto'] ?? 0);
        if ($concepto === 2 || $concepto === 3) {
            $permitidas = array_merge($permitidas, self::SCHEMA_POR_TIPO['servicios']);
        }
        if (TiposComprobante::esNotaCredito($tipo)) {
            $permitidas = array_merge($permitidas, self::SCHEMA_POR_TIPO['nota_credito']);
        }

        $desconocidas = array_diff(array_keys($data), $permitidas, ['cbte_tipo']);
        if (count($desconocidas) > 0) {
            throw new ValidationException(
                'claves desconocidas en $data: ' . implode(', ', $desconocidas)
            );
        }

        // Punto de venta: $data gana sobre default
        $pv = isset($data['punto_venta']) ? (int) $data['punto_venta'] : $defaultPuntoVenta;
        if ($pv < 1 || $pv > 99998) {
            throw new ValidationException("punto_venta fuera de rango: {$pv}");
        }

        // Concepto
        if (!in_array($concepto, [1, 2, 3], true)) {
            throw new ValidationException("concepto invalido: {$concepto} (esperado 1|2|3)");
        }

        // Receptor
        $docTipo = isset($data['receptor_documento_tipo']) ? (int) $data['receptor_documento_tipo'] : 0;
        $docNro  = isset($data['receptor_documento_nro'])  ? (string) $data['receptor_documento_nro'] : '';
        if (!in_array($docTipo, [80, 86, 87, 89, 90, 91, 92, 93, 94, 95, 96, 99], true)) {
            throw new ValidationException("receptor_documento_tipo invalido: {$docTipo}");
        }
        if ($docNro === '' || strlen($docNro) > 11) {
            throw new ValidationException("receptor_documento_nro obligatorio y <= 11 chars");
        }
        if (TiposComprobante::requiereCuit($tipo) && $docTipo !== 80) {
            throw new ValidationException("cbte_tipo={$tipo} requiere receptor con CUIT (docTipo=80)");
        }

        $condIva = (string) ($data['receptor_condicion_iva'] ?? '');
        if (!in_array($condIva, ['RI', 'CF', 'MT', 'EX', 'NC'], true)) {
            throw new ValidationException("receptor_condicion_iva invalida: '{$condIva}' (esperado RI|CF|MT|EX|NC)");
        }

        // Moneda
        $monId   = strtoupper(trim((string) ($data['mon_id'] ?? 'PES')));
        $monCotiz = (string) ($data['mon_cotiz'] ?? '1.00');
        if ($monId === '') {
            $monId = 'PES';
        }
        if ($monId === 'PES' && bccomp(Money::normalize($monCotiz), '1.00', 2) !== 0) {
            // Para PES la cotizacion es 1.00 por convencion; aceptamos 1.0000 etc pero
            // advertimos si no es exactamente 1.00. No bloqueamos para no romper callers
            // que envien "1.0000".
        }
        $monCotiz = Money::round($monCotiz, 4);
        if (bccomp($monCotiz, '0', 4) <= 0) {
            throw new ValidationException("mon_cotiz debe ser > 0 (recibido: {$monCotiz})");
        }

        // Items
        $items = self::normalizarItems($data['items'] ?? null);

        // Servicios (si aplica)
        $servDesde = null; $servHasta = null; $vencPago = null;
        if ($concepto !== 1) {
            foreach (['servicio_desde', 'servicio_hasta'] as $k) {
                if (isset($data[$k]) && !preg_match('/^\d{8}$/', (string) $data[$k])) {
                    throw new ValidationException("{$k} debe ser YYYYMMDD (recibido: {$data[$k]})");
                }
            }
            if (!isset($data['servicio_desde']) || !isset($data['servicio_hasta'])) {
                throw new ValidationException('servicio_desde y servicio_hasta son obligatorios para concepto 2/3');
            }
            $servDesde = (int) $data['servicio_desde'];
            $servHasta = (int) $data['servicio_hasta'];
            if ($servHasta < $servDesde) {
                throw new ValidationException('servicio_hasta no puede ser anterior a servicio_desde');
            }
            if (isset($data['vencimiento_pago'])) {
                if (!preg_match('/^\d{8}$/', (string) $data['vencimiento_pago'])) {
                    throw new ValidationException('vencimiento_pago debe ser YYYYMMDD');
                }
                $vencPago = (int) $data['vencimiento_pago'];
            }
        }

        // Notas de credito: cbtes_asoc obligatorios y del tipo compatible
        $cbtesAsoc = [];
        if (TiposComprobante::esNotaCredito($tipo)) {
            $raw = $data['cbtes_asoc'] ?? null;
            if (!is_array($raw) || count($raw) === 0) {
                throw new ValidationException('cbtes_asoc obligatorio para Nota de Credito');
            }
            $esperado = TiposComprobante::tipoAsocEsperadoParaNotaCredito($tipo);
            foreach ($raw as $idx => $a) {
                if (!is_array($a) || !isset($a['tipo'], $a['punto_venta'], $a['nro'])) {
                    throw new ValidationException("cbtes_asoc[{$idx}]: requiere tipo, punto_venta, nro");
                }
                $aTipo = (int) $a['tipo'];
                if ($esperado !== null && $aTipo !== $esperado) {
                    throw new ValidationException(
                        "cbtes_asoc[{$idx}]: tipo {$aTipo} incompatible con cbte_tipo={$tipo} (esperado {$esperado})"
                    );
                }
                $cbtesAsoc[] = [
                    'Tipo'     => $aTipo,
                    'PtoVta'   => (int) $a['punto_venta'],
                    'Nro'      => (int) $a['nro'],
                ];
            }
            usort($cbtesAsoc, fn(array $a, array $b) => [$a['Tipo'], $a['PtoVta'], $a['Nro']] <=> [$b['Tipo'], $b['PtoVta'], $b['Nro']]);
        }

        return new self(
            cbteTipo: $tipo,
            puntoVenta: $pv,
            concepto: $concepto,
            receptorDocumentoTipo: $docTipo,
            receptorDocumentoNro: $docNro,
            receptorCondicionIva: $condIva,
            monId: $monId,
            monCotiz: $monCotiz,
            items: $items,
            importeNoGravado: Money::round((string) ($data['importe_no_gravado'] ?? '0')),
            importeExento: Money::round((string) ($data['importe_exento'] ?? '0')),
            importeOtrosTributos: Money::round((string) ($data['importe_otros_tributos'] ?? '0')),
            servicioDesde: $servDesde,
            servicioHasta: $servHasta,
            vencimientoPago: $vencPago,
            cbtesAsoc: $cbtesAsoc,
        );
    }

    /**
     * @param array<int, mixed>|null $raw
     * @return array<int, array{importe_gravado: string, alicuota_iva: string}>
     */
    private static function normalizarItems(mixed $raw): array
    {
        if (!is_array($raw) || count($raw) === 0) {
            throw new ValidationException('items: array no vacio requerido');
        }
        $out = [];
        foreach ($raw as $idx => $item) {
            if (!is_array($item) || !isset($item['importe_gravado'], $item['alicuota_iva'])) {
                throw new ValidationException("items[{$idx}]: requiere importe_gravado y alicuota_iva");
            }
            $out[] = [
                'importe_gravado' => Money::round((string) $item['importe_gravado']),
                'alicuota_iva'    => IvaCalculator::normalizarAlicuota((string) $item['alicuota_iva']),
            ];
        }
        usort($out, function (array $a, array $b) {
            $cmp = bccomp($a['importe_gravado'], $b['importe_gravado'], 2);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp($a['alicuota_iva'], $b['alicuota_iva']);
        });
        return $out;
    }

    /**
     * JSON canonico de los datos de negocio NORMALIZADOS.
     *
     * Excluye CbteNro y CbteFch (asignados por el SDK).
     * Usado como base del request_fingerprint (sha256) y para persistir
     * el snapshot inmutable en request_json.
     */
    public function canonicalJson(): string
    {
        $arr = [
            'cbte_tipo'   => $this->cbteTipo,
            'punto_venta' => $this->puntoVenta,
            'concepto'    => $this->concepto,
            'receptor'    => [
                'documento_tipo'    => $this->receptorDocumentoTipo,
                'documento_nro'     => $this->receptorDocumentoNro,
                'condicion_iva'     => $this->receptorCondicionIva,
            ],
            'moneda'      => [
                'id'     => $this->monId,
                'cotiz'  => $this->monCotiz,
            ],
            'items'       => $this->items,
            'importe_no_gravado'   => $this->importeNoGravado,
            'importe_exento'       => $this->importeExento,
            'importe_otros_tributos' => $this->importeOtrosTributos,
        ];
        if ($this->concepto !== 1) {
            $arr['servicio'] = [
                'desde'             => $this->servicioDesde,
                'hasta'             => $this->servicioHasta,
                'vencimiento_pago'  => $this->vencimientoPago,
            ];
        }
        if (count($this->cbtesAsoc) > 0) {
            $arr['cbtes_asoc'] = $this->cbtesAsoc;
        }
        return json_encode(self::ksortRecursive($arr), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** SHA-256 hex sobre canonicalJson(). */
    public function fingerprint(): string
    {
        return hash('sha256', $this->canonicalJson());
    }

    private static function ksortRecursive(array $arr): array
    {
        ksort($arr, SORT_STRING);
        foreach ($arr as $k => $v) {
            if (is_array($v)) {
                $arr[$k] = self::ksortRecursive($v);
            }
        }
        return $arr;
    }
}
