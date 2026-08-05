<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Wsfe;

/**
 * Resultado inmutable del calculo de importes por IvaCalculator.
 *
 * Estructura alineada con el payload que WsfeClient enviara a ARCA:
 *  - netoGravado:        suma de importes gravados (no incluye IVA).
 *  - iva:                array indexado por alicuota (string canonico) =>
 *                        importe de IVA, agrupado por alicuota. Para Facturas
 *                        C/M viene vacio (no se discrimina).
 *  - gravadoPorAlicuota: array indexado por alicuota (string canonico) =>
 *                        gravado parcial correspondiente a esa alicuota. Se
 *                        usa para emitir BaseImp en cada AlicIva. La suma
 *                        de todos los BaseImp es igual a netoGravado
 *                        (validado en IvaCalculator::calcular()). Para
 *                        Facturas C/M viene vacio (no se discrimina).
 *  - ivaTotal:           suma de iva (0 para C/M).
 *  - importeNoGravado:   no gravado (opcional, default 0).
 *  - importeExento:      exento (opcional, default 0).
 *  - importeOtrosTrib:   otros tributos (opcional, default 0).
 *  - total:              netoGravado + ivaTotal + noGravado + exento + otrosTrib.
 */
final class ResultadoIva
{
    /**
     * @param array<string, string> $iva alicuota (string canonico) => importe de IVA
     * @param array<string, string> $gravadoPorAlicuota alicuota (string canonico) => gravado parcial
     */
    public function __construct(
        public readonly string $netoGravado,
        public readonly array $iva,
        public readonly array $gravadoPorAlicuota,
        public readonly string $ivaTotal,
        public readonly string $importeNoGravado,
        public readonly string $importeExento,
        public readonly string $importeOtrosTrib,
        public readonly string $total,
    ) {
    }

    /**
     * Emite el bloque AlicIva que WsfeClient serializa en el FECAESolicitar.
     *
     * Para cada alicuota emite su BaseImp parcial (gravado correspondiente
     * a esa alicuota) — NO el netoGravado total. ARCA valida que
     * sum(BaseImp) === ImpNeto; emitir el netoGravado completo en cada
     * AlicIva (bug v1) da una suma N*netoGravado y la factura se rechaza
     * como "inconsistente".
     *
     * @return array<int, array{Id: string, BaseImp: string, Importe: string}>
     */
    public function aAlicIva(): array
    {
        $out = [];
        foreach ($this->iva as $alicuota => $importe) {
            $alicuotaStr = (string) $alicuota;
            // Si por algun motivo no tenemos gravadoPorAlicuota
            // pre-calculado (no deberia pasar, el constructor lo
            // garantiza), caemos al netoGravado completo y
            // avisamos via excepcion. Esto preserva el contrato
            // aunque alguien construya el objeto a mano.
            if (!isset($this->gravadoPorAlicuota[$alicuotaStr])) {
                throw new \LogicException(
                    "ResultadoIva: gravadoPorAlicuota no tiene entrada para alicuota {$alicuotaStr} (recalcule via IvaCalculator::calcular)"
                );
            }
            $out[] = [
                'Id'       => self::alicuotaId($alicuotaStr),
                'BaseImp'  => $this->gravadoPorAlicuota[$alicuotaStr],
                'Importe'  => $importe,
            ];
        }
        return $out;
    }

    /**
     * Mapea alicuota string canonica al codigo ARCA (Id).
     * Catalogo oficial: 3=0%, 9=2.5%, 8=5%, 4=10.5%, 5=21%, 6=27%.
     */
    public static function alicuotaId(string $alicuota): string
    {
        return match ($alicuota) {
            '0'    => '3',
            '2.5'  => '9',
            '5'    => '8',
            '10.5' => '4',
            '21'   => '5',
            '27'   => '6',
            default => throw new \InvalidArgumentException("alicuota no soportada: {$alicuota}"),
        };
    }
}
