<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\Wsfe;

use PHPUnit\Framework\TestCase;
use Rbbsoft\ArcaSdk\Config\Config;
use Rbbsoft\ArcaSdk\Exceptions\WsfeArcaTransientException;
use Rbbsoft\ArcaSdk\Exceptions\WsfeException;
use Rbbsoft\ArcaSdk\Exceptions\WsfeProtocolException;
use Rbbsoft\ArcaSdk\Support\RetryPolicy;
use Rbbsoft\ArcaSdk\Wsfe\Comprobante;
use Rbbsoft\ArcaSdk\Wsfe\SoapClientFactory;
use Rbbsoft\ArcaSdk\Wsfe\TiposComprobante;
use Rbbsoft\ArcaSdk\Wsfe\WsfeClient;
use SoapFault;

/**
 * Cubre la fachada WSFE: dummy / ultimoAutorizado / solicitar /
 * consultar, integracion con WsaaClient y politica de retry.
 */
final class WsfeClientTest extends TestCase
{
    private const CUIT = '20123456786';

    /**
     * Config minimo valido en homo.
     *
     * @param array<string, mixed> $overrides
     */
    private function makeConfig(array $overrides = []): Config
    {
        $base = [
            'cuit'                 => self::CUIT,
            'punto_venta'          => 1,
            'cert_path'            => 'C:\xampp\htdocs\Certificados\MiCertificado.pem',
            'key_path'             => 'C:\xampp\htdocs\Certificados\MiClavePrivada.key',
            'env'                  => 'homo',
            'db_dsn'               => 'mysql:host=localhost;dbname=arca_facturador_test;charset=utf8mb4',
            'db_user'              => 'root',
            'db_pass'              => '',
            'soap_timeout'         => 10,
            'wsaa_lock_timeout'    => 10,
            'emit_lock_timeout'    => 10,
            'wsaa_tra_ttl'         => 600,
            'wsaa_generation_skew' => 120,
            'wsaa_expiry_margin'   => 300,
            'retry_max_attempts'   => 3,
            'retry_base_backoff_ms' => 1,
            'retry_max_backoff_ms'  => 5,
            'idempotencia_max_intentos' => 5,
            'idempotencia_ttl_segundos' => 300,
        ];
        return Config::fromArray(array_merge($base, $overrides));
    }

    private function makeComprobante(array $overrides = []): Comprobante
    {
        $data = array_merge([
            'concepto'    => 1,
            'receptor_documento_tipo' => 80,
            'receptor_documento_nro'  => '20999999999',
            'receptor_condicion_iva'  => 'RI',
            'items' => [
                ['importe_gravado' => '100.00', 'alicuota_iva' => '21'],
            ],
        ], $overrides);
        return Comprobante::fromArray($data, defaultPuntoVenta: 1, cbteTipo: TiposComprobante::FACTURA_B);
    }

    /**
     * Construye el WsfeClient con un SoapClientDouble y un
     * WsaaClientDouble. Devuelve la terna para que el test inspeccione.
     *
     * @return array{0: WsfeClient, 1: SoapClientDouble, 2: WsaaClientDouble}
     */
    private function makeClient(Config $config, ?RetryPolicy $policy = null): array
    {
        $wsaa = new WsaaClientDouble();
        $soap = new SoapClientDouble();
        $client = new WsfeClient($config, $wsaa->asTokenProvider(), null, $policy ?? new RetryPolicy(), $soap);
        return [$client, $soap, $wsaa];
    }

