<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\ArcaSdk;

use PHPUnit\Framework\TestCase;
use Rbbsoft\ArcaSdk\ArcaSdk;
use Rbbsoft\ArcaSdk\Config\Config;
use Rbbsoft\ArcaSdk\Pdf\ComprobantePdfGenerator;
use Rbbsoft\ArcaSdk\Sdk\Container;
use Rbbsoft\ArcaSdk\Wsfe\ComprobanteEmitido;

/**
 * Tests del wrapper {@see ArcaSdk::generarPdf()}.
 *
 * Verifican que el wrapper delega correctamente al Container y que el
 * parametro `$destino` custom bypassea al generador del Container.
 *
 * Estrategia: usar {@see ComprobantePdfGeneratorCapturing}, una
 * subclase que overridea `generar()` y guarda los argumentos
 * recibidos en propiedades publicas. Asi no tocamos disco ni
 * ejercitamos mPDF real (que es lento y requiere `ext-gd` y QrCode
 * inicializado en el autoload de vendor); testeamos el wiring, no
 * la generacion de PDF.
 */
final class GenerarPdfTest extends TestCase
{
    /**
     * CUIT receptor ficticio (con checksum valido).
     */
    private const REF_CUIT_RECEPTOR = 30912345676;

    protected function setUp(): void
    {
        // El Singleton de ArcaSdk retiene la primera instancia creada
        // y la devuelve en llamadas siguientes con config "compatible",
        // ignorando el Container pasado. Sin reset, el segundo test
        // heredaria el Container del primero y las asserciones sobre
        // los capturing fallarian.
        ArcaSdk::resetInstance();
    }

    protected function tearDown(): void
    {
        ArcaSdk::resetInstance();
    }

    /**
     * @return array<string, mixed>
     */
    private function dataEmitido(): array
    {
        return [
            'cbte_tipo'               => 11,
            'cbte_nro'                => 4,
            'cbte_fch'                => '2026-08-04',
            'cae'                     => '86310728945116',
            'cae_fch_vto'             => '20260814',
            'monto_total'             => '100.00',
            'monto_neto'              => '100.00',
            'monto_iva'               => '0.00',
            'mon_id'                  => 'PES',
            'mon_cotiz'               => '1.00',
            'punto_venta'             => 2,
            'cuit'                    => 20123456786,
            'receptor_documento_tipo' => 80,
            'receptor_documento_nro'  => (string) self::REF_CUIT_RECEPTOR,
            'receptor_condicion_iva'  => 'MT',
            'items'                   => [
                ['importe_gravado' => '100.00', 'alicuota_iva' => '21'],
            ],
        ];
    }

    private function makeConfig(): Config
    {
        return Config::fromArray([
            'env'         => 'homo',
            'cuit'        => '20123456786',
            'punto_venta' => 2,
            'cert_path'   => __DIR__,
            'key_path'    => __DIR__,
            'db_dsn'      => 'mysql:host=localhost;dbname=arca_facturador_test;charset=utf8mb4',
            'db_user'     => 'root',
            'db_pass'     => '',
        ]);
    }

    public function test_generar_pdf_con_dto_delega_a_container(): void
    {
        $container = new Container($this->makeConfig());
        $capturing = new ComprobantePdfGeneratorCapturing('path/desde/container');
        $container->withComprobantePdfGenerator($capturing);

        $sdk = ArcaSdk::getInstance($this->makeConfig(), $container);
        $dto = ComprobanteEmitido::fromArray($this->dataEmitido());

        $result = $sdk->generarPdf($dto);

        $this->assertSame('stub://generado', $result);
        $this->assertNotNull($capturing->lastComprobante);
        $this->assertSame($dto->cae, $capturing->lastComprobante->cae);
        $this->assertSame($dto->cbteNro, $capturing->lastComprobante->cbteNro);
        $this->assertSame('path/desde/container', $capturing->lastDestDir);
    }

    public function test_generar_pdf_con_array_lo_convierte_a_dto(): void
    {
        $container = new Container($this->makeConfig());
        $capturing = new ComprobantePdfGeneratorCapturing();
        $container->withComprobantePdfGenerator($capturing);

        $sdk = ArcaSdk::getInstance($this->makeConfig(), $container);
        $array = $this->dataEmitido();

        $result = $sdk->generarPdf($array);

        $this->assertSame('stub://generado', $result);
        $this->assertNotNull($capturing->lastComprobante);
        $this->assertSame('86310728945116', $capturing->lastComprobante->cae);
        $this->assertSame(4, $capturing->lastComprobante->cbteNro);
        $this->assertSame((string) self::REF_CUIT_RECEPTOR, $capturing->lastComprobante->receptorDocumentoNro);
    }

    public function test_generar_pdf_con_destino_construye_generador_custom(): void
    {
        $container = new Container($this->makeConfig());
        $containerGen = new ComprobantePdfGeneratorCapturing('container/dir');
        $container->withComprobantePdfGenerator($containerGen);

        $sdk = ArcaSdk::getInstance($this->makeConfig(), $container);
        $dto = ComprobanteEmitido::fromArray($this->dataEmitido());

        $customDest = 'C:/custom/dir';
        $result = $sdk->generarPdf($dto, $customDest);

        // El Container NO debio recibir la llamada (es el assert clave
        // de este test). El resultado es del generador real de mPDF
        // y depende del entorno, asi que no se valida su contenido.
        $this->assertNotNull($result, 'el wrapper debe devolver un path no vacio');
        $this->assertNull($containerGen->lastComprobante, 'Container no debio recibir la llamada');
        $this->assertNull($containerGen->lastDestDir);
    }

    public function test_generar_pdf_sin_destino_usa_container(): void
    {
        $container = new Container($this->makeConfig());
        $capturing = new ComprobantePdfGeneratorCapturing();
        $container->withComprobantePdfGenerator($capturing);

        $sdk = ArcaSdk::getInstance($this->makeConfig(), $container);
        $dto = ComprobanteEmitido::fromArray($this->dataEmitido());

        $result = $sdk->generarPdf($dto);

        $this->assertSame('stub://generado', $result);
        $this->assertNotNull($capturing->lastComprobante);
        $this->assertSame($dto->cae, $capturing->lastComprobante->cae);
    }
}
