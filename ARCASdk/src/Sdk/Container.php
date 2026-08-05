<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Sdk;

use Closure;
use DateTimeImmutable;
use PDO;
use Rbbsoft\ArcaSdk\Config\Config;
use Rbbsoft\ArcaSdk\Idempotencia\IdempotenciaRepository;
use Rbbsoft\ArcaSdk\Lock\LockManager;
use Rbbsoft\ArcaSdk\Padron\PadronClient;
use Rbbsoft\ArcaSdk\Pdf\ComprobantePdfGenerator;
use Rbbsoft\ArcaSdk\Support\RetryPolicy;
use Rbbsoft\ArcaSdk\Time\Clock;
use Rbbsoft\ArcaSdk\Wsaa\MysqlTicketCache;
use Rbbsoft\ArcaSdk\Wsaa\TicketCacheInterface;
use Rbbsoft\ArcaSdk\Wsaa\WsaaClient;
use Rbbsoft\ArcaSdk\Wsfe\WsfeClient;
use SoapClient;

/**
 * Contenedor liviano de dependencias del orquestador (Phase 6).
 *
 * Reune las piezas cableadas que ArcaSdk necesita:
 *  - PDO principal (lectura/escritura de las dos tablas)
 *  - LockManager (named locks por (cuit, pv, tipo))
 *  - IdempotenciaRepository (FSM CAS sobre la tabla de emisiones)
 *  - WsaaClient + cache MySQL (compartido entre workers)
 *  - WsfeClient (operaciones de negocio sobre WSFE)
 *  - Clock (inyectable para tests)
 *
 * El default constructor cablea todo desde Config (modo produccion).
 * Tests pueden pasar piezas individuales via `with*` setters (fluent).
 *
 * Decisiones:
 *  - NO se usa un contenedor de IoC externo (Pimple, Symfony DI, etc.)
 *    por blast-radius: el SDK es chico y un contenedor de ~150 lineas
 *    es mas facil de auditar que una libreria extra.
 *  - Los setters devuelven $this para fluent setup en tests.
 *  - El PDO es inyectable. Si nadie lo inyecta, el SDK lo construye
 *    automáticamente desde Config::dbDsn / dbUser / dbPass / dbPersistent
 *    (default derivado). Para control total del ciclo de vida, usar
 *    withPdo() — la inyección gana sobre el default.
 *  - La pieza "cache de dos niveles" que menciona el brief se reduce
 *    a un MysqlTicketCache (L2); el L1 en memoria vive como flag
 *    propio en ArcaSdk, no como capa separada. Razon: el L1
 *    es opcional y esta atado al ciclo de vida del Singleton, no
 *    al cache compartido entre workers.
 *  - La fabricacion de SoapClients se hace dentro de un closure
 *    inyectable (withSoapFactory) para que los tests puedan pasar
 *    dobles sin tocar red. El default construye un SoapClient en
 *    non-WSDL para WSFE y en WSDL para WSAA, ambos con el
 *    soap_timeout del config.
 */
final class Container
{
    private ?PDO $pdo = null;
    private ?LockManager $lockManager = null;
    private ?IdempotenciaRepository $idempotenciaRepository = null;
    private ?WsaaClient $wsaaClient = null;
    private ?WsfeClient $wsfeClient = null;
    private ?PadronClient $padronClient = null;
    private ?TicketCacheInterface $ticketCache = null;
    private ?Closure $ticketCacheFactory = null;
    private ?Closure $soapFactory = null;
    private ?Clock $clock = null;
    private ?RetryPolicy $retryPolicy = null;
    private ?ComprobantePdfGenerator $comprobantePdfGenerator = null;

    public function __construct(
        private readonly Config $config,
    ) {
    }

    public function config(): Config
    {
        return $this->config;
    }

    /**
     * Devuelve (y opcionalmente inyecta) el PDO principal del SDK.
     *
     * Si se pasa un PDO como argumento, se inyecta y se devuelve. Si no,
     * devuelve el PDO previamente inyectado. Si nunca se inyectó, lanza
     * {@see \LogicException}.
     *
     * Para preguntar si hay un PDO inyectado sin lanzar, usar
     * {@see self::hasPdo()}.
     *
     * @param PDO|null $pdo PDO a inyectar. Si `null`, no se modifica el
     *                       PDO actual; solo se devuelve.
     *
     * @return PDO El PDO actualmente inyectado.
     *
     * @throws \LogicException Si nunca se inyectó un PDO.
     */
    public function pdo(?PDO $pdo = null): PDO
    {
        if ($pdo !== null) {
            $this->pdo = $pdo;
        }
        if ($this->pdo === null) {
            throw new \LogicException('Container: PDO no inyectado');
        }
        return $this->pdo;
    }

