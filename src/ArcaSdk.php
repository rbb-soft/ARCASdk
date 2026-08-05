<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Rbbsoft\ArcaSdk\Auditoria\ResetAuditLogger;
use Rbbsoft\ArcaSdk\Config\Config;
use Rbbsoft\ArcaSdk\Exceptions\CbteRechazadoException;
use Rbbsoft\ArcaSdk\Exceptions\ConfigException;
use Rbbsoft\ArcaSdk\Exceptions\EmisionEnCursoException;
use Rbbsoft\ArcaSdk\Exceptions\IdempotencyConflictException;
use Rbbsoft\ArcaSdk\Exceptions\IdempotencyStateException;
use Rbbsoft\ArcaSdk\Exceptions\MaxIdempotencyAttemptsException;
use Rbbsoft\ArcaSdk\Exceptions\PadronArcaTransientException;
use Rbbsoft\ArcaSdk\Exceptions\PadronException;
use Rbbsoft\ArcaSdk\Exceptions\PadronProtocolException;
use Rbbsoft\ArcaSdk\Exceptions\ValidationException;
use Rbbsoft\ArcaSdk\Exceptions\WsaaException;
use Rbbsoft\ArcaSdk\Exceptions\WsfeException;
use Rbbsoft\ArcaSdk\Exceptions\ZombieRecoveryFailedException;
use Rbbsoft\ArcaSdk\Sdk\Container;
use Rbbsoft\ArcaSdk\Idempotencia\FilaEmision;
use Rbbsoft\ArcaSdk\Idempotencia\IdempotenciaRepository;
use Rbbsoft\ArcaSdk\Idempotencia\UuidFactory;
use Rbbsoft\ArcaSdk\Lock\LockManager;
use Rbbsoft\ArcaSdk\Padron\Emisor;
use Rbbsoft\ArcaSdk\Support\RetryPolicy;
use Rbbsoft\ArcaSdk\Wsfe\Comprobante;
use Rbbsoft\ArcaSdk\Wsfe\ComprobanteEmitido;
use Rbbsoft\ArcaSdk\Wsfe\ComprobanteResponse;
use Rbbsoft\ArcaSdk\Wsfe\IvaCalculator;
use Rbbsoft\ArcaSdk\Wsfe\TiposComprobante;
use Throwable;

/**
 * Singleton publico y orquestador del flujo de emision.
 *
 * Reune las piezas de las fases 1-5 detras de una API minima:
 *
 *     $sdk = ArcaSdk::getInstance($config);
 *     $sdk->emitirFactura($externalId, $data);   // devuelve array con CAE
 *     $sdk->emitirNotaCredito($externalId, $data);
 *
 * El Singleton (v1) es **single-tenant**: un CUIT por proceso PHP-FPM.
 * Un segundo getInstance() con Config incompatible lanza ConfigException.
 *
 * ----------------------------------------------------------------
 * Reglas de diseno (no negociables, tomadas del plan maestro seccion 6)
 * ----------------------------------------------------------------
 *
 *  1. **Validar ANTES de tocar nada**: el externalId se valida como
 *     UUID v4 y el Comprobante se construye (Comprobante::fromArray)
 *     antes de cualquier DB o red. ValidationException NO consume
 *     intentos de idempotencia.
 *
 *  2. **Una sola fuente de verdad para el cutoff**: $cutoff se calcula
 *     UNA vez al inicio del flujo y se bindea en cada comparacion
 *     con TTL. No se recalcula entre el check pre-lock y el post-lock.
 *
 *  3. **finally maneja lock release**: solo el finally llama a
 *     release(). Ningun catch libera el lock. release() solo se llama
 *     si acquire() devolvio true.
 *
 *  4. **El catch de CbteRechazadoException SOLO re-lanza**: el catch
 *     dedicado no escribe la fila, no libera el lock, no hace nada
 *     mas que propagar la excepcion. El marcado a fallido ocurre en
 *     el bloque principal antes del throw.
 *
 *  5. **No se llama a WsfeClient::solicitar sin haber reservado
 *     numero**: la primera llamada a ARCA (solicitar) va precedida
 *     por reservarNumero(), que es un CAS con lease_token.
 *
 *  6. **RetryPolicy::isTransient() clasifica es_fallo_infra**: el
 *     orquestador usa el mismo flag que retry, asi nunca discrepan.
 *
 *  7. **Recover zombie**: cuando una fila tiene
 *     `cbte_nro_enviado IS NOT NULL`, se delega a `ZombieRecovery`
 *     (consulta ARCA, compara con snapshot y decide entre recuperar /
 *     re-emitir / `CaeSecuestradoException` / `ZombieRecoveryFailedException`).
 *
 * @package Rbbsoft\ArcaSdk
 *
 * @author  Richard Barolin — RBB Soft ®
 * @license MIT
 * @since   0.1.0
 */
final class ArcaSdk
{
    /**
     * Instancia singleton del SDK. `null` hasta el primer
     * {@see self::getInstance()}.
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Fingerprint del {@see Config} que se usó para construir la
     * instancia singleton. Permite detectar un segundo `getInstance()`
     * con un `Config` incompatible (multi-tenant no soportado en v1).
     *
     * @var string|null
     */
    private static ?string $instanceFingerprint = null;

    /**
     * Config inmutable del SDK. Resolución única por instancia
     * (provista por el `Container`).
     *
     * @var Config
     */
    private readonly Config $config;

    /**
     * Container de servicios (WsaaClient, WsfeClient,
     * IdempotenciaRepository, LockManager, Clock, PDO, etc.).
     *
     * @var Container
     */
    private readonly Container $container;

    /**
     * Logger PSR-3. Si no se inyecta uno, el constructor usa
     * `NullLogger`.
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * Memoria in-memory (L1) del cache de Ticket de Acceso.
     *
     * Clave: `$cuit.':'.$wsn`. Valor: estructura opaca del
     * `TicketDeAcceso` devuelta por `TicketCache::getTokenInfo()`.
     * Se limpia con {@see self::reset()} — no toca la DB ni invalida
     * el cache L2 compartido.
     *
     * @var array<string, \Rbbsoft\ArcaSdk\Wsaa\TicketDeAcceso>
     */
    private array $tokenMemoryCache = [];

