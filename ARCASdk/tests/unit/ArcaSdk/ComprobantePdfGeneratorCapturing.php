<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\ArcaSdk;

use Rbbsoft\ArcaSdk\Pdf\ComprobantePdfGenerator;
use Rbbsoft\ArcaSdk\Wsfe\ComprobanteEmitido;

/**
 * Doble de {@see ComprobantePdfGenerator} para tests unitarios.
 *
 * Overridea `generar()` para NO tocar disco ni ejercitar mPDF real.
 * Guarda el comprobante y el directorio destino que recibio en
 * propiedades publicas (`lastComprobante`, `lastDestDir`) para que
 * los tests aserten sobre el wiring. Devuelve un string fijo
 * (`stub://generado`) como path simulado.
 *
 * Se usa desde `GenerarPdfTest` para verificar que
 * `ArcaSdk::generarPdf()` delega correctamente al Container.
 */
final class ComprobantePdfGeneratorCapturing extends ComprobantePdfGenerator
{
    public ?ComprobanteEmitido $lastComprobante = null;
    public ?string $lastDestDir = null;

    public function generar(ComprobanteEmitido|array $comprobante, ?string $filename = null): string
    {
        if (is_array($comprobante)) {
            $comprobante = ComprobanteEmitido::fromArray($comprobante);
        }
        $this->lastComprobante = $comprobante;
        $this->lastDestDir = $this->destDir;
        return 'stub://generado';
    }
}