    private function envelope(string $bodyXml): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<SOAP-ENV:Header/>'
            . '<SOAP-ENV:Body>'
            . $bodyXml
            . '</SOAP-ENV:Body>'
            . '</SOAP-ENV:Envelope>';
    }

    /**
     * SoapClient en non-WSDL mode envuelve el body en
     * <param0 xsi:type="xsd:string"> con el contenido HTML-encodado.
     * Este helper extrae ese contenido decodificado para inspeccion.
     */
    private function decodeBody(string $requestXml): string
    {
        if (preg_match('#<param0[^>]*>(.*?)</param0>#s', $requestXml, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
        return $requestXml;
    }

    private function feSolicitarAprobado(string $cbteFch, int $cbteNro, string $cae, string $caeFchVto): string
    {
        $body = '<FECAESolicitarResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FECAESolicitarResult>'
            . '<FeCabResp><CantReg>1</CantReg><Resultado>A</Resultado></FeCabResp>'
            . '<FeDetResp>'
            . '<FECAEDetResponse>'
            . '<CbteDesde>' . $cbteNro . '</CbteDesde><CbteHasta>' . $cbteNro . '</CbteHasta>'
            . '<CbteFch>' . $cbteFch . '</CbteFch>'
            . '<Resultado>A</Resultado>'
            . '<CAE>' . $cae . '</CAE><CAEFchVto>' . $caeFchVto . '</CAEFchVto>'
            . '</FECAEDetResponse>'
            . '</FeDetResp>'
            . '</FECAESolicitarResult>'
            . '</FECAESolicitarResponse>';
        return $this->envelope($body);
    }

    private function feSolicitarRechazado(int $cbteNro, array $observaciones): string
    {
        $obs = '';
        foreach ($observaciones as $o) {
            $obs .= '<Obs><Code>' . $o['codigo'] . '</Code><Msg>' . htmlspecialchars($o['mensaje'], ENT_XML1) . '</Msg></Obs>';
        }
        $body = '<FECAESolicitarResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FECAESolicitarResult>'
            . '<FeCabResp><CantReg>1</CantReg><Resultado>R</Resultado></FeCabResp>'
            . '<FeDetResp>'
            . '<FECAEDetResponse>'
            . '<CbteDesde>' . $cbteNro . '</CbteDesde><CbteHasta>' . $cbteNro . '</CbteHasta>'
            . '<Resultado>R</Resultado>'
            . '<Observaciones>' . $obs . '</Observaciones>'
            . '</FECAEDetResponse>'
            . '</FeDetResp>'
            . '</FECAESolicitarResult>'
            . '</FECAESolicitarResponse>';
        return $this->envelope($body);
    }

    // -------------------------------------------------------------------
    // dummy()
    // -------------------------------------------------------------------

    public function test_dummy_ok_ok(): void
    {
        $body = '<FEDummyResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FEDummyResult><AppServer>OK</AppServer><DbServer>OK</DbServer></FEDummyResult>'
            . '</FEDummyResponse>';
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->envelope($body));

        $d = $client->dummy();
        $this->assertTrue($d->isFullyOk());
        $this->assertSame('OK', $d->appServer);
        $this->assertSame('OK', $d->dbServer);
        $this->assertSame(1, $soap->callCount);
    }

    public function test_dummy_error_app_server(): void
    {
        $body = '<FEDummyResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FEDummyResult><AppServer>ERROR</AppServer><DbServer>OK</DbServer></FEDummyResult>'
            . '</FEDummyResponse>';
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->envelope($body));

        $d = $client->dummy();
        $this->assertSame('ERROR', $d->appServer);
        $this->assertFalse($d->isAppServerOk());
        $this->assertTrue($d->isDbServerOk());
    }

    public function test_dummy_error_db_server(): void
    {
        $body = '<FEDummyResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FEDummyResult><AppServer>OK</AppServer><DbServer>ERROR</DbServer></FEDummyResult>'
            . '</FEDummyResponse>';
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->envelope($body));

        $d = $client->dummy();
        $this->assertSame('ERROR', $d->dbServer);
        $this->assertFalse($d->isDbServerOk());
    }

    public function test_dummy_unknown_unknown(): void
    {
        $body = '<FEDummyResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FEDummyResult><AppServer>UNKNOWN</AppServer><DbServer>UNKNOWN</DbServer></FEDummyResult>'
            . '</FEDummyResponse>';
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->envelope($body));

        $d = $client->dummy();
        $this->assertSame('UNKNOWN', $d->appServer);
        $this->assertSame('UNKNOWN', $d->dbServer);
        $this->assertFalse($d->isFullyOk());
    }

    public function test_dummy_llama_a_wsaa_y_pone_token_en_request(): void
    {
        $body = '<FEDummyResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FEDummyResult><AppServer>OK</AppServer><DbServer>OK</DbServer></FEDummyResult>'
            . '</FEDummyResponse>';
        $config = $this->makeConfig();
        [$client, $soap, $wsaa] = $this->makeClient($config);
        $soap->enqueueResponse($this->envelope($body));

        $client->dummy();
        $this->assertSame(1, $wsaa->callCount, 'se pidio un TA al WsaaClient');
        $this->assertSame(['wsfe'], $wsaa->wsnRequests, 'WSN pedido fue wsfe');
        $req = $this->decodeBody($soap->lastRequest());
        $this->assertStringContainsString('TKN_TEST', $req);
        $this->assertStringContainsString('SGN_TEST', $req);
        $this->assertStringContainsString('<Cuit>' . self::CUIT . '</Cuit>', $req);
    }

    // -------------------------------------------------------------------
    // ultimoAutorizado()
    // -------------------------------------------------------------------

    public function test_ultimo_autorizado_devuelve_nro(): void
    {
        $body = '<FECompUltimoAutorizadoResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FECompUltimoAutorizadoResult><PtoVta>1</PtoVta><CbteTipo>6</CbteTipo><nro>99</nro></FECompUltimoAutorizadoResult>'
            . '</FECompUltimoAutorizadoResponse>';
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->envelope($body));

        $nro = $client->ultimoAutorizado(1, 6);
        $this->assertSame(99, $nro);
    }

    public function test_ultimo_autorizado_pv_y_tipo_en_request(): void
    {
        $body = '<FECompUltimoAutorizadoResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FECompUltimoAutorizadoResult><PtoVta>2</PtoVta><CbteTipo>11</CbteTipo><nro>5</nro></FECompUltimoAutorizadoResult>'
            . '</FECompUltimoAutorizadoResponse>';
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->envelope($body));

        $client->ultimoAutorizado(2, 11);
        $req = $this->decodeBody($soap->lastRequest());
        $this->assertStringContainsString('<PtoVta>2</PtoVta>', $req);
        $this->assertStringContainsString('<CbteTipo>11</CbteTipo>', $req);
    }

    public function test_ultimo_autorizado_sin_nro_es_protocol_error(): void
    {
        $body = '<FECompUltimoAutorizadoResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FECompUltimoAutorizadoResult><PtoVta>1</PtoVta><CbteTipo>6</CbteTipo></FECompUltimoAutorizadoResult>'
            . '</FECompUltimoAutorizadoResponse>';
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->envelope($body));

        $this->expectException(WsfeProtocolException::class);
        $client->ultimoAutorizado(1, 6);
    }

    public function test_ultimo_autorizado_error_arca_lanza_wsfexception(): void
    {
        $body = '<FECompUltimoAutorizadoResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FECompUltimoAutorizadoResult>'
            . '<Errors><Err><Code>12000</Code><Msg>PtoVta invalido</Msg></Err></Errors>'
            . '</FECompUltimoAutorizadoResult>'
            . '</FECompUltimoAutorizadoResponse>';
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->envelope($body));

        $this->expectException(WsfeException::class);
        $client->ultimoAutorizado(1, 6);
    }

    public function test_ultimo_autorizado_evento_9999_lanza_arca_transient(): void
    {
        $body = '<FECompUltimoAutorizadoResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FECompUltimoAutorizadoResult>'
            . '<Events><Evt><Code>9999</Code><Msg>reintente</Msg></Evt></Events>'
            . '</FECompUltimoAutorizadoResult>'
            . '</FECompUltimoAutorizadoResponse>';
        $config = $this->makeConfig(['retry_max_attempts' => 1]);
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->envelope($body));

        $this->expectException(WsfeArcaTransientException::class);
        $client->ultimoAutorizado(1, 6);
    }

    // -------------------------------------------------------------------
    // solicitar()
    // -------------------------------------------------------------------

    public function test_solicitar_aprobado(): void
    {
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->feSolicitarAprobado('20260101', 1, '74001234567890', '20260210'));

        $r = $client->solicitar($this->makeComprobante(), 1, '20260101');
        $this->assertTrue($r->isAprobado());
        $this->assertFalse($r->isRechazado());
        $this->assertSame('74001234567890', $r->cae);
        $this->assertSame('20260210', $r->caeFchVto);
        $this->assertSame(1, $r->cbteNro);
        $this->assertSame([], $r->observaciones);
    }

    public function test_solicitar_rechazado_no_lanza_excepcion(): void
    {
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->feSolicitarRechazado(1, [
            ['codigo' => 10016, 'mensaje' => 'balsa de prueba'],
            ['codigo' => 10017, 'mensaje' => 'otro error'],
        ]));

        $r = $client->solicitar($this->makeComprobante(), 1, '20260101');
        $this->assertTrue($r->isRechazado());
        $this->assertSame(1, $r->cbteNro);
        $this->assertCount(2, $r->observaciones);
        $this->assertSame(10016, $r->observaciones[0]['codigo']);
        $this->assertSame(10017, $r->observaciones[1]['codigo']);
        // NO se relanzo CbteRechazadoException; el orquestador decide.
        $this->assertNull($r->cae);
    }

    public function test_solicitar_rechazado_no_retry(): void
    {
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->feSolicitarRechazado(1, [
            ['codigo' => 10016, 'mensaje' => 'x'],
        ]));

        $client->solicitar($this->makeComprobante(), 1, '20260101');
        $this->assertSame(1, $soap->callCount, 'rechazo funcional no dispara retry');
    }

    public function test_solicitar_aprobado_sin_cae_lanza_protocol_error(): void
    {
        $body = '<FECAESolicitarResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FECAESolicitarResult>'
            . '<FeCabResp><CantReg>1</CantReg><Resultado>A</Resultado></FeCabResp>'
            . '<FeDetResp><FECAEDetResponse>'
            . '<CbteDesde>1</CbteDesde><CbteHasta>1</CbteHasta>'
            . '<Resultado>A</Resultado>'   // sin CAE / CAEFchVto
            . '</FECAEDetResponse></FeDetResp>'
            . '</FECAESolicitarResult></FECAESolicitarResponse>';

        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->envelope($body));

        $this->expectException(WsfeProtocolException::class);
        $client->solicitar($this->makeComprobante(), 1, '20260101');
    }

    public function test_solicitar_observacion_9999_lanza_arca_transient(): void
    {
        $config = $this->makeConfig(['retry_max_attempts' => 1]);
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->feSolicitarRechazado(1, [
            ['codigo' => 9999, 'mensaje' => 'reintente'],
        ]));

        $this->expectException(WsfeArcaTransientException::class);
        $client->solicitar($this->makeComprobante(), 1, '20260101');
    }

    public function test_solicitar_response_sin_fedetresp_es_protocol_error(): void
    {
        $body = '<FECAESolicitarResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FECAESolicitarResult>'
            . '<FeCabResp><CantReg>1</CantReg><Resultado>A</Resultado></FeCabResp>'
            . '</FECAESolicitarResult></FECAESolicitarResponse>';

        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->envelope($body));

        $this->expectException(WsfeProtocolException::class);
        $client->solicitar($this->makeComprobante(), 1, '20260101');
    }

    // -------------------------------------------------------------------
    // consultar()
    // -------------------------------------------------------------------

    public function test_consultar_no_existe_devuelve_null(): void
    {
        $body = '<FECompConsultarResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FECompConsultarResult>'
            . '<Errors><Err><Code>601</Code><Msg>No existe el comprobante</Msg></Err></Errors>'
            . '</FECompConsultarResult>'
            . '</FECompConsultarResponse>';
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->envelope($body));

        $c = $client->consultar(1, 6, 999);
        $this->assertNull($c);
    }

    public function test_consultar_existente_devuelve_value_object(): void
    {
        // Wire format real de ARCA (homo y prod): los datos viven en
        // <ResultGet> con <CodAutorizacion> y <FchVto> (no <CAE> y
        // <CAEFchVto> como usaba el mock legacy). <Resultado> adentro
        // de <ResultGet> es solo el estado ('A' / 'R').
        $body = '<FECompConsultarResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FECompConsultarResult>'
            . '<ResultGet>'
            . '<Concepto>1</Concepto><DocTipo>80</DocTipo><DocNro>20999999999</DocNro>'
            . '<CbteDesde>1</CbteDesde><CbteHasta>1</CbteHasta><CbteFch>20260101</CbteFch>'
            . '<Resultado>A</Resultado><CodAutorizacion>74001234567890</CodAutorizacion><FchVto>20260210</FchVto>'
            . '<ImpTotal>121.00</ImpTotal><ImpTotConc>0.00</ImpTotConc><ImpNeto>100.00</ImpNeto>'
            . '<ImpOpEx>0.00</ImpOpEx><ImpTrib>0.00</ImpTrib><ImpIVA>21.00</ImpIVA>'
            . '<MonId>PES</MonId><MonCotiz>1.0000</MonCotiz>'
            . '<Iva><AlicIva><Id>5</Id><BaseImp>100.00</BaseImp><Importe>21.00</Importe></AlicIva></Iva>'
            . '</ResultGet>'
            . '</FECompConsultarResult>'
            . '</FECompConsultarResponse>';
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->envelope($body));

        $c = $client->consultar(1, 6, 1);
        $this->assertNotNull($c);
        $this->assertSame(1, $c->cbteNro);
        $this->assertSame(1, $c->puntoVenta);
        $this->assertSame(6, $c->cbteTipo);
        $this->assertSame('20260101', $c->cbteFch);
        $this->assertSame('74001234567890', $c->cae);
        $this->assertSame('20260210', $c->caeFchVto);
        $this->assertSame('121.00', $c->impTotal);
        $this->assertSame('100.00', $c->impNeto);
        $this->assertSame('21.00', $c->impIva);
        $this->assertCount(1, $c->alicIva);
        $this->assertSame('5', $c->alicIva[0]['Id']);
        $this->assertSame('100.00', $c->alicIva[0]['BaseImp']);
        $this->assertSame('21.00', $c->alicIva[0]['Importe']);
    }

    public function test_consultar_evento_9999_lanza_arca_transient(): void
    {
        $body = '<FECompConsultarResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FECompConsultarResult>'
            . '<Events><Evt><Code>9999</Code><Msg>transitorio</Msg></Evt></Events>'
            . '</FECompConsultarResult>'
            . '</FECompConsultarResponse>';
        $config = $this->makeConfig(['retry_max_attempts' => 1]);
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->envelope($body));

        $this->expectException(WsfeArcaTransientException::class);
        $client->consultar(1, 6, 1);
    }

    public function test_consultar_estructura_vacia_devuelve_null(): void
    {
        $body = '<FECompConsultarResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FECompConsultarResult><Resultado/></FECompConsultarResult>'
            . '</FECompConsultarResponse>';
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->envelope($body));

        $c = $client->consultar(1, 6, 1);
        $this->assertNull($c);
    }

    public function test_consultar_response_malformada_es_protocol_error(): void
    {
        $config = $this->makeConfig(['retry_max_attempts' => 1]);
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse('<html>502 Bad Gateway</html>');

        $this->expectException(WsfeProtocolException::class);
        $this->expectExceptionMessage('HTML');
        $client->consultar(1, 6, 1);
    }

    // -------------------------------------------------------------------
    // Retry integration
    // -------------------------------------------------------------------

    public function test_retry_transitorio_soapfault_luego_aprobado(): void
    {
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        // 1ra: SoapFault transitorio
        $soap->enqueueFault(new SoapFault('HTTP', 'Could not connect to host'));
        // 2da: aprobado
        $soap->enqueueResponse($this->feSolicitarAprobado('20260101', 1, '74001234567890', '20260210'));

        $r = $client->solicitar($this->makeComprobante(), 1, '20260101');
        $this->assertTrue($r->isAprobado());
        $this->assertSame(2, $soap->callCount, 'segunda llamada tras retry');
    }

    public function test_no_retry_en_rechazo_funcional(): void
    {
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->feSolicitarRechazado(1, [
            ['codigo' => 10016, 'mensaje' => 'x'],
        ]));

        $r = $client->solicitar($this->makeComprobante(), 1, '20260101');
        $this->assertTrue($r->isRechazado());
        $this->assertSame(1, $soap->callCount, 'rechazo funcional no se reintenta');
    }

    public function test_no_retry_en_observacion_no_transitoria(): void
    {
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->feSolicitarRechazado(1, [
            ['codigo' => 10016, 'mensaje' => 'balsa'],
        ]));

        $r = $client->solicitar($this->makeComprobante(), 1, '20260101');
        $this->assertSame(1, $soap->callCount);
    }

    public function test_observacion_9999_es_transitoria_y_retry(): void
    {
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        // 1ra: 9999
        $soap->enqueueResponse($this->feSolicitarRechazado(1, [
            ['codigo' => 9999, 'mensaje' => 'reintente'],
        ]));
        // 2da: aprobado
        $soap->enqueueResponse($this->feSolicitarAprobado('20260101', 1, '74001234567890', '20260210'));

        $r = $client->solicitar($this->makeComprobante(), 1, '20260101');
        $this->assertTrue($r->isAprobado());
        $this->assertSame(2, $soap->callCount, '9999 dispara retry, segunda llamada aprueba');
    }

    public function test_protocol_empty_body_es_transitorio_y_retry(): void
    {
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse('');  // body vacio -> WsfeProtocolException kind=empty_body
        $soap->enqueueResponse($this->feSolicitarAprobado('20260101', 1, '74001234567890', '20260210'));

        $r = $client->solicitar($this->makeComprobante(), 1, '20260101');
        $this->assertTrue($r->isAprobado());
        $this->assertSame(2, $soap->callCount);
    }

    public function test_protocol_html_gateway_es_transitorio_y_retry(): void
    {
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse('<!DOCTYPE html><html><body>502 Bad Gateway</body></html>');
        $soap->enqueueResponse($this->feSolicitarAprobado('20260101', 1, '74001234567890', '20260210'));

        $r = $client->solicitar($this->makeComprobante(), 1, '20260101');
        $this->assertTrue($r->isAprobado());
        $this->assertSame(2, $soap->callCount);
    }

    public function test_protocol_structural_no_retry(): void
    {
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        // Respuesta con estructura SOAP pero sin FeCabResp -> WsfeProtocolException kind=structural
        $body = '<FECAESolicitarResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FECAESolicitarResult><AlgoRaro/></FECAESolicitarResult>'
            . '</FECAESolicitarResponse>';
        $soap->enqueueResponse($this->envelope($body));

        $this->expectException(WsfeProtocolException::class);
        $client->solicitar($this->makeComprobante(), 1, '20260101');
        $this->assertSame(1, $soap->callCount, 'structural no retry');
    }

    public function test_agota_max_attempts_y_relanza(): void
    {
        $config = $this->makeConfig(['retry_max_attempts' => 3]);
        [$client, $soap] = $this->makeClient($config);
        for ($i = 0; $i < 3; $i++) {
            $soap->enqueueFault(new SoapFault('HTTP', 'Connection refused'));
        }
        try {
            $client->solicitar($this->makeComprobante(), 1, '20260101');
            $this->fail('Debio relanzar SoapFault');
        } catch (WsfeException $e) {
            // El WsfeClient traduce SoapFault 'HTTP' a WsfeException.
            $this->assertSame(3, $soap->callCount);
        }
    }

    // -------------------------------------------------------------------
    // WSA integration
    // -------------------------------------------------------------------

    public function test_wsa_token_se_pide_en_cada_llamada(): void
    {
        $config = $this->makeConfig();
        [$client, $soap, $wsaa] = $this->makeClient($config);
        $bodyDummy = '<FEDummyResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FEDummyResult><AppServer>OK</AppServer><DbServer>OK</DbServer></FEDummyResult>'
            . '</FEDummyResponse>';
        $bodyUlt = '<FECompUltimoAutorizadoResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FECompUltimoAutorizadoResult><PtoVta>1</PtoVta><CbteTipo>6</CbteTipo><nro>0</nro></FECompUltimoAutorizadoResult>'
            . '</FECompUltimoAutorizadoResponse>';

        $soap->enqueueResponse($this->envelope($bodyDummy));
        $soap->enqueueResponse($this->envelope($bodyUlt));
        $client->dummy();
        $client->ultimoAutorizado(1, 6);

        $this->assertSame(2, $wsaa->callCount);
        $this->assertSame(['wsfe', 'wsfe'], $wsaa->wsnRequests);
    }

    public function test_wsa_token_aparece_en_bloque_auth_de_cada_request(): void
    {
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $bodyDummy = '<FEDummyResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FEDummyResult><AppServer>OK</AppServer><DbServer>OK</DbServer></FEDummyResult>'
            . '</FEDummyResponse>';
        $bodySolicitar = $this->feSolicitarAprobado('20260101', 1, 'X', '20260210');

        $soap->enqueueResponse($this->envelope($bodyDummy));
        $soap->enqueueResponse($bodySolicitar);
        $client->dummy();
        $client->solicitar($this->makeComprobante(), 1, '20260101');

        $this->assertStringContainsString('<Token>TKN_TEST</Token>', $this->decodeBody($soap->requestBodies[0]));
        $this->assertStringContainsString('<Sign>SGN_TEST</Sign>', $this->decodeBody($soap->requestBodies[0]));
        $this->assertStringContainsString('<Cuit>' . self::CUIT . '</Cuit>', $this->decodeBody($soap->requestBodies[0]));
        $this->assertStringContainsString('<Token>TKN_TEST</Token>', $this->decodeBody($soap->requestBodies[1]));
        $this->assertStringContainsString('<Sign>SGN_TEST</Sign>', $this->decodeBody($soap->requestBodies[1]));
    }

    // -------------------------------------------------------------------
    // WSDL cache config
    // -------------------------------------------------------------------

    public function test_wsdl_cache_homo_desactivado(): void
    {
        $config = $this->makeConfig(['env' => 'homo']);
        $factory = new SoapClientFactory($config);
        $opts = $factory->optionsForWsfe();
        $this->assertSame(WSDL_CACHE_NONE, $opts['cache_wsdl']);
    }

    public function test_wsdl_cache_prod_habilitado(): void
    {
        $config = $this->makeConfig(['env' => 'prod']);
        $factory = new SoapClientFactory($config);
        $opts = $factory->optionsForWsfe();
        $this->assertNotSame(WSDL_CACHE_NONE, $opts['cache_wsdl']);
        $this->assertContains(
            $opts['cache_wsdl'],
            [WSDL_CACHE_DISK, WSDL_CACHE_MEMORY, WSDL_CACHE_BOTH],
            'en prod se permite cache (disk/memory/both)'
        );
    }

    public function test_wsdl_cache_factory_incluye_soap_version_y_timeout(): void
    {
        $config = $this->makeConfig(['soap_timeout' => 42]);
        $factory = new SoapClientFactory($config);
        $opts = $factory->optionsForWsfe();
        $this->assertSame(SOAP_1_1, $opts['soap_version']);
        $this->assertSame(42, $opts['connection_timeout']);
    }

    // -------------------------------------------------------------------
    // FECAESolicitar payload mapping (the most important test)
    // -------------------------------------------------------------------

    public function test_payload_solicitar_b_incluye_campos_completos(): void
    {
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->feSolicitarAprobado('20260101', 1, 'X', '20260210'));

        $c = $this->makeComprobante([
            'mon_id'    => 'PES',
            'mon_cotiz' => '1.0000',
        ]);
        $client->solicitar($c, 1, '20260101');
        $body = $this->decodeBody($soap->lastRequest());

        // Cabecera
        $this->assertStringContainsString('<CantReg>1</CantReg>', $body);
        $this->assertStringContainsString('<PtoVta>1</PtoVta>', $body);
        $this->assertStringContainsString('<CbteTipo>6</CbteTipo>', $body);

        // Auth
        $this->assertStringContainsString('<Token>TKN_TEST</Token>', $body);
        $this->assertStringContainsString('<Sign>SGN_TEST</Sign>', $body);
        $this->assertStringContainsString('<Cuit>' . self::CUIT . '</Cuit>', $body);

        // Detalle
        $this->assertStringContainsString('<Concepto>1</Concepto>', $body);
        $this->assertStringContainsString('<DocTipo>80</DocTipo>', $body);
        $this->assertStringContainsString('<DocNro>20999999999</DocNro>', $body);
        $this->assertStringContainsString('<CbteDesde>1</CbteDesde>', $body);
        $this->assertStringContainsString('<CbteHasta>1</CbteHasta>', $body);
        $this->assertStringContainsString('<CbteFch>20260101</CbteFch>', $body);
        $this->assertStringContainsString('<ImpTotal>121.00</ImpTotal>', $body);
        $this->assertStringContainsString('<ImpTotConc>0.00</ImpTotConc>', $body);
        $this->assertStringContainsString('<ImpNeto>100.00</ImpNeto>', $body);
        $this->assertStringContainsString('<ImpOpEx>0.00</ImpOpEx>', $body);
        $this->assertStringContainsString('<ImpTrib>0.00</ImpTrib>', $body);
        $this->assertStringContainsString('<ImpIVA>21.00</ImpIVA>', $body);
        $this->assertStringContainsString('<MonId>PES</MonId>', $body);
        $this->assertStringContainsString('<MonCotiz>1.0000</MonCotiz>', $body);

        // AlicIva (codigo 5 = 21%)
        $this->assertStringContainsString('<Iva>', $body);
        $this->assertStringContainsString('<AlicIva>', $body);
        $this->assertStringContainsString('<Id>5</Id>', $body);
        $this->assertStringContainsString('<BaseImp>100.00</BaseImp>', $body);
        $this->assertStringContainsString('<Importe>21.00</Importe>', $body);

        // IvaReceptor (RI=1) -> bajo RG 5616 el nombre es CondicionIVAReceptorId
        $this->assertStringContainsString('<CondicionIVAReceptorId>1</CondicionIVAReceptorId>', $body);
    }

    public function test_payload_solicitar_c_sin_aliciva(): void
    {
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->feSolicitarAprobado('20260101', 1, 'X', '20260210'));

        $c = Comprobante::fromArray([
            'concepto' => 1,
            'receptor_documento_tipo' => 96,
            'receptor_documento_nro' => '12345678',
            'receptor_condicion_iva' => 'CF',
            'items' => [['importe_gravado' => '100.00', 'alicuota_iva' => '21']],
        ], defaultPuntoVenta: 1, cbteTipo: TiposComprobante::FACTURA_C);
        $client->solicitar($c, 1, '20260101');
        $body = $this->decodeBody($soap->lastRequest());

        // Factura C: no tiene AlicIva (ImpIVA=0, sin discriminacion).
        $this->assertStringContainsString('<ImpNeto>100.00</ImpNeto>', $body);
        $this->assertStringContainsString('<ImpIVA>0.00</ImpIVA>', $body);
        $this->assertStringContainsString('<ImpTotal>100.00</ImpTotal>', $body);
        $this->assertStringNotContainsString('<AlicIva>', $body);
        $this->assertStringContainsString('<CbteTipo>11</CbteTipo>', $body);
        // CF=5
        $this->assertStringContainsString('<CondicionIVAReceptorId>5</CondicionIVAReceptorId>', $body);
    }

    public function test_payload_solicitar_m_incluye_bloque_iva_alicuota_cero_cuando_hay_gravado(): void
    {
        // Bug 8: Factura M (51) bajo RG 5616 exige el bloque <Iva> con
        // un AlicIva de Id=3 (iva 0%) cuando hay gravado. ARCA rechaza
        // con 10070 si falta y 10018 si el Id no es 3. La C no lo
        // exige, por eso el comportamiento diverge entre tipos.
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->feSolicitarAprobado('20260101', 1, 'X', '20260210'));

        $c = Comprobante::fromArray([
            'concepto' => 1,
            'receptor_documento_tipo' => 80,
            'receptor_documento_nro' => self::CUIT,
            'receptor_condicion_iva' => 'MT',
            'items' => [['importe_gravado' => '100.00', 'alicuota_iva' => '21']],
        ], defaultPuntoVenta: 1, cbteTipo: TiposComprobante::FACTURA_M);
        $client->solicitar($c, 1, '20260101');
        $body = $this->decodeBody($soap->lastRequest());

        $this->assertStringContainsString('<CbteTipo>51</CbteTipo>', $body);
        $this->assertStringContainsString('<ImpNeto>100.00</ImpNeto>', $body);
        $this->assertStringContainsString('<ImpIVA>0.00</ImpIVA>', $body);
        $this->assertStringContainsString('<ImpTotal>100.00</ImpTotal>', $body);
        // M exige bloque <Iva> explicito con AlicIva Id=3 (iva 0%):
        $this->assertStringContainsString('<Iva><AlicIva>', $body);
        $this->assertStringContainsString('<Id>3</Id>', $body);
        $this->assertStringContainsString('<BaseImp>100.00</BaseImp>', $body);
        $this->assertStringContainsString('<Importe>0.00</Importe>', $body);
        $this->assertStringContainsString('</AlicIva></Iva>', $body);
        // MT=6 bajo RG 5616.
        $this->assertStringContainsString('<CondicionIVAReceptorId>6</CondicionIVAReceptorId>', $body);
    }

    public function test_payload_solicitar_m_sin_gravado_omite_bloque_iva(): void
    {
        // Sin gravado (todos exentos / no gravados) ARCA no exige el
        // bloque <Iva> y emitirlo igual podria confundir. El SDK no
        // debe enviarlo en ese caso.
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->feSolicitarAprobado('20260101', 1, 'X', '20260210'));

        $c = Comprobante::fromArray([
            'concepto' => 1,
            'receptor_documento_tipo' => 80,
            'receptor_documento_nro' => self::CUIT,
            'receptor_condicion_iva' => 'MT',
            'importe_exento'         => '100.00',
            // items no puede ir vacio (validacion del Comprobante), pero
            // con gravado=0 M trata al comprobante como sin gravado.
            'items' => [['importe_gravado' => '0.00', 'alicuota_iva' => '21']],
        ], defaultPuntoVenta: 1, cbteTipo: TiposComprobante::FACTURA_M);
        $client->solicitar($c, 1, '20260101');
        $body = $this->decodeBody($soap->lastRequest());

        $this->assertStringContainsString('<ImpOpEx>100.00</ImpOpEx>', $body);
        $this->assertStringContainsString('<ImpTotal>100.00</ImpTotal>', $body);
        $this->assertStringNotContainsString('<AlicIva>', $body);
    }

    public function test_payload_solicitar_nc_m_incluye_bloque_iva_y_cbtes_asoc(): void
    {
        // NC M (53) comparte con M la exigencia del bloque <Iva> bajo
        // RG 5616 cuando hay gravado. Ademas requiere cbtes_asoc que
        // apunte a la M original.
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->feSolicitarAprobado('20260101', 1, 'X', '20260210'));

        $c = Comprobante::fromArray([
            'concepto' => 1,
            'receptor_documento_tipo' => 80,
            'receptor_documento_nro' => self::CUIT,
            'receptor_condicion_iva' => 'MT',
            'cbtes_asoc' => [
                ['tipo' => TiposComprobante::FACTURA_M, 'punto_venta' => 1, 'nro' => 1],
            ],
            'items' => [['importe_gravado' => '100.00', 'alicuota_iva' => '21']],
        ], defaultPuntoVenta: 1, cbteTipo: TiposComprobante::NOTA_CREDITO_M);
        $client->solicitar($c, 1, '20260101');
        $body = $this->decodeBody($soap->lastRequest());

        $this->assertStringContainsString('<CbteTipo>53</CbteTipo>', $body);
        $this->assertStringContainsString('<Iva><AlicIva>', $body);
        $this->assertStringContainsString('<Id>3</Id>', $body);
        $this->assertStringContainsString('<BaseImp>100.00</BaseImp>', $body);
        $this->assertStringContainsString('<Importe>0.00</Importe>', $body);
        $this->assertStringContainsString('<CbtesAsoc>', $body);
        $this->assertStringContainsString('<Tipo>51</Tipo>', $body);
        $this->assertStringContainsString('<Nro>1</Nro>', $body);
    }

    public function test_payload_solicitar_alicuotas_mixtas(): void
    {
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->feSolicitarAprobado('20260101', 1, 'X', '20260210'));

        $c = Comprobante::fromArray([
            'concepto' => 1,
            'receptor_documento_tipo' => 80,
            'receptor_documento_nro' => self::CUIT,
            'receptor_condicion_iva' => 'RI',
            'items' => [
                ['importe_gravado' => '100.00', 'alicuota_iva' => '21'],
                ['importe_gravado' => '50.00',  'alicuota_iva' => '10.5'],
            ],
        ], defaultPuntoVenta: 1, cbteTipo: TiposComprobante::FACTURA_B);
        $client->solicitar($c, 1, '20260101');
        $body = $this->decodeBody($soap->lastRequest());

        // Total = 100 + 50 + 21 + 5.25 = 176.25
        $this->assertStringContainsString('<ImpNeto>150.00</ImpNeto>', $body);
        $this->assertStringContainsString('<ImpIVA>26.25</ImpIVA>', $body);
        $this->assertStringContainsString('<ImpTotal>176.25</ImpTotal>', $body);
        // AlicIva: 21% -> Id=5, 10.5% -> Id=4
        $this->assertStringContainsString('<Id>5</Id>', $body);
        $this->assertStringContainsString('<Id>4</Id>', $body);
        $this->assertStringContainsString('<Importe>21.00</Importe>', $body);
        $this->assertStringContainsString('<Importe>5.25</Importe>', $body);

        // Bug 1 fix: BaseImp por alicuota, NO el netoGravado completo.
        // ARCA exige sum(BaseImp) === ImpNeto (= 150.00). El bug
        // original emitia BaseImp=150.00 en CADA AlicIva (suma=300.00)
        // y la factura se rechazaba como "inconsistente".
        $this->assertStringContainsString(
            '<AlicIva><Id>5</Id><BaseImp>100.00</BaseImp><Importe>21.00</Importe></AlicIva>',
            $body,
            'AlicIva para 21% lleva BaseImp del gravado parcial (100.00), no el netoGravado completo (150.00)'
        );
        $this->assertStringContainsString(
            '<AlicIva><Id>4</Id><BaseImp>50.00</BaseImp><Importe>5.25</Importe></AlicIva>',
            $body,
            'AlicIva para 10.5% lleva BaseImp del gravado parcial (50.00)'
        );

        // Cierre AlicIva: extrae todos los BaseImp y confirma la suma.
        preg_match_all('#<AlicIva>.*?</AlicIva>#s', $body, $blocks);
        $sumaBases = '0.00';
        foreach ($blocks[0] as $block) {
            if (preg_match('#<BaseImp>([\d.]+)</BaseImp>#', $block, $m)) {
                $sumaBases = bcadd($sumaBases, $m[1], 2);
            }
        }
        $this->assertSame(0, bccomp($sumaBases, '150.00', 2),
            'sum(BaseImp) de AlicIva debe ser 150.00 (= ImpNeto)');
    }

    public function test_payload_solicitar_iva_receptor_por_condicion(): void
    {
        // Mapeo actualizado a la Resolucion General AFIP 5616.
        // Ver src/Wsfe/WsfeClient.php::IVA_RECEPTOR_ID.
        $mapeo = [
            'RI' => 1,
            'EX' => 4,
            'CF' => 5,
            'NC' => 7,
            'MT' => 6,
        ];
        foreach ($mapeo as $cond => $id) {
            $config = $this->makeConfig();
            [$client, $soap] = $this->makeClient($config);
            $soap->enqueueResponse($this->feSolicitarAprobado('20260101', 1, 'X', '20260210'));
            $c = Comprobante::fromArray([
                'concepto' => 1,
                'receptor_documento_tipo' => 80,
                'receptor_documento_nro' => self::CUIT,
                'receptor_condicion_iva' => $cond,
                'items' => [['importe_gravado' => '100.00', 'alicuota_iva' => '21']],
            ], defaultPuntoVenta: 1, cbteTipo: TiposComprobante::FACTURA_B);
            $client->solicitar($c, 1, '20260101');
            $body = $this->decodeBody($soap->lastRequest());
            $this->assertStringContainsString(
                "<CondicionIVAReceptorId>{$id}</CondicionIVAReceptorId>",
                $body,
                "condicion {$cond} debe mapear a CondicionIVAReceptorId={$id} (RG 5616)"
            );
        }
    }

    public function test_payload_solicitar_concepto_servicios_incluye_fechas_servicio(): void
    {
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->feSolicitarAprobado('20260101', 1, 'X', '20260210'));

        $c = Comprobante::fromArray([
            'concepto' => 2,
            'servicio_desde' => '20260101',
            'servicio_hasta' => '20260131',
            'vencimiento_pago' => '20260215',
            'receptor_documento_tipo' => 80,
            'receptor_documento_nro' => self::CUIT,
            'receptor_condicion_iva' => 'RI',
            'items' => [['importe_gravado' => '100.00', 'alicuota_iva' => '21']],
        ], defaultPuntoVenta: 1, cbteTipo: TiposComprobante::FACTURA_B);
        $client->solicitar($c, 1, '20260101');
        $body = $this->decodeBody($soap->lastRequest());

        $this->assertStringContainsString('<Concepto>2</Concepto>', $body);
        $this->assertStringContainsString('<FchServDesde>20260101</FchServDesde>', $body);
        $this->assertStringContainsString('<FchServHasta>20260131</FchServHasta>', $body);
        $this->assertStringContainsString('<FchVtoPago>20260215</FchVtoPago>', $body);
    }

    public function test_payload_solicitar_concepto_productos_sin_fechas_servicio(): void
    {
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->feSolicitarAprobado('20260101', 1, 'X', '20260210'));

        $c = $this->makeComprobante();
        $client->solicitar($c, 1, '20260101');
        $body = $this->decodeBody($soap->lastRequest());

        $this->assertStringContainsString('<Concepto>1</Concepto>', $body);
        $this->assertStringNotContainsString('<FchServDesde>', $body);
        $this->assertStringNotContainsString('<FchServHasta>', $body);
        $this->assertStringNotContainsString('<FchVtoPago>', $body);
    }

    public function test_payload_solicitar_nota_credito_incluye_cbtes_asoc(): void
    {
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->feSolicitarAprobado('20260101', 1, 'X', '20260210'));

        $c = Comprobante::fromArray([
            'concepto' => 1,
            'receptor_documento_tipo' => 80,
            'receptor_documento_nro' => self::CUIT,
            'receptor_condicion_iva' => 'RI',
            'items' => [['importe_gravado' => '100.00', 'alicuota_iva' => '21']],
            'cbtes_asoc' => [
                ['tipo' => TiposComprobante::FACTURA_B, 'punto_venta' => 1, 'nro' => 100],
                ['tipo' => TiposComprobante::FACTURA_B, 'punto_venta' => 2, 'nro' => 200],
            ],
        ], defaultPuntoVenta: 1, cbteTipo: TiposComprobante::NOTA_CREDITO_B);
        $client->solicitar($c, 1, '20260101');
        $body = $this->decodeBody($soap->lastRequest());

        $this->assertStringContainsString('<CbteTipo>8</CbteTipo>', $body);
        $this->assertStringContainsString('<CbtesAsoc>', $body);
        $this->assertStringContainsString('<Tipo>6</Tipo>', $body);
        $this->assertStringContainsString('<PtoVta>1</PtoVta>', $body);
        $this->assertStringContainsString('<Nro>100</Nro>', $body);
        $this->assertStringContainsString('<PtoVta>2</PtoVta>', $body);
        $this->assertStringContainsString('<Nro>200</Nro>', $body);
    }

    public function test_payload_solicitar_moneda_extranjera(): void
    {
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->feSolicitarAprobado('20260101', 1, 'X', '20260210'));

        $c = Comprobante::fromArray([
            'concepto' => 1,
            'receptor_documento_tipo' => 80,
            'receptor_documento_nro' => self::CUIT,
            'receptor_condicion_iva' => 'RI',
            'mon_id' => 'USD',
            'mon_cotiz' => '1234.5678',
            'items' => [['importe_gravado' => '100.00', 'alicuota_iva' => '21']],
        ], defaultPuntoVenta: 1, cbteTipo: TiposComprobante::FACTURA_B);
        $client->solicitar($c, 1, '20260101');
        $body = $this->decodeBody($soap->lastRequest());

        $this->assertStringContainsString('<MonId>USD</MonId>', $body);
        $this->assertStringContainsString('<MonCotiz>1234.5678</MonCotiz>', $body);
    }

    public function test_payload_solicitar_punto_venta_y_tipo_del_comprobante(): void
    {
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->feSolicitarAprobado('20260101', 7, 'X', '20260210'));

        $c = Comprobante::fromArray([
            'punto_venta' => 7,
            'concepto' => 1,
            'receptor_documento_tipo' => 80,
            'receptor_documento_nro' => self::CUIT,
            'receptor_condicion_iva' => 'RI',
            'items' => [['importe_gravado' => '100.00', 'alicuota_iva' => '21']],
        ], defaultPuntoVenta: 1, cbteTipo: TiposComprobante::FACTURA_B);
        $client->solicitar($c, 7, '20260101');
        $body = $this->decodeBody($soap->lastRequest());

        $this->assertStringContainsString('<PtoVta>7</PtoVta>', $body);
        $this->assertStringContainsString('<CbteDesde>7</CbteDesde>', $body);
        $this->assertStringContainsString('<CbteHasta>7</CbteHasta>', $body);
    }

    public function test_payload_solicitar_importe_no_gravado_y_exento(): void
    {
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->feSolicitarAprobado('20260101', 1, 'X', '20260210'));

        $c = Comprobante::fromArray([
            'concepto' => 1,
            'receptor_documento_tipo' => 80,
            'receptor_documento_nro' => self::CUIT,
            'receptor_condicion_iva' => 'RI',
            'importe_no_gravado' => '10.00',
            'importe_exento' => '20.00',
            'importe_otros_tributos' => '5.00',
            'items' => [['importe_gravado' => '100.00', 'alicuota_iva' => '21']],
        ], defaultPuntoVenta: 1, cbteTipo: TiposComprobante::FACTURA_B);
        $client->solicitar($c, 1, '20260101');
        $body = $this->decodeBody($soap->lastRequest());

        $this->assertStringContainsString('<ImpTotConc>10.00</ImpTotConc>', $body);
        $this->assertStringContainsString('<ImpOpEx>20.00</ImpOpEx>', $body);
        $this->assertStringContainsString('<ImpTrib>5.00</ImpTrib>', $body);
        // Total = 100 + 21 + 10 + 20 + 5 = 156.00
        $this->assertStringContainsString('<ImpTotal>156.00</ImpTotal>', $body);
    }

    public function test_payload_solicitar_cantreg_siempre_1(): void
    {
        // Por la API del WsfeClient, cada llamada procesa un unico comprobante.
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->feSolicitarAprobado('20260101', 1, 'X', '20260210'));
        $client->solicitar($this->makeComprobante(), 1, '20260101');
        $this->assertStringContainsString('<CantReg>1</CantReg>', $this->decodeBody($soap->lastRequest()));
    }

    public function test_payload_solicitar_no_gravado_negativo_en_no_factura_b_falla_en_validacion(): void
    {
        // El receptor Condicion IVA "NC" (No Responsable IVA bajo RG 5616)
        // tiene IvaReceptor=7 (bajo RG 5616 el nombre es CondicionIVAReceptorId). Ver src/Wsfe/WsfeClient.php::IVA_RECEPTOR_ID.
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse($this->feSolicitarAprobado('20260101', 1, 'X', '20260210'));
        $c = Comprobante::fromArray([
            'concepto' => 1,
            'receptor_documento_tipo' => 80,
            'receptor_documento_nro' => self::CUIT,
            'receptor_condicion_iva' => 'NC',
            'items' => [['importe_gravado' => '100.00', 'alicuota_iva' => '21']],
        ], defaultPuntoVenta: 1, cbteTipo: TiposComprobante::FACTURA_B);
        $client->solicitar($c, 1, '20260101');
        $this->assertStringContainsString('<CondicionIVAReceptorId>7</CondicionIVAReceptorId>', $this->decodeBody($soap->lastRequest()));
    }

    // -------------------------------------------------------------------
    // Edge cases
    // -------------------------------------------------------------------

    public function test_payload_consultar_incluye_pv_tipo_nro(): void
    {
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $body = '<FECompConsultarResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FECompConsultarResult>'
            . '<ResultGet>'
            . '<CbteDesde>100</CbteDesde><CbteHasta>100</CbteHasta>'
            . '<Resultado>A</Resultado><CodAutorizacion>1</CodAutorizacion><FchVto>20260210</FchVto>'
            . '</ResultGet>'
            . '</FECompConsultarResult></FECompConsultarResponse>';
        $soap->enqueueResponse($this->envelope($body));
        $client->consultar(2, 7, 100);
        $req = $this->decodeBody($soap->lastRequest());
        $this->assertStringContainsString('<PtoVta>2</PtoVta>', $req);
        $this->assertStringContainsString('<CbteTipo>7</CbteTipo>', $req);
        $this->assertStringContainsString('<CbteNro>100</CbteNro>', $req);
    }

    public function test_consultar_estructura_vacia_es_no_existe_no_retry(): void
    {
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $body = '<FECompConsultarResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FECompConsultarResult><Resultado/></FECompConsultarResult></FECompConsultarResponse>';
        $soap->enqueueResponse($this->envelope($body));
        $this->assertNull($client->consultar(1, 6, 1));
        $this->assertSame(1, $soap->callCount, 'no retry en no-existe');
    }

    public function test_constructor_con_wsaaclient_real_en_lugar_de_closure(): void
    {
        // El overload que toma WsaaClient directamente. Pre-sembramos
        // el cache con un TA vigente para que getToken() no intente
        // realmente llamar a WSAA (no queremos red en unit tests).
        $cache = new \Rbbsoft\ArcaSdk\Wsaa\NullTicketCache(
            expiryMarginSeconds: 0,
        );
        $cache->save(new \Rbbsoft\ArcaSdk\Wsaa\TicketDeAcceso(
            cuit: self::CUIT,
            wsn: 'wsfe',
            token: 'TKN_WSAA',
            sign: 'SGN_WSAA',
            expirationTimeUtc: new \DateTimeImmutable('2099-01-01T00:00:00+00:00'),
        ));
        $wsaa = new \Rbbsoft\ArcaSdk\Wsaa\WsaaClient(
            $this->makeConfig(),
            new SoapClientDouble(),
            $cache,
        );
        $soap = new SoapClientDouble();
        $body = '<FEDummyResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FEDummyResult><AppServer>OK</AppServer><DbServer>OK</DbServer></FEDummyResult>'
            . '</FEDummyResponse>';
        $soap->enqueueResponse($this->envelope($body));
        $client = new WsfeClient($this->makeConfig(), null, $wsaa, new RetryPolicy(), $soap);
        $d = $client->dummy();
        $this->assertTrue($d->isFullyOk());
        $this->assertStringContainsString('TKN_WSAA', $this->decodeBody($soap->lastRequest()));
    }

    public function test_constructor_sin_token_provider_ni_wsaaclient_lanza(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WsfeClient($this->makeConfig(), null, null, new RetryPolicy(), new SoapClientDouble());
    }

    // -------------------------------------------------------------------
    // Bug 2: classifySoapFault — diagnostic taxonomy for non-SOAP bodies.
    //
    // El bug original ordenaba los checks al reves: detectaba HTML
    // (pattern matching en el faultstring) ANTES que HTTP 5xx, y el
    // faultstring de SoapClient para un 502/503/504 NO contiene el
    // body, solo "looks like we got no XML document". Resultado: los
    // 5xx quedaban mal clasificados como html_gateway.
    //
    // La nueva implementacion accede al body crudo via
    // SoapClient::__getLastResponse() (overrideado en el test double
    // para devolver el body encolado) y decide en base a eso. Los
    // tests de esta seccion bloquean ese contrato.
    // -------------------------------------------------------------------

    public function test_classify_soapfault_con_body_http_5xx_es_kind_http_5xx(): void
    {
        // Caso tipico: el proxy reverso de ARCA devuelve la linea de
        // status HTTP/1.1 503 como primera linea del body. En
        // produccion SoapClient tira SoapFault y perdemos el body
        // salvo que lo recuperemos via __getLastResponse().
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $raw = "HTTP/1.1 503 Service Unavailable\r\nContent-Type: text/html\r\n\r\n<html>oops</html>";
        $soap->enqueueResponse($raw);

        try {
            $client->consultar(1, 6, 1);
            $this->fail('Debio lanzar WsfeProtocolException');
        } catch (WsfeProtocolException $e) {
            $this->assertSame(WsfeProtocolException::KIND_HTTP_5XX, $e->kind,
                '5xx en el body crudo debe clasificarse como http_5xx, no html_gateway');
        }
    }

    public function test_classify_soapfault_con_body_html_es_kind_html_gateway(): void
    {
        // HTML sin linea de status HTTP 5xx. SoapClient tira SoapFault
        // ("Wrong Version") y __getLastResponse() devuelve el body.
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $raw = '<!DOCTYPE html><html><body>502 Bad Gateway</body></html>';
        $soap->enqueueResponse($raw);

        try {
            $client->consultar(1, 6, 1);
            $this->fail('Debio lanzar WsfeProtocolException');
        } catch (WsfeProtocolException $e) {
            $this->assertSame(WsfeProtocolException::KIND_HTML_GATEWAY, $e->kind);
        }
    }

    public function test_classify_soapfault_con_body_whitespace_es_kind_empty_body(): void
    {
        // El bug original clasificaba whitespace como html_gateway
        // porque SoapClient tira "looks like we got no XML document"
        // y el viejo codigo hacia stripos en el faultstring.
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $soap->enqueueResponse("   \n  \t  ");

        try {
            $client->consultar(1, 6, 1);
            $this->fail('Debio lanzar WsfeProtocolException');
        } catch (WsfeProtocolException $e) {
            $this->assertSame(WsfeProtocolException::KIND_EMPTY_BODY, $e->kind,
                'body whitespace-only debe ser empty_body (transitorio), no html_gateway');
        }
    }

    public function test_classify_soapfault_con_envelope_body_vacio_es_kind_empty_body(): void
    {
        // SOAP envelope valido pero con <Body/> vacio: debe ser
        // empty_body (transitorio), NO html_gateway ni structural.
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $raw = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<SOAP-ENV:Header/>'
            . '<SOAP-ENV:Body/>'
            . '</SOAP-ENV:Envelope>';
        $soap->enqueueResponse($raw);

        try {
            $client->consultar(1, 6, 1);
            $this->fail('Debio lanzar WsfeProtocolException');
        } catch (WsfeProtocolException $e) {
            $this->assertSame(WsfeProtocolException::KIND_EMPTY_BODY, $e->kind,
                'SOAP-ENV:Body vacio debe ser empty_body');
        }
    }

    public function test_classify_soapfault_con_root_inesperado_es_kind_structural(): void
    {
        // SOAP envelope valido pero con un root que NO es operacion
        // FEV1: debe ser structural (definitivo, NO se reintenta).
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $raw = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<SOAP-ENV:Header/>'
            . '<SOAP-ENV:Body><Foo/></SOAP-ENV:Body>'
            . '</SOAP-ENV:Envelope>';
        $soap->enqueueResponse($raw);

        try {
            $client->consultar(1, 6, 1);
            $this->fail('Debio lanzar WsfeProtocolException');
        } catch (WsfeProtocolException $e) {
            $this->assertSame(WsfeProtocolException::KIND_STRUCTURAL, $e->kind,
                'Body con root no-FEV1 debe ser structural (definitivo)');
        }
    }

    public function test_5xx_y_html_en_body_clasifica_como_http_5xx_no_html(): void
    {
        // Caso adversarial: el body es simultaneamente "HTTP/1.1 503"
        // Y contiene <html>. El 5xx debe ganar (los 502/503/504 son
        // siempre transitorios, el contenido HTML es solo el body que
        // acompana al status).
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $raw = "HTTP/1.1 502 Bad Gateway\r\n\r\n<html><body>nope</body></html>";
        $soap->enqueueResponse($raw);

        try {
            $client->consultar(1, 6, 1);
            $this->fail('Debio lanzar WsfeProtocolException');
        } catch (WsfeProtocolException $e) {
            $this->assertSame(WsfeProtocolException::KIND_HTTP_5XX, $e->kind,
                '5xx gana sobre HTML cuando ambos estan en el body');
        }
    }

    // -------------------------------------------------------------------
    // Bug 3: parseSolicitarResponse must inspect top-level Events/Errors
    // for code 9999. The original code only checked Observaciones
    // inside FECAEDetResponse, missing per-CUIT 9999 at the request
    // level. That made 9999-in-Errors / 9999-in-Events silently
    // classify as structural (definitivo) instead of transient.
    // -------------------------------------------------------------------

    public function test_solicitar_evento_9999_top_level_es_transient_y_retry(): void
    {
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        // 1ra: 9999 en Events del result de la operacion (NO en
        // observaciones del detalle).
        $body = '<FECAESolicitarResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FECAESolicitarResult>'
            . '<Events><Evt><Code>9999</Code><Msg>transitorio</Msg></Evt></Events>'
            . '<FeCabResp><CantReg>1</CantReg><Resultado>A</Resultado></FeCabResp>'
            . '<FeDetResp><FECAEDetResponse>'
            . '<CbteDesde>1</CbteDesde><CbteHasta>1</CbteHasta>'
            . '<Resultado>A</Resultado><CAE>74001234567890</CAE><CAEFchVto>20260210</CAEFchVto>'
            . '</FECAEDetResponse></FeDetResp>'
            . '</FECAESolicitarResult>'
            . '</FECAESolicitarResponse>';
        $soap->enqueueResponse($this->envelope($body));
        // 2da: aprobado
        $soap->enqueueResponse($this->feSolicitarAprobado('20260101', 1, '74001234567890', '20260210'));

        $r = $client->solicitar($this->makeComprobante(), 1, '20260101');
        $this->assertTrue($r->isAprobado());
        $this->assertSame(2, $soap->callCount, '9999 en Events top-level debe disparar retry');
    }

    public function test_solicitar_evento_9999_top_level_lanza_arca_transient(): void
    {
        $config = $this->makeConfig(['retry_max_attempts' => 1]);
        [$client, $soap] = $this->makeClient($config);
        $body = '<FECAESolicitarResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FECAESolicitarResult>'
            . '<Events><Evt><Code>9999</Code><Msg>transitorio</Msg></Evt></Events>'
            . '<FeCabResp><CantReg>1</CantReg><Resultado>A</Resultado></FeCabResp>'
            . '</FECAESolicitarResult>'
            . '</FECAESolicitarResponse>';
        $soap->enqueueResponse($this->envelope($body));

        $this->expectException(WsfeArcaTransientException::class);
        $client->solicitar($this->makeComprobante(), 1, '20260101');
    }

    public function test_solicitar_error_9999_top_level_es_transient_y_retry(): void
    {
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        // 1ra: 9999 en Errors (no en Events ni en Observaciones).
        $body = '<FECAESolicitarResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FECAESolicitarResult>'
            . '<Errors><Err><Code>9999</Code><Msg>transitorio</Msg></Err></Errors>'
            . '<FeCabResp><CantReg>1</CantReg><Resultado>A</Resultado></FeCabResp>'
            . '<FeDetResp><FECAEDetResponse>'
            . '<CbteDesde>1</CbteDesde><CbteHasta>1</CbteHasta>'
            . '<Resultado>A</Resultado><CAE>74001234567890</CAE><CAEFchVto>20260210</CAEFchVto>'
            . '</FECAEDetResponse></FeDetResp>'
            . '</FECAESolicitarResult>'
            . '</FECAESolicitarResponse>';
        $soap->enqueueResponse($this->envelope($body));
        // 2da: aprobado
        $soap->enqueueResponse($this->feSolicitarAprobado('20260101', 1, '74001234567890', '20260210'));

        $r = $client->solicitar($this->makeComprobante(), 1, '20260101');
        $this->assertTrue($r->isAprobado());
        $this->assertSame(2, $soap->callCount, '9999 en Errors top-level debe disparar retry');
    }

    public function test_solicitar_error_9999_top_level_lanza_arca_transient(): void
    {
        $config = $this->makeConfig(['retry_max_attempts' => 1]);
        [$client, $soap] = $this->makeClient($config);
        $body = '<FECAESolicitarResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FECAESolicitarResult>'
            . '<Errors><Err><Code>9999</Code><Msg>transitorio</Msg></Err></Errors>'
            . '<FeCabResp><CantReg>1</CantReg><Resultado>A</Resultado></FeCabResp>'
            . '</FECAESolicitarResult>'
            . '</FECAESolicitarResponse>';
        $soap->enqueueResponse($this->envelope($body));

        $this->expectException(WsfeArcaTransientException::class);
        $client->solicitar($this->makeComprobante(), 1, '20260101');
    }

    public function test_solicitar_error_top_level_no_9999_es_structural_no_retry(): void
    {
        // Codigo no-9999 en Errors top-level (ej. 500) -> WsfeException
        // generico via inspectErrors. NO transitorio, no retry.
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $body = '<FECAESolicitarResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FECAESolicitarResult>'
            . '<Errors><Err><Code>500</Code><Msg>error funcional</Msg></Err></Errors>'
            . '<FeCabResp><CantReg>1</CantReg><Resultado>A</Resultado></FeCabResp>'
            . '</FECAESolicitarResult>'
            . '</FECAESolicitarResponse>';
        $soap->enqueueResponse($this->envelope($body));

        $this->expectException(WsfeException::class);
        try {
            $client->solicitar($this->makeComprobante(), 1, '20260101');
        } finally {
            $this->assertSame(1, $soap->callCount, 'Error no-9999 top-level NO se reintenta');
        }
    }

    // -------------------------------------------------------------------
    // Advisory 5: FeCabResp header echo (CantReg / PtoVta / CbteTipo)
    // -------------------------------------------------------------------

    public function test_solicitar_cantreg_distinto_de_1_es_protocol_error(): void
    {
        // v1 siempre envia CantReg=1; recibir 2 es un contrato
        // violado. Antes el SDK aceptaba silenciosamente el CAE del
        // primer FECAEDetResponse y descartaba el resto (o emitia
        // un CAE que no correspondia al comprobante solicitado).
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $body = '<FECAESolicitarResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FECAESolicitarResult>'
            . '<FeCabResp><CantReg>2</CantReg><Resultado>A</Resultado></FeCabResp>'
            . '<FeDetResp><FECAEDetResponse>'
            . '<CbteDesde>1</CbteDesde><CbteHasta>1</CbteHasta>'
            . '<Resultado>A</Resultado><CAE>74001234567890</CAE><CAEFchVto>20260210</CAEFchVto>'
            . '</FECAEDetResponse></FeDetResp>'
            . '</FECAESolicitarResult>'
            . '</FECAESolicitarResponse>';
        $soap->enqueueResponse($this->envelope($body));

        try {
            $client->solicitar($this->makeComprobante(), 1, '20260101');
            $this->fail('Debio lanzar WsfeProtocolException por CantReg=2');
        } catch (WsfeProtocolException $e) {
            $this->assertSame(WsfeProtocolException::KIND_STRUCTURAL, $e->kind,
                'CantReg != 1 es structural, no se reintenta');
            $this->assertStringContainsString('CantReg', $e->getMessage());
        }
    }

    public function test_solicitar_pto_vta_no_matchea_request_es_protocol_error(): void
    {
        // Defense in depth: la respuesta debe eco del PtoVta pedido.
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $body = '<FECAESolicitarResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FECAESolicitarResult>'
            . '<FeCabResp><CantReg>1</CantReg><PtoVta>99</PtoVta><CbteTipo>6</CbteTipo><Resultado>A</Resultado></FeCabResp>'
            . '<FeDetResp><FECAEDetResponse>'
            . '<CbteDesde>1</CbteDesde><CbteHasta>1</CbteHasta>'
            . '<Resultado>A</Resultado><CAE>74001234567890</CAE><CAEFchVto>20260210</CAEFchVto>'
            . '</FECAEDetResponse></FeDetResp>'
            . '</FECAESolicitarResult>'
            . '</FECAESolicitarResponse>';
        $soap->enqueueResponse($this->envelope($body));

        $this->expectException(WsfeProtocolException::class);
        $client->solicitar($this->makeComprobante(), 1, '20260101');
    }

    public function test_solicitar_cbte_tipo_no_matchea_request_es_protocol_error(): void
    {
        // Defense in depth: la respuesta debe eco del CbteTipo pedido.
        $config = $this->makeConfig();
        [$client, $soap] = $this->makeClient($config);
        $body = '<FECAESolicitarResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FECAESolicitarResult>'
            . '<FeCabResp><CantReg>1</CantReg><PtoVta>1</PtoVta><CbteTipo>1</CbteTipo><Resultado>A</Resultado></FeCabResp>'
            . '<FeDetResp><FECAEDetResponse>'
            . '<CbteDesde>1</CbteDesde><CbteHasta>1</CbteHasta>'
            . '<Resultado>A</Resultado><CAE>74001234567890</CAE><CAEFchVto>20260210</CAEFchVto>'
            . '</FECAEDetResponse></FeDetResp>'
            . '</FECAESolicitarResult>'
            . '</FECAESolicitarResponse>';
        $soap->enqueueResponse($this->envelope($body));

        $this->expectException(WsfeProtocolException::class);
        $client->solicitar($this->makeComprobante(), 1, '20260101');
    }
}
