<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Pdf;

use Mpdf\Mpdf;
use Mpdf\QrCode\Output\Png as QrPngOutput;
use Mpdf\QrCode\QrCode;
use Rbbsoft\ArcaSdk\Wsfe\ComprobanteEmitido;
use RuntimeException;

/**
 * Generador de PDF para comprobantes ARCA con QR oficial.
 *
 * Recibe un {@see ComprobanteEmitido} (o un array mergeado con la misma
 * forma, para compat con callers pre-v0.3.0) y produce un PDF A4 con:
 *
 *  - Header: tipo de comprobante (texto), numero, fecha.
 *  - Emisor: CUIT, punto de venta.
 *  - Receptor (opcional): tipo y numero de documento, condicion IVA.
 *  - Items: tabla con gravado, alicuota e IVA por linea.
 *  - Totales: subtotal, IVA, total (los del comprobante).
 *  - CAE y vencimiento.
 *  - QR oficial de ARCA (https://www.arca.gob.ar/fe/qr/?p=...) embebido
 *    como imagen PNG via data URI. La URL completa se imprime debajo
 *    del QR para debug / auditoria.
 *
 * El layout HTML es FUNCIONAL, no final. Es la primera iteracion del
 * generador; los detalles visuales (logo, margen, tipografia, datos del
 * emisor desde padron) se iran refinando en iteraciones futuras.
 *
 * ---------------------------------------------------------------
 * Decisiones de diseno (no obvias, vale la pena explicitar)
 * ---------------------------------------------------------------
 *
 *  1. **QR segun spec oficial ARCA v1** (ver
 *     docs/ARCA/QRespecificaciones.pdf). La logica del payload vive
 *     en {@see ComprobanteEmitido::toQrPayload()}; este generador
 *     solo la invoca y serializa. Si cambia la spec, solo el DTO se
 *     toca. El `ver` siempre es 1, el `tipoCodAut` siempre es "E"
 *     (este SDK solo emite CAE, no CAEA). La codificacion final es
 *     `base64_encode(json_encode(..., JSON_UNESCAPED_UNICODE |
 *     JSON_UNESCAPED_SLASHES))` — Base64 estandar (`+/=`), NO
 *     base64url. La URL base es `https://www.arca.gob.ar/fe/qr/`
 *     (renombrado del historico `afip.gob.ar`).
 *
 *  2. **Acepta DTO o array**: la firma es
 *     `generar(ComprobanteEmitido|array $comprobante, ...)`. Si recibe
 *     array, lo pasa por `ComprobanteEmitido::fromArray()` al inicio.
 *     Esto preserva compat con callers que aun arman `array_merge($data, $resp)`.
 *
 *  3. **Receptor opcional**: la spec dice "de corresponder" para
 *     `tipoDocRec`/`nroDocRec`. Si en el input vienen vacios o en 0
 *     se omiten del JSON del QR (manejado en el DTO). Lo mismo para
 *     el bloque Receptor del PDF.
 *
 *  4. **Fecha**: el comprobante emitido ya tiene `cbteFch` en formato
 *     `YYYY-MM-DD` (canónico del DTO). Se acepta el formato
 *     `YYYYMMDD` defensivamente al parsear arrays.
 *
 *  5. **mPDF 8.x + mpdf/qrcode 1.x**: a partir de mPDF 8.0 la clase
 *     `Mpdf\QrCode\QrCode` se movio a un paquete separado
 *     (`mpdf/qrcode`). La API no es `$qr->output(...)` (eso era del
 *     bundle viejo de mPDF 7.x); la API actual es:
 *
 *         $qr     = new QrCode($url);
 *         $render = new QrPngOutput();
 *         $png    = $render->output($qr, 300, [255,255,255], [0,0,0]);
 *
 *     El data URI se embebe en `<img src="data:image/png;base64,...">`
 *     (mPDF 8.1+ lo maneja OK, antes habia un bug con data URIs).
 *
 *  6. **Path del PDF**: si `$destDir` es relativo, se resuelve contra
 *     `getcwd()`. Tras crear el directorio se llama a `realpath()` para
 *     devolver un path normalizado (sin `..` ni separadores
 *     mezclados). En Windows el separador sera `\`.
 *
 *  7. **Sobrescritura silenciosa**: si el PDF destino ya existe, se
 *     sobreescribe sin error. Es el comportamiento esperado para
 *     re-emisiones con el mismo (pv, tipo, nro): el caller es
 *     responsable de no regenerar comprobantes ya emitidos (eso es
 *     problema de la capa de emision, no del generador downstream).
 *
 *  8. **ext-gd requerida**: mPDF usa GD para procesar imagenes y
 *     tablas. La extension `ext-gd` debe estar habilitada en
 *     `php.ini`. {@see \Rbbsoft\ArcaSdk\Config\Config::verificarExtensionesRequeridas()}
 *     la valida.
 *
 * @package Rbbsoft\ArcaSdk\Pdf
 *
 * @author  Richard Barolin — RBB Soft ®
 * @license MIT
 * @since   0.2.0
 */
