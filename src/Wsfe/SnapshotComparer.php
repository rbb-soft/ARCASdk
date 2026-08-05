<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Wsfe;

use Rbbsoft\ArcaSdk\Exceptions\CaeSecuestradoException;
use Rbbsoft\ArcaSdk\Exceptions\ValidationException;
use Rbbsoft\ArcaSdk\Support\Money;

/**
 * Comparador semantico entre el snapshot inmutable de un comprobante
 * (Comprobante) y la respuesta de `FECompConsultar` (ComprobanteConsultado).
 *
 * Se usa en la recuperacion zombie (Phase 7): cuando un worker muere
 * despues de reservar un `cbte_nro_enviado`, el siguiente reabre la
 * fila, consulta ARCA por ese numero y compara lo que ARCA devolvio
 * contra lo que originalmente se intento emitir. Si coinciden, el CAE
 * que devolvio ARCA es nuestro y lo recuperamos. Si NO coinciden, es
 * un CAE secuestrado: ARCA lo emitio con datos distintos (probable
 * uso manual del mismo PV/tipo/numero por otro proceso) y NO nos lo
 * apropiamos.
 *
 * Reglas de comparacion (plan seccion 7 paso 2):
 *
 *  - **Siempre se compara** (identidad del comprobante):
 *    * cbte_tipo, punto_venta, cbte_nro, cbte_fch
 *    * documento receptor (DocTipo + DocNro)
 *    * total (ImpTotal)
 *    * moneda (MonId, MonCotiz)
 *
 *  - **IVA solo cuando el tipo discrimina** (`discriminaIva(cbteTipo)`):
 *    se compara ImpIva + AlicIva (por alicuota). El agrupamiento se
 *    hace con BCMath a la escala contractual (2 decimales) usando
 *    `IvaCalculator::calcular` sobre los items del snapshot y luego
 *    comparando contra AlicIva de ARCA.
 *
 *  - **cbtes_asoc SOLO para NC**: si la NC requiere cbtes_asoc y
 *    ARCA no los devuelve (o difiere), es mismatch.
 *
 *  - **Campos adicionales de ARCA** (no contractuales o nuevos) se
 *    ignoran: no cuentan como mismatch.
 *
 *  - **Campos que el snapshot exige y ARCA no devuelve** -> error de
 *    protocolo (CaeSecuestradoException con mensaje explicito).
 *
 *  - **Comparacion SEMANTICA, no textual**:
 *    * Documentos CUIT (DocTipo=80) se normalizan a digitos puros
 *      (sin guiones, sin puntos, sin espacios).
 *    * Documentos que no son CUIT (DNI, etc.) se comparan literalmente
 *      preservando ceros a la izquierda.
 *    * Enteros sin padding ("00100" == "100").
 *    * Codigos canonicos (MonId, MonCotiz) se comparan como strings.
 *    * Fechas normalizadas a YYYYMMDD antes de comparar.
 *    * Decimales con BCMath a la escala contractual antes de bccomp.
 *    * Alicuotas: el snapshot trae la tasa (e.g. "21"), ARCA trae
 *      el Id interno (e.g. "5"). Hay un mapa canonico entre ambos.
 */
final class SnapshotComparer
{
    /**
     * Mapeo canonico: Id ARCA de AlicIva -> tasa (string canonico).
     * Catalogo oficial del manual WSFEV1 v4.3.
     */
    private const ARCA_ALIC_ID_TO_RATE = [
        '3'  => '0',
        '4'  => '10.5',
        '5'  => '21',
        '6'  => '27',
        '8'  => '5',
        '9'  => '2.5',
    ];

    /** Escala contractual ARCA para importes monetarios. */
    private const MONEY_SCALE = 2;

