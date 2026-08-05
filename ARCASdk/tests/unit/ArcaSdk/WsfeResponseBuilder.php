<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\ArcaSdk;

use Rbbsoft\ArcaSdk\Tests\Unit\Wsfe\SoapClientDouble;

/**
 * Helper para construir envelopes SOAP que el WsfeClient (con su
 * SoapClient inyectado) puede parsear. Centraliza los XML de cada
 * operacion para que los tests del orquestador no se llenen de
 * boilerplate XML.
 */
final class WsfeResponseBuilder
{
    private SoapClientDouble $soap;

    public function __construct(SoapClientDouble $soap)
    {
        $this->soap = $soap;
    }

    /**
     * Encola respuesta para FECompUltimoAutorizado.
     */
    public function enqueueUltimoAutorizado(int $ultimo): void
    {
        $body = '<FECompUltimoAutorizadoResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FECompUltimoAutorizadoResult>'
            . '<PtoVta>1</PtoVta><CbteTipo>11</CbteTipo>'
            . '<nro>' . $ultimo . '</nro>'
            . '</FECompUltimoAutorizadoResult>'
            . '</FECompUltimoAutorizadoResponse>';
        $this->soap->enqueueResponse($this->envelope($body));
    }

    /**
     * Encola respuesta APROBADA para FECAESolicitar.
     */
    public function enqueueAprobado(int $cbteNro, string $cae, string $caeFchVto): void
    {
        $body = '<FECAESolicitarResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FECAESolicitarResult>'
            . '<FeCabResp><CantReg>1</CantReg><Resultado>A</Resultado></FeCabResp>'
            . '<FeDetResp>'
            . '<FECAEDetResponse>'
            . '<CbteDesde>' . $cbteNro . '</CbteDesde><CbteHasta>' . $cbteNro . '</CbteHasta>'
            . '<Resultado>A</Resultado>'
            . '<CAE>' . $cae . '</CAE><CAEFchVto>' . $caeFchVto . '</CAEFchVto>'
            . '</FECAEDetResponse>'
            . '</FeDetResp>'
            . '</FECAESolicitarResult>'
            . '</FECAESolicitarResponse>';
        $this->soap->enqueueResponse($this->envelope($body));
    }