class ComprobantePdfGenerator
{
    /**
     * URL base del servicio de QR de ARCA. Cambio de dominio respecto
     * del historico `afip.gob.ar`; el nuevo es `arca.gob.ar` (ver spec
     * oficial vigente, no la URL vieja impresa en PDFs emitidos antes
     * del rebranding).
     */
    private const QR_BASE_URL = 'https://www.arca.gob.ar/fe/qr/';

    /**
     * Ancho del PNG del QR (px). 300 es buena fidelidad para A4 a
     * 72 dpi sin inflar el PDF. La libreria mpdf/qrcode usa este valor
     * como lado del cuadrado final.
     */
    private const QR_PNG_WIDTH = 300;

    /**
     * Directorio destino de los PDFs generados. `null` (o vacio)
     * significa "directorio por defecto `comprobantes` relativo a
     * `getcwd()`". El path se resuelve a absoluto en cada llamada a
     * {@see self::generar()} via {@see self::resolveAbsDir()}.
     *
     * Visibilidad: `protected` (no `private`) para que los test
     * doubles del generador (en `tests/unit/ArcaSdk/`) puedan leer
     * el destino configurado en su override de `generar()`. No es
     * parte de la API pública.
     *
     * @var string|null
     */
    protected readonly ?string $destDir;

    /**
     * @param string|null $destDir Directorio destino (relativo a
     *                              getcwd() o absoluto). `null` =>
     *                              "comprobantes" (default).
     */
    public function __construct(?string $destDir = 'comprobantes')
    {
        $this->destDir = ($destDir === null || $destDir === '') ? 'comprobantes' : $destDir;
    }