    /**
     * Compara el snapshot contra la respuesta de `FECompConsultar`.
     *
     * @param Comprobante           $expected        Snapshot reconstruido.
     * @param ComprobanteConsultado $actual          Respuesta de FECompConsultar.
     * @param string|null           $expectedCbteFch Fecha civil (YYYYMMDD) que
     *                                               se intento enviar. Si es
     *                                               null, no se compara la
     *                                               fecha (util para tests
     *                                               unitarios que no la
     *                                               incluyen).
     *
     * @throws CaeSecuestradoException Si difieren en cualquier campo
     *                                 contractual o si ARCA no devuelve
     *                                 un campo que el snapshot exige.
     */
    public static function compare(
        Comprobante $expected,
        ComprobanteConsultado $actual,
        ?string $expectedCbteFch = null
    ): void {
        // Identidad fija (no deberia diferir: la consulta fue para el
        // mismo (pv, tipo, nro) pero validamos por defensa).
        // cbte_nro NO se compara aca: la fuente de verdad es el cbte_nro_enviado
        // persistido en la fila (FilaEmision), no el del Comprobante (que
        // no lo tiene — el caller no lo provee, lo asigna el SDK). La
        // validacion cbte_nro_persistido == cbte_nro_arca vive en
        // ZombieRecovery::handleExists, que es donde ambos valores estan
        // disponibles.
        self::assertSameInt('cbte_tipo', $expected->cbteTipo, $actual->cbteTipo);
        self::assertSameInt('punto_venta', $expected->puntoVenta, $actual->puntoVenta);

        // cbte_fch: ambos lados YYYYMMDD pero pueden tener padding o
        // separadores. Normalizamos y comparamos.
        if ($expectedCbteFch !== null) {
            $expFch = self::normalizeDate($expectedCbteFch);
            $actFch = self::normalizeDate((string) $actual->cbteFch);
            if ($expFch === '' || $actFch === '' || $expFch !== $actFch) {
                throw self::mismatch('cbte_fch', $expFch, $actFch);
            }
        }

        // Receptor: DocTipo + DocNro (normalizado segun tipo).
        self::assertSameInt('receptor_documento_tipo', $expected->receptorDocumentoTipo, $actual->receptorDocumentoTipo);
        $expDoc = self::normalizeDocumento(
            $expected->receptorDocumentoTipo,
            $expected->receptorDocumentoNro
        );
        $actDoc = self::normalizeDocumento(
            $actual->receptorDocumentoTipo,
            $actual->receptorDocumentoNro
        );
        if ($expDoc !== $actDoc) {
            throw self::mismatch('receptor_documento_nro', $expDoc, $actDoc);
        }

        // Total: BCMath a 2 decimales. Calculamos el "esperado" con la
        // misma logica que el envio original (IvaCalculator) para no
        // depender de campos que el snapshot no guarda.
        $expectedTotal = self::computeTotal($expected);
        self::assertMoneyEqual('total', $expectedTotal, $actual->impTotal);

        // Moneda: monId canonico, monCotiz a 4 decimales (escala
        // contractual del WSFE para cotizacion).
        $expectedMonCotiz = self::normalizeMoneyDecimal($expected->monCotiz, 4);
        $actualMonCotiz = self::normalizeMoneyDecimal($actual->monCotiz, 4);
        if ($expected->monId !== $actual->monId) {
            throw self::mismatch('mon_id', $expected->monId, $actual->monId);
        }
        if (bccomp($expectedMonCotiz, $actualMonCotiz, 4) !== 0) {
            throw self::mismatch('mon_cotiz', $expectedMonCotiz, $actualMonCotiz);
        }

        // Servicio (concepto != 1): si el snapshot tiene fechas de
        // servicio y ARCA las devolvio, comparar. ARCA puede no
        // devolverlas si el campo no es contractual para el tipo: en
        // ese caso, ignorar.
        if ($expected->concepto !== 1) {
            self::compareServicio($expected, $actual);
        }

        // cbtes_asoc SOLO para NC.
        if (TiposComprobante::esNotaCredito($expected->cbteTipo)) {
            self::compareCbtesAsoc($expected, $actual);
        }

        // IVA: solo si el tipo discrimina.
        if (TiposComprobante::discriminaIva($expected->cbteTipo)) {
            self::compareIva($expected, $actual);
        }
    }