    public function withPdo(PDO $pdo): self
    {
        $this->pdo = $pdo;
        return $this;
    }

    /**
     * Indica si el Container tiene un PDO inyectado.
     *
     * A diferencia de {@see self::pdo()}, este método NO lanza excepción
     * cuando el PDO no está presente. Útil para preguntar antes de
     * intentar usar el PDO, y para tests que necesitan asertar sobre el
     * cableado.
     *
     * @return bool `true` si hay un PDO inyectado, `false` en caso contrario.
     */
    public function hasPdo(): bool
    {
        return $this->pdo !== null;
    }

    /**
     * Devuelve (y construye lazy) el LockManager. La factoria de la
     * conexion se arma una sola vez con el DSN del config.
     */
    public function lockManager(): LockManager
    {
        if ($this->lockManager === null) {
            $factory = $this->defaultLockPdoFactory();
            $this->lockManager = new LockManager($factory);
        }
        return $this->lockManager;
    }

    public function withLockManager(LockManager $lm): self
    {
        $this->lockManager = $lm;
        return $this;
    }

    public function clock(): Clock
    {
        return $this->clock ??= new Clock();
    }

    public function withClock(Clock $c): self
    {
        $this->clock = $c;
        // Al cambiar el clock hay que re-armar las piezas que lo usaron.
        $this->idempotenciaRepository = null;
        $this->ticketCache = null;
        return $this;
    }

    public function retryPolicy(): RetryPolicy
    {
        return $this->retryPolicy ??= new RetryPolicy();
    }

    public function withRetryPolicy(RetryPolicy $rp): self
    {
        $this->retryPolicy = $rp;
        // WsfeClient y PadronClient capturan el retry policy en su
        // constructor; cambiarlos requiere reconstruir.
        $this->wsfeClient = null;
        $this->padronClient = null;
        return $this;
    }

    public function idempotenciaRepository(): IdempotenciaRepository
    {
        if ($this->idempotenciaRepository === null) {
            $pdo = $this->pdo();
            $self = $this;
            $clockFn = static function () use ($self): DateTimeImmutable {
                return $self->clock()->now();
            };
            $this->idempotenciaRepository = new IdempotenciaRepository(
                $pdo,
                $this->config,
                null,
                $clockFn
            );
        }
        return $this->idempotenciaRepository;
    }

    public function withIdempotenciaRepository(IdempotenciaRepository $r): self
    {
        $this->idempotenciaRepository = $r;
        return $this;
    }

    public function wsaaClient(): WsaaClient
    {
        if ($this->wsaaClient === null) {
            $soap = ($this->soapFactory ?? $this->defaultSoapFactory())(
                $this->config->wsaaUrl,
                true
            );
            $cache = $this->ticketCache();
            $self = $this;
            $clockFn = static function () use ($self): DateTimeImmutable {
                return $self->clock()->now();
            };
            $this->wsaaClient = new WsaaClient(
                $this->config,
                $soap,
                $cache,
                $clockFn
            );
        }
        return $this->wsaaClient;
    }

    public function withWsaaClient(WsaaClient $c): self
    {
        $this->wsaaClient = $c;
        return $this;
    }

    public function wsfeClient(): WsfeClient
    {
        if ($this->wsfeClient === null) {
            $soap = ($this->soapFactory ?? $this->defaultSoapFactory())(
                $this->config->wsfeUrl,
                false
            );
            $wsaa = $this->wsaaClient();
            $this->wsfeClient = new WsfeClient(
                $this->config,
                null,
                $wsaa,
                $this->retryPolicy(),
                $soap,
            );
        }
        return $this->wsfeClient;
    }

    public function withWsfeClient(WsfeClient $c): self
    {
        $this->wsfeClient = $c;
        return $this;
    }

    /**
     * Devuelve (y construye lazy) el PadronClient. Replica el patron de
     * wsfeClient(): el SoapClient se construye en WSDL mode contra la
     * URL del padron, y la autenticacion se reutiliza del wsaaClient
     * ya cacheado (el mismo certificado sirve para WSFE y para
     * padron; el WSN se pasa explicito al pedir el TA).
     */
    public function padronClient(): PadronClient
    {
        if ($this->padronClient === null) {
            $soap = ($this->soapFactory ?? $this->defaultSoapFactory())(
                $this->config->padronUrl,
                true
            );
            $wsaa = $this->wsaaClient();
            $this->padronClient = new PadronClient(
                $this->config,
                null,
                $wsaa,
                $this->retryPolicy(),
                $soap,
            );
        }
        return $this->padronClient;
    }

    public function withPadronClient(PadronClient $c): self
    {
        $this->padronClient = $c;
        return $this;
    }

