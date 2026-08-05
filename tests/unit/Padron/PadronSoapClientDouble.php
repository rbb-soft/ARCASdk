<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\Padron;

use SoapClient;
use SoapFault;

/**
 * Test double para \SoapClient, equivalente al de Wsfe pero
 * configurado para el padron A13 (uri / location por default).
 *
 * Construido en non-WSDL mode (parent recibe null + location/uri),
 * permite inyectar:
 *  - respuestas SOAP crudas (envelope completo, queue FIFO)
 *  - SoapFaults programaticos (queue FIFO)
 *  - respuestas stdClass (cuando ARCA devuelve el resultado
 *    deserializado; usualmente soap.wsdl habilita string, pero
 *    algunos test doubles mockean stdClass)
 *
 * Captura cada request (body, location, action) para que los tests
 * inspeccionen lo que PadronClient envia a ARCA.
 *
 * Tambien expone la ultima respuesta cruda via __getLastResponse()
 * (overrideado) para que PadronClient pueda clasificar la respuesta
 * (http_5xx, html_gateway, empty_body, structural) sin depender de
 * que la libreria SoapClient de PHP conserve el body crudo en todos
 * los caminos (en la practica, la SoapClient nativa NO garantiza
 * __getLastResponse() despues de un SoapFault).
 */
final class PadronSoapClientDouble extends SoapClient
{
    /** @var string[] Cola de respuestas SOAP crudas (envelope). */
    private array $responseQueue = [];

    /** @var SoapFault[] Cola de faults a lanzar. */
    private array $faultQueue = [];

    /** @var array<int, string> Requests capturados. */
    public array $requestBodies = [];

    /** @var array<int, string> Locations de cada request. */
    public array $locations = [];

    /** @var array<int, string|null> Actions de cada request. */
    public array $actions = [];

    /**
     * Ultima respuesta cruda que __doRequest entrego (antes de que
     * el parent la parsee). Sirve para que __getLastResponse() la
     * devuelva incluso cuando el parent tiro SoapFault.
     */
    private ?string $lastRawResponse = null;

    /**
     * Location efectiva (endpoint) que el caller pasa a __doRequest.
     * La guardamos en el constructor para overridear __getLocation()
     * con un valor deterministico, independientemente de lo que el
     * SoapClient PHP nativo exponga (en non-WSDL mode la implementacion
     * nativa a veces devuelve '' o null segun la version de PHP).
     */
    private string $location;

    public int $callCount = 0;

    public function __construct(
        string $location = 'http://test/padron',
        // targetNamespace del WSDL real de personaServiceA13.
        string $uri = 'http://a13.soap.ws.server.puc.sr/',
    ) {
        $this->location = $location;
        parent::__construct(null, [
            'location'     => $location,
            'uri'          => $uri,
            'soap_version' => SOAP_1_1,
        ]);
    }

    public function enqueueResponse(string $soapEnvelopeXml): void
    {
        $this->responseQueue[] = $soapEnvelopeXml;
    }

    public function enqueueFault(SoapFault $fault): void
    {
        $this->faultQueue[] = $fault;
    }

    public function lastRequest(): string
    {
        if (count($this->requestBodies) === 0) {
            return '';
        }
        return $this->requestBodies[count($this->requestBodies) - 1];
    }

    /**
     * Override de SoapClient::__getLastResponse() para devolver la
     * ultima respuesta cruda encolada (incluso si la libreria Soap
     * tiro un SoapFault y por defecto devolveria NULL).
     */
    public function __getLastResponse(): ?string
    {
        return $this->lastRawResponse;
    }

    /**
     * Override de SoapClient::__getLocation() para devolver la location
     * efectiva que el constructor recibio. La implementacion nativa
     * del SoapClient PHP en non-WSDL mode a veces retorna '' o null
     * segun la version, lo que rompia el check del PadronClient. Con
     * este override el caller obtiene la URL del endpoint directo.
     */
    public function __getLocation(): ?string
    {
        return $this->location;
    }

    public function __doRequest(
        $request,
        $location,
        $action,
        $version,
        $one_way = 0,
    ): ?string {
        $this->callCount++;
        $this->requestBodies[] = (string) $request;
        $this->locations[]     = (string) $location;
        $this->actions[]       = $action !== null ? (string) $action : null;

        if (count($this->faultQueue) > 0) {
            $fault = array_shift($this->faultQueue);
            // No tenemos body "real" cuando el fault es inyectado
            // programaticamente; dejamos lastRawResponse en null.
            $this->lastRawResponse = null;
            throw $fault;
        }
        if (count($this->responseQueue) === 0) {
            throw new SoapFault('Server', 'PadronSoapClientDouble: no hay respuestas encoladas');
        }
        $this->lastRawResponse = array_shift($this->responseQueue);
        return $this->lastRawResponse;
    }
}