    /**
     * Compara fechas de servicio: si ARCA las devolvio, deben coincidir.
     * Si no las devolvio, no es error (algunos tipos no las exponen).
     */
    private static function compareServicio(Comprobante $expected, ComprobanteConsultado $actual): void
    {
        if ($expected->servicioDesde !== null && $actual->fchServDesde !== null) {
            if ((int) $expected->servicioDesde !== (int) $actual->fchServDesde) {
                throw self::mismatch(
                    'servicio_desde',
                    (string) $expected->servicioDesde,
                    (string) $actual->fchServDesde
                );
            }
        }
        if ($expected->servicioHasta !== null && $actual->fchServHasta !== null) {
            if ((int) $expected->servicioHasta !== (int) $actual->fchServHasta) {
                throw self::mismatch(
                    'servicio_hasta',
                    (string) $expected->servicioHasta,
                    (string) $actual->fchServHasta
                );
            }
        }
        if ($expected->vencimientoPago !== null && $actual->fchVtoPago !== null) {
            if ((int) $expected->vencimientoPago !== (int) $actual->fchVtoPago) {
                throw self::mismatch(
                    'vencimiento_pago',
                    (string) $expected->vencimientoPago,
                    (string) $actual->fchVtoPago
                );
            }
        }
    }

    /**
     * Compara cbtes_asoc (NC). El snapshot declara la asociacion; ARCA
     * debe devolver un set equivalente (mismos elementos, posiblemente
     * en otro orden). Si ARCA no devuelve cbtes_asoc, es un error de
     * protocolo (CaeSecuestradoException), no un warning: para NC la
     * asociacion es contractual.
     */
    private static function compareCbtesAsoc(Comprobante $expected, ComprobanteConsultado $actual): void
    {
        // Si el snapshot NO exige asociacion (NC sin cbtes_asoc, lo
        // cual no es posible porque la validacion rechaza NC sin
        // cbtes_asoc, pero por defensa), aceptamos que ARCA tampoco
        // la devuelva.
        if (count($expected->cbtesAsoc) === 0) {
            return;
        }
        if (count($actual->cbtesAsoc) === 0) {
            throw new CaeSecuestradoException(
                'protocol error: ARCA no devolvio CbtesAsoc para NC',
                ['cbtes_asoc' => 'requerido'],
                ['cbtes_asoc' => 'ausente'],
            );
        }
        // Canonicalizar ambos sets: ordenar por (Tipo, PtoVta, Nro).
        $expectedSorted = self::sortCbtesAsoc($expected->cbtesAsoc);
        $actualSorted = self::sortCbtesAsoc($actual->cbtesAsoc);
        if (count($expectedSorted) !== count($actualSorted)) {
            throw self::mismatch(
                'cbtes_asoc.count',
                (string) count($expectedSorted),
                (string) count($actualSorted)
            );
        }
        foreach ($expectedSorted as $i => $e) {
            $a = $actualSorted[$i];
            if ((int) $e['Tipo'] !== (int) $a['Tipo']
                || (int) $e['PtoVta'] !== (int) $a['PtoVta']
                || (int) $e['Nro'] !== (int) $a['Nro']
            ) {
                throw self::mismatch(
                    "cbtes_asoc[{$i}]",
                    sprintf('(Tipo=%d, PtoVta=%d, Nro=%d)', $e['Tipo'], $e['PtoVta'], $e['Nro']),
                    sprintf('(Tipo=%d, PtoVta=%d, Nro=%d)', $a['Tipo'], $a['PtoVta'], $a['Nro'])
                );
            }
        }
    }

    /**
     * @param array<int, array{Tipo:int, PtoVta:int, Nro:int}> $asoc
     * @return array<int, array{Tipo:int, PtoVta:int, Nro:int}>
     */
    private static function sortCbtesAsoc(array $asoc): array
    {
        $copy = $asoc;
        usort($copy, static fn(array $x, array $y) => [$x['Tipo'], $x['PtoVta'], $x['Nro']] <=> [$y['Tipo'], $y['PtoVta'], $y['Nro']]);
        return $copy;
    }