    public function ticketCache(): TicketCacheInterface
    {
        if ($this->ticketCache === null) {
            $this->ticketCache = $this->ticketCacheFactory
                ? ($this->ticketCacheFactory)($this)
                : $this->defaultTicketCache();
        }
        return $this->ticketCache;
    }

    public function withTicketCache(TicketCacheInterface $c): self
    {
        $this->ticketCache = $c;
        return $this;
    }

    public function withTicketCacheFactory(Closure $factory): self
    {
        $this->ticketCacheFactory = $factory;
        $this->ticketCache = null;
        return $this;
    }

    public function withSoapFactory(Closure $factory): self
    {
        $this->soapFactory = $factory;
        $this->wsaaClient = null;
        $this->wsfeClient = null;
        $this->padronClient = null;
        return $this;
    }

    /**
     * Devuelve (y construye lazy) el generador de PDFs de comprobantes.
     * Sin argumentos: usa el directorio por defecto `comprobantes/`
     * relativo a `getcwd()`. Si el caller necesita un destino custom
     * (por ejemplo, en un job queue que organiza PDFs por tenant),
     * puede inyectarlo con {@see self::withComprobantePdfGenerator()}
     * o construir su propio `ComprobantePdfGenerator` directamente
     * (es una pieza sin estado mas alla del directorio destino).
     */
    public function comprobantePdfGenerator(): ComprobantePdfGenerator
    {
        return $this->comprobantePdfGenerator ??= new ComprobantePdfGenerator();
    }

    public function withComprobantePdfGenerator(ComprobantePdfGenerator $g): self
    {
        $this->comprobantePdfGenerator = $g;
        return $this;
    }

    /**
     * Cache por defecto: MysqlTicketCache (L2 compartido entre workers).
     * El L1 en memoria vive en ArcaSdk, no aqui.
     */
    private function defaultTicketCache(): TicketCacheInterface
    {
        $pdo = $this->pdo();
        $lockFactory = $this->defaultLockPdoFactory();
        $self = $this;
        $clockFn = static function () use ($self): DateTimeImmutable {
            return $self->clock()->now();
        };
        return new MysqlTicketCache(
            $pdo,
            $lockFactory,
            expiryMarginSeconds: $this->config->wsaaExpiryMargin,
            lockTimeoutSeconds: $this->config->wsaaLockTimeout,
            clock: $clockFn,
        );
    }

    /**
     * Factoria de PDO NO persistente para locks (WSAA + emision).
     * Centralizada: la usan LockManager, MysqlTicketCache y
     * el cache de TA por default.
     */
    private function defaultLockPdoFactory(): Closure
    {
        $dsn = $this->config->dbDsn;
        $user = $this->config->dbUser;
        $pass = $this->config->dbPass;
        return static function () use ($dsn, $user, $pass): PDO {
            return new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_PERSISTENT => false,
            ]);
        };
    }

    /**
     * SoapClient default: WSDL mode para WSAA y padron, non-WSDL para WSFE.
     * Cache WSDL: en homo desactivado, en prod disk cache (politica
     * documentada en src/Wsfe/SoapClientFactory.php; replicada aca
     * para que el Container no dependa de esa clase cuando no hace
     * falta).
     *
     * @return Closure(string, bool): SoapClient
     */
    private function defaultSoapFactory(): Closure
    {
        $env = $this->config->env;
        $timeout = $this->config->soapTimeout;
        return static function (string $url, bool $wsdl) use ($env, $timeout): SoapClient {
            $opts = [
                'soap_version'       => SOAP_1_1,
                'connection_timeout' => $timeout,
                // trace=1 es OBLIGATORIO para que __getLastResponse()
                // devuelva el body real cuando el servidor responde
                // con un SoapFault. Sin esto, en fallos HTTP 5xx o
                // soap:Client, el clasificador de WsfeClient termina
                // marcando todo como "empty_body" porque recibe null
                // y pierde la capacidad de distinguir entre 5xx real
                // (transitorio, reintentable) y fault estructural.
                'trace'              => 1,
            ];
            if ($wsdl) {
                $opts['cache_wsdl'] = $env === Config::ENV_HOMO ? WSDL_CACHE_NONE : WSDL_CACHE_DISK;
                // Las constantes URL_WSAA_* y URL_WSFE_* no incluyen
                // `?WSDL` (lo agregamos aca). URL_PADRON_* SI lo
                // incluye; toleramos ambas formas para no duplicar el
                // query string.
                $wsdlUrl = str_contains($url, '?WSDL') ? $url : ($url . '?WSDL');
                return new SoapClient($wsdlUrl, $opts);
            }
            // Non-WSDL para WSFE: SoapClient recibe location + uri.
            $opts['location'] = $url;
            $opts['uri']      = 'http://ar.gov.afip.dif.FEV1/';
            return new SoapClient(null, $opts);
        };
    }
}
