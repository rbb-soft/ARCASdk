<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Padron;

use Closure;
use Rbbsoft\ArcaSdk\Config\Config;
use Rbbsoft\ArcaSdk\Exceptions\PadronException;
use Rbbsoft\ArcaSdk\Exceptions\PadronProtocolException;
use Rbbsoft\ArcaSdk\Support\RetryPolicy;
use Rbbsoft\ArcaSdk\Wsaa\TicketDeAcceso;
use Rbbsoft\ArcaSdk\Wsaa\WsaaClient;
use SimpleXMLElement;
use SoapClient;
use SoapFault;
use Throwable;

/**
 * Cliente del web service de padron A13 (ws_sr_padron_a13) de
 * ARCA. Encapsula la operacion getPersona y devuelve un DTO Emisor
 * con los datos que el padron expone.
 *
 * Responsabilidades:
 *  - Autenticarse: antes de cada llamada obtiene un TA vigente via el
 *    token provider (WsaaClient::getToken('ws_sr_padron_a13'))
 *    y lo coloca en el body de la operacion (no en un header Auth: el
 *    WSDL A13 no define header de autenticacion, los 4 campos van en
 *    el body directo).
 *  - Mapear CUIT -> request getPersona con los 4 campos
 *    (token, sign, cuitRepresentada, idPersona) en el body.
 *  - Normalizar la respuesta a un Emisor inmutable a partir del
 *    sub-objeto <persona> dentro de <personaReturn>.
 *  - Clasificar malformaciones -> PadronProtocolException con kind
 *    (empty_body | html_gateway | http_5xx | structural | unknown).
 *  - Aplicar la misma politica de retry que WsfeClient a las
 *    excepciones transitorias.
 *
 * NO decide nada sobre persistencia, composicion con datos manuales
 * (logo, email, web) ni generacion de PDF: eso es trabajo de la
 * aplicacion del usuario.
 */
final class PadronClient
{
    /**
     * targetNamespace del WSDL real de personaServiceA13. Es el
     * namespace que ARCA declara en el WSDL y el que deben llevar los
     * elementos <getPersona>/<getPersonaResponse> en el envelope.
     */
    public const NS_PADRON = 'http://a13.soap.ws.server.puc.sr/';