    /**
     * Compara el desglose de IVA: agrupa los items del snapshot por
     * alicuota, suma base e importe con BCMath a 2 decimales, y compara
     * contra AlicIva de ARCA.
     */
    private static function compareIva(Comprobante $expected, ComprobanteConsultado $actual): void
    {
        // Reconstruir el ResultadoIva del snapshot. Esto re-aplica la
        // logica de calculo oficial sobre los items, asi sabemos que
        // el "esperado" es lo que el SDK envio originalmente a ARCA.
        $discrimina = true;
        try {
            $res = IvaCalculator::calcular(
                $expected->items,
                $discrimina,
                $expected->importeNoGravado,
                $expected->importeExento,
                $expected->importeOtrosTributos,
            );
        } catch (ValidationException $e) {
            // El snapshot no deberia estar corrupto a nivel de calculo
            // (se valido al emitir), pero por defensa, propagamos.
            throw $e;
        }

        // Comparar ImpIva total.
        self::assertMoneyEqual('imp_iva', $res->ivaTotal, $actual->impIva);

        // Comparar AlicIva por alicuota. La clave es la tasa canonica
        // (string), el valor es (BaseImp, Importe) en 2 decimales.
        /** @var array<string, array{BaseImp:string, Importe:string}> $esperadoAlic */
        $esperadoAlic = [];
        foreach ($res->gravadoPorAlicuota as $alicuota => $gravado) {
            $importe = $res->iva[$alicuota] ?? '0.00';
            $esperadoAlic[(string) $alicuota] = [
                'BaseImp' => self::normalizeMoneyDecimal($gravado, self::MONEY_SCALE),
                'Importe' => self::normalizeMoneyDecimal($importe, self::MONEY_SCALE),
            ];
        }

        // Agrupar AlicIva de ARCA por tasa (no por Id interno).
        /** @var array<string, array{BaseImp:string, Importe:string}> $actualAlic */
        $actualAlic = [];
        foreach ($actual->alicIva as $a) {
            $id = (string) ($a['Id'] ?? '');
            $tasa = self::ARCA_ALIC_ID_TO_RATE[$id] ?? null;
            if ($tasa === null) {
                // Id desconocido: ignoramos (no es error de protocolo
                // por si solo, pero lo registramos en el diff).
                continue;
            }
            $base = self::normalizeMoneyDecimal((string) ($a['BaseImp'] ?? '0.00'), self::MONEY_SCALE);
            $imp = self::normalizeMoneyDecimal((string) ($a['Importe'] ?? '0.00'), self::MONEY_SCALE);
            if (!isset($actualAlic[$tasa])) {
                $actualAlic[$tasa] = ['BaseImp' => $base, 'Importe' => $imp];
            } else {
                // Sumar alicuotas con la misma tasa (caso borde:
                // ARCA envia AlicIva repetidos; los agrupamos como
                // hace el SDK al construir el request).
                $actualAlic[$tasa]['BaseImp'] = bcadd($actualAlic[$tasa]['BaseImp'], $base, self::MONEY_SCALE);
                $actualAlic[$tasa]['Importe'] = bcadd($actualAlic[$tasa]['Importe'], $imp, self::MONEY_SCALE);
            }
        }

        // Mismas claves (tasa)? Solo exigimos que las tasas del snapshot
        // esten presentes en ARCA; tasas extra en ARCA (e.g. una
        // AlicIva 0% adicional) se IGNORAN (el plan dice: "ARCA may
        // have additional fields not in snapshot -> ignored").
        $expKeys = array_keys($esperadoAlic);
        $actKeys = array_keys($actualAlic);
        sort($expKeys);
        sort($actKeys);
        // Subset: snapshot ⊆ actual. Si falta alguna tasa del snapshot
        // en ARCA, es protocol error.
        $missing = array_diff($expKeys, $actKeys);
        if (count($missing) > 0) {
            $missingList = implode(',', $missing);
            throw new CaeSecuestradoException(
                "protocol error: ARCA no devolvio AlicIva para tasas: {$missingList}",
                ['alicuotas.faltantes' => $missingList],
                ['alicuotas.recibidas' => '[' . implode(',', $actKeys) . ']'],
            );
        }
        // Comparar BaseImp e Importe por alicuota del snapshot.
        foreach ($esperadoAlic as $tasa => $vals) {
            $actVals = $actualAlic[$tasa] ?? null;
            if ($actVals === null) {
                throw new CaeSecuestradoException(
                    "protocol error: ARCA no devolvio AlicIva para tasa {$tasa}",
                    ['alicIva.' . $tasa => 'requerido'],
                    ['alicIva.' . $tasa => 'ausente'],
                );
            }
            if (bccomp($vals['BaseImp'], $actVals['BaseImp'], self::MONEY_SCALE) !== 0) {
                throw self::mismatch(
                    "alicuota.{$tasa}.base_imp",
                    $vals['BaseImp'],
                    $actVals['BaseImp']
                );
            }
            if (bccomp($vals['Importe'], $actVals['Importe'], self::MONEY_SCALE) !== 0) {
                throw self::mismatch(
                    "alicuota.{$tasa}.importe",
                    $vals['Importe'],
                    $actVals['Importe']
                );
            }
        }
    }

