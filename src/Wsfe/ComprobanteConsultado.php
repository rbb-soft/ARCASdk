<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Wsfe;

/**
 * Resultado normalizado de FECompConsultar. Es un value object inmutable
 * con los datos del comprobante que ARCA tiene almacenado, suficientes
 * para la comparacion zombie (Phase 7).
 *
 * El WsfeClient devuelve null cuando ARCA indica que el comprobante
 * NO existe (codigo 601 tipico).
 *
 * No todos los campos son contractuales para todos los cbte_tipo:
 *   - AlicIva solo si el tipo discrimina IVA.
 *   - CbtesAsoc solo para notas de credito.
 *   - FchServDesde / FchServHasta / FchVtoPago solo si concepto != 1.
 *
 * Las fechas y montos vienen como strings canonicos (YYYYMMDD /
 * "100.00" con 2 decimales) para poder compararse con bccomp / igualdad
 * textual estable.
 */
final class ComprobanteConsultado
{
    /**
     * @param array<int, array{Id:string, BaseImp:string, Importe:string}> $alicIva
     * @param array<int, array{Tipo:int, PtoVta:int, Nro:int}>             $cbtesAsoc
     */
    public function __construct(
        public readonly int $cbteTipo,
        public readonly int $puntoVenta,
        public readonly int $cbteNro,
        public readonly string $cbteFch,
        public readonly string $resultado,
        public readonly ?string $cae,
        public readonly ?string $caeFchVto,
        public readonly int $concepto,
        public readonly int $receptorDocumentoTipo,
        public readonly string $receptorDocumentoNro,
        public readonly string $impTotal,
        public readonly string $impNeto,
        public readonly string $impIva,
        public readonly string $impTrib,
        public readonly string $impOpEx,
        public readonly string $impTotConc,
        public readonly string $monId,
        public readonly string $monCotiz,
        public readonly array $alicIva = [],
        public readonly array $cbtesAsoc = [],
        public readonly ?int $fchServDesde = null,
        public readonly ?int $fchServHasta = null,
        public readonly ?int $fchVtoPago = null,
    ) {
        if (!in_array($resultado, ['A', 'R'], true)) {
            throw new \InvalidArgumentException("resultado invalido: {$resultado}");
        }
    }
}