    /**
     * Genera el PDF de un comprobante y lo guarda en `$destDir`.
     *
     * Acepta:
     *  - Un {@see ComprobanteEmitido} (forma canonica desde v0.3.0).
     *  - Un array mergeado con la union de los datos de input del caller
     *    y el response de `ArcaSdk::emitirFactura()` /
     *    `emitirNotaCredito()` (forma historica pre-v0.3.0). La forma
     *    esperada (claves tipicas) es:
     *
     *      - Del input del caller: cbte_tipo, mon_id, mon_cotiz, items,
     *        receptor_documento_tipo, receptor_documento_nro,
     *        receptor_condicion_iva.
     *      - Del response: cuit, punto_venta, cbte_nro, cbte_fch, cae,
     *        cae_fch_vto, monto_total, monto_neto, monto_iva.
     *
     *    Para hacer el merge tipico:
     *
     *        $pdfInput = array_merge($data, $resp->asArray());
     *        (new ComprobantePdfGenerator())->generar($pdfInput);
     *
     * @param ComprobanteEmitido|array<string,mixed> $comprobante
     * @param string|null                             $filename   Nombre del archivo (sin
     *                                                          path). Si `null`, se
     *                                                          autogenera como
     *                                                          `{cbte_tipo}-{cbte_nro}.pdf`.
     *
     * @return string Path absoluto del PDF generado.
     *
     * @throws RuntimeException Si falta un campo obligatorio, no se
     *                          puede escribir el archivo, o falla la
     *                          generacion del QR / el render del PDF.
     */
    public function generar(ComprobanteEmitido|array $comprobante, ?string $filename = null): string
    {
        if (is_array($comprobante)) {
            $comprobante = ComprobanteEmitido::fromArray($comprobante);
        }

        $absDir = $this->resolveAbsDir();
        $this->ensureDir($absDir);

        $qrPayload = $comprobante->toQrPayload();
        $qrJson    = json_encode(
            $qrPayload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($qrJson === false) {
            throw new RuntimeException(
                'No se pudo serializar el payload del QR a JSON: ' . json_last_error_msg()
            );
        }
        $qrB64 = base64_encode($qrJson);
        $qrUrl = self::QR_BASE_URL . '?p=' . $qrB64;

        $qrDataUri = $this->renderQrDataUri($qrUrl);

        $finalName = $this->resolveFilename($filename, $comprobante->cbteTipo, $comprobante->cbteNro);
        $fullPath  = $absDir . DIRECTORY_SEPARATOR . $finalName;

        $html = $this->buildHtml($comprobante, $qrDataUri, $qrUrl, $qrPayload);

        try {
            $pdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4']);
            $pdf->WriteHTML($html);
            $pdf->Output($fullPath, 'F');
        } catch (\Throwable $e) {
            throw new RuntimeException(
                "Fallo la generacion del PDF '{$fullPath}': " . $e->getMessage(),
                0,
                $e
            );
        }

        if (!is_file($fullPath) || filesize($fullPath) === 0) {
            throw new RuntimeException(
                "El PDF no se escribio o quedo vacio en '{$fullPath}'"
            );
        }

        return $fullPath;
    }

    // ----------------------------------------------------------------
    // QR render
    // ----------------------------------------------------------------

    /**
     * Genera el PNG del QR y lo devuelve como data URI
     * `data:image/png;base64,...` listo para embeber en un `<img src>`.
     *
     * @param string $url URL a codificar en el QR (la URL completa
     *                    con `?p=...`).
     *
     * @return string Data URI con el PNG.
     *
     * @throws RuntimeException Si mpdf/qrcode no esta disponible o falla
     *                          el render.
     */
    private function renderQrDataUri(string $url): string
    {
        try {
            $qr     = new QrCode($url);
            $render = new QrPngOutput();
            $png    = $render->output($qr, self::QR_PNG_WIDTH, [255, 255, 255], [0, 0, 0]);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Fallo el render del QR (mpdf/qrcode): ' . $e->getMessage()
                . '. Verifica que el paquete mpdf/qrcode este instalado (composer require mpdf/qrcode).',
                0,
                $e
            );
        }
        if (!is_string($png) || $png === '') {
            throw new RuntimeException('El render del QR devolvio un PNG vacio.');
        }
        return 'data:image/png;base64,' . base64_encode($png);
    }

    // ----------------------------------------------------------------
    // HTML layout (funcional, no final — ver docblock de la clase)
    // ----------------------------------------------------------------