    /**
     * WSN que identifica este servicio en WSAA. No se usa en el body
     * (los 4 campos del request son token/sign/cuitRepresentada/idPersona,
     * no el WSN); lo conservamos como constante para el tokenProvider.
     */
    private const WSN_PADRON = 'ws_sr_padron_a13';

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
        ?string $endpoint = null,
    ) {
        if ($tokenProvider === null && $wsaaClient === null) {
            throw new \InvalidArgumentException(
                'PadronClient requiere tokenProvider (Closure) o wsaaClient (WsaaClient)'
            );
        }
        if ($tokenProvider === null) {
            $wsaa = $wsaaClient;
            $tokenProvider = static fn(string $wsn): TicketDeAcceso => $wsaa->getToken($wsn);
        }
        $this->tokenProvider = $tokenProvider;
        // Endpoint real del web service (sin el ?WSDL del WSDL).
        // SoapClient en WSDL mode NO expone __getLocation() (es un
        // metodo valido solo en non-WSDL mode), asi que el caller tiene
        // que proveer el endpoint para que __doRequest sepa donde mandar.
        $this->endpoint = $endpoint ?? rtrim($this->config->padronUrl, '?WSDL');
    }

    /** @var string */
    private readonly string $endpoint;

    /** @var Closure(string): TicketDeAcceso */
    private readonly Closure $tokenProvider;

    /**
     * getPersona -> Emisor con todos los datos del padron para el CUIT
     * consultado.
     *
     * - Respuesta valida: Emisor inmutable con domicilioFiscal, etc.
     *   (actividades e impuestos quedan en [] porque el WSDL A13 actual
     *   no expone listas; ver docblock de Emisor).
     * - Body vacio, HTML, 5xx, root SOAP inesperado, respuesta sin
     *   <persona>: PadronProtocolException con kind correspondiente.
     * - <soap:Fault> con SRValidationException: PadronException
     *   generico (definitivo, no se reintenta via 9999; el WSDL A13
     *   no expone codigo 9999 en su contrato, los errores funcionales
     *   llegan como SoapFault).
     *
     * @throws PadronException                  En errores funcionales ARCA.
     * @throws PadronProtocolException          En respuestas malformadas.
     */
    public function obtener(int $cuit): Emisor
    {
        $op = function () use ($cuit): Emisor {
            $this->assertSoap();
            $body = $this->buildObtenerBody($cuit);
            $xml  = $this->callSoap('getPersona', $body);
            return $this->parseObtenerResponse($xml, $cuit);
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

    /**
     * Arma el envelope SOAP con el <getPersona> que el WSDL A13
     * declara: 4 elementos en este orden exacto dentro del body
     * (no hay header Auth, el WSDL no lo define):
     *   - <token>             (TA vigente)
     *   - <sign>              (TA vigente)
     *   - <cuitRepresentada>  (CUIT emisor, del Config)
     *   - <idPersona>         (CUIT que se esta consultando)
     */
    private function buildObtenerBody(int $cuit): string
    {
        $ta = ($this->tokenProvider)(self::WSN_PADRON);

        // El WSDL A13 tiene elementFormDefault="unqualified" en el schema:
        // el elemento getPersona va en el namespace a13, pero los hijos
        // (token, sign, cuitRepresentada, idPersona) van SIN namespace
        // (default, {}). Usamos prefijo a13 en getPersona para que los
        // hijos no hereden el namespace.
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<SOAP-ENV:Header/>'
            . '<SOAP-ENV:Body>'
            . '<a13:getPersona xmlns:a13="' . self::NS_PADRON . '">'
            . '<token>' . htmlspecialchars($ta->token, ENT_XML1) . '</token>'
            . '<sign>'  . htmlspecialchars($ta->sign,  ENT_XML1) . '</sign>'
            . '<cuitRepresentada>' . $this->config->cuit . '</cuitRepresentada>'
            . '<idPersona>' . $cuit . '</idPersona>'
            . '</a13:getPersona>'
            . '</SOAP-ENV:Body>'
            . '</SOAP-ENV:Envelope>';
    }

    // -------------------------------------------------------------------
    // Call helper
    // -------------------------------------------------------------------

    /**
     * Lanza si el SoapClient no fue inyectado (modo produccion usa
     * PadronSoapClientFactory; tests inyectan directamente).
     */
    private function assertSoap(): void
    {
        if ($this->soap === null) {
            throw new \LogicException('PadronClient requiere SoapClient inyectado o fabrica externa');
        }
    }

    /**
     * Envia el envelope SOAP y devuelve el body de la respuesta.
     * Captura SoapFaults y los traduce a PadronException con kind
     * cuando es posible.
     *
     * Devuelve un envelope SOAP equivalente al que ARCA retornaria, para
     * que el resto del pipeline pueda usar simplexml_load_string() sin
     * preocuparse por el modo del SoapClient (WSDL vs non-WSDL, string
     * vs stdClass).
     *
     * Implementacion: __doRequest directo (no __soapCall). Razon: el
     * SoapClient de PHP en non-WSDL mode, cuando recibe un array
     * asociativo de parametros, reconstruye el body a partir de la
     * firma del WSDL y descarta el envelope custom que el caller
     * construyo. Con __doRequest el caller controla el XML exacto que
     * va al socket. Es el mismo bug que tuvo WsfeClient (sesion 2) y
     * se arreglo reemplazando __soapCall por __doRequest directo.
     */
    private function callSoap(string $operation, string $body): string
    {
        // El WSDL A13 declara <soap:operation soapAction=""/>; un
        // SOAPAction vacio es el contrato contra ARCA, no es un descuido.
        // one_way=false porque getPersona espera respuesta.
        try {
            // El endpoint real NO se obtiene via __getLocation() porque
            // SoapClient en WSDL mode no expone ese metodo (tira SoapFault
            // "Function is not a valid method for this service"). En su
            // lugar usamos el endpoint del Config (sin el ?WSDL) que se
            // setea en el constructor. Tambien sirve como override en
            // tests que necesiten apuntar a otro endpoint.
            $response = $this->soap->__doRequest($body, $this->endpoint, '', SOAP_1_1, false);
            // __doRequest NO parsea la respuesta: si ARCA devolvio un
            // <soap:Fault> (HTTP 500 con body Fault, caso tipico del
            // WSDL A13 cuando rechaza la operacion), lo vemos como un
            // string comun. Detectamos el caso y lo sintetizamos como
            // SoapFault para que el flujo normal (classifySoapFault)
            // lo procese uniformemente. Mismo patron que WsfeClient.
            if (is_string($response) && $this->responseIsSoapFault($response)) {
                throw $this->makeSoapFaultFromResponse($response);
            }
        } catch (SoapFault $e) {
            // Pasamos el $body del request y la $response cruda (si
            // la tenemos) para que classifySoapFault pueda clasificar
            // sin depender de __getLastResponse() (que en WSDL mode
            // con __doRequest directo queda null).
            $rawResponse = is_string($response ?? null) ? $response : null;
            throw $this->classifySoapFault($e, $operation, $body, $rawResponse);
        } catch (Throwable $e) {
            throw $e;
        }

        if ($response === null) {
            // __doRequest devolvio null: caso limite (red rota, etc.).
            // Clasificamos el body crudo si esta disponible.
            $raw = $this->getLastRawResponse();
            throw PadronProtocolException::emptyBody($raw ?? '');
        }
        if (is_string($response) && trim($response) === '') {
            throw PadronProtocolException::emptyBody($response);
        }
        if (is_string($response)) {
            // Clasificacion de la respuesta cruda antes de pasarla al
            // parser. Esto reemplaza la logica de "if ($response ===
            // null)" que tenia el flujo __soapCall (donde el
            // SoapClient parseaba y descartaba envelopes vacios o con
            // root inesperado, devolviendo null). Con __doRequest
            // recibimos el envelope siempre, asi que clasificamos
            // aca:
            //   - HTTP 5xx en el body        -> http_5xx
            //   - body con HTML (502 gateway) -> htmlGateway
            //   - envelope vacio / malformed  -> empty_body
            //   - body con root no-A13        -> structural
            //   - soap fault                  -> soap fault (ya
            //                                    interceptado arriba)
            //
            // El check de 5xx va PRIMERO porque el body de un 502/503
            // suele ser HTML, y no queremos perder la semantica de
            // "transitorio" por clasificarlo como html_gateway.
            if (preg_match('/HTTP\/\d+\.\d+\s+5\d\d/', $response)) {
                throw PadronProtocolException::http5xx($response);
            }
            $low = strtolower($response);
            if (str_contains($low, '<html')
                || str_contains($low, '<!doctype')
            ) {
                throw PadronProtocolException::htmlGateway($response);
            }
            $root = $this->classifySoapBodyRoot($response);
            if ($root === 'empty' || $root === 'malformed') {
                throw PadronProtocolException::emptyBody($response);
            }
            if ($root === 'unexpected') {
                throw PadronProtocolException::structural(
                    "PADRON {$operation}: respuesta SOAP con Body de root inesperado (no es operacion A13)",
                    $this->excerpt($response),
                );
            }
            return $response;
        }
        // __doRequest siempre devuelve ?string; cualquier otro tipo es
        // estructural (defensivo, no se ha observado en la practica).
        throw PadronProtocolException::structural(
            "PADRON {$operation}: respuesta con tipo inesperado (" . gettype($response) . ')',
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
            $msg = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if ($code === '' && $msg === '') {
            return new SoapFault('Server', 'PADRON: respuesta con Fault sin faultcode/faultstring parseable');
        }
        return new SoapFault($code, $msg);
    }

    private function classifySoapFault(SoapFault $e, string $op, string $request, ?string $rawBody = null): PadronException
    {
        $code = (string) $e->faultcode;
        $msg  = $e->getMessage();

        // El raw body puede llegar como parametro (caso tipico con
        // __doRequest directo, donde __getLastResponse() queda null
        // porque SoapClient en WSDL mode no almacena la respuesta
        // cuando el caller la obtiene via __doRequest). Si no llega,
        // caemos al getLastRawResponse() (caso SoapFault nativo).
        $rawBody ??= $this->getLastRawResponse();

        // 1) HTTP/1.1 5xx en el body crudo -> transitorio.
        if ($rawBody !== null
            && $rawBody !== ''
            && preg_match('/HTTP\/\d+\.\d+\s+5\d\d/', $rawBody)
        ) {
            return PadronProtocolException::http5xx($rawBody, $e);
        }

        // 2) Body crudo vacio o solo whitespace -> empty_body.
        if ($rawBody === null || trim($rawBody) === '') {
            return PadronProtocolException::emptyBody($rawBody ?? $msg, $e);
        }

        // 3) HTML real en el body.
        $low = strtolower($rawBody);
        if (str_contains($low, '<html')
            || str_contains($low, '<!doctype')
            || str_contains($low, '<body')
        ) {
            return PadronProtocolException::htmlGateway($rawBody, $e);
        }

        // 4) SOAP envelope con Body presente. Clasificamos el root del
        //    Body una sola vez y lo reutilizamos en las ramas siguientes.
        $root = $this->classifySoapBodyRoot($rawBody);

        // 4.5) Body crudo trae <soap:Fault> como root: es un rechazo
        //      funcional de ARCA, NO un problema de protocolo del SDK.
        //      El WSDL A13 define el fault SRValidationException para
        //      CUIT no encontrado, sin permisos, etc. Traducimos a
        //      PadronException generico con el faultstring accionable
        //      para que el operador sepa que ARCA rechazo la operacion
        //      y por que (en lugar de etiquetarlo como
        //      PadronProtocolException::structural, que apunta al
        //      wrapper del SDK cuando el problema es del backend).
        if ($root === 'fault') {
            return new PadronException(
                sprintf('PADRON %s SoapFault [%s]: %s', $op, $code, $msg),
                0,
                $e,
            );
        }

        if ($root === 'empty') {
            return PadronProtocolException::emptyBody($rawBody, $e);
        }
        if ($root === 'unexpected') {
            return PadronProtocolException::structural(
                "PADRON {$op}: respuesta SOAP con Body de root inesperado (no es operacion A13)",
                $this->excerpt($rawBody),
                $e,
            );
        }

        // 5) Fallback: SoapFault con faultcode 'HTTP' y mensaje 5xx.
        if (strcasecmp($code, 'HTTP') === 0
            && preg_match('/HTTP\/\d+\.\d+\s+5\d\d/', $msg)
        ) {
            return PadronProtocolException::http5xx($msg, $e);
        }

        // 6) Resto: PadronException generico. Caso residual: el body
        //    no es Fault, no es empty, no es html, no es 5xx, pero el
        //    SoapFault tiene un faultcode que sugiere HTTP 5xx o un
        //    marcado equivalente. Lo propagamos como PadronException
        //    para que el caller lo capture y reporte; el retry queda
        //    en manos de la RetryPolicy segun isTransient().
        return new PadronException(
            sprintf('PADRON %s SoapFault [%s]: %s', $op, $code, $msg),
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
     *   - 'a13'        -> <Body><getPersonaResponse>...valido A13
     *   - 'fault'      -> <Body><soap:Fault>...</soap:Fault> (rechazo
     *                     funcional de ARCA, NO problema de protocolo)
     *   - 'unexpected' -> <Body> con un root que NO es operacion A13
     *                     ni Fault (envelope estructuralmente invalido
     *                     o respuesta de un servicio que no es A13)
     *   - 'malformed'  -> no es SOAP parseable, o falta el Body
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
        // Contar cualquier hijo (en cualquier namespace) — el memory del
        // proyecto documenta que body->children() solo cuenta hijos en el
        // default namespace, y un <ns2:getPersonaResponse> con namespace
        // daba count=0 y se clasificaba como empty falso. Mismo bug que
        // tuvo WsfeClient y que ya se arreglo ahi.
        if (count($body->xpath('./*')) === 0) {
            return 'empty';
        }
        // <soap:Fault> es un root ESPERADO del protocolo SOAP (no es un
        // problema de estructura). Cuando ARCA rechaza una operacion con
        // un fault funcional (ej. SRValidationException), el body trae
        // un <soap:Fault> como unico hijo, en el namespace SOAP. Hay que
        // distinguirlo de un body con root arbitrario (kind=unexpected)
        // para que classifySoapFault() lo traduzca a PadronException y no
        // a PadronProtocolException::structural.
        //
        // Chequeamos en el namespace SOAP explicito y, en default
        // namespace, un hijo literalmente llamado "Fault" (defensivo:
        // SimpleXML con prefijo soap: a veces expone el hijo como
        // "Fault" directamente en body->children() segun la version).
        if (isset($body->Fault) || $body->children('http://schemas.xmlsoap.org/soap/envelope/')->Fault !== null) {
            return 'fault';
        }
        // Cualquier hijo del Body bajo el namespace A13 es reconocido
        // como respuesta valida de una operacion ARCA.
        $a13 = $body->children(self::NS_PADRON);
        foreach ($a13 as $_) {
            return 'a13';
        }
        // Tambien aceptar <getPersonaResponse> sin namespace explicito.
        $defaultNs = $body->children('');
        foreach ($defaultNs as $name => $_) {
            if (str_ends_with($name, 'Response')) {
                return 'a13';
            }
        }
        return 'unexpected';
    }

    // -------------------------------------------------------------------
    // Response parser
    // -------------------------------------------------------------------

    /**
     * Parsea el envelope de la respuesta y construye el Emisor a partir
     * del sub-objeto <persona> dentro de <personaReturn>. El path es:
     *   <soap:Body>
     *     <getPersonaResponse>
     *       <personaReturn>
     *         <metadata>...</metadata>
     *         <persona>...</persona>
     *       </personaReturn>
     *     </getPersonaResponse>
     *   </soap:Body>
     */
    private function parseObtenerResponse(string $xml, int $cuit): Emisor
    {
        $sx = $this->safeParse($xml, 'getPersona');
        $personaReturn = $this->locatePersonaReturn($sx);

        // <personaReturn> tiene <metadata> y <persona>. Los datos del
        // emisor viven en <persona>; <metadata> (fechaHora/servidor)
        // se ignora por ahora. Tambien aqui aplica elementFormDefault=
        // "unqualified" del WSDL A13: <persona> vive en default
        // namespace, no en a13.
        $persona = $personaReturn->children('')->persona ?? null;
        if ($persona === null) {
            throw PadronProtocolException::structural(
                'getPersona: respuesta sin <persona> dentro de <personaReturn>',
                $this->excerpt($xml),
            );
        }

        return Emisor::fromArray($this->personaToArray($persona, $cuit));
    }

    /**
     * Localiza el nodo <personaReturn> tanto en una respuesta
     * "estricta" (envelope > body > getPersonaResponse > personaReturn)
     * como en la forma "desnuda" que produce SoapClient en WSDL mode.
     */
    private function locatePersonaReturn(SimpleXMLElement $sx): SimpleXMLElement
    {
        // Forma estricta: <soap:Body><getPersonaResponse>...
        $body = $sx->children('http://schemas.xmlsoap.org/soap/envelope/')->Body ?? null;
        if ($body !== null) {
            $a13 = $body->children(self::NS_PADRON);
            $gp = $a13->getPersonaResponse ?? null;
            if ($gp !== null) {
                // WSDL A13 tiene elementFormDefault="unqualified":
                // personaReturn vive en default namespace, no en a13.
                $pr = $gp->children('')->personaReturn ?? null;
                if ($pr !== null) {
                    return $pr;
                }
            }
            // Tambien aceptar getPersonaResponse en default namespace
            // (algunos servidores no lo prefijan).
            $default = $body->children('');
            $gp2 = $default->getPersonaResponse ?? null;
            if ($gp2 !== null) {
                $pr = $gp2->children('')->personaReturn ?? null;
                if ($pr !== null) {
                    return $pr;
                }
            }
        }
        // Forma desnuda: el sx ya es el personaReturn directo.
        $pr = $sx->children('')->personaReturn ?? null;
        if ($pr !== null) {
            return $pr;
        }
        // Estructura presente pero sin personaReturn: lo que tenemos
        // puede ser un getPersonaResponse o un envelope minimalista.
        $pr = $sx->children(self::NS_PADRON)->personaReturn ?? null;
        if ($pr !== null) {
            return $pr;
        }
        throw PadronProtocolException::structural(
            'getPersona: respuesta sin personaReturn',
            null,
        );
    }

    /**
     * Convierte el sub-objeto <persona> del WSDL A13 a un array
     * asociativo que Emisor::fromArray() consume. Aplana los campos
     * directos (apellido, nombre, razonSocial, estadoClave, etc.) y
     * el primer <domicilio> de la lista.
     *
     * @return array<string, mixed>
     */
    private function personaToArray(SimpleXMLElement $persona, int $cuit): array
    {
        $out = [
            'idPersona' => $this->intOrNull($persona->idPersona) ?? $cuit,
        ];
        // Campos directos del <persona> segun el WSDL A13.
        foreach ([
            'apellido', 'nombre', 'razonSocial', 'tipoPersona', 'estadoClave',
            'tipoClave', 'tipoDocumento', 'numeroDocumento', 'formaJuridica',
            'idActividadPrincipal', 'descripcionActividadPrincipal',
            'periodoActividadPrincipal', 'fechaNacimiento', 'fechaContratoSocial',
        ] as $field) {
            $val = $persona->{$field} ?? null;
            if ($val !== null) {
                $out[$field] = trim((string) $val);
            }
        }
        // mesCierre viene como xs:int.
        $mesCierre = $this->intOrNull($persona->mesCierre ?? null);
        if ($mesCierre !== null) {
            $out['mesCierre'] = $mesCierre;
        }
        // <domicilio> es una lista (maxOccurs="unbounded"); tomamos el
        // primero. Si la lista viene vacia, no se setea la clave y
        // Emisor::fromArray() cae al default (todos los campos null).
        //
        // Mapeos de campos del WSDL A13 al DTO DomicilioFiscal (que
        // conserva los nombres historicos del WSDL A5 para no romper
        // la API publica de los callers pre-v0.3.1):
        //   - idProvincia     -> provincia
        //   - oficinaDptoLocal -> departamento
        $domicilios = $persona->domicilio ?? [];
        $first = null;
        foreach ($domicilios as $d) {
            $first = $d;
            break;
        }
        if ($first !== null) {
            $domicilioMap = $this->extractChildMap($first, null);
            if (isset($domicilioMap['idProvincia']) && !isset($domicilioMap['provincia'])) {
                $domicilioMap['provincia'] = $domicilioMap['idProvincia'];
            }
            if (isset($domicilioMap['oficinaDptoLocal']) && !isset($domicilioMap['departamento'])) {
                $domicilioMap['departamento'] = $domicilioMap['oficinaDptoLocal'];
            }
            $out['domicilio'] = $domicilioMap;
        }
        return $out;
    }

    /**
     * Extrae los hijos de primer nivel de un sub-nodo como mapa
     * asociativo. Devuelve [] si el sub-nodo no existe. Si $name es
     * null, extrae los hijos del nodo recibido.
     *
     * @return array<string, mixed>
     */
    private function extractChildMap(SimpleXMLElement $parent, ?string $name): array
    {
        $node = $name === null ? $parent : ($parent->{$name} ?? null);
        if ($node === null) {
            return [];
        }
        $out = [];
        foreach ($node->children() as $k => $v) {
            $out[(string) $k] = $this->stringifyNode($v);
        }
        return $out;
    }

    /**
     * Convierte un nodo SimpleXMLElement a su representacion escalar.
     * Si tiene hijos, devuelve el XML serializado (defensivo); si no,
     * devuelve el texto como string.
     */
    private function stringifyNode(SimpleXMLElement $node): string
    {
        if (count($node->children()) > 0) {
            $xml = $node->asXML();
            return $xml === false ? '' : $xml;
        }
        return trim((string) $node);
    }

    private function intOrNull(mixed $v): ?int
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        return (int) $s;
    }

    // -------------------------------------------------------------------
    // XML helpers
    // -------------------------------------------------------------------

    /**
     * Parsea el XML SOAP y devuelve el SimpleXMLElement raiz. Detecta
     * HTML, body vacio y 5xx para asignar kind al PadronProtocolException.
     */
    private function safeParse(string $xml, string $op): SimpleXMLElement
    {
        if (trim($xml) === '') {
            throw PadronProtocolException::emptyBody($xml);
        }
        // HTML detection rapido antes de cargar el parser.
        $lower = strtolower(ltrim($xml));
        if (str_starts_with($lower, '<!doctype') || str_starts_with($lower, '<html')) {
            throw PadronProtocolException::htmlGateway($xml);
        }
        if (preg_match('/HTTP\/\d+\.\d+\s+5\d\d/', $xml)) {
            throw PadronProtocolException::http5xx($xml);
        }

        $prev = libxml_use_internal_errors(true);
        try {
            $sx = simplexml_load_string($xml);
        } catch (Throwable $e) {
            throw PadronProtocolException::structural(
                "{$op}: XML no parseable: " . $e->getMessage(),
                $this->excerpt($xml),
                $e,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
        if ($sx === false) {
            throw PadronProtocolException::structural(
                "{$op}: XML invalido",
                $this->excerpt($xml),
            );
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