    /**
     * Constructor privado. La construcción se delega a
     * {@see self::getInstance()} (Singleton).
     *
     * @param Container           $container DI de servicios.
     * @param LoggerInterface|null $logger   PSR-3 logger. Si `null`, se usa
     *                                       `NullLogger` (no emite nada).
     */
    private function __construct(Container $container, ?LoggerInterface $logger = null)
    {
        $this->container = $container;
        $this->config = $container->config();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Devuelve la instancia singleton del SDK, construyéndola en el
     * primer llamado.
     *
     * El segundo llamado con un `Config` "compatible" devuelve la
     * misma instancia; con un `Config` "incompatible" lanza
     * {@see ConfigException}. "Compatible" significa: mismo CUIT,
     * mismo punto de venta, mismo env, mismo DB DSN, mismos paths
     * de cert/key. Cualquier diferencia implica multi-tenant, fuera
     * del alcance de v1.
     *
     * @param Config              $config Config del SDK (obligatorio).
     * @param Container|null       $deps   Si se pasa, se usa como container
     *                                     base (útil en tests). Si `null`,
     *                                     se construye uno nuevo a partir
     *                                     de `$config`.
     * @param LoggerInterface|null $logger PSR-3 logger. Si es `null` se usa
     *                                     `NullLogger`. Solo se usa en la
     *                                     primera construcción del Singleton;
     *                                     los llamados posteriores lo ignoran.
     *
     * @return self Instancia singleton del SDK.
     *
     * @throws ConfigException Faltan extensiones PHP requeridas, o el
     *                         `Config` provisto no coincide con la
     *                         instancia ya construida (single-tenant).
     */
    public static function getInstance(
        Config $config,
        ?Container $deps = null,
        ?LoggerInterface $logger = null,
    ): self {
        $fingerprint = self::configFingerprint($config);
        if (self::$instance === null) {
            $container = $deps ?? new Container($config);
            $container = self::withDefaultWiring($container, $config);
            self::$instance = new self($container, $logger);
            self::$instanceFingerprint = $fingerprint;
            return self::$instance;
        }
        if (self::$instanceFingerprint !== $fingerprint) {
            throw new ConfigException(sprintf(
                'ArcaSdk: Singleton v1 single-tenant. El Config actual '
                . '(cuit=%s, pv=%d, env=%s, dbDsn=%s, cert=%s) no coincide con '
                . 'la instancia ya construida (cuit=%s, pv=%d, env=%s, dbDsn=%s, cert=%s). '
                . 'V1 no soporta multiples CUITs por proceso.',
                $config->cuit, $config->puntoVenta, $config->env, $config->dbDsn, $config->certPath,
                self::$instance->config->cuit, self::$instance->config->puntoVenta,
                self::$instance->config->env, self::$instance->config->dbDsn,
                self::$instance->config->certPath
            ));
        }
        return self::$instance;
    }

    /**
     * Limpia la instancia singleton. Pensado para tests (donde cada
     * test necesita una instancia fresca). En produccion normalmente
     * NO se llama: el Singleton vive toda la vida del proceso.
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
        self::$instanceFingerprint = null;
    }

    /**
     * Fingerprint determinista del `Config` para comparar instancias.
     * Si dos `Config` tienen este fingerprint igual, son "iguales"
     * para los efectos del Singleton.
     *
     * @param Config $c Config a hashear.
     *
     * @return string Fingerprint `implode('|', …)` sobre los campos
     *                que definen el "tenant" (cuit, pv, env, dbDsn,
     *                dbUser, certPath, keyPath).
     */
    private static function configFingerprint(Config $c): string
    {
        return implode('|', [
            $c->cuit,
            (string) $c->puntoVenta,
            $c->env,
            $c->dbDsn,
            $c->dbUser,
            $c->certPath,
            $c->keyPath,
        ]);
    }

    /**
     * Aplica el cableado por defecto al Container. Hoy cubre dos puntos:
     *
     *  - Verifica que las extensiones PHP requeridas estén presentes
     *    (lanzando {@see ConfigException} si falta alguna).
     *  - Auto-cablea el PDO principal desde la {@see Config} si nadie
     *    lo inyectó. El PDO se construye con `PDO::ATTR_ERRMODE =>
     *    PDO::ERRMODE_EXCEPTION` y respeta `Config::$dbPersistent`. La
     *    inyección explícita del caller (vía {@see Container::withPdo()})
     *    gana sobre este default.
     *
     * El Container base (locks, ticket cache, SOAP clients) se considera
     * auto-cableado por lazy init en cada `get*` del Container, no acá.
     *
     * @param Container $container Container base.
     * @param Config    $config    Config del SDK.
     *
     * @return Container Container con el cableado aplicado.
     *
     * @throws ConfigException Faltan extensiones PHP requeridas.
     * @throws \PDOException   Falla la construcción del PDO (DSN/user/pass
     *                        inválidos en la Config).
     */
    private static function withDefaultWiring(Container $container, Config $config): Container
    {
        $missing = Config::verificarExtensionesRequeridas();
        if (count($missing) > 0) {
            throw new ConfigException(
                'ArcaSdk: faltan extensiones PHP requeridas: ' . implode(', ', $missing)
            );
        }

        // Auto-cablear el PDO principal si el caller no lo inyectó.
        // La inyección explícita (vía withPdo() antes o después de
        // getInstance()) gana sobre este default. Mantener la coherencia
        // con el resto del Container, que también se auto-cablea desde
        // Config.
        if (!$container->hasPdo()) {
            $container->withPdo(new PDO(
                $config->dbDsn,
                $config->dbUser,
                $config->dbPass,
                [
                    PDO::ATTR_ERRMODE    => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_PERSISTENT => $config->dbPersistent,
                ],
            ));
        }

        return $container;
    }

    /**
     * Devuelve el `Config` del SDK (la misma referencia siempre).
     *
     * @return Config
     */
    public function config(): Config
    {
        return $this->config;
    }

    /**
     * Devuelve el `Container` de servicios.
     *
     * @return Container
     */
    public function container(): Container
    {
        return $this->container;
    }

    // ----------------------------------------------------------------
    // API publica de emision
    // ----------------------------------------------------------------

    /**
     * Emite una Factura A/B/C/M.
     *
     * Wrapper sobre {@see self::emitir()} con `$cbteTipo` validado
     * contra el set de Facturas soportadas. Si `$data['cbte_tipo']`
     * no es una Factura, lanza `ValidationException` antes de tocar
     * DB o red.
     *
     * @param string               $externalId UUID v4 (se valida en `emitir()`).
     * @param array<string, mixed> $data       Mismos campos que `Comprobante::fromArray()`.
     *                                         Si no trae `cbte_tipo`, se asume
     *                                         `FACTURA_C` (11).
     *
     * @return ComprobanteEmitido Snapshot normalizado del comprobante emitido.
     *                             Use `->asArray()` para obtener la forma
     *                             snake_case historica de v0.2.x.
     *
     * @throws ValidationException             UUID inválido o datos de negocio inválidos.
     * @throws IdempotencyConflictException    Mismo `externalId` con datos distintos.
     * @throws EmisionEnCursoException         La fila está en_curso dentro del TTL.
     * @throws MaxIdempotencyAttemptsException La fila fallida alcanzó el máximo.
     * @throws IdempotencyStateException       Estado incoherente que requiere revisión manual.
     * @throws CbteRechazadoException          ARCA rechazó el comprobante (Resultado='R').
     * @throws WsfeException                   Fallo de infra (red/SOAP/9999). Reintentable.
     * @throws ZombieRecoveryFailedException   Fila en estado zombie.
     */
    public function emitirFactura(string $externalId, array $data): ComprobanteEmitido
    {
        $cbteTipo = isset($data['cbte_tipo']) ? (int) $data['cbte_tipo'] : TiposComprobante::FACTURA_C;
        if (!in_array($cbteTipo, [
            TiposComprobante::FACTURA_A,
            TiposComprobante::FACTURA_B,
            TiposComprobante::FACTURA_C,
            TiposComprobante::FACTURA_M,
        ], true)
        ) {
            throw new ValidationException(
                "emitirFactura: cbte_tipo={$cbteTipo} no es una Factura. Use emitirNotaCredito() para NC."
            );
        }
        return $this->emitir($externalId, $data, $cbteTipo);
    }

    /**
     * Atajo de {@see self::emitir()} restringido a Notas de Crédito (A/B/C/M).
     *
     * Valida que `$data['cbte_tipo']` (si está presente) sea una NC;
     * si se omite, se asume `NOTA_CREDITO_C` (13). Cualquier otro tipo
     * lanza `ValidationException` antes de tocar DB o red.
     *
     * @param string               $externalId UUID v4 (se valida en `emitir()`).
     * @param array<string, mixed> $data       Mismos campos que `Comprobante::fromArray()`.
     *                                         Si no trae `cbte_tipo`, se asume
     *                                         `NOTA_CREDITO_C` (13).
     *
     * @return ComprobanteEmitido Snapshot normalizado del comprobante emitido.
     *                             Use `->asArray()` para obtener la forma
     *                             snake_case historica de v0.2.x.
     *
     * @throws ValidationException             `$data['cbte_tipo']` no es NC, o UUID/datos inválidos.
     * @throws IdempotencyConflictException    Mismo `externalId` con datos distintos.
     * @throws EmisionEnCursoException         Fila en `en_curso` dentro del TTL.
     * @throws MaxIdempotencyAttemptsException La fila fallida alcanzó el máximo de intentos.
     * @throws IdempotencyStateException       Estado incoherente que requiere revisión manual.
     * @throws CbteRechazadoException          ARCA rechazó el comprobante (Resultado='R').
     * @throws WsfeException                   Fallo de infra (red/SOAP/9999). Reintentable.
     * @throws ZombieRecoveryFailedException   Fila en estado zombie.
     */
    public function emitirNotaCredito(string $externalId, array $data): ComprobanteEmitido
    {
        $cbteTipo = isset($data['cbte_tipo']) ? (int) $data['cbte_tipo'] : TiposComprobante::NOTA_CREDITO_C;
        if (!TiposComprobante::esNotaCredito($cbteTipo)) {
            throw new ValidationException(
                "emitirNotaCredito: cbte_tipo={$cbteTipo} no es una Nota de Credito. Use emitirFactura() para F."
            );
        }
        return $this->emitir($externalId, $data, $cbteTipo);
    }

    // ----------------------------------------------------------------
    // API publica de padron
    // ----------------------------------------------------------------

    /**
     * Consulta el padron A13 de ARCA (ws_sr_padron_a13) y
     * devuelve los datos del contribuyente correspondiente al CUIT.
     *
     * Es un thin wrapper sobre {@see \Rbbsoft\ArcaSdk\Sdk\Container::padronClient()};
     * el Container queda accesible para tests y para consumidores que
     * no quieran pasar por el Singleton.
     *
     * El CUIT es siempre un argumento: el SDK no deduce el CUIT propio
     * del Singleton. Para consultar el propio CUIT, pasar
     * `(int) $this->config->cuit`. Para un CUIT de tercero, pasar el
     * CUIT deseado; la autorizacion a nivel ARCA (permiso de "Consulta
     * de Padron") se asume configurada por el operador.
     *
     * Esta operacion NO escribe en la tabla de emisiones ni en
     * ninguna otra tabla del SDK. La aplicacion del usuario decide si
     * persiste el resultado y donde.
     *
     * @param int $cuit CUIT a consultar (11 digitos, sin guiones).
     *
     * @return Emisor DTO inmutable con los datos del padron.
     *
     * @throws PadronException              Error funcional ARCA.
     * @throws PadronArcaTransientException Codigo 9999 (transitorio, reintentable por la RetryPolicy).
     * @throws PadronProtocolException      Respuesta malformada (body vacio, HTML, 5xx, estructura invalida).
     */
    public function obtenerEmisor(int $cuit): Emisor
    {
        return $this->container->padronClient()->obtener($cuit);
    }

    /**
     * Genera el PDF del comprobante y devuelve el path absoluto al archivo.
     *
     * Es un thin wrapper sobre
     * {@see \Rbbsoft\ArcaSdk\Sdk\Container::comprobantePdfGenerator()}.
     * El PDF se arma a partir del {@see ComprobanteEmitido} devuelto
     * por `emitirFactura()` / `emitirNotaCredito()`, o de un array con
     * la union de los datos de input y el response historico
     * (compat con callers pre-v0.3.0).
     *
     * Comportamiento del parametro `$destino`:
     *  - `null` (default): usa el generador del Container, que escribe
     *    en el directorio por defecto `comprobantes/` relativo a
     *    `getcwd()`.
     *  - string no vacia: se IGNORA el generador del Container y se
     *    construye uno nuevo con ese directorio destino. Esto permite
     *    que cada llamada escriba en un lugar distinto (un disco de
     *    red, un path por tenant, un directorio de output de un job
     *    queue) sin necesidad de re-cablear el Container.
     *
     * Decisiones:
     *  - La conversion de array a DTO se hace siempre con
     *    `ComprobanteEmitido::fromArray()` para garantizar que la
     *    logica del QR y el HTML consuman la misma representacion
     *    canonica (camelCase, tipos estrictos).
     *  - Esta API no toca la red ni la DB. Es una operacion local
     *    que toma un comprobante ya emitido y lo serializa a PDF.
     *
     * @param ComprobanteEmitido|array<string, mixed> $comprobante Comprobante
     *        emitido (forma directa del SDK) o array mergeado con la
     *        union de input + response (forma historica v0.2.x).
     * @param string|null                            $destino    Directorio
     *        destino custom. Si `null`, se usa el del Container.
     *
     * @return string Path absoluto del PDF generado.
     *
     * @throws \RuntimeException Si falta un campo obligatorio del
     *                           comprobante, falla la escritura del
     *                           archivo, o falla el render del QR / PDF.
     */
    public function generarPdf(ComprobanteEmitido|array $comprobante, ?string $destino = null): string
    {
        if (is_array($comprobante)) {
            $comprobante = ComprobanteEmitido::fromArray($comprobante);
        }
        if ($destino !== null) {
            $gen = new \Rbbsoft\ArcaSdk\Pdf\ComprobantePdfGenerator($destino);
        } else {
            $gen = $this->container->comprobantePdfGenerator();
        }
        return $gen->generar($comprobante);
    }

    /**
     * Flujo unificado de emisión. Hace todo el trabajo
     * pesado detrás de `emitirFactura()` y `emitirNotaCredito()`.
     *
     * Secuencia: validar UUID → construir `Comprobante` → leer/insertar
     * fila idempotente → `GET_LOCK` → `ultimoAutorizado` → reservar
     * número → `FECAESolicitar` → transicionar a `emitido`/`fallido`.
     * El `finally` libera el lock si fue adquirido.
     *
     * @param string               $externalId   UUID v4 (debe pasar `UuidFactory::isValid()`).
     * @param array<string, mixed> $data         Mismos campos que `Comprobante::fromArray()`.
     * @param int|null             $cbteTipoHint Si se pasa, se usa como `$data['cbte_tipo']`
     *                                          (los wrappers públicos lo infieren del nombre).
     *
     * @return ComprobanteEmitido Snapshot normalizado del comprobante emitido.
     *
     * @throws ValidationException             UUID inválido o datos de negocio inválidos.
     * @throws IdempotencyConflictException    Mismo `externalId` con datos distintos.
     * @throws EmisionEnCursoException         Fila en `en_curso` dentro del TTL.
     * @throws MaxIdempotencyAttemptsException La fila fallida alcanzó el máximo.
     * @throws IdempotencyStateException       Estado incoherente que requiere revisión manual.
     * @throws CbteRechazadoException          ARCA rechazó el comprobante (Resultado='R').
     * @throws WsfeException                   Fallo de infra (red/SOAP/9999). Reintentable.
     * @throws ZombieRecoveryFailedException   Fila en estado zombie.
     */
    private function emitir(string $externalId, array $data, ?int $cbteTipoHint): ComprobanteEmitido
    {
        // 1) Validar UUID v4
        if (!UuidFactory::isValid($externalId)) {
            throw new ValidationException(
                "emitir: externalId no es un UUID v4 valido (recibio: '{$externalId}')"
            );
        }

        // 2) Construir Comprobante (validacion estricta). Si falla,
        //    ValidationException y NINGUNA fila se persiste.
        $comprobante = Comprobante::fromArray($data, $this->config->puntoVenta, $cbteTipoHint);

        // 3) Fingerprint + request_json
        $fingerprint = $comprobante->fingerprint();
        $requestJson = $comprobante->canonicalJson();

        $repo = $this->container->idempotenciaRepository();
        $cuit = $this->config->cuit;
        $pv = $comprobante->puntoVenta;
        $tipo = $comprobante->cbteTipo;
        // Cutoff: calculado UNA sola vez. Lo usamos en el check pre-lock
        // y en el markEnCursoZombieFromStaleLock, garantizando que ambos
        // bindean el mismo valor.
        $cutoff = $this->nowUtcStringMinusTtl();

        // 4) Leer fila idempotente
        $fila = $repo->findByExternalId($externalId);
        $lease = null;

        if ($fila === null) {
            // Insert en_curso. Si la PK colisiona (otro worker inserto
            // entre el find y el insert) el SQLSTATE 23000 explota
            // aca; lo tratamos como carrera y re-leemos abajo.
            try {
                $lease = $repo->insertEnCurso(
                    $externalId,
                    $cuit,
                    $pv,
                    $tipo,
                    $fingerprint,
                    $requestJson
                );
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
                $fila = $repo->findByExternalId($externalId);
                if ($fila === null) {
                    throw $e;
                }
            }
        }

        if ($fila !== null) {
            $this->assertIdentity($fila, $cuit, $pv, $tipo, $fingerprint);
            // emitido: devolver cached ANTES de tomar el lock y de hacer
            // cualquier transicion. (El plan lo dice: "si esta emitido,
            // ... devolverla sin lock ni red".)
            if ($fila->estado === FilaEmision::ESTADO_EMITIDO) {
                return $this->returnCachedEmitido($fila, $externalId);
            }
            $lease = $this->transitionForExistingFila($repo, $fila, $externalId, $fingerprint, $cutoff);
        }

        // A esta punto $lease SIEMPRE esta seteado. Si $fila era null
        // al inicio, $lease viene de insertEnCurso. Si $fila existia,
        // $lease viene de una de las transiciones (insert, fallido,
        // o fallido-via-stale-en_curso).

        // 5) Lock de emision (named lock por cuit/pv/tipo)
        $lockName = LockManager::computeEmitLockName($cuit, $pv, $tipo);
        $lockManager = $this->container->lockManager();
        $acquired = $lockManager->acquire($lockName, $this->config->emitLockTimeout);

        if (!$acquired) {
            // No pudimos tomar el lock. La fila sigue en_curso bajo
            // NUESTRO lease pero no tenemos el lock: devolvemos a
            // fallido para no dejar la fila en estado inconsistente.
            $this->markFallidoForLease($repo, $externalId, $lease, $fingerprint, true, json_encode([
                'error' => 'lock_no_adquirido',
                'lock_name' => $lockName,
                'timeout' => $this->config->emitLockTimeout,
            ], JSON_UNESCAPED_UNICODE));
            throw new WsfeException(
                "emitir: no se pudo adquirir el named lock '{$lockName}' en "
                . "{$this->config->emitLockTimeout}s. Otro worker esta procesando "
                . "este (cuit, pv, tipo); reintentar mas tarde."
            );
        }

        try {
            // 6) Re-leer la fila post-lock (pudo haber cambiado mientras esperabamos).
            $filaPost = $repo->findByExternalId($externalId);
            if ($filaPost === null) {
                throw new IdempotencyStateException(
                    "emitir: fila {$externalId} desaparecio despues de tomar el lock"
                );
            }
            $this->assertIdentity($filaPost, $cuit, $pv, $tipo, $fingerprint);

            if ($filaPost->estado === FilaEmision::ESTADO_EMITIDO) {
                return $this->returnCachedEmitido($filaPost, $externalId);
            }
            if ($filaPost->estado === FilaEmision::ESTADO_FALLIDO) {
                // Cayeron a fallido mientras esperabamos el lock. Reabrir
                // y re-asignar el lease.
                $lease = $repo->transitionFallidoToEnCurso($externalId, $fingerprint);
            } elseif ($filaPost->estado === FilaEmision::ESTADO_EN_CURSO) {
                if (!$filaPost->isOwnedBy($lease)) {
                    throw new EmisionEnCursoException(
                        "emitir: fila {$externalId} en_curso con lease ajeno post-lock (carrera)"
                    );
                }
                // Mismo lease -> seguimos con el flujo.
            }

            // 7) Branch por cbte_nro_enviado
            if ($filaPost->cbteNroEnviado !== null) {
                // Zombie: Phase 7. Delegamos al orquestador de
                // recuperacion, que consulta ARCA, compara con el
                // snapshot y decide entre: recuperar como emitido,
                // re-emitir, marcar CaeSecuestradoException, o
                // ZombieRecoveryFailedException. El lock sigue
                // sostenido por este `try`; el recovery NO toca el
                // lock.
                //
                // Pasar el `$filaPost` (no `findByExternalId` de nuevo)
                // porque ya estamos dentro del lock y la fila es
                // estable: no hay ventana de race. El repo usara el
                // lease_token de `$filaPost` para los CAS.
                $wsfe = $this->container->wsfeClient();
                return \Rbbsoft\ArcaSdk\Zombie\ZombieRecovery::recover(
                    $filaPost,
                    $repo,
                    $wsfe,
                    $this->config,
                    $this->container->clock(),
                    $lockManager,
                    $externalId
                );
            }

            // 8) Emision nueva: pedir ultimo, calcular cbteNro, persistir
            $wsfe = $this->container->wsfeClient();
            try {
                $ultimo = $wsfe->ultimoAutorizado($pv, $tipo);
            } catch (WsfeException | WsaaException $e) {
                // Catch ampliado: WsaaException cubre WsaaCeeYaPoseeTaException
                // y cualquier fallo de WSAA (red, loginCms, CEE bloqueado).
                // Sin este catch, la fila quedaba en en_curso con intento=0
                // (zombie) porque la excepcion se propagaba sin pasar por
                // markFallidoForLease. Coherente con el catch equivalente
                // en $wsfe->solicitar() mas abajo.
                $this->markFallidoForLease(
                    $repo, $externalId, $lease, $fingerprint,
                    RetryPolicy::isTransient($e),
                    $this->serializeExceptionForLog($e)
                );
                throw $e;
            }
            $cbteNro = $ultimo + 1;
            if ($cbteNro <= 0) {
                $this->markFallidoForLease($repo, $externalId, $lease, $fingerprint, false, json_encode([
                    'error' => 'ultimo_invalido',
                    'ultimo' => $ultimo,
                ], JSON_UNESCAPED_UNICODE));
                throw new IdempotencyStateException(
                    "emitir: ultimoAutorizado devolvio {$ultimo}, no se puede calcular cbteNro"
                );
            }

            // CbteFch: fecha civil argentina en formato YYYYMMDD.
            // Usamos el Clock inyectado (en vez de `new DateTimeImmutable('now')`)
            // para que los tests puedan fijar la fecha. La zona se
            // convierte al momento del format.
            $cbteFchClock = $this->container->clock()->now()
                ->setTimezone(new DateTimeZone('America/Argentina/Buenos_Aires'));
            $cbteFch = $cbteFchClock->format('Ymd');
            $cbteFchDb = substr($cbteFch, 0, 4) . '-' . substr($cbteFch, 4, 2) . '-' . substr($cbteFch, 6, 2);

            // 9) Reservar numero: CAS con lease + cbte_nro_enviado IS NULL
            $reservado = $repo->reservarNumero(
                $externalId,
                $lease,
                $fingerprint,
                $cbteNro,
                $cbteFchDb,
                $requestJson
            );
            if (!$reservado) {
                // La fila cambio entre el lock y el CAS. Releer y decidir.
                $filaRace = $repo->findByExternalId($externalId);
                if ($filaRace === null) {
                    throw new IdempotencyStateException(
                        "emitir: fila {$externalId} desaparecio al reservar numero"
                    );
                }
                if ($filaRace->estado === FilaEmision::ESTADO_EMITIDO) {
                    return $this->returnCachedEmitido($filaRace, $externalId);
                }
                if ($filaRace->estado === FilaEmision::ESTADO_EN_CURSO) {
                    if (!$filaRace->isOwnedBy($lease)) {
                        throw new EmisionEnCursoException(
                            "emitir: reservaNumero fallo, fila {$externalId} con lease ajeno (carrera)"
                        );
                    }
                    // Mismo lease, en_curso, sin cbte_nro_enviado.
                    // El CAS fallo por otro motivo; reintentar la reserva
                    // con el mismo lease.
                    $reservado = $repo->reservarNumero(
                        $externalId,
                        $lease,
                        $fingerprint,
                        $cbteNro,
                        $cbteFchDb,
                        $requestJson
                    );
                    if (!$reservado) {
                        throw new IdempotencyStateException(
                            "emitir: reservaNumero fallo dos veces para {$externalId}"
                        );
                    }
                } elseif ($filaRace->estado === FilaEmision::ESTADO_FALLIDO) {
                    $lease = $repo->transitionFallidoToEnCurso($externalId, $fingerprint);
                    $reservado = $repo->reservarNumero(
                        $externalId,
                        $lease,
                        $fingerprint,
                        $cbteNro,
                        $cbteFchDb,
                        $requestJson
                    );
                    if (!$reservado) {
                        throw new IdempotencyStateException(
                            "emitir: reservaNumero fallo dos veces para {$externalId}"
                        );
                    }
                }
            }

            // 10) Llamar a ARCA (solicitar)
            try {
                $response = $wsfe->solicitar($comprobante, $cbteNro, $cbteFch);
            } catch (CbteRechazadoException $e) {
                // Catch dedicado: SOLO re-lanza. El marcado a fallido
                // NO ocurre aca (el WsfeClient no lanza este tipo; el
                // orquestador lo hace mas abajo).
                throw $e;
            } catch (WsfeException $e) {
                $this->markFallidoForLease(
                    $repo, $externalId, $lease, $fingerprint,
                    RetryPolicy::isTransient($e),
                    $this->serializeExceptionForLog($e)
                );
                throw $e;
            } catch (Throwable $e) {
                $this->markFallidoForLease(
                    $repo, $externalId, $lease, $fingerprint,
                    RetryPolicy::isTransient($e),
                    $this->serializeExceptionForLog($e)
                );
                throw $e;
            }

            // 11) Branch por Resultado
            if ($response->isAprobado()) {
                $discrimina = TiposComprobante::discriminaIva($comprobante->cbteTipo);
                $res = IvaCalculator::calcular(
                    $comprobante->items,
                    $discrimina,
                    $comprobante->importeNoGravado,
                    $comprobante->importeExento,
                    $comprobante->importeOtrosTributos,
                );
                $casOk = $repo->transitionEnCursoToEmitido(
                    $externalId,
                    $lease,
                    $fingerprint,
                    (string) $response->cae,
                    (string) $response->caeFchVto,
                    $response->cbteNro,
                    json_encode([
                        'resultado' => $response->resultado,
                        'cae' => $response->cae,
                        'cae_fch_vto' => $response->caeFchVto,
                        'cbte_nro' => $response->cbteNro,
                        'cbte_fch' => $cbteFchDb,
                        'monto_total' => $res->total,
                        'monto_neto'  => $res->netoGravado,
                        'monto_iva'   => $res->ivaTotal,
                        'cbtes_asoc'  => $comprobante->cbtesAsoc ?: null,
                    ], JSON_UNESCAPED_UNICODE)
                );
                if (!$casOk) {
                    throw new IdempotencyStateException(
                        "emitir: transitionEnCursoToEmitido CAS no afecto la fila {$externalId}"
                    );
                }
                return $this->buildEmitidoArray(
                    $comprobante, $response, $externalId, $cuit, $cbteFchDb
                );
            }

            // Resultado='R' (rechazo funcional)
            $this->markFallidoForLease(
                $repo, $externalId, $lease, $fingerprint,
                false,
                json_encode([
                    'resultado' => 'R',
                    'observaciones' => $response->observaciones,
                    'raw' => $response->rawExcerpt,
                ], JSON_UNESCAPED_UNICODE)
            );
            throw new CbteRechazadoException(
                sprintf(
                    'ARCA rechazo el comprobante (cbte_tipo=%d, pv=%d, nro=%d): %s',
                    $comprobante->cbteTipo,
                    $comprobante->puntoVenta,
                    $cbteNro,
                    self::observacionesAsString($response->observaciones)
                ),
                $response->observaciones
            );
        } finally {
            // Solo el finally libera el lock, y solo si fue adquirido.
            if ($acquired) {
                $lockManager->release($lockName);
            }
        }
    }

    /**
     * Aplica la transición de estado correspondiente a una fila
     * existente (no emitida). Devuelve el lease bajo el cual debemos
     * trabajar en el resto del flujo.
     *
     * Estados:
     *  - emitido: ya se devolvió arriba, no deberíamos llegar acá.
     *  - en_curso fresh: lanza `EmisionEnCursoException`.
     *  - en_curso stale: reclamar vía `markEnCursoZombieFromStaleLock`
     *    + `transitionFallidoToEnCurso`.
     *  - fallido: `transitionFallidoToEnCurso`.
     *
     * @param IdempotenciaRepository $repo        Repo de idempotencia.
     * @param FilaEmision            $fila        Fila existente leída por
     *                                            `findByExternalId()`.
     * @param string                 $externalId  UUID v4 (sólo para mensajes de error).
     * @param string                 $fingerprint Fingerprint de la request actual.
     * @param string                 $cutoff      Cutoff UTC ya computado (mismo valor
     *                                            que el pre-lock para garantizar coherencia).
     *
     * @return string Lease token bajo el cual trabajar.
     *
     * @throws EmisionEnCursoException    La fila está en_curso dentro del TTL.
     * @throws IdempotencyStateException  La fila está en un estado desconocido, o el
     *                                    CAS de recuperación no afectó la fila.
     */
    private function transitionForExistingFila(
        IdempotenciaRepository $repo,
        FilaEmision $fila,
        string $externalId,
        string $fingerprint,
        string $cutoff
    ): string {
        switch ($fila->estado) {
            case FilaEmision::ESTADO_EN_CURSO:
                // Comparacion string-vs-DateTimeImmutable en PHP es
                // fragil (PHP a veces compara referencias en vez de
                // valores). Formateamos updatedAt al mismo string
                // antes de comparar.
                $filaUpdatedUtc = $fila->updatedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                if (strcmp($filaUpdatedUtc, $cutoff) >= 0) {
                    throw new EmisionEnCursoException(
                        "emitir: fila {$externalId} en_curso dentro del TTL (otro worker activo)"
                    );
                }
                // Lease stale. Reclamar via CAS con lease viejo.
                $claimed = $repo->markEnCursoZombieFromStaleLock(
                    $externalId,
                    (string) $fila->leaseToken,
                    $fingerprint,
                    $cutoff
                );
                if (!$claimed) {
                    throw new EmisionEnCursoException(
                        "emitir: fila {$externalId} en_curso pero el CAS de recuperacion no afecto la fila"
                    );
                }
                return $repo->transitionFallidoToEnCurso($externalId, $fingerprint);
            case FilaEmision::ESTADO_FALLIDO:
                return $repo->transitionFallidoToEnCurso($externalId, $fingerprint);
            default:
                throw new IdempotencyStateException(
                    "emitir: fila {$externalId} en estado desconocido '{$fila->estado}'"
                );
        }
    }

    // ----------------------------------------------------------------
    // Operaciones no destructivas
    // ----------------------------------------------------------------

    /**
     * Devuelve `true` si el `externalId` tiene una fila persistida
     * (en cualquier estado). No toca la red ni WSFE.
     *
     * Si el `externalId` no es UUID v4, devuelve `false` sin consultar
     * la DB.
     *
     * @param string $externalId UUID v4 candidato.
     *
     * @return bool
     */
    public function esIdempotente(string $externalId): bool
    {
        if (!UuidFactory::isValid($externalId)) {
            return false;
        }
        $fila = $this->container->idempotenciaRepository()->findByExternalId($externalId);
        return $fila !== null;
    }

    /**
     * Devuelve información del TA vigente en el cache (L1+L2).
     *
     * Estructura: `['cuit' => …, 'wsn' => …, 'is_valid' => bool, …]`
     * o `null` si no hay fila.
     *
     * @param string $wsn Web service name (default `wsfe`).
     *
     * @return array<string, mixed>|null
     */
    public function getTokenInfo(string $wsn = 'wsfe'): ?array
    {
        return $this->container->ticketCache()->getTokenInfo($this->config->cuit, $wsn);
    }

    /**
     * Invalida el cache del TA (L1+L2). Operación administrativa.
     *
     * Útil cuando el caller sospecha que el TA en cache está corrupto
     * o venció fuera del margen de seguridad. La próxima llamada a
     * `emitirFactura()`/`emitirNotaCredito()` volverá a generar uno
     * fresco contra ARCA.
     *
     * @param string $wsn Web service name (default `wsfe`).
     */
    public function flushTicket(string $wsn = 'wsfe'): void
    {
        $this->container->ticketCache()->flush($this->config->cuit, $wsn);
        $key = $this->config->cuit . ':' . $wsn;
        unset($this->tokenMemoryCache[$key]);
    }

    /**
     * Limpia el cache L1 en memoria. No toca la DB ni el cache L2
     * compartido.
     *
     * Útil en tests y para re-inicializar el Singleton sin generar
     * un nuevo TA contra ARCA.
     */
    public function reset(): void
    {
        $this->tokenMemoryCache = [];
    }

    // ----------------------------------------------------------------
    // Phase 8: Reconciliacion y operaciones administrativas
    // ----------------------------------------------------------------

    /**
     * Sweeper de filas zombie `en_curso` cuyo TTL expiró.
     *
     * Implementa la sección 8 punto 1 del plan:
     *  - Selecciona candidatas `en_curso AND updated_at < $cutoff`.
     *  - Para cada candidata, ejecuta el CAS
     *    `markEnCursoZombieFromStaleLock(external_id, lease, fp, cutoff)`
     *    pasando **el mismo cutoff** que usó el SELECT (sin recalcular
     *    entre los dos pasos). Así, una fila que se vuelve "fresca"
     *    entre SELECT y CAS NO es transicionada por nosotros: la
     *    condición `updated_at < cutoff` del WHERE del CAS la filtra.
     *  - Solo cuenta como transicionada aquella fila para la que
     *    `affected_rows === 1`. Varios sweepers pueden coexistir sin
     *    procesar dos veces la misma fila.
     *
     * @param int|null $limit Cantidad máxima de filas a transicionar
     *                        por llamado. El caller puede llamar varias
     *                        veces para vaciar un backlog. Default 100.
     *
     * @return int Cantidad de filas transicionadas a `fallido`.
     *
     * @throws ValidationException Si `$limit` es `< 1`.
     */
    public function reconciliar(?int $limit = 100): int
    {
        $limit = $limit ?? 100;
        if ($limit < 1) {
            throw new ValidationException(
                "reconciliar: limit debe ser >= 1 (recibio: {$limit})"
            );
        }
        $cutoff = $this->nowUtcStringMinusTtl();
        $pdo = $this->container->pdo();
        $repo = $this->container->idempotenciaRepository();

        // SELECT: candidatas stale. Leemos lease_token y
        // request_fingerprint porque el CAS los exige (sin lease no
        // sabemos que fila "pertenece" a este lock).
        //
        // Por que `updated_at < cutoff` aca Y en el CAS: si el CAS
        // re-evalua el cutoff, una fila que se volvio fresca entre
        // SELECT y CAS no sera afectada. Esto es lo que el plan
        // pide: "usar el mismo cutoff bound (no recalc)".
        $sql = 'SELECT external_id, lease_token, request_fingerprint
                  FROM arca_emisiones_idempotencia
                 WHERE estado = :en_curso
                   AND updated_at < :cutoff
                 ORDER BY updated_at ASC
                 LIMIT :limite';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':en_curso', FilaEmision::ESTADO_EN_CURSO);
        $stmt->bindValue(':cutoff', $cutoff);
        $stmt->bindValue(':limite', $limit, PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array{external_id:string, lease_token:?string, request_fingerprint:string}> $candidates */
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $count = 0;
        foreach ($candidates as $row) {
            $externalId = (string) $row['external_id'];
            $lease = $row['lease_token'] === null ? '' : (string) $row['lease_token'];
            $fingerprint = (string) $row['request_fingerprint'];
            if ($lease === '') {
                // Una fila en_curso sin lease no deberia existir (el
                // modelo la limpia al transicionar a emitido/fallido),
                // pero si la encontramos, la saltamos: no se puede
                // hacer un CAS seguro.
                continue;
            }
            $claimed = $repo->markEnCursoZombieFromStaleLock(
                $externalId,
                $lease,
                $fingerprint,
                $cutoff
            );
            if ($claimed) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Operación administrativa destructiva: borra la fila del
     * `externalId`. Pensada para limpiar el estado después de un
     * cierre manual o un error de operator.
     *
     * Reglas estrictas (plan maestro sección 8 punto 4):
     *  1. AUDIT FIRST: se emite una entrada append-only vía PSR-3
     *     con todos los campos de la fila + operator + motivo +
     *     `timestamp_utc` + flags. Si el log lanza o devuelve
     *     `false`, ABORTAR (no se borra nada).
     *  2. Refuse conditions ANTES del DELETE:
     *     - `en_curso AND updated_at >= cutoff` → `EmisionEnCursoException`
     *       (no se puede resetear mientras un worker la tiene
     *       vigente).
     *     - `emitido AND !forceEmitido` → `IdempotencyStateException`
     *       (borrar un comprobante emitido sin `force` permite
     *       duplicar el comprobante en ARCA).
     *  3. DELETE físico de la fila.
     *  4. Warning si el estado era emitido: el comprobante sigue
     *     existiendo en ARCA; reusar el UUID crea una emisión lógica
     *     nueva capaz de duplicarlo.
     *
     * @param string $externalId  UUID v4 de la emisión a borrar.
     * @param string $operator    Identificación del operator (no se
     *                            loguea vacío).
     * @param string $motivo      Motivo obligatorio del reset.
     * @param bool   $force       Si `true`, permite el reset sobre
     *                            cualquier estado que no sea las dos
     *                            refuse conditions de arriba. Si
     *                            `false`, los únicos estados
     *                            resettables son `fallido` y
     *                            `en_curso` fuera del TTL.
     * @param bool   $forceEmitido Si `true`, permite borrar una fila
     *                             `emitido`. Aún con esta flag, se
     *                             emite un WARNING post-DELETE.
     *
     * @throws ValidationException        Si `externalId` no es UUID v4,
     *                                    o `motivo`/`operator` vacíos.
     * @throws EmisionEnCursoException    Si la fila está en_curso dentro del TTL.
     * @throws IdempotencyStateException  Si la fila no existe, o si es
     *                                    `emitido` y `!forceEmitido`, o
     *                                    si el audit no fue aceptado por
     *                                    el logger.
     */
    public function resetExternalId(
        string $externalId,
        string $operator,
        string $motivo,
        bool $force = false,
        bool $forceEmitido = false,
    ): void {
        if (!UuidFactory::isValid($externalId)) {
            throw new ValidationException(
                "resetExternalId: externalId no es un UUID v4 valido (recibio: '{$externalId}')"
            );
        }
        $motivo = trim($motivo);
        if ($motivo === '') {
            throw new ValidationException(
                'resetExternalId: motivo es obligatorio y no puede estar vacio'
            );
        }
        $operator = trim($operator);
        if ($operator === '') {
            throw new ValidationException(
                'resetExternalId: operator es obligatorio y no puede estar vacio'
            );
        }

        $repo = $this->container->idempotenciaRepository();
        $fila = $repo->findByExternalId($externalId);
        if ($fila === null) {
            throw new IdempotencyStateException(
                "resetExternalId: no existe la fila {$externalId}"
            );
        }

        $cutoff = $this->nowUtcStringMinusTtl();
        $filaUpdatedUtc = $fila->updatedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        // 1) AUDIT FIRST. Si falla, abortamos sin tocar la fila.
        $now = $this->container->clock()->now();
        $auditLogger = new ResetAuditLogger($this->logger);
        $context = ResetAuditLogger::buildContext(
            $fila,
            $operator,
            $motivo,
            $force,
            $forceEmitido,
            $now
        );
        $message = ResetAuditLogger::formatMessage($context);
        $ok = $auditLogger->audit($context, $message);
        if (!$ok) {
            throw new IdempotencyStateException(
                "resetExternalId: la entrada de auditoria no fue aceptada por el logger; "
                . "abortando reset de {$externalId}"
            );
        }

        // 2) Refuse conditions (BEFORE DELETE).
        if ($fila->estado === FilaEmision::ESTADO_EN_CURSO
            && strcmp($filaUpdatedUtc, $cutoff) >= 0
        ) {
            throw new EmisionEnCursoException(
                "resetExternalId: {$externalId} esta en_curso dentro del TTL "
                . "(updated_at={$filaUpdatedUtc}, cutoff={$cutoff}). "
                . "Espere a que el TTL expire o use la operacion de sweeper antes de resetear."
            );
        }
        if ($fila->estado === FilaEmision::ESTADO_EMITIDO && !$forceEmitido) {
            throw new IdempotencyStateException(
                "resetExternalId: {$externalId} esta emitido (CAE={$fila->cae}, "
                . "nro={$fila->cbteNroConfirmado}). Borrarlo permite que una llamada "
                . "futura con el mismo UUID cree una nueva emision logica capaz de "
                . "duplicar el comprobante. Para forzar, pase force_emitido=true."
            );
        }

        // 3) DELETE fisico. Sin CAS: el audit + refuse checks ya
        //    gatean. Plan seccion 8: "No la marca fallido ni
        //    reinicia contadores in-place" -> el camino es DELETE.
        $pdo = $this->container->pdo();
        $stmt = $pdo->prepare(
            'DELETE FROM arca_emisiones_idempotencia WHERE external_id = :external_id'
        );
        $stmt->execute([':external_id' => $externalId]);
        $deleted = $stmt->rowCount();
        if ($deleted !== 1) {
            // La fila desaparecio entre el find y el delete (otro
            // reset / una intervencion manual simultanea).
            throw new IdempotencyStateException(
                "resetExternalId: DELETE no afecto 1 fila para {$externalId} "
                . "(affected_rows={$deleted})"
            );
        }

        // 4) Warning post-DELETE si era emitido: el comprobante
        //    sigue existiendo en ARCA.
        if ($fila->estado === FilaEmision::ESTADO_EMITIDO) {
            $warnContext = $context;
            $warnContext['warning'] = 'comprobante_emitido_borrado_idempotencia';
            $auditLogger->warnDuplicado(
                'resetExternalId: WARNING el comprobante emitido sigue existiendo en ARCA; '
                . 'reusar el UUID puede duplicarlo. ' . $message,
                $warnContext
            );
        }
    }

    // ----------------------------------------------------------------
    // Helpers internos
    // ----------------------------------------------------------------

    /**
     * Verifica que la fila existente coincida con la nueva llamada
     * en los campos que identifican la emisión: cuit, puntoVenta,
     * cbteTipo y fingerprint. Si alguno difiere, lanza
     * `IdempotencyConflictException`.
     *
     * @param FilaEmision $fila        Fila leída.
     * @param string      $cuit        CUIT de la nueva llamada.
     * @param int         $puntoVenta  Punto de venta de la nueva llamada.
     * @param int         $cbteTipo    Tipo de comprobante de la nueva llamada.
     * @param string      $fingerprint Fingerprint de la nueva llamada.
     *
     * @throws IdempotencyConflictException Algún campo no coincide.
     */
    private function assertIdentity(
        FilaEmision $fila,
        string $cuit,
        int $puntoVenta,
        int $cbteTipo,
        string $fingerprint
    ): void {
        if ($fila->cuit !== $cuit
            || $fila->puntoVenta !== $puntoVenta
            || $fila->cbteTipo !== $cbteTipo
        ) {
            throw new IdempotencyConflictException(
                "emitir: externalId={$fila->externalId} existe con (cuit={$fila->cuit}, "
                . "pv={$fila->puntoVenta}, tipo={$fila->cbteTipo}) pero la nueva llamada "
                . "usa (cuit={$cuit}, pv={$puntoVenta}, tipo={$cbteTipo}). "
                . "externalId es inmutable por emision logica."
            );
        }
        if ($fila->requestFingerprint !== $fingerprint) {
            throw new IdempotencyConflictException(
                "emitir: externalId={$fila->externalId} existe con fingerprint distinto. "
                . "La nueva llamada tiene datos de negocio diferentes. externalId es "
                . "inmutable por emision logica."
            );
        }
    }

    /**
     * Calcula la fecha de cutoff UTC para el TTL de `en_curso`.
     *
     * Se llama UNA sola vez al inicio del flujo para que la
     * comparación pre-lock y post-lock usen exactamente el mismo
     * valor (regla 2 del diseño).
     *
     * @return string `Y-m-d H:i:s` en UTC, restado
     *                `idempotenciaTtlSegundos` del `now` del `Clock`.
     */
    private function nowUtcStringMinusTtl(): string
    {
        $clock = $this->container->clock();
        $now = $clock->now();
        $cutoff = $now->modify('-' . $this->config->idempotenciaTtlSegundos . ' seconds');
        return $cutoff->format('Y-m-d H:i:s');
    }

    /**
     * Marca la fila como `fallido` con el lease dado. Usado para
     * limpiar el estado en casos donde el flujo no puede avanzar
     * (lock no adquirido, `WsfeException`, `Resultado='R'`, etc.).
     *
     * @param IdempotenciaRepository $repo         Repo de idempotencia.
     * @param string                 $externalId   UUID v4.
     * @param string                 $lease        Lease token del worker actual.
     * @param string                 $fingerprint  Fingerprint de la request.
     * @param bool                   $esFalloInfra Si `true`, marca `es_fallo_infra=1`
     *                                             (no incrementa `intento`).
     * @param string|null            $responseJson JSON a persistir en `response_json`
     *                                             (puede ser `null`).
     */
    private function markFallidoForLease(
        IdempotenciaRepository $repo,
        string $externalId,
        string $lease,
        string $fingerprint,
        bool $esFalloInfra,
        ?string $responseJson
    ): void {
        $repo->transitionEnCursoToFallido(
            $externalId,
            $lease,
            $fingerprint,
            $esFalloInfra,
            $responseJson
        );
    }

    /**
     * Serializa una excepción para guardarla en `response_json` sin
     * filtrar data sensible (no expone paths ni secretos): solo
     * `class` + `message`.
     *
     * @param Throwable $e Excepción a serializar.
     *
     * @return string JSON con `{class, message}`. Si `json_encode`
     *                falla, devuelve un JSON de fallback.
     */
    private function serializeExceptionForLog(Throwable $e): string
    {
        return json_encode([
            'class'   => get_class($e),
            'message' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE) ?: '{"class":"unknown","message":"unserializable"}';
    }

    /**
     * Convierte la lista de observaciones de ARCA en una sola línea
     * legible: `[codigo] mensaje | [codigo2] mensaje2 | …`.
     *
     * @param array<int, array{codigo: int, mensaje: string}> $observaciones
     *        Lista de observaciones que devuelve `FECAESolicitar` cuando
     *        `Resultado='R'`.
     *
     * @return string Observaciones concatenadas.
     */
    private static function observacionesAsString(array $observaciones): string
    {
        $parts = [];
        foreach ($observaciones as $o) {
            $parts[] = sprintf('[%d] %s', $o['codigo'], $o['mensaje']);
        }
        return implode(' | ', $parts);
    }

    /**
     * Valida la respuesta de una fila `emitido` y la convierte al
     * shape de retorno. Si la fila no tiene CAE, número o `cae_fch_vto`,
     * o el `response_json` no es coherente, lanza `IdempotencyStateException`
     * (reconciliación manual requerida).
     *
     * @param FilaEmision $fila       Fila en estado `emitido`.
     * @param string      $externalId UUID v4 (sólo para mensajes de error).
     *
     * @return ComprobanteEmitido Snapshot normalizado del comprobante emitido.
     *
     * @throws IdempotencyStateException Fila en estado distinto de `emitido`,
     *                                   o sin CAE/número/vto, o `response_json`
     *                                   no parseable.
     */
    private function returnCachedEmitido(FilaEmision $fila, string $externalId): ComprobanteEmitido
    {
        if ($fila->estado !== FilaEmision::ESTADO_EMITIDO) {
            throw new IdempotencyStateException(
                "emitir: returnCachedEmitido llamado con estado '{$fila->estado}' (esperado emitido)"
            );
        }
        if ($fila->cae === null || $fila->cae === ''
            || $fila->cbteNroConfirmado === null
            || $fila->caeFchVto === null
        ) {
            throw new IdempotencyStateException(
                "emitir: fila {$externalId} marcada emitido pero sin CAE/numero/vto. "
                . "Reconciliacion manual requerida."
            );
        }
        $raw = $fila->responseJson;
        $payload = $raw !== null ? json_decode($raw, true) : null;
        if (!is_array($payload)) {
            $payload = [];
        }
        $resultado = isset($payload['resultado']) ? (string) $payload['resultado'] : 'A';
        // Preferir el formato YYYYMMDD del response_json original; si
        // no esta, normalizar desde el DateTimeImmutable de la fila
        // (que esta como YYYY-MM-DD por la columna DATE).
        $caeFchVto = isset($payload['cae_fch_vto'])
            ? (string) $payload['cae_fch_vto']
            : ($fila->caeFchVto?->format('Ymd'));
        $cbteFch = isset($payload['cbte_fch'])
            ? (string) $payload['cbte_fch']
            : ($fila->cbteFchEnviado?->format('Ymd'));
        $cbteFch = self::formatFchYmdDash($cbteFch);
        $montoTotal = (string) ($payload['monto_total'] ?? '0.00');
        $montoNeto  = (string) ($payload['monto_neto']  ?? $montoTotal);
        $montoIva   = (string) ($payload['monto_iva']   ?? '0.00');
        $cbtesAsoc  = [];
        if (isset($payload['cbtes_asoc']) && is_array($payload['cbtes_asoc'])) {
            $cbtesAsoc = $payload['cbtes_asoc'];
        }
        return new ComprobanteEmitido(
            cbteTipo: $fila->cbteTipo,
            cbteNro: (int) $fila->cbteNroConfirmado,
            cbteFch: $cbteFch,
            cae: (string) $fila->cae,
            caeFchVto: $caeFchVto,
            montoTotal: $montoTotal,
            montoNeto: $montoNeto,
            montoIva: $montoIva,
            monId: (string) ($payload['mon_id'] ?? 'PES'),
            monCotiz: (string) ($payload['mon_cotiz'] ?? '1.00'),
            puntoVenta: $fila->puntoVenta,
            cuit: (int) $fila->cuit,
            receptorDocumentoTipo: isset($payload['receptor_documento_tipo'])
                ? (int) $payload['receptor_documento_tipo'] : null,
            receptorDocumentoNro: isset($payload['receptor_documento_nro'])
                ? (string) $payload['receptor_documento_nro'] : null,
            receptorCondicionIva: isset($payload['receptor_condicion_iva'])
                ? (string) $payload['receptor_condicion_iva'] : null,
            items: isset($payload['items']) && is_array($payload['items'])
                ? $payload['items'] : [],
            observaciones: [],
            origen: isset($payload['origen']) ? (string) $payload['origen'] : null,
            externalId: $externalId,
            resultado: $resultado,
            cbtesAsoc: $cbtesAsoc,
        );
    }

    /**
     * Construye el DTO de retorno a partir de un `ComprobanteResponse`
     * aprobado fresco de ARCA.
     *
     * Aplica el `IvaCalculator` para derivar `monto_total`/`monto_neto`/
     * `monto_iva` a partir de los `items` del comprobante y la alícuota
     * de la NC padre (si aplica).
     *
     * @param Comprobante         $comprobante Comprobante emitido.
     * @param ComprobanteResponse $response    Respuesta de `FECAESolicitar`.
     * @param string              $externalId  UUID v4.
     * @param string              $cuit        CUIT emisor.
     * @param string              $cbteFchDb   Fecha del comprobante en formato
     *                                        `YYYY-MM-DD` (la del DB).
     *
     * @return ComprobanteEmitido Snapshot normalizado.
     */
    private function buildEmitidoArray(
        Comprobante $comprobante,
        ComprobanteResponse $response,
        string $externalId,
        string $cuit,
        string $cbteFchDb
    ): ComprobanteEmitido {
        $discrimina = TiposComprobante::discriminaIva($comprobante->cbteTipo);
        $res = IvaCalculator::calcular(
            $comprobante->items,
            $discrimina,
            $comprobante->importeNoGravado,
            $comprobante->importeExento,
            $comprobante->importeOtrosTributos,
        );
        return new ComprobanteEmitido(
            cbteTipo: $comprobante->cbteTipo,
            cbteNro: $response->cbteNro,
            cbteFch: $cbteFchDb,
            cae: (string) $response->cae,
            caeFchVto: (string) $response->caeFchVto,
            montoTotal: $res->total,
            montoNeto: $res->netoGravado,
            montoIva: $res->ivaTotal,
            monId: $comprobante->monId,
            monCotiz: $comprobante->monCotiz,
            puntoVenta: $comprobante->puntoVenta,
            cuit: (int) $cuit,
            receptorDocumentoTipo: $comprobante->receptorDocumentoTipo,
            receptorDocumentoNro: $comprobante->receptorDocumentoNro,
            receptorCondicionIva: $comprobante->receptorCondicionIva,
            items: $comprobante->items,
            observaciones: [],
            origen: 'nuevo',
            externalId: $externalId,
            resultado: $response->resultado,
            cbtesAsoc: $comprobante->cbtesAsoc,
        );
    }

    /**
     * Normaliza una fecha en formato YYYYMMDD o YYYY-MM-DD a YYYY-MM-DD.
     * Usado por returnCachedEmitido() para aceptar el formato
     * `cbte_fch` legacy (YYYYMMDD, guardado en response_json de v0.2.x).
     */
    private static function formatFchYmdDash(string $fch): string
    {
        if (preg_match('/^\d{8}$/', $fch)) {
            return substr($fch, 0, 4) . '-' . substr($fch, 4, 2) . '-' . substr($fch, 6, 2);
        }
        return $fch;
    }
}
