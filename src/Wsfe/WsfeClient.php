<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Wsfe;

use Closure;
use Rbbsoft\ArcaSdk\Config\Config;
use Rbbsoft\ArcaSdk\Exceptions\WsfeArcaTransientException;
use Rbbsoft\ArcaSdk\Exceptions\WsfeException;
use Rbbsoft\ArcaSdk\Exceptions\WsfeProtocolException;
use Rbbsoft\ArcaSdk\Support\RetryPolicy;
use Rbbsoft\ArcaSdk\Wsaa\TicketDeAcceso;
use Rbbsoft\ArcaSdk\Wsaa\WsaaClient;
use SimpleXMLElement;
use SoapClient;
use SoapFault;
use Throwable;

/**
 * Cliente WSFE de bajo nivel. Encapsula las 4 operaciones del servicio:
 *  - FEDummy
 *  - FECompUltimoAutorizado
 *  - FECAESolicitar
 *  - FECompConsultar
 *
 * Responsabilidades:
 *  - Autenticarse: antes de cada llamada obtiene un TA vigente via
 *    el token provider (WsaaClient::getToken('wsfe')) y lo coloca en
 *    el bloque <Auth>.
 *  - Mapear Comprobante -> FECAESolicitar payload.
 *  - Normalizar SIEMPRE la respuesta de FECAESolicitar a
 *    ComprobanteResponse, incluso en Resultado='R' (rechazo funcional).
 *  - Detectar codigo ARCA 9999 -> WsfeArcaTransientException (transitorio).
 *  - Clasificar malformaciones -> WsfeProtocolException con kind
 *    (empty_body | html_gateway | http_5xx | structural | unknown).
 *  - Aplicar la misma politica de retry a las 3 operaciones de negocio.
 *
 * NO decide nada sobre idempotencia, leases, named locks, ni recuperacion
 * zombie: eso es trabajo del orquestador (Phase 6) y de la capa de
 * Idempotencia (Phase 5).
 */
final class WsfeClient
{
    /**
     * Mapea receptor_condicion_iva del caller al Id ARCA
     * (CondicionIVAReceptorId). Catalogo oficial de la RG 5616.
     *
     * Cambio de RG 5616 (vigente en homo desde 2025-04-06,
     * obligatorio en prod desde 2025-09-01):
     *   - el nombre del campo paso de "IvaReceptor" a
     *     "CondicionIVAReceptorId"
     *   - el catalogo de IDs se reordeno (Monotributo paso de 13 a 6).
     * Ver wsfev1-RG-4291.pdf de ARCA y pyafipws como ref cruzada.
     */
    private const IVA_RECEPTOR_ID = [
        'RI' => 1,  // IVA Responsable Inscripto
        'EX' => 4,  // IVA Sujeto Exento
        'CF' => 5,  // Consumidor Final
        'NC' => 7,  // Sujeto No Categorizado
        'MT' => 6,  // Responsable Monotributo
    ];

    private const WSN_WSFE = 'wsfe';

    /**
     * @param (Closure(string $wsn): TicketDeAcceso)|null $tokenProvider
     *        Callable que entrega un TA vigente para un WSN. Por
     *        defecto, si se pasa $wsaaClient, se usa
     *        $wsaaClient->getToken($wsn). En tests se inyecta un doble.
     */
    public function __construct(
        private readonly Config $config,
        ?Closure $tokenProvider = null,
        ?WsaaClient $wsaaClient = null,
        private readonly RetryPolicy $retryPolicy = new RetryPolicy(),
        private readonly ?SoapClient $soap = null,
    ) {
        if ($tokenProvider === null && $wsaaClient === null) {
            throw new \InvalidArgumentException(
                'WsfeClient requiere tokenProvider (Closure) o wsaaClient (WsaaClient)'
            );
        }
        if ($tokenProvider === null) {
            $wsaa = $wsaaClient;
            $tokenProvider = static fn(string $wsn): TicketDeAcceso => $wsaa->getToken($wsn);
        }
        $this->tokenProvider = $tokenProvider;
    }

    /** @var Closure(string): TicketDeAcceso */
    private readonly Closure $tokenProvider;

    /**
     * FEDummy -> DummyResponse. Solo se consulta el estado de los
     * servidores. Aplica retry a SoapFaults/errores de red; nunca a
     * respuestas validas (que siempre se devuelven).
     */
    public function dummy(): DummyResponse
    {
        $op = function (): DummyResponse {
            $this->assertSoap();
            $body = $this->buildDummyBody();
            $xml  = $this->callSoap('FEDummy', $body);
            return $this->parseDummyResponse($xml);
        };
        return $this->retryPolicy->execute(
            $op,
            $this->config->retryMaxAttempts,
            $this->config->retryBaseBackoffMs,
            $this->config->retryMaxBackoffMs,
        );
    }

    /**
     * FECompUltimoAutorizado -> siguiente numero disponible.
     *
     * @throws WsfeException       si ARCA devuelve Errors (no transitorio).
     * @throws WsfeArcaTransientException si ARCA devuelve un Event con codigo 9999.
     */
    public function ultimoAutorizado(int $puntoVenta, int $cbteTipo): int
    {
        $op = function () use ($puntoVenta, $cbteTipo): int {
            $this->assertSoap();
            $body = $this->buildUltimoAutorizadoBody($puntoVenta, $cbteTipo);
            $xml  = $this->callSoap('FECompUltimoAutorizado', $body);
            return $this->parseUltimoAutorizadoResponse($xml, $puntoVenta, $cbteTipo);
        };
        return $this->retryPolicy->execute(
            $op,
            $this->config->retryMaxAttempts,
            $this->config->retryBaseBackoffMs,
            $this->config->retryMaxBackoffMs,
        );
    }

    /**
     * FECAESolicitar -> ComprobanteResponse.
     *
     * - 'A' (aprobado): ComprobanteResponse con CAE, caeFchVto.
     * - 'R' (rechazado): ComprobanteResponse con observaciones. El
     *   cliente NO lanza CbteRechazadoException; el orquestador lo
     *   decide.
     * - Observacion 9999: WsfeArcaTransientException (transitorio).
     * - Resultado='A' sin CAE / estructura invalida: WsfeProtocolException
     *   kind=structural.
     */
    public function solicitar(Comprobante $comprobante, int $cbteNro, string $cbteFch): ComprobanteResponse
    {
        $op = function () use ($comprobante, $cbteNro, $cbteFch): ComprobanteResponse {
            $this->assertSoap();
            $body = $this->buildSolicitarBody($comprobante, $cbteNro, $cbteFch);
            $xml  = $this->callSoap('FECAESolicitar', $body);
            return $this->parseSolicitarResponse(
                $xml,
                $cbteNro,
                $comprobante->puntoVenta,
                $comprobante->cbteTipo,
            );
        };
        return $this->retryPolicy->execute(
            $op,
            $this->config->retryMaxAttempts,
            $this->config->retryBaseBackoffMs,
            $this->config->retryMaxBackoffMs,
        );
    }