    /**
     * Renderiza el HTML del comprobante. Estilos inline (mPDF resuelve
     * CSS inline con mejor compatibilidad que `<style>` externo).
     *
     * @param ComprobanteEmitido   $c          Comprobante emitido.
     * @param string               $qrDataUri  Data URI del QR.
     * @param string               $qrUrl      URL completa (con ?p=).
     * @param array<string, mixed> $qrPayload  Payload ya construido (para
     *                                         mostrar campos crudos en
     *                                         la seccion de debug).
     *
     * @return string HTML listo para $mpdf->WriteHTML().
     */
    private function buildHtml(ComprobanteEmitido $c, string $qrDataUri, string $qrUrl, array $qrPayload): string
    {
        $cuit       = (string) $c->cuit;
        $tipoLabel  = $this->tipoComprobanteLabel($c->cbteTipo);
        $itemsRows  = $this->renderItemsRows($c->items);
        $receptorBox = $this->renderReceptorBlock($c);

        $qrUrlSafe   = $this->h($qrUrl);
        $qrJsonSafe  = $this->h(json_encode($qrPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>{$tipoLabel} {$c->cbteNro}</title>
</head>
<body style="font-family: Helvetica, Arial, sans-serif; font-size: 11pt; color: #222;">

<table style="width:100%; border-collapse: collapse; margin-bottom: 20px;">
  <tr>
    <td style="vertical-align: top;">
      <div style="font-size: 22pt; font-weight: bold;">{$this->h($tipoLabel)}</div>
      <div style="font-size: 10pt; color: #555; margin-top: 4px;">
        N&deg; {$this->h($this->padLeft((string) $c->cbteNro, 8, '0'))} &nbsp;&middot;&nbsp;
        {$this->h($c->cbteFch)}
      </div>
    </td>
    <td style="text-align: right; vertical-align: top;">
      <div style="font-size: 9pt; color: #555;">Comprobante electronico</div>
      <div style="font-size: 9pt; color: #555;">ARCA - WSFEv1</div>
    </td>
  </tr>
</table>

<table style="width:100%; border-collapse: collapse; margin-bottom: 16px;">
  <tr>
    <td style="width:50%; vertical-align: top; padding: 8px; background: #f6f6f6; border: 1px solid #ddd;">
      <div style="font-size: 9pt; color: #555; text-transform: uppercase; letter-spacing: 0.5px;">Emisor</div>
      <div style="margin-top: 4px;"><strong>CUIT:</strong> {$this->h($this->formatCuitDashed($cuit))}</div>
      <div><strong>Punto de Venta:</strong> {$this->h($this->padLeft((string) $c->puntoVenta, 5, '0'))}</div>
    </td>
    <td style="width:50%; vertical-align: top; padding: 8px; background: #f6f6f6; border: 1px solid #ddd;">
{$receptorBox}
    </td>
  </tr>
</table>

<div style="font-size: 9pt; color: #555; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Detalle</div>
<table style="width:100%; border-collapse: collapse; margin-bottom: 16px;">
  <thead>
    <tr style="background: #eee;">
      <th style="text-align: right; padding: 6px 8px; border-bottom: 2px solid #999;">Gravado</th>
      <th style="text-align: right; padding: 6px 8px; border-bottom: 2px solid #999;">Al&iacute;cuota</th>
      <th style="text-align: right; padding: 6px 8px; border-bottom: 2px solid #999;">IVA</th>
    </tr>
  </thead>
  <tbody>
{$itemsRows}
  </tbody>
</table>

<table style="width: 50%; margin-left: auto; border-collapse: collapse; margin-bottom: 20px;">
  <tr>
    <td style="padding: 4px 8px;">Subtotal</td>
    <td style="padding: 4px 8px; text-align: right;">{$this->h($this->formatMoney($c->montoNeto))}</td>
  </tr>
  <tr>
    <td style="padding: 4px 8px;">IVA</td>
    <td style="padding: 4px 8px; text-align: right;">{$this->h($this->formatMoney($c->montoIva))}</td>
  </tr>
  <tr style="background: #eee; font-weight: bold;">
    <td style="padding: 6px 8px; border-top: 2px solid #999;">Total</td>
    <td style="padding: 6px 8px; text-align: right; border-top: 2px solid #999;">{$this->h($this->formatMoney($c->montoTotal))}</td>
  </tr>
</table>

<table style="width:100%; border-collapse: collapse; padding: 8px; background: #f6f6f6; border: 1px solid #ddd; margin-bottom: 20px;">
  <tr>
    <td style="width:50%;">
      <div style="font-size: 9pt; color: #555; text-transform: uppercase;">CAE</div>
      <div style="font-family: monospace; font-size: 12pt; margin-top: 2px;">{$this->h($c->cae)}</div>
    </td>
    <td style="width:50%;">
      <div style="font-size: 9pt; color: #555; text-transform: uppercase;">Vencimiento CAE</div>
      <div style="font-family: monospace; font-size: 12pt; margin-top: 2px;">{$this->h($this->formatFechaYmdDash($c->caeFchVto))}</div>
    </td>
  </tr>
</table>

<table style="width:100%; border-collapse: collapse;">
  <tr>
    <td style="text-align: center; vertical-align: top;">
      <img src="{$qrDataUri}" alt="QR ARCA" style="width: 45mm; height: 45mm;" />
      <div style="font-size: 7pt; color: #555; word-break: break-all; max-width: 60mm; margin: 6px auto 0;">
        {$qrUrlSafe}
      </div>
      <div style="font-size: 7pt; color: #888; word-break: break-all; max-width: 60mm; margin: 4px auto 0; text-align: left; font-family: monospace;">
        <strong>Payload:</strong> {$qrJsonSafe}
      </div>
    </td>
  </tr>
</table>

</body>
</html>
HTML;
    }

    /**
     * Renderiza las filas de la tabla de items. Si el item no trae
     * `importe_iva` lo computamos como gravado * alicuota / 100 para
     * que la tabla muestre el desglose incluso para comprobantes que
     * discriminan IVA (A, B). Para C/M el IVA siempre sera 0.00.
     *
     * @param array<int, mixed> $items Puede ser lista de items o vacio.
     *
     * @return string HTML de los `<tr>` (sin `<tbody>`/`<table>`).
     */
    private function renderItemsRows(array $items): string
    {
        if (count($items) === 0) {
            return '<tr><td colspan="3" style="padding: 6px 8px; color: #888; text-align: center; font-style: italic;">Sin items.</td></tr>';
        }
        $out = '';
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $grav = (string) ($it['importe_gravado'] ?? '0.00');
            $ali  = isset($it['alicuota_iva']) ? (string) $it['alicuota_iva'] : null;
            if (isset($it['importe_iva']) && $it['importe_iva'] !== '') {
                $iva = (string) $it['importe_iva'];
            } elseif ($ali !== null && $ali !== '') {
                // gravado * alicuota / 100 con BCMath, redondeo a 2.
                $iva = bcdiv(bcmul($grav, $ali, 4), '100', 2);
            } else {
                $iva = '0.00';
            }
            $gravFmt = $this->formatMoney($grav);
            $aliFmt  = $ali !== null ? $this->h(rtrim(rtrim($ali, '0'), '.')) . ' %' : '—';
            $ivaFmt  = $this->formatMoney($iva);
            $out .= "    <tr>\n"
                  . "      <td style=\"text-align: right; padding: 4px 8px; border-bottom: 1px solid #eee;\">{$gravFmt}</td>\n"
                  . "      <td style=\"text-align: right; padding: 4px 8px; border-bottom: 1px solid #eee;\">{$aliFmt}</td>\n"
                  . "      <td style=\"text-align: right; padding: 4px 8px; border-bottom: 1px solid #eee;\">{$ivaFmt}</td>\n"
                  . "    </tr>\n";
        }
        return $out;
    }

    /**
     * Renderiza el bloque HTML del receptor (celda derecha de la
     * tabla "Emisor | Receptor"). Devuelve string vacio si no hay
     * datos del receptor para mostrar.
     */
    private function renderReceptorBlock(ComprobanteEmitido $c): string
    {
        $tipoDoc = $c->receptorDocumentoTipo;
        $nroDoc  = $c->receptorDocumentoNro;
        $condIva = $c->receptorCondicionIva;
        $hasAny  = ($tipoDoc !== null && $tipoDoc !== 0)
                || ($nroDoc !== null && $nroDoc !== '')
                || ($condIva !== null && $condIva !== '');
        if (!$hasAny) {
            return "      <div style=\"font-size: 9pt; color: #555; text-transform: uppercase; letter-spacing: 0.5px;\">Receptor</div>\n"
                 . "      <div style=\"margin-top: 4px; color: #888; font-style: italic;\">No informado</div>\n";
        }
        $out = "      <div style=\"font-size: 9pt; color: #555; text-transform: uppercase; letter-spacing: 0.5px;\">Receptor</div>\n";
        if ($tipoDoc !== null && $tipoDoc !== 0 && (int) $tipoDoc > 0) {
            $tipoLabel = $this->tipoDocLabel((int) $tipoDoc);
            $nroFmt    = $nroDoc !== null && $nroDoc !== ''
                ? ' ' . $this->h($this->formatCuitDashed((string) $nroDoc))
                : '';
            $out .= "      <div style=\"margin-top: 4px;\"><strong>{$this->h($tipoLabel)}:</strong>{$nroFmt}</div>\n";
        } elseif ($nroDoc !== null && $nroDoc !== '') {
            $out .= "      <div style=\"margin-top: 4px;\">{$this->h((string) $nroDoc)}</div>\n";
        }
        if ($condIva !== null && $condIva !== '') {
            $out .= "      <div><strong>Cond. IVA:</strong> {$this->h((string) $condIva)}</div>\n";
        }
        return $out;
    }

    // ----------------------------------------------------------------
    // Helpers de formato
    // ----------------------------------------------------------------

    /**
     * Devuelve el path absoluto y normalizado del directorio destino.
     * Si el directorio aun no existe, devuelve el path resuelto
     * (mkdir() corre despues). Si existe, devuelve `realpath()`.
     */
    private function resolveAbsDir(): string
    {
        $dir = $this->destDir;
        if (!$this->isAbsolutePath($dir)) {
            $dir = (getcwd() !== false ? getcwd() : '.') . DIRECTORY_SEPARATOR . $dir;
        }
        $dir = rtrim($dir, '/\\');
        if (is_dir($dir)) {
            $real = realpath($dir);
            if ($real !== false) {
                return $real;
            }
        }
        return $dir;
    }

    /**
     * Crea el directorio destino recursivamente, idempotente.
     * Patron recomendado por PHP para tolerar la race condition con
     * otro proceso que cree el mismo directorio entre is_dir() y
     * mkdir().
     */
    private function ensureDir(string $absDir): void
    {
        if (is_dir($absDir)) {
            return;
        }
        if (!mkdir($absDir, 0775, true) && !is_dir($absDir)) {
            throw new RuntimeException(
                "No se pudo crear el directorio destino '{$absDir}' (verifica permisos)."
            );
        }
    }

    /**
     * Resuelve el nombre del archivo final. Si el caller paso uno
     * explicito, se respeta (asegurandose que termine en .pdf). Si
     * no, se autogenera como `{cbte_tipo}-{cbte_nro}.pdf`.
     */
    private function resolveFilename(?string $filename, int $cbteTipo, int $cbteNro): string
    {
        if ($filename !== null && $filename !== '') {
            return str_ends_with(strtolower($filename), '.pdf')
                ? $filename
                : $filename . '.pdf';
        }
        return $cbteTipo . '-' . $cbteNro . '.pdf';
    }

    /**
     * Formatea una fecha como `YYYY-MM-DD` aceptando tanto 8 digitos
     * pegados (`YYYYMMDD`) como 10 chars con guion (`YYYY-MM-DD`).
     * Lanza excepcion si el formato de entrada no es reconocible.
     */
    private function formatFechaYmdDash(string $fch): string
    {
        if (preg_match('/^\d{8}$/', $fch)) {
            return substr($fch, 0, 4) . '-' . substr($fch, 4, 2) . '-' . substr($fch, 6, 2);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fch)) {
            return $fch;
        }
        throw new RuntimeException(
            "Fecha con formato no reconocido (se esperaba YYYYMMDD o YYYY-MM-DD, recibio: '{$fch}')"
        );
    }