    /**
     * Compara dos enteros: si difieren, lanza CaeSecuestradoException.
     * "00100" y "100" son iguales (sin padding).
     */
    private static function assertSameInt(string $field, int $expected, int $actual): void
    {
        if ($expected !== $actual) {
            throw self::mismatch($field, (string) $expected, (string) $actual);
        }
    }

    /**
     * Compara dos importes monetarios con BCMath a la escala contractual.
     * "0.00" y "0" son iguales; "121.00" y "121" son iguales.
     */
    private static function assertMoneyEqual(string $field, string $expected, string $actual): void
    {
        $exp = self::normalizeMoneyDecimal($expected, self::MONEY_SCALE);
        $act = self::normalizeMoneyDecimal($actual, self::MONEY_SCALE);
        if (bccomp($exp, $act, self::MONEY_SCALE) !== 0) {
            throw self::mismatch($field, $exp, $act);
        }
    }

    /**
     * Normaliza un numero de documento segun su tipo:
     *  - CUIT (DocTipo=80): strip no-digitos, comparar como digitos puros.
     *  - Otros: trim, comparar literal (preservando ceros a la izquierda).
     */
    private static function normalizeDocumento(int $docTipo, string $nro): string
    {
        if ($docTipo === 80) {
            $digits = preg_replace('/\D+/', '', $nro) ?? '';
            return $digits;
        }
        return trim($nro);
    }

    /**
     * Normaliza un importe decimal a la escala indicada, con Money::round.
     */
    private static function normalizeMoneyDecimal(string $value, int $scale): string
    {
        return Money::round($value, $scale);
    }

    /**
     * Total del snapshot (calculado con IvaCalculator sobre los items).
     * Se calcula aca para no requerir que el caller lo pase; ademas
     * garantiza que usamos la misma logica que el envio original.
     */
    private static function computeTotal(Comprobante $expected): string
    {
        $discrimina = TiposComprobante::discriminaIva($expected->cbteTipo);
        $res = IvaCalculator::calcular(
            $expected->items,
            $discrimina,
            $expected->importeNoGravado,
            $expected->importeExento,
            $expected->importeOtrosTributos,
        );
        return $res->total;
    }

    /**
     * Normaliza una fecha a YYYYMMDD. Acepta los formatos que el
     * parser documenta: YYYYMMDD (8 digitos), YYYY-MM-DD (con guion).
     */
    private static function normalizeDate(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($digits) === 8) {
            return $digits;
        }
        // Intentar YYYY-MM-DD -> YYYYMMDD.
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
            return $m[1] . $m[2] . $m[3];
        }
        return $raw;
    }

    /**
     * Helper: lanza CaeSecuestradoException con campos esperados y
     * recibidos para diagnostico.
     */
    private static function mismatch(string $field, string $expected, string $actual): CaeSecuestradoException
    {
        return new CaeSecuestradoException(
            "snapshot mismatch in {$field}: expected={$expected}, actual={$actual}",
            [$field => $expected],
            [$field => $actual],
        );
    }
}