    /**
     * Encola respuesta RECHAZADA para FECAESolicitar.
     *
     * @param array<int, array{codigo:int, mensaje:string}> $observaciones
     */
    public function enqueueRechazado(int $cbteNro, array $observaciones): void
    {
        $obs = '';
        foreach ($observaciones as $o) {
            $obs .= '<Obs><Code>' . $o['codigo'] . '</Code><Msg>'
                . htmlspecialchars($o['mensaje'], ENT_XML1)
                . '</Msg></Obs>';
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
        $this->soap->enqueueResponse($this->envelope($body));
    }

    /**
     * Encola respuesta para FECompConsultar con un comprobante que
     * ARCA tiene persistido. Usado en tests de recuperacion zombie
     * (Phase 7) para simular el caso "el comprobante ya esta en ARCA".
     *
     * Los AlicIva se envian con Id=5 (21%) por default; tests
     * especificos pueden pasarlos.
     *
     * @param array<int, array{Id:string, BaseImp:string, Importe:string}> $alicIva
     * @param array<int, array{Tipo:int, PtoVta:int, Nro:int}>             $cbtesAsoc
     */
    public function enqueueConsultar(
        int $puntoVenta,
        int $cbteTipo,
        int $cbteNro,
        string $cbteFch,
        string $cae,
        string $caeFchVto,
        int $concepto,
        int $docTipo,
        string $docNro,
        string $impTotal,
        string $impNeto,
        string $impIva,
        string $impTrib = '0.00',
        string $impOpEx = '0.00',
        string $impTotConc = '0.00',
        string $monId = 'PES',
        string $monCotiz = '1.0000',
        array $alicIva = [],
        array $cbtesAsoc = [],
        ?int $fchServDesde = null,
        ?int $fchServHasta = null,
        ?int $fchVtoPago = null,
    ): void {
        $alicXml = '';
        if (count($alicIva) > 0) {
            $parts = [];
            foreach ($alicIva as $a) {
                $parts[] = '<AlicIva>'
                    . '<Id>' . $a['Id'] . '</Id>'
                    . '<BaseImp>' . $a['BaseImp'] . '</BaseImp>'
                    . '<Importe>' . $a['Importe'] . '</Importe>'
                    . '</AlicIva>';
            }
            $alicXml = '<Iva>' . implode('', $parts) . '</Iva>';
        }
        $asocXml = '';
        if (count($cbtesAsoc) > 0) {
            $parts = [];
            foreach ($cbtesAsoc as $a) {
                $parts[] = '<CbteAsoc>'
                    . '<Tipo>' . $a['Tipo'] . '</Tipo>'
                    . '<PtoVta>' . $a['PtoVta'] . '</PtoVta>'
                    . '<Nro>' . $a['Nro'] . '</Nro>'
                    . '</CbteAsoc>';
            }
            $asocXml = '<CbtesAsoc>' . implode('', $parts) . '</CbtesAsoc>';
        }
        $servXml = '';
        if ($fchServDesde !== null) {
            $servXml .= '<FchServDesde>' . $fchServDesde . '</FchServDesde>';
        }
        if ($fchServHasta !== null) {
            $servXml .= '<FchServHasta>' . $fchServHasta . '</FchServHasta>';
        }
        if ($fchVtoPago !== null) {
            $servXml .= '<FchVtoPago>' . $fchVtoPago . '</FchVtoPago>';
        }
        $body = '<FECompConsultarResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FECompConsultarResult>'
            . '<ResultGet>'
            . '<Concepto>' . $concepto . '</Concepto>'
            . '<DocTipo>' . $docTipo . '</DocTipo>'
            . '<DocNro>' . htmlspecialchars($docNro, ENT_XML1) . '</DocNro>'
            . '<CbteDesde>' . $cbteNro . '</CbteDesde>'
            . '<CbteHasta>' . $cbteNro . '</CbteHasta>'
            . '<CbteFch>' . $cbteFch . '</CbteFch>'
            . '<ImpTotal>' . $impTotal . '</ImpTotal>'
            . '<ImpTotConc>' . $impTotConc . '</ImpTotConc>'
            . '<ImpNeto>' . $impNeto . '</ImpNeto>'
            . '<ImpOpEx>' . $impOpEx . '</ImpOpEx>'
            . '<ImpTrib>' . $impTrib . '</ImpTrib>'
            . '<ImpIVA>' . $impIva . '</ImpIVA>'
            . $servXml
            . '<MonId>' . htmlspecialchars($monId, ENT_XML1) . '</MonId>'
            . '<MonCotiz>' . $monCotiz . '</MonCotiz>'
            . $asocXml
            . $alicXml
            . '<Resultado>A</Resultado>'
            . '<CodAutorizacion>' . $cae . '</CodAutorizacion>'
            . '<FchVto>' . $caeFchVto . '</FchVto>'
            . '</ResultGet>'
            . '</FECompConsultarResult>'
            . '</FECompConsultarResponse>';
        $this->soap->enqueueResponse($this->envelope($body));
    }

    /**
     * Encola respuesta "no existe" (codigo 601) para FECompConsultar.
     * El WsfeClient::parseConsultarResponse lo traduce a null.
     */
    public function enqueueConsultarNoExiste(int $puntoVenta, int $cbteTipo, int $cbteNro): void
    {
        $body = '<FECompConsultarResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FECompConsultarResult>'
            . '<Errors><Err><Code>601</Code><Msg>No existe el comprobante solicitado</Msg></Err></Errors>'
            . '</FECompConsultarResult>'
            . '</FECompConsultarResponse>';
        $this->soap->enqueueResponse($this->envelope($body));
    }

    /**
     * Encola respuesta para FECAESolicitar con un Event codigo 9999
     * a nivel de operacion (NO dentro de FECAEDetResponse). Esto hace
     * que el WsfeClient lance WsfeArcaTransientException.
     */
    public function enqueueSolicitarCon9999(): void
    {
        $body = '<FECAESolicitarResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FECAESolicitarResult>'
            . '<Events><Evt><Code>9999</Code><Msg>ARCA transitorio</Msg></Evt></Events>'
            . '</FECAESolicitarResult>'
            . '</FECAESolicitarResponse>';
        $this->soap->enqueueResponse($this->envelope($body));
    }

    /**
     * Encola respuesta para FECAESolicitar con un FE structure
     * inesperada (sin FECAESolicitarResult claro) -> WsfeClient
     * lanza WsfeProtocolException kind=structural.
     */
    public function enqueueSolicitarStructural(): void
    {
        $body = '<FECAESolicitarResponse xmlns="http://ar.gov.afip.dif.FEV1/">'
            . '<FECAESolicitarResult>'
            . '<FeCabResp><Resultado>X</Resultado></FeCabResp>' // X invalido
            . '</FECAESolicitarResult>'
            . '</FECAESolicitarResponse>';
        $this->soap->enqueueResponse($this->envelope($body));
    }

    private function envelope(string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<SOAP-ENV:Header/>'
            . '<SOAP-ENV:Body>'
            . $body
            . '</SOAP-ENV:Body>'
            . '</SOAP-ENV:Envelope>';
    }
}