    /**
     * Formatea un CUIT de 11 digitos como `XX-XXXXXXXX-X`. Si el
     * CUIT no tiene 11 digitos lo devuelve como esta.
     */
    private function formatCuitDashed(string $cuit): string
    {
        if (strlen($cuit) !== 11) {
            return $cuit;
        }
        return substr($cuit, 0, 2) . '-' . substr($cuit, 2, 8) . '-' . substr($cuit, 10, 1);
    }

    /**
     * Pad a la izquierda con un caracter. Usado para punto de venta
     * (5 chars) y numero de comprobante (8 chars), siguiendo la
     * convencion de display ARCA.
     */
    private function padLeft(string $s, int $len, string $pad): string
    {
        return str_pad($s, $len, $pad, STR_PAD_LEFT);
    }

    /**
     * Formatea un importe string (BCMath) con coma decimal y 2
     * decimales, para display. No recorta precision: el caller
     * garantiza 2 decimales (Money::round del SDK).
     */
    private function formatMoney(string $n): string
    {
        if (!is_numeric($n)) {
            return $n;
        }
        return number_format((float) $n, 2, ',', '.');
    }

    /**
     * Etiqueta humana para el tipo de comprobante (catalogo ARCA).
     * Si el codigo no esta en el switch, devuelve un fallback legible
     * para evitar PDF silenciosamente con un "tipo 7" sin contexto.
     */
    private function tipoComprobanteLabel(int $codigo): string
    {
        return match ($codigo) {
            1   => 'Factura A',
            2   => 'Nota de Debito A',
            3   => 'Nota de Credito A',
            6   => 'Factura B',
            7   => 'Nota de Debito B',
            8   => 'Nota de Credito B',
            11  => 'Factura C',
            12  => 'Nota de Debito C',
            13  => 'Nota de Credito C',
            51  => 'Factura M',
            53  => 'Nota de Credito M',
            default => 'Comprobante (codigo ' . $codigo . ')',
        };
    }