    /**
     * FECompConsultar -> ?ComprobanteConsultado.
     *
     * Devuelve null cuando ARCA reporta que el comprobante no existe
     * (codigo 601). Lanza WsfeException para otros errores, o
     * WsfeArcaTransientException para 9999.
     */
    public function consultar(int $puntoVenta, int $cbteTipo, int $cbteNro): ?ComprobanteConsultado
    {
        $op = function () use ($puntoVenta, $cbteTipo, $cbteNro): ?ComprobanteConsultado {
            $this->assertSoap();
            $body = $this->buildConsultarBody($puntoVenta, $cbteTipo, $cbteNro);
            $xml  = $this->callSoap('FECompConsultar', $body);
            return $this->parseConsultarResponse($xml, $puntoVenta, $cbteTipo, $cbteNro);
        };
        return $this->retryPolicy->execute(
            $op,
            $this->config->retryMaxAttempts,
            $this->config->retryBaseBackoffMs,
            $this->config->retryMaxBackoffMs,
        );
    }

    // -------------------------------------------------------------------
    // Body builders
    // -------------------------------------------------------------------

    private function buildAuthBlock(): string
    {
        $ta = ($this->tokenProvider)(self::WSN_WSFE);
        return '<Auth>'
            . '<Token>' . htmlspecialchars($ta->token, ENT_XML1) . '</Token>'
            . '<Sign>'  . htmlspecialchars($ta->sign,  ENT_XML1) . '</Sign>'
            . '<Cuit>'  . htmlspecialchars($this->config->cuit, ENT_XML1) . '</Cuit>'
            . '</Auth>';
    }

    private function buildDummyBody(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<SOAP-ENV:Header/>'
            . '<SOAP-ENV:Body>'
            . '<FEDummy xmlns="http://ar.gov.afip.dif.FEV1/">'
            . $this->buildAuthBlock()
            . '</FEDummy>'
            . '</SOAP-ENV:Body>'
            . '</SOAP-ENV:Envelope>';
    }

