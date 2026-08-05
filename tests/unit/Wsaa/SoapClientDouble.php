<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\Wsaa;

use SoapClient;
use SoapFault;

/**
 * Test double para \SoapClient. Construido en non-WSDL mode (parent
 * recibe null + location/uri), permite inyectar:
 *  - respuestas SOAP crudas (nextResponse)
 *  - SoapFault programaticos (nextFault)
 *  - respuestas encoladas (queue)
 *
 * Registra todas las requests que pasaron por __doRequest para que los
 * tests puedan inspeccionar el CMS firmado, la location, la SOAPAction,
 * etc.
 *
 * NO es un test case (no extiende TestCase) para que PHPUnit no lo
 * ejecute como prueba.
 */
final class SoapClientDouble extends SoapClient
{
    /** @var string[] Cola de respuestas SOAP crudas (envoltorio completo). */
    private array $responseQueue = [];

    /** @var SoapFault[] Cola de faults a lanzar. */
    private array $faultQueue = [];

    /** @var array<int, string> Requests capturados. */
    public array $requestBodies = [];

    /** @var array<int, string> Locations de cada request. */
    public array $locations = [];

    /** @var array<int, string|null> Actions de cada request. */
    public array $actions = [];

    public int $callCount = 0;

    public function __construct(string $location = 'http://test/wsaa')
    {
        parent::__construct(null, [
            'location'    => $location,
            'uri'         => 'http://wsaa.view.sua.dvadac.desein.afip.gov',
            'soap_version' => SOAP_1_1,
        ]);
    }

    /** Proxima respuesta SOAP cruda (FIFO). */
    public function enqueueResponse(string $soapEnvelopeXml): void
    {
        $this->responseQueue[] = $soapEnvelopeXml;
    }

    /** Proximo SoapFault a lanzar (FIFO). */
    public function enqueueFault(SoapFault $fault): void
    {
        $this->faultQueue[] = $fault;
    }

    public function setNextResponse(string $soapEnvelopeXml): void
    {
        $this->responseQueue[] = $soapEnvelopeXml;
    }

    public function setNextFault(SoapFault $fault): void
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

    public function __doRequest(
        $request,
        $location,
        $action,
        $version,
        $one_way = 0,
    ): ?string {
        $this->callCount++;
        $this->requestBodies[] = (string) $request;
        $this->locations[] = (string) $location;
        $this->actions[] = $action !== null ? (string) $action : null;

        if (count($this->faultQueue) > 0) {
            $fault = array_shift($this->faultQueue);
            throw $fault;
        }
        if (count($this->responseQueue) === 0) {
            throw new SoapFault('Server', 'SoapClientDouble: no hay respuestas encoladas');
        }
        return array_shift($this->responseQueue);
    }
}
