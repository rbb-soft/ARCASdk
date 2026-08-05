<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Wsfe;

use Rbbsoft\ArcaSdk\Exceptions\ValidationException;
use Rbbsoft\ArcaSdk\Support\Money;

/**
 * Calculadora de importes para comprobantes electronicos ARCA.
 *
 * Reglas:
 *  - Toda aritmetica con BCMath (ext-bcmath). Nunca float.
 *  - Redondeo con Money::round a 2 decimales (escala contractual ARCA).
 *  - Alicuotas soportadas: 0, 2.5, 5, 10.5, 21, 27 (catalogo ARCA).
 *  - Cierre exacto: bccomp(total_calculado, total_declarado) === 0.
 *  - Para tipos que NO discriminan IVA (C, M): iva = [], ivaTotal = "0.00",
 *    total == netoGravado + noGravado + exento + otrosTrib.
 */
final class IvaCalculator
{
    /** Alicuotas permitidas por ARCA, canonicalizadas como string. */
    public const ALICUOTAS_PERMITIDAS = ['0', '2.5', '5', '10.5', '21', '27'];

    /**
     * Calcula importes a partir de los items y tipo de comprobante.
     *
     * @param array<int, array{importe_gravado: string|int|float, alicuota_iva: string|int|float}> $items
     * @param bool $discriminaIva true para A/B, false para C/M.
     */
    public static function calcular(
        array $items,
        bool $discriminaIva,
        string $importeNoGravado = '0',
        string $importeExento = '0',
        string $importeOtrosTrib = '0',
    ): ResultadoIva {
        if (count($items) === 0) {
            throw new ValidationException('items vacio: el comprobante debe tener al menos un item');
        }

        $neto = '0';
        /** @var array<string, string> $ivaAgrupado alicuota (string canonico) => importe */
        $ivaAgrupado = [];
        /**
         * Gravado agrupado por alicuota. ARCA exige que la suma de
         * BaseImp de todos los AlicIva coincida exactamente con ImpNeto,
         * por lo que no se puede emitir el netoGravado completo en cada
         * AlicIva cuando hay alicuotas mixtas. Cada AlicIva lleva el
         * gravado parcial que le corresponde.
         * @var array<string, string> $gravadoPorAlicuota alicuota => gravado
         */
        $gravadoPorAlicuota = [];

        foreach ($items as $idx => $item) {
            if (!isset($item['importe_gravado'], $item['alicuota_iva'])) {
                throw new ValidationException("item[{$idx}]: requiere importe_gravado y alicuota_iva");
            }

            $gravado = Money::round((string) $item['importe_gravado']);
            if (bccomp($gravado, '0', 2) < 0) {
                throw new ValidationException("item[{$idx}]: importe_gravado no puede ser negativo ({$gravado})");
            }

            $alicuota = self::normalizarAlicuota((string) $item['alicuota_iva']);
            if (!in_array($alicuota, self::ALICUOTAS_PERMITIDAS, true)) {
                throw new ValidationException(
                    "item[{$idx}]: alicuota_iva no permitida ({$alicuota}). Permitidas: "
                    . implode(', ', self::ALICUOTAS_PERMITIDAS)
                );
            }

            $neto = bcadd($neto, $gravado, 2);

            // Acumulamos gravado por alicuota (incluso si no discrimina
            // IVA, asi WsfeClient puede emitir AlicIva vacio sin BaseImp
            // incorrecta). En no-discrimina IVA se mantiene vacio
            // porque AlicIva no se envia.
            $gravadoPorAlicuota[$alicuota] = bcadd(
                $gravadoPorAlicuota[$alicuota] ?? '0',
                $gravado,
                2
            );

            if ($discriminaIva) {
                $ivaItem = self::calcularIvaItem($gravado, $alicuota);
                $ivaAgrupado[$alicuota] = bcadd(
                    $ivaAgrupado[$alicuota] ?? '0',
                    $ivaItem,
                    2
                );
            }
        }

        $neto = Money::round($neto);
        $noGravado = Money::round($importeNoGravado);
        $exento = Money::round($importeExento);
        $otrosTrib = Money::round($importeOtrosTrib);

        if ($discriminaIva) {
            // Orden determinista por alicuota canonica (ascendente) para reproducibilidad.
            ksort($ivaAgrupado, SORT_STRING);
            ksort($gravadoPorAlicuota, SORT_STRING);
            $ivaTotal = Money::round(Money::sum(array_values($ivaAgrupado)));
        } else {
            $ivaAgrupado = [];
            $gravadoPorAlicuota = [];
            $ivaTotal = '0.00';
        }

        $total = Money::round(bcadd(bcadd(bcadd($neto, $ivaTotal, 2), bcadd($noGravado, $exento, 2), 2), $otrosTrib, 2));

        // Cierre exacto
        $sumaComponentes = bcadd(
            bcadd(bcadd($neto, $ivaTotal, 2), bcadd($noGravado, $exento, 2), 2),
            $otrosTrib,
            2
        );
        if (bccomp($sumaComponentes, $total, 2) !== 0) {
            throw new ValidationException(sprintf(
                'cierre no exacto: neto=%s iva=%s nograv=%s exento=%s otros=%s total=%s',
                $neto, $ivaTotal, $noGravado, $exento, $otrosTrib, $total
            ));
        }

        // Cierre por-alicuota: sum(BaseImp_i) === netoGravado.
        // ARCA valida esto al recibir el FECAESolicitar: si la suma
        // no cierra, rechaza con "inconsistencia entre AlicIva e
        // ImpNeto". Aplicamos la verificacion aca para que un caller
        // que use IvaCalculator fuera de WsfeClient tambien dispare la
        // excepcion temprano, en vez de enterarse al recibir el CAE
        // rechazado.
        if ($discriminaIva) {
            $sumaBases = Money::sum(array_values($gravadoPorAlicuota));
            $sumaBases = Money::round($sumaBases);
            if (bccomp($sumaBases, $neto, 2) !== 0) {
                throw new ValidationException(sprintf(
                    'cierre AlicIva inconsistente: sum(BaseImp)=%s != ImpNeto=%s',
                    $sumaBases, $neto
                ));
            }
        }

        return new ResultadoIva(
            netoGravado: $neto,
            iva: $ivaAgrupado,
            gravadoPorAlicuota: $gravadoPorAlicuota,
            ivaTotal: $ivaTotal,
            importeNoGravado: $noGravado,
            importeExento: $exento,
            importeOtrosTrib: $otrosTrib,
            total: $total,
        );
    }

    /**
     * Normaliza una alicuota (puede venir como "21", 21, "21.0", "21,0") a su forma canonica.
     */
    public static function normalizarAlicuota(string|int|float $alicuota): string
    {
        $s = Money::normalize((string) $alicuota);
        if (!is_numeric($s)) {
            throw new ValidationException("alicuota no numerica: {$alicuota}");
        }
        $bcm = bcmul($s, '1', 2); // normaliza a 2 decimales exactos
        $bcm = rtrim(rtrim($bcm, '0'), '.');
        if ($bcm === '') {
            $bcm = '0';
        }
        return $bcm;
    }

    private static function calcularIvaItem(string $gravado, string $alicuota): string
    {
        if (bccomp($alicuota, '0', 2) === 0) {
            return '0.00';
        }
        // iva = gravado * alicuota / 100
        $iva = bcdiv(bcmul($gravado, $alicuota, 6), '100', 6);
        return Money::round($iva);
    }
}