    private function buildUltimoAutorizadoBody(int $puntoVenta, int $cbteTipo): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<SOAP-ENV:Header/>'
            . '<SOAP-ENV:Body>'
            . '<FECompUltimoAutorizado xmlns="http://ar.gov.afip.dif.FEV1/">'
            . $this->buildAuthBlock()
            . '<PtoVta>' . $puntoVenta . '</PtoVta>'
            . '<CbteTipo>' . $cbteTipo . '</CbteTipo>'
            . '</FECompUltimoAutorizado>'
            . '</SOAP-ENV:Body>'
            . '</SOAP-ENV:Envelope>';
    }

    private function buildConsultarBody(int $puntoVenta, int $cbteTipo, int $cbteNro): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<SOAP-ENV:Header/>'
            . '<SOAP-ENV:Body>'
            . '<FECompConsultar xmlns="http://ar.gov.afip.dif.FEV1/">'
            . $this->buildAuthBlock()
            . '<FeCompConsReq>'
            . '<PtoVta>' . $puntoVenta . '</PtoVta>'
            . '<CbteTipo>' . $cbteTipo . '</CbteTipo>'
            . '<CbteNro>' . $cbteNro . '</CbteNro>'
            . '</FeCompConsReq>'
            . '</FECompConsultar>'
            . '</SOAP-ENV:Body>'
            . '</SOAP-ENV:Envelope>';
    }

    private function buildSolicitarBody(Comprobante $c, int $cbteNro, string $cbteFch): string
    {
        $discrimina = TiposComprobante::discriminaIva($c->cbteTipo);
        $res = IvaCalculator::calcular(
            $c->items,
            $discrimina,
            $c->importeNoGravado,
            $c->importeExento,
            $c->importeOtrosTributos,
        );

        $alicuotasXml = '';
        if ($discrimina) {
            $parts = [];
            foreach ($res->aAlicIva() as $a) {
                $parts[] = '<AlicIva>'
                    . '<Id>' . $a['Id'] . '</Id>'
                    . '<BaseImp>' . $a['BaseImp'] . '</BaseImp>'
                    . '<Importe>' . $a['Importe'] . '</Importe>'
                    . '</AlicIva>';
            }
            if (count($parts) > 0) {
                $alicuotasXml = '<Iva>' . implode('', $parts) . '</Iva>';
            } else {
                $alicuotasXml = '<Iva/>';
            }
        } elseif (TiposComprobante::requiereBloqueIva($c->cbteTipo)
                  && bccomp((string) $res->netoGravado, '0', 2) > 0) {
            // M (y posiblemente otras) NO discrimina IVA pero exige el
            // bloque <Iva> explicito cuando hay gravado (RG 5616;
            // codigo 10070 si falta, 10018 si el Id de AlicIva no
            // corresponde a la alicuota 0%). Mandamos un unico AlicIva
            // con Id=3 (catalogo ARCA: alicuota 0%), BaseImp=gravado
            // e Importe=0, que es la forma "neutra" que ARCA acepta
            // para indicar "hay gravado, IVA integrado en el total".
            $alicuotasXml = '<Iva><AlicIva>'
                . '<Id>3</Id>'
                . '<BaseImp>' . $res->netoGravado . '</BaseImp>'
                . '<Importe>0.00</Importe>'
                . '</AlicIva></Iva>';
        }

        $cbtesAsocXml = '';
        if (count($c->cbtesAsoc) > 0) {
            $parts = [];
            foreach ($c->cbtesAsoc as $a) {
                $parts[] = '<CbteAsoc>'
                    . '<Tipo>' . $a['Tipo'] . '</Tipo>'
                    . '<PtoVta>' . $a['PtoVta'] . '</PtoVta>'
                    . '<Nro>' . $a['Nro'] . '</Nro>'
                    . '</CbteAsoc>';
            }
            $cbtesAsocXml = '<CbtesAsoc>' . implode('', $parts) . '</CbtesAsoc>';
        }

        $fechasServicio = '';
        if ($c->concepto !== 1) {
            $fechasServicio = '<FchServDesde>' . $c->servicioDesde . '</FchServDesde>'
                . '<FchServHasta>' . $c->servicioHasta . '</FchServHasta>';
            if ($c->vencimientoPago !== null) {
                $fechasServicio .= '<FchVtoPago>' . $c->vencimientoPago . '</FchVtoPago>';
            }
        }

        $ivaReceptor = self::IVA_RECEPTOR_ID[$c->receptorCondicionIva] ?? null;
        if ($ivaReceptor === null) {
            // Nunca deberia ocurrir: Comprobante ya valido la condicion.
            throw new \LogicException("receptor_condicion_iva invalida: {$c->receptorCondicionIva}");
        }

        $det = '<FECAEDetRequest>'
            . '<Concepto>' . $c->concepto . '</Concepto>'
            . '<DocTipo>' . $c->receptorDocumentoTipo . '</DocTipo>'
            . '<DocNro>' . htmlspecialchars($c->receptorDocumentoNro, ENT_XML1) . '</DocNro>'
            . '<CbteDesde>' . $cbteNro . '</CbteDesde>'
            . '<CbteHasta>' . $cbteNro . '</CbteHasta>'
            . '<CbteFch>' . $cbteFch . '</CbteFch>'
            . '<ImpTotal>' . $res->total . '</ImpTotal>'
            . '<ImpTotConc>' . $res->importeNoGravado . '</ImpTotConc>'
            . '<ImpNeto>' . $res->netoGravado . '</ImpNeto>'
            . '<ImpOpEx>' . $res->importeExento . '</ImpOpEx>'
            . '<ImpTrib>' . $res->importeOtrosTrib . '</ImpTrib>'
            . '<ImpIVA>' . $res->ivaTotal . '</ImpIVA>'
            . $fechasServicio
            . '<MonId>' . htmlspecialchars($c->monId, ENT_XML1) . '</MonId>'
            . '<MonCotiz>' . $c->monCotiz . '</MonCotiz>'
            . $cbtesAsocXml
            . $alicuotasXml
            // Bajo RG 5616 el campo es CondicionIVAReceptorId
            // (no IvaReceptor). ARCA rechaza con codigo 10246 si
            // llega con el nombre viejo. Ver pyafipws como ref.
            . '<CondicionIVAReceptorId>' . $ivaReceptor . '</CondicionIVAReceptorId>'
            . '</FECAEDetRequest>';

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<SOAP-ENV:Header/>'
            . '<SOAP-ENV:Body>'
            . '<FECAESolicitar xmlns="http://ar.gov.afip.dif.FEV1/">'
            . $this->buildAuthBlock()
            . '<FeCAEReq>'
            . '<FeCabReq>'
            . '<CantReg>1</CantReg>'
            . '<PtoVta>' . $c->puntoVenta . '</PtoVta>'
            . '<CbteTipo>' . $c->cbteTipo . '</CbteTipo>'
            . '</FeCabReq>'
            . '<FeDetReq>'
            . $det
            . '</FeDetReq>'
            . '</FeCAEReq>'
            . '</FECAESolicitar>'
            . '</SOAP-ENV:Body>'
            . '</SOAP-ENV:Envelope>';
    }

    // -------------------------------------------------------------------
    // Call helper
    // -------------------------------------------------------------------

    /**
     * Lanza si el SoapClient no fue inyectado (modo produccion usa
     * SoapClientFactory; tests inyectan directamente).
     */
    private function assertSoap(): void
    {
        if ($this->soap === null) {
            throw new \LogicException('WsfeClient requiere SoapClient inyectado o fabrica externa');
        }
    }

    /**
     * Envia el envelope SOAP y devuelve el body de la respuesta.
     * Captura SoapFaults y los traduce a WsfeException con kind
     * cuando es posible.
     *
     * Devuelve un envelope SOAP equivalente al que ARCA retornaria, para
     * que el resto del pipeline pueda usar simplexml_load_string() sin
     * preocuparse por el modo del SoapClient (WSDL vs non-WSDL, string
     * vs stdClass).
     */
    private function callSoap(string $operation, string $body): string
    {
        // ARCA espera el SOAPAction como "uri/operation" (con '/').
        // El SoapClient PHP en non-WSDL mode genera "uri#operation"
        // (con '#'), que ARCA rechaza con HTTP 500
        // (Server did not recognize the value of HTTP Header
        // SOAPAction). Usamos __doRequest para tener control total
        // sobre el header, en lugar de __soapCall.
        $action = 'http://ar.gov.afip.dif.FEV1/' . $operation;

        try {
            $response = $this->soap->__doRequest(
                $body,
                $this->config->wsfeUrl,
                $action,
                SOAP_1_1,
            );
            // __doRequest NO parsea la respuesta: si ARCA devolvio
            // un SoapFault (HTTP 500 con body <soap:Fault>), lo vemos
            // como un string comun. Detectamos el caso y lanzamos un
            // SoapFault sintetico para que el flujo normal
            // (classifySoapFault) lo procese uniformemente.
            if (is_string($response) && $this->responseIsSoapFault($response)) {
                throw $this->makeSoapFaultFromResponse($response);
            }
        } catch (SoapFault $e) {
            throw $this->classifySoapFault($e, $operation, $body);
        } catch (Throwable $e) {
            // Cualquier otra excepcion: dejamos que RetryPolicy la
            // evalue (red, etc.) o, si es definitiva, propague.
            throw $e;
        }

        if ($response === null) {
            // __doRequest devolvio null. Es un caso limite (red rota,
            // etc.); clasificamos el body crudo si esta disponible.
            $raw = $this->getLastRawResponse();
            throw WsfeProtocolException::emptyBody($raw ?? '');
        }
        if (is_string($response) && trim($response) === '') {
            throw WsfeProtocolException::emptyBody($response);
        }
        if (is_string($response)) {
            // Clasificacion de la respuesta cruda antes de pasarla al
            // parser. Esto reemplaza la logica de "if ($response ===
            // null)" que tenia el flujo __soapCall (donde el
            // SoapClient parseaba y descartaba envelopes vacios o con
            // root inesperado, devolviendo null). Con __doRequest
            // recibimos el envelope siempre, asi que clasificamos
            // aca:
            //   - body con HTML (502 gateway)  -> htmlGateway
            //   - envelope vacio / malformed   -> empty_body
            //   - body con root no-FEV1       -> structural
            //   - soap fault                   -> soap fault (ya
            //                                    interceptado arriba)
            //
            // HTML check va PRIMERO porque un body HTML no es XML
            // parseable, asi que classifySoapBodyRoot lo marca como
            // 'malformed' y perderiamos la informacion de que es un
            // gateway HTTP, no un envelope SOAP vacio.
            $low = strtolower($response);
            if (str_contains($low, '<html')
                || str_contains($low, '<!doctype')
            ) {
                throw WsfeProtocolException::htmlGateway($response);
            }
            $root = $this->classifySoapBodyRoot($response);
            if ($root === 'empty' || $root === 'malformed') {
                throw WsfeProtocolException::emptyBody($response);
            }
            if ($root === 'unexpected') {
                throw WsfeProtocolException::structural(
                    "WSFE {$operation}: respuesta SOAP con Body de root inesperado (no es operacion FEV1)",
                    $this->excerpt($response),
                );
            }
            return $response;
        }
        if (is_object($response)) {
            // SoapClient en WSDL mode parsea la respuesta y entrega un
            // stdClass. Re-serializamos a un envelope sintetico para
            // que los parsers existentes funcionen uniformemente.
            return $this->envelopeFromStdClass($response, $operation);
        }
        throw WsfeProtocolException::structural(
            "WSFE {$operation}: respuesta con tipo inesperado (" . gettype($response) . ')',
            null,
        );
    }

    /**
     * Detecta si una respuesta cruda de __doRequest contiene un
     * <soap:Fault>. Usamos regex para no depender de la serializacion
     * del namespace (prefijo soap:, SOAP-ENV:, etc).
     */
    private function responseIsSoapFault(string $response): bool
    {
        return (bool) preg_match('/<(?:\w+:)?Fault[\s>]/i', $response);
    }

    /**
     * Construye un SoapFault sintetico a partir del body de un fault
     * SOAP. Extrae faultcode y faultstring con regex para tolerar
     * diferencias de prefijo/encoding.
     */
    private function makeSoapFaultFromResponse(string $response): SoapFault
    {
        $code = '';
        $msg  = '';
        if (preg_match('/<(?:\w+:)?faultcode[^>]*>([^<]+)/i', $response, $m)) {
            $code = trim($m[1]);
        }
        if (preg_match('/<(?:\w+:)?faultstring[^>]*>(.+?)<\/(?:\w+:)?faultstring>/is', $response, $m)) {
            // Decodificar entidades HTML basicas que ARCA mete
            // (System.Web.Services serializa los namespaces como &amp;).
            $msg = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if ($code === '' && $msg === '') {
            return new SoapFault('Server', 'WSFE: respuesta con Fault sin faultcode/faultstring parseable');
        }
        return new SoapFault($code, $msg);
    }

    /**
     * Construye un envelope SOAP "falso" a partir de un stdClass para
     * unificar la entrada a los parsers (todos reciben string).
     *
     * SoapClient en WSDL mode ya "desnudo" la respuesta: el stdClass
     * que entrega es el `<Op>Result` mismo, no el `<Op>Response>`. Por
     * lo tanto re-envuelvo en `<Op>Result>` directamente para que los
     * parsers existentes lo encuentren al hacer
     * `$sx->{$op . 'Result'}`.
     */
    private function envelopeFromStdClass(object $response, string $operation): string
    {
        $inner = $this->stdClassToXml($response, $operation . 'Result');
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<SOAP-ENV:Header/>'
            . '<SOAP-ENV:Body>'
            . $inner
            . '</SOAP-ENV:Body>'
            . '</SOAP-ENV:Envelope>';
    }

    /**
     * Convierte un stdClass (o un SoapParam/SoapVar) en un fragmento
     * XML sin namespace. Arrays -> multiples hijos, stdClass -> un
     * solo hijo, scalar -> texto escapado.
     */
    private function stdClassToXml(mixed $value, string $wrapper): string
    {
        $xml = '<' . $wrapper . '>';
        if (is_object($value)) {
            $vars = get_object_vars($value);
            foreach ($vars as $k => $v) {
                if (is_array($v)) {
                    foreach ($v as $item) {
                        $xml .= $this->stdClassToXml($item, $k);
                    }
                } else {
                    $xml .= $this->stdClassToXml($v, $k);
                }
            }
        } elseif (is_array($value)) {
            foreach ($value as $k => $v) {
                if (is_int($k)) {
                    $xml .= $this->stdClassToXml($v, $wrapper);
                } else {
                    $xml .= $this->stdClassToXml($v, $k);
                }
            }
        } elseif ($value === null) {
            // nodo vacio
        } else {
            $xml .= htmlspecialchars((string) $value, ENT_XML1);
        }
        $xml .= '</' . $wrapper . '>';
        return $xml;
    }

    /**
     * SoapClient espera el body, no el envelope completo. Extraemos el
     * primer hijo del Body para que __soapCall lo serialice.
     */
    private function stripEnvelope(string $envelope): string
    {
        $sxe = @simplexml_load_string($envelope);
        if ($sxe === false) {
            throw WsfeProtocolException::structural('envelope SOAP invalido', $envelope);
        }
        // Buscar el Body con la URI SOAP canonica; PHP simplexml
        // requiere la URI, no el prefijo, en children().
        $body = $sxe->children('http://schemas.xmlsoap.org/soap/envelope/', false)->Body ?? null;
        if ($body === null) {
            throw WsfeProtocolException::structural('envelope SOAP sin Body', $envelope);
        }
        $child = $body->children('', false);
        if ($child === null || count($child) === 0) {
            throw WsfeProtocolException::structural('envelope SOAP sin operacion', $envelope);
        }
        $first = $child[0];
        $xml = $first->asXML();
        if ($xml === false || $xml === '') {
            throw WsfeProtocolException::structural('envelope SOAP: asXML() devolvio vacio', $envelope);
        }
        return $xml;
    }

    private function classifySoapFault(SoapFault $e, string $op, string $request): WsfeException
    {
        $code = (string) $e->faultcode;
        $msg  = $e->getMessage();

        // Recuperamos el body crudo que el SoapClient recibio antes de
        // tirar el SoapFault. SoapClient lo expone via
        // __getLastResponse() pero la implementacion nativa de PHP
        // devuelve NULL en algunos caminos (ej. SoapFault 'Client' por
        // body no-XML). El SoapClientDouble usado en tests overridea
        // este metodo para devolver el body encolado.
        $rawBody = $this->getLastRawResponse();

        // 1) HTTP/1.1 5xx en el body crudo -> transitorio
        //    (502/503/504 de proxies reversos son lo tipico). Va
        //    PRIMERO porque el body de un 502 suele ser HTML, y no
        //    queremos que se confunda con html_gateway. El bug
        //    original hacia al reves: chequeaba HTML antes que 5xx y
        //    perdiamos la semantica.
        if ($rawBody !== null
            && $rawBody !== ''
            && preg_match('/HTTP\/\d+\.\d+\s+5\d\d/', $rawBody)
        ) {
            return WsfeProtocolException::http5xx($rawBody, $e);
        }

        // 2) Body crudo vacio o solo whitespace -> empty_body.
        //    SoapClient tipicamente tira "looks like we got no XML
        //    document" para whitespace; queremos empty_body, no
        //    html_gateway.
        if ($rawBody === null || trim($rawBody) === '') {
            return WsfeProtocolException::emptyBody($rawBody ?? $msg, $e);
        }

        // 3) HTML real en el body (marcadores concretos: <html,
        //    <!doctype, <body). NO usar el faultstring: en produccion
        //    SoapClient pone "looks like we got no XML document" o
        //    "Wrong Version" y no contiene el body.
        $low = strtolower($rawBody);
        if (str_contains($low, '<html')
            || str_contains($low, '<!doctype')
            || str_contains($low, '<body')
        ) {
            return WsfeProtocolException::htmlGateway($rawBody, $e);
        }

        // 4) SOAP envelope con Body presente: si esta vacio -> empty
        //    (un SOAP valido pero sin contenido). Si tiene un hijo
        //    que no es una response FEV1 -> structural (cambio de
        //    contrato o respuesta inesperada).
        $root = $this->classifySoapBodyRoot($rawBody);
        if ($root === 'empty') {
            return WsfeProtocolException::emptyBody($rawBody, $e);
        }
        if ($root === 'unexpected') {
            return WsfeProtocolException::structural(
                "WSFE {$op}: respuesta SOAP con Body de root inesperado (no es operacion FEV1)",
                $this->excerpt($rawBody),
                $e,
            );
        }

        // 5) Fallback: el SoapFault trae faultcode 'HTTP' con un
        //    mensaje que empieza con "HTTP/1.1 5" (caso tipico cuando
        //    SoapClient ve la linea de status y aborta antes de
        //    mostrar el body). Mantenemos este path por si
        //    __getLastResponse() no devuelve nada en produccion.
        if (strcasecmp($code, 'HTTP') === 0
            && preg_match('/HTTP\/\d+\.\d+\s+5\d\d/', $msg)
        ) {
            return WsfeProtocolException::http5xx($msg, $e);
        }

        // 6) Resto: WsfeException generico (retry policy decidira si
        //    es transitorio segun su contenido: red, timeout,
        //    soap:Server).
        return new WsfeException(
            sprintf('WSFE %s SoapFault [%s]: %s', $op, $code, $msg),
            0,
            $e,
        );
    }

    /**
     * Devuelve la ultima respuesta cruda que el SoapClient recibio,
     * o null si no esta disponible. En produccion depende de
     * SoapClient::__getLastResponse(); en tests el SoapClientDouble
     * overridea el metodo para devolver el body encolado.
     */
    private function getLastRawResponse(): ?string
    {
        if ($this->soap === null || !method_exists($this->soap, '__getLastResponse')) {
            return null;
        }
        $body = $this->soap->__getLastResponse();
        if ($body === null || $body === false) {
            return null;
        }
        return (string) $body;
    }

    /**
     * Clasifica el root del Body de una respuesta SOAP cruda:
     *   - 'empty'      -> <Body/> sin hijos
     *   - 'fev1'       -> <Body><Op>Response>...</Op>Response></Body> (FEV1 valido)
     *   - 'unexpected' -> <Body> con un root que NO es operacion FEV1
     *   - 'malformed'  -> no es SOAP parseable, o falta el Body
     *   - 'unknown'    -> no se pudo determinar (caso limite)
     *
     * Esto es lo que la libreria SoapClient no nos da: cuando
     * parsea un envelope "valido pero vacio" o con root raro,
     * termina retornando NULL y perdemos la causa.
     */
    private function classifySoapBodyRoot(string $rawBody): string
    {
        if (trim($rawBody) === '') {
            return 'empty';
        }
        $prev = libxml_use_internal_errors(true);
        try {
            $sx = simplexml_load_string($rawBody);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
        if ($sx === false) {
            return 'malformed';
        }
        $body = $sx->children('http://schemas.xmlsoap.org/soap/envelope/')->Body ?? null;
        if ($body === null) {
            return 'malformed';
        }
        // Contar TODOS los hijos directos del Body sin importar el
        // namespace. count($body->children()) solo cuenta los hijos
        // en el default namespace, por lo que un <soap:Fault> dentro
        // de <soap:Body> no se contaba y el clasificador terminaba
        // diciendo 'empty' cuando en realidad venia un SoapFault
        // completo (ej. HTTP 500 con faultstring accionable). xpath
        // './*' cuenta cualquier hijo sin importar namespace.
        if (count($body->xpath('./*')) === 0) {
            return 'empty';
        }
        // Cualquier hijo del Body bajo el namespace FEV1 es
        // reconocido como respuesta valida de una operacion ARCA.
        $fev1 = $body->children('http://ar.gov.afip.dif.FEV1/');
        foreach ($fev1 as $_) {
            return 'fev1';
        }
        // Tambin aceptar <Op>Response> sin namespace explicito
        // (algunos servers lo envian asi).
        $defaultNs = $body->children('');
        foreach ($defaultNs as $name => $_) {
            if (str_ends_with($name, 'Response') || str_ends_with($name, 'Result')) {
                return 'fev1';
            }
        }
        return 'unexpected';
    }

    // -------------------------------------------------------------------
    // Response parsers
    // -------------------------------------------------------------------

    private function parseDummyResponse(string $xml): DummyResponse
    {
        $sx = $this->safeParse($xml, 'FEDummy');
        $res = $this->getOperationResult($sx, 'FEDummy');
        $app = isset($res->AppServer) ? strtoupper(trim((string) $res->AppServer)) : DummyResponse::STATUS_UNKNOWN;
        $db  = isset($res->DbServer)  ? strtoupper(trim((string) $res->DbServer))  : DummyResponse::STATUS_UNKNOWN;
        $auth = isset($res->AuthRequest) ? (string) $res->AuthRequest : null;
        return new DummyResponse(
            appServer: $app,
            dbServer: $db,
            authRequest: $auth,
            rawExcerpt: $this->excerpt($xml),
        );
    }

    private function parseUltimoAutorizadoResponse(string $xml, int $puntoVenta, int $cbteTipo): int
    {
        $sx = $this->safeParse($xml, 'FECompUltimoAutorizado');
        $node = $this->getOperationResult($sx, 'FECompUltimoAutorizado');
        $this->inspectEvents($node, 'FECompUltimoAutorizado');
        $this->inspectErrors($node, 'FECompUltimoAutorizado');
        if (!isset($node->nro) && !isset($node->Nro) && !isset($node->CbteNro)) {
            throw WsfeProtocolException::structural(
                "FECompUltimoAutorizado: respuesta sin CbteNro (pv={$puntoVenta}, tipo={$cbteTipo})",
                $this->excerpt($xml),
            );
        }
        $nro = (int) ($node->CbteNro ?? $node->nro ?? $node->Nro);
        if ($nro < 0) {
            throw WsfeProtocolException::structural(
                "FECompUltimoAutorizado: nro invalido ({$nro})",
                $this->excerpt($xml),
            );
        }
        return $nro;
    }

    private function parseSolicitarResponse(
        string $xml,
        int $cbteNro,
        int $puntoVentaSolicitado,
        int $cbteTipoSolicitado,
    ): ComprobanteResponse {
        $sx = $this->safeParse($xml, 'FECAESolicitar');
        $result = $this->getOperationResult($sx, 'FECAESolicitar');

        // Bug 3 fix: 9999 a nivel de operacion (NO en una observacion
        // del detalle) tambien es transitorio. El codigo v1 solo
        // inspeccionaba Observaciones dentro de FECAEDetResponse y
        // trataba un 9999 en <Errors> o <Events> del FECAESolicitarResult
        // como estructural, sin reintentar. El plan maestro dice 9999
        // es transient en todos los niveles.
        $this->inspectEvents($result, 'FECAESolicitar');
        $this->inspectSolicitarErrors($result, 'FECAESolicitar', $cbteNro);

        // Cabecera. Validamos que la respuesta eco del request:
        // CantReg, PtoVta y CbteTipo deben coincidir con lo que
        // pedimos. Si ARCA devuelve otra cosa, es un contrato roto
        // (definitivo, no se reintenta).
        $cab = $result->FeCabResp ?? null;
        $cabRes = $cab !== null ? (string) ($cab->Resultado ?? '') : '';
        if ($cabRes === '') {
            throw WsfeProtocolException::structural(
                'FECAESolicitar: respuesta sin FeCabResp.Resultado',
                $this->excerpt($xml),
            );
        }
        if (!in_array($cabRes, ['A', 'R'], true)) {
            throw WsfeProtocolException::structural(
                "FECAESolicitar: FeCabResp.Resultado invalido ({$cabRes})",
                $this->excerpt($xml),
            );
        }

        // FeCabReq.CantReg siempre es 1 (v1 procesa 1 comprobante por
        // llamada). Si la respuesta trae otro valor, hay una
        // inconsistencia: podria ser una respuesta para otro request
        // o un bug del server. NO se reintenta (es estructural, no
        // transitorio).
        $cantReg = $cab !== null ? trim((string) ($cab->CantReg ?? '')) : '';
        if ($cantReg !== '1') {
            throw WsfeProtocolException::structural(
                "FECAESolicitar: FeCabResp.CantReg='{$cantReg}' != esperado 1 (cbte={$cbteNro})",
                $this->excerpt($xml),
            );
        }

        // Defense in depth: si FeCabResp trae PtoVta y CbteTipo, deben
        // coincidir con el comprobante solicitado. La respuesta puede
        // o no traerlos (algunos servers solo devuelven CantReg y
        // Resultado), asi que validamos solo si estan presentes.
        // Si NO coinciden -> structural (definitivo, no se reintenta).
        if ($cab !== null) {
            $cabPv = $cab->PtoVta ?? null;
            if ($cabPv !== null && (int) $cabPv !== $puntoVentaSolicitado) {
                throw WsfeProtocolException::structural(
                    "FECAESolicitar: FeCabResp.PtoVta='{$cabPv}' != solicitado {$puntoVentaSolicitado} (cbte={$cbteNro})",
                    $this->excerpt($xml),
                );
            }
            $cabTipo = $cab->CbteTipo ?? null;
            if ($cabTipo !== null && (int) $cabTipo !== $cbteTipoSolicitado) {
                throw WsfeProtocolException::structural(
                    "FECAESolicitar: FeCabResp.CbteTipo='{$cabTipo}' != solicitado {$cbteTipoSolicitado} (cbte={$cbteNro})",
                    $this->excerpt($xml),
                );
            }
        }

        // Detalle: buscamos la entrada cuyo CbteDesde == cbteNro.
        $det = $result->FeDetResp ?? null;
        $entry = $this->findDetEntry($det, $cbteNro);

        $detRes = isset($entry->Resultado) ? (string) $entry->Resultado : '';
        if ($detRes === '') {
            throw WsfeProtocolException::structural(
                "FECAESolicitar: FeDetResp[cbte={$cbteNro}] sin Resultado",
                $this->excerpt($xml),
            );
        }

        $observaciones = $this->parseObservaciones($entry->Observaciones ?? null);

        // Codigo 9999 en cualquier observacion -> transitorio.
        foreach ($observaciones as $o) {
            if ($o['codigo'] === RetryPolicy::ARCA_TRANSIENT_CODE) {
                throw new WsfeArcaTransientException(
                    "FECAESolicitar: observacion ARCA transitoria (9999) cbte={$cbteNro}",
                    $observaciones,
                    'FECAESolicitar',
                );
            }
        }

        if ($detRes === 'A') {
            $cae = isset($entry->CAE) ? (string) $entry->CAE : '';
            $caeFchVto = isset($entry->CAEFchVto) ? (string) $entry->CAEFchVto : '';
            if ($cae === '' || !preg_match('/^\d{8}$/', $caeFchVto)) {
                throw WsfeProtocolException::structural(
                    "FECAESolicitar: Resultado='A' sin CAE/CAEFchVto validos (cbte={$cbteNro}, cae='" . $cae . "', fchvto='" . $caeFchVto . "')",
                    $this->excerpt($xml),
                );
            }
            return new ComprobanteResponse(
                resultado: ComprobanteResponse::RESULTADO_APROBADO,
                cae: $cae,
                caeFchVto: $caeFchVto,
                cbteNro: $cbteNro,
                observaciones: $observaciones,
                rawExcerpt: $this->excerpt($xml),
            );
        }

        // Resultado='R'
        return new ComprobanteResponse(
            resultado: ComprobanteResponse::RESULTADO_RECHAZADO,
            cae: null,
            caeFchVto: null,
            cbteNro: $cbteNro,
            observaciones: $observaciones,
            rawExcerpt: $this->excerpt($xml),
        );
    }

    /**
     * Inspecciona <Errors> del FECAESolicitarResult (NO de
     * FECAEDetResponse). Si encuentra codigo 9999 lanza
     * WsfeArcaTransientException; cualquier otro codigo genera un
     * WsfeException generico (rechazo no funcional, no se reintenta).
     *
     * Diferencia con inspectErrors(): este helper es para el nivel
     * de operacion (por CUIT/request) y maneja 9999 explicitamente.
     * inspectErrors se sigue usando para FECompUltimoAutorizado.
     */
    private function inspectSolicitarErrors(SimpleXMLElement $node, string $op, int $cbteNro): void
    {
        $errs = $node->Errors ?? null;
        if ($errs === null) {
            return;
        }
        $first = null;
        foreach ($errs->Err ?? [] as $e) {
            $first = $e;
            break;
        }
        if ($first === null) {
            return;
        }
        $code = (int) ($first->Code ?? 0);
        $msg  = (string) ($first->Msg ?? '');
        if ($code === RetryPolicy::ARCA_TRANSIENT_CODE) {
            throw new WsfeArcaTransientException(
                "{$op}: error ARCA transitorio (9999) cbte={$cbteNro}",
                [['codigo' => $code, 'mensaje' => $msg]],
                $op,
            );
        }
        throw new WsfeException("{$op}: error ARCA code={$code} msg='{$msg}' cbte={$cbteNro}");
    }

    private function parseConsultarResponse(string $xml, int $puntoVenta, int $cbteTipo, int $cbteNro): ?ComprobanteConsultado
    {
        $sx = $this->safeParse($xml, 'FECompConsultar');
        $result = $this->getOperationResult($sx, 'FECompConsultar');

        // Eventos 9999 -> transitorio.
        $this->inspectEvents($result, 'FECompConsultar');

        // Errors
        $errs = $result->Errors ?? null;
        if ($errs !== null) {
            $code = null; $msg = '';
            foreach ($errs->Err ?? [] as $err) {
                $c = (int) ($err->Code ?? 0);
                if ($code === null) {
                    $code = $c;
                    $msg = (string) ($err->Msg ?? '');
                }
            }
            if ($code === 601) {
                // 601 = "No existe el comprobante"
                return null;
            }
            throw new WsfeException(
                "FECompConsultar: error ARCA code={$code} msg='{$msg}' (pv={$puntoVenta}, tipo={$cbteTipo}, nro={$cbteNro})",
            );
        }

        // El comprobante viene en <Resultado> o en <ResultGet> (wire
        // format real de ARCA; ver comentario mas abajo). Si ninguno
        // de los dos esta presente y la respuesta no es un <Errors>,
        // se trata como "no existe".
        $rNode = $result->Resultado ?? null;
        $rgNode = $result->ResultGet ?? null;
        if ($rNode === null && $rgNode === null) {
            // Respuesta sin <Resultado>, sin <ResultGet> y sin <Errors>
            // -> "no existe" (caso limite; la rama de error 601 ya
            // se manejo arriba en $errs).
            return null;
        }
        // Wire format real de ARCA (homo y prod): los datos viven en
        // <ResultGet> (sibling de <Resultado> en el FECompConsultarResult),
        // y dentro de ResultGet hay un <Resultado> que es solo el ESTADO
        // ('A' aprobado / 'R' rechazado), no el wrapper. Ademas el
        // CAE viene como <CodAutorizacion> (no <CAE>) y el vencimiento
        // como <FchVto> (no <CAEFchVto>).
        //
        // Algunos tests/fixtures legacy mockeaban el formato viejo
        // (<Resultado> con datos directos, <CAE> adentro, <CAEFchVto>).
        // Para no romperlos, aceptamos ambos: si <ResultGet> existe, es
        // el formato real; si no, caemos al formato viejo.
        $resultGet = $result->ResultGet ?? null;
        $resultado = $result->Resultado ?? null;
        $inner = $resultGet ?? $resultado;
        $esFormatoReal = $resultGet !== null;
        if (!isset($inner->CbteDesde) && !isset($inner->CbteHasta)) {
            // Estructura vacia -> "no existe".
            return null;
        }

        $alicuotas = [];
        if (isset($inner->Iva->AlicIva)) {
            foreach ($inner->Iva->AlicIva as $a) {
                $alicuotas[] = [
                    'Id'      => (string) ($a->Id ?? ''),
                    'BaseImp' => (string) ($a->BaseImp ?? '0.00'),
                    'Importe' => (string) ($a->Importe ?? '0.00'),
                ];
            }
        }
        $cbtesAsoc = [];
        if (isset($inner->CbtesAsoc->CbteAsoc)) {
            foreach ($inner->CbtesAsoc->CbteAsoc as $a) {
                $cbtesAsoc[] = [
                    'Tipo'   => (int) ($a->Tipo ?? 0),
                    'PtoVta' => (int) ($a->PtoVta ?? 0),
                    'Nro'    => (int) ($a->Nro ?? 0),
                ];
            }
        }

        $fchServDesde = isset($inner->FchServDesde) && (string) $inner->FchServDesde !== '' ? (int) $inner->FchServDesde : null;
        $fchServHasta = isset($inner->FchServHasta) && (string) $inner->FchServHasta !== '' ? (int) $inner->FchServHasta : null;
        $fchVtoPago   = isset($inner->FchVtoPago)   && (string) $inner->FchVtoPago   !== '' ? (int) $inner->FchVtoPago   : null;

        // Formato real: <CodAutorizacion> y <FchVto>. Formato legacy:
        // <CAE> y <CAEFchVto>.
        $cae      = $esFormatoReal
            ? (isset($inner->CodAutorizacion) && (string) $inner->CodAutorizacion !== '' ? (string) $inner->CodAutorizacion : null)
            : (isset($inner->CAE) && (string) $inner->CAE !== '' ? (string) $inner->CAE : null);
        $caeFchVto = $esFormatoReal
            ? (isset($inner->FchVto) && (string) $inner->FchVto !== '' ? (string) $inner->FchVto : null)
            : (isset($inner->CAEFchVto) && (string) $inner->CAEFchVto !== '' ? (string) $inner->CAEFchVto : null);

        return new ComprobanteConsultado(
            cbteTipo: $cbteTipo,
            puntoVenta: $puntoVenta,
            cbteNro: (int) ($inner->CbteDesde ?? $cbteNro),
            cbteFch: (string) ($inner->CbteFch ?? ''),
            resultado: (string) ($inner->Resultado ?? ''),
            cae: $cae,
            caeFchVto: $caeFchVto,
            concepto: (int) ($inner->Concepto ?? 1),
            receptorDocumentoTipo: (int) ($inner->DocTipo ?? 0),
            receptorDocumentoNro: (string) ($inner->DocNro ?? ''),
            impTotal: (string) ($inner->ImpTotal ?? '0.00'),
            impNeto: (string) ($inner->ImpNeto ?? '0.00'),
            impIva: (string) ($inner->ImpIVA ?? '0.00'),
            impTrib: (string) ($inner->ImpTrib ?? '0.00'),
            impOpEx: (string) ($inner->ImpOpEx ?? '0.00'),
            impTotConc: (string) ($inner->ImpTotConc ?? '0.00'),
            monId: (string) ($inner->MonId ?? 'PES'),
            monCotiz: (string) ($inner->MonCotiz ?? '1.0000'),
            alicIva: $alicuotas,
            cbtesAsoc: $cbtesAsoc,
            fchServDesde: $fchServDesde,
            fchServHasta: $fchServHasta,
            fchVtoPago: $fchVtoPago,
        );
    }

    /**
     * @return array<int, array{codigo:int, mensaje:string}>
     */
    private function parseObservaciones(?SimpleXMLElement $obs): array
    {
        if ($obs === null) {
            return [];
        }
        $out = [];
        foreach ($obs->Obs ?? [] as $o) {
            $out[] = [
                'codigo'  => (int) ($o->Code ?? 0),
                'mensaje' => (string) ($o->Msg ?? ''),
            ];
        }
        return $out;
    }

    private function findDetEntry(?SimpleXMLElement $det, int $cbteNro): SimpleXMLElement
    {
        if ($det === null) {
            throw WsfeProtocolException::structural(
                "FECAESolicitar: respuesta sin FeDetResp (cbte={$cbteNro})",
                null,
            );
        }
        $entries = $det->FECAEDetResponse ?? [];
        if (count($entries) === 0) {
            throw WsfeProtocolException::structural(
                "FECAESolicitar: FeDetResp vacio (cbte={$cbteNro})",
                null,
            );
        }
        foreach ($entries as $e) {
            $desde = (int) ($e->CbteDesde ?? 0);
            $hasta = (int) ($e->CbteHasta ?? 0);
            if ($desde === $cbteNro || $hasta === $cbteNro) {
                return $e;
            }
        }
        throw WsfeProtocolException::structural(
            "FECAESolicitar: FeDetResp no contiene cbte={$cbteNro}",
            null,
        );
    }

    /**
     * Si ARCA devuelve <Events> con 9999 -> WsfeArcaTransientException.
     * Otros codigos de Events: WARN (no bloqueante, no se reintenta,
     * pero el WsfeClient no los inspecciona mas alla del 9999; el
     * orchestrator podria recibirlos via la respuesta normalizada en
     * otros endpoints).
     */
    private function inspectEvents(SimpleXMLElement $node, string $op): void
    {
        $evs = $node->Events ?? null;
        if ($evs === null) {
            return;
        }
        foreach ($evs->Evt ?? [] as $e) {
            $code = (int) ($e->Code ?? 0);
            if ($code === RetryPolicy::ARCA_TRANSIENT_CODE) {
                throw new WsfeArcaTransientException(
                    "{$op}: evento ARCA transitorio (9999)",
                    [['codigo' => $code, 'mensaje' => (string) ($e->Msg ?? '')]],
                    $op,
                );
            }
        }
    }

    /**
     * Si ARCA devuelve <Errors> en ultimoAutorizado/consultar, lo
     * tratamos como WsfeException (rechazo no funcional). 9999 ya se
     * intercepto en inspectEvents.
     */
    private function inspectErrors(SimpleXMLElement $node, string $op): void
    {
        $errs = $node->Errors ?? null;
        if ($errs === null) {
            return;
        }
        $first = null;
        foreach ($errs->Err ?? [] as $e) {
            $first = $e;
            break;
        }
        if ($first === null) {
            return;
        }
        $code = (int) ($first->Code ?? 0);
        $msg  = (string) ($first->Msg ?? '');
        throw new WsfeException("{$op}: error ARCA code={$code} msg='{$msg}'");
    }

    // -------------------------------------------------------------------
    // XML helpers
    // -------------------------------------------------------------------

    /**
     * Parsea el XML SOAP y devuelve el SimpleXMLElement raiz.
     * Detecta HTML, body vacio y 5xx para asignar kind al
     * WsfeProtocolException.
     */
    private function safeParse(string $xml, string $op): SimpleXMLElement
    {
        if (trim($xml) === '') {
            throw WsfeProtocolException::emptyBody($xml);
        }
        // HTML detection rapido antes de cargar el parser.
        $lower = strtolower(ltrim($xml));
        if (str_starts_with($lower, '<!doctype') || str_starts_with($lower, '<html')) {
            throw WsfeProtocolException::htmlGateway($xml);
        }
        if (preg_match('/HTTP\/\d+\.\d+\s+5\d\d/', $xml)) {
            throw WsfeProtocolException::http5xx($xml);
        }

        $prev = libxml_use_internal_errors(true);
        try {
            $sx = simplexml_load_string($xml);
        } catch (Throwable $e) {
            throw WsfeProtocolException::structural(
                "{$op}: XML no parseable: " . $e->getMessage(),
                $this->excerpt($xml),
                $e,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
        if ($sx === false) {
            throw WsfeProtocolException::structural(
                "{$op}: XML invalido",
                $this->excerpt($xml),
            );
        }
        return $sx;
    }

    /**
     * Devuelve el nodo "<Op>Result" dentro del envelope SOAP. Acepta
     * tanto la forma "estricta" (envelope > body > <Op>Response > <Op>Result)
     * como la forma "desnuda" (envelope > body > <Op>Result, cuando
     * SoapClient en WSDL mode ya desenvuelve la respuesta).
     */
    private function getOperationResult(SimpleXMLElement $sx, string $op): SimpleXMLElement
    {
        // Forma desnuda: <Op>Result> hijo directo de <Body>
        $body = $sx->children('http://schemas.xmlsoap.org/soap/envelope/')->Body ?? null;
        if ($body !== null) {
            // Buscar en el default namespace (nuestro re-envelope y
            // algunas respuestas de SoapClient en non-WSDL).
            $direct = $body->children('')->{$op . 'Result'} ?? null;
            if ($direct !== null) {
                return $direct;
            }
            // Buscar en el namespace FEV1 (respuestas soap:WSDL con
            // xmlns="http://ar.gov.afip.dif.FEV1/" en <Op>Response).
            $fev1 = $body->children('http://ar.gov.afip.dif.FEV1/');
            $strict = $fev1->{$op . 'Response'}->{$op . 'Result'} ?? null;
            if ($strict !== null) {
                return $strict;
            }
            // O el <Op>Response> viene con default namespace.
            $strict2 = $body->children('')->{$op . 'Response'}->{$op . 'Result'} ?? null;
            if ($strict2 !== null) {
                return $strict2;
            }
        }
        // Sin envelope: el sx ya es el result directo.
        $root = $sx->children('')->{$op . 'Result'} ?? null;
        if ($root !== null) {
            return $root;
        }
        return $sx;
    }

    private function excerpt(string $xml, int $max = 200): string
    {
        $xml = trim($xml);
        if (strlen($xml) <= $max) {
            return $xml;
        }
        return substr($xml, 0, $max) . '...(truncated)';
    }
}