    /**
     * Etiqueta humana para el tipo de documento del receptor
     * (catalogo ARCA / RG 1361).
     */
    private function tipoDocLabel(int $codigo): string
    {
        return match ($codigo) {
            80  => 'CUIT',
            86  => 'CUIL',
            87  => 'CDI',
            89  => 'LE',
            90  => 'LC',
            91  => 'CI Extranjera',
            92  => 'En tramite',
            93  => 'Acta de nacimiento',
            94  => 'Pasaporte',
            95  => 'CI Bs.As. RNP',
            96  => 'DNI',
            99  => 'Identidad Fiscal Exterior',
            default => 'Doc.codigo ' . $codigo,
        };
    }

    /**
     * @return bool true si el path es absoluto (Unix `/foo` o Windows
     *              `C:\foo`, `C:/foo`, `\\server\share`).
     */
    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        // Unix
        if ($path[0] === '/') {
            return true;
        }
        // Windows: "C:\" o "C:/" (2+ chars, segundo es ':')
        if (strlen($path) >= 3 && $path[1] === ':' && ($path[2] === '\\' || $path[2] === '/')) {
            return true;
        }
        // UNC: \\server\share
        if (str_starts_with($path, '\\\\')) {
            return true;
        }
        return false;
    }

    /**
     * Escape HTML basico (atributos y contenido). Suficiente para
     * los textos que metemos en el layout; no es para HTML externo.
     */
    private function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
