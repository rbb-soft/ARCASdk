<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Wsaa;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Rbbsoft\ArcaSdk\Config\Config;
use Rbbsoft\ArcaSdk\Exceptions\WsaaCeeYaPoseeTaException;
use Rbbsoft\ArcaSdk\Exceptions\WsaaException;
use SimpleXMLElement;
use SoapClient;
use SoapFault;
use Throwable;

/**
 * Cliente WSAA: autentica contra ARCA y entrega un Ticket de Acceso (TA)
 * vigente, usando el cache de dos niveles.
 *
 * Flujo de getToken($wsn):
 *   1. Llama a TicketCacheInterface::loadOrAcquire(cuit, wsn, producer).
 *      El cache (MysqlTicketCache) toma el named lock, hace
 *      double-check, y si no hay TA vigente invoca al producer.
 *   2. El producer (este cliente en su nivel mas bajo):
 *      a. Captura un unico "now" UTC.
 *      b. Construye el TRA via TraBuilder con el skew y el TTL de Config.
 *      c. Firma el TRA con CmsSigner (PKCS#7 detached, base64).
 *      d. Llama a loginCms sobre el SoapClient inyectado.
 *      e. Parsea la respuesta XML, extrae token/sign, los persiste como
 *         un TicketDeAcceso en UTC.
 *      f. Si loginCms lanza SoapFault con "ya posee TA" -> poll
 *         acotado del cache y, si no aparece, WsaaCeeYaPoseeTaException.
 *      g. Si la respuesta no es SOAP-fault pero tampoco parseable ->
 *         WsaaException.
 *
 * Inyeccion:
 *   - Config: timeouts, skew, TTL, URLs (la URL no se usa directamente
 *     porque el SoapClient ya viene construido; queda en Config para
 *     que el caller construya el SoapClient con la URL correcta).
 *   - SoapClient: cualquier \SoapClient construido con la WSDL de WSAA.
 *     Se usa __soapCall para ser tolerante a WSDL/no-WSDL mode.
 *   - TicketCacheInterface: el cache ya configurado (L1+L2 o el L2 solo).
 *   - Closure $clock: clock inyectable para tests; default now() UTC.
 *   - CmsSigner / TraBuilder: opcionales; por defecto se construyen
 *     instancias frescas, pero se pueden inyectar para tests mas
 *     finos.
 */
final class WsaaClient
{
    /** Tiempo maximo de polling cuando loginCms devuelve "ya posee TA". */
    private int $ceeYaPoseeTaPollSeconds;
    private int $ceeYaPoseeTaPollIntervalMs;

    private readonly Closure $clock;
    private readonly TraBuilder $traBuilder;
    private readonly CmsSigner $signer;

    /**
     * @param (Closure(): DateTimeImmutable)|null $clock Default: now UTC.
     * @param int|null $ceeYaPoseeTaPollSeconds Override del tiempo maximo
     *         de polling cuando loginCms devuelve "ya posee TA". Solo
     *         para tests; en produccion queda en 30s.
     * @param int|null $ceeYaPoseeTaPollIntervalMs Override del intervalo
     *         entre lecturas del cache. Solo para tests.
     */
    public function __construct(
        private readonly Config $config,
        private readonly SoapClient $soap,
        private readonly TicketCacheInterface $cache,
        ?Closure $clock = null,
        ?TraBuilder $traBuilder = null,
        ?CmsSigner $signer = null,
        ?int $ceeYaPoseeTaPollSeconds = null,
        ?int $ceeYaPoseeTaPollIntervalMs = null,
    ) {
        $this->clock = $clock ?? static fn(): DateTimeImmutable
            => new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->traBuilder = $traBuilder ?? new TraBuilder();
        $this->signer = $signer ?? new CmsSigner();
        $this->ceeYaPoseeTaPollSeconds = $ceeYaPoseeTaPollSeconds ?? 30;
        $this->ceeYaPoseeTaPollIntervalMs = $ceeYaPoseeTaPollIntervalMs ?? 500;
    }

    /**
     * Devuelve un TA vigente para el WSN solicitado. Si el cache no
     * tiene uno valido, adquiere el lock correspondiente, genera un
     * TRA fresco, lo firma, llama a loginCms y persiste el resultado.
     *
     * @param string $wsn 'wsfe' o 'wsaa' (ARCA). v1: el caller pasa
     *                    explicitamente el WSN que quiere autenticar.
     */
    public function getToken(string $wsn): TicketDeAcceso
    {
        $cuit = $this->config->cuit;
        return $this->cache->loadOrAcquire(
            $cuit,
            $wsn,
            fn(): TicketDeAcceso => $this->fetchNewTicket($wsn)
        );
    }

    /**
     * Productor de TA fresco: TRA + firma + loginCms + parse. Solo se
     * invoca cuando el cache no tiene un TA vigente. Lanza excepciones
     * tipadas; el cache las propaga al caller.
     */
    private function fetchNewTicket(string $wsn): TicketDeAcceso
    {
        $now = ($this->clock)();
        $tra = $this->traBuilder->build(
            service: $wsn,
            now: $now,
            generationSkewSeconds: $this->config->wsaaGenerationSkew,
            ttlSeconds: $this->config->wsaaTraTtl,
        );
        $cms = $this->signer->sign(
            $tra,
            $this->config->certPath,
            $this->config->keyPath,
        );
        $result = $this->callLoginCms($cms, $wsn);
        if ($result instanceof TicketDeAcceso) {
            // "ya posee TA" -> el cache ya tiene un TA publicado por
            // otro worker; lo devolvemos tal cual.
            return $result;
        }
        return $this->parseCredentials($result, $wsn, $now);
    }

    /**
     * Llama a SoapClient->__soapCall('loginCms', ...). Maneja:
     *  - SoapFault con "ya posee TA" -> polling del cache y retorno del
     *    TicketDeAcceso publicado por otro worker.
     *  - Otros SoapFault -> WsaaException(infra).
     *  - Resultado no-string ni stdClass con loginCmsReturn -> WsaaException.
     *
     * El caso "ya posee TA" retorna TicketDeAcceso (no XML) para que
     * fetchNewTicket lo devuelva sin re-parsear las credenciales.
     */
    private function callLoginCms(string $cms, string $wsn): string|TicketDeAcceso
    {
        try {
            $result = $this->soap->__soapCall('loginCms', [['in0' => $cms]]);
        } catch (SoapFault $e) {
            $msg = $e->getMessage();
            if ($this->isCeeYaPoseeTa($msg)) {
                return $this->handleCeeYaPoseeTa($wsn);
            }
            throw new WsaaException(
                sprintf('WSAA loginCms SoapFault: %s', $msg),
                0,
                $e
            );
        } catch (Throwable $e) {
            throw new WsaaException(
                sprintf('WSAA loginCms error: %s: %s', get_class($e), $e->getMessage()),
                0,
                $e
            );
        }

        if (is_string($result)) {
            // Caso de borde PHP/XAMPP: SoapClient a veces NO lanza
            // SoapFault y devuelve el body del fault como string. Sin
            // este catch, parseCredentials buscaba <credentials> y
            // lanzaba "credenciales incompletas" — false positive que
            // dejaba la fila en estado zombie (WsaaException no era
            // transient para el orquestador).
            //
            // Cualquier respuesta que NO contenga <credentials> es un
            // error: o fault, o un XML no esperado. Lo procesamos
            // antes de pasarlo a parseCredentials.
            if (!str_contains($result, '<credentials>')) {
                if (preg_match('/<soap(?:env)?:Fault[\s>]/i', $result)) {
                    $faultString = $this->extractFaultString($result) ?? $result;
                    if ($this->isCeeYaPoseeTa($faultString)) {
                        return $this->handleCeeYaPoseeTa($wsn);
                    }
                    throw new WsaaException(
                        sprintf('WSAA loginCms SoapFault (as string): %s', $faultString)
                    );
                }
                // No es fault, tampoco credentials. Es un error no
                // esperado: devolver un mensaje accionable.
                throw new WsaaException(
                    'WSAA loginCms: respuesta sin <credentials> y sin SoapFault (revisar formato). ' .
                    'Excerpt: ' . substr($result, 0, 500)
                );
            }
            return $result;
        }
        if (is_object($result) && property_exists($result, 'loginCmsReturn')) {
            return (string) $result->loginCmsReturn;
        }
        throw new WsaaException('WSAA loginCms: respuesta con formato inesperado');
    }

    /**
     * Extrae el <faultstring> de un envelope SOAP fault. Devuelve null
     * si no se encuentra (XML malformado, otra estructura).
     */
    private function extractFaultString(string $xml): ?string
    {
        if (!preg_match('/<soap(?:env)?:faultstring[^>]*>([^<]+)<\/soap(?:env)?:faultstring>/i', $xml, $m)) {
            return null;
        }
        return html_entity_decode(trim($m[1]), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * Detecta el mensaje "ya posee TA" tanto en homologacion como en
     * produccion. La cadena es case-insensitive y tolerante a variantes
     * de acentos (ARCA a veces escribe "valido" con o sin tilde).
     */
    private function isCeeYaPoseeTa(string $message): bool
    {
        $normalized = strtolower($message);
        // cubre: "ya posee un TA", "ya posee un TA valido/valido"
        return str_contains($normalized, 'ya posee un ta')
            || str_contains($normalized, 'ya posee un t.a')
            || str_contains($normalized, 'cee ya posee');
    }

    /**
     * Politica "ya posee TA": polling acotado del cache porque otro
     * worker puede estar por publicar el TA. Si despues del polling
     * sigue ausente o vencido, lanza WsaaCeeYaPoseeTaException con
     * un mensaje accionable.
     *
     * Retorna el TicketDeAcceso (no XML) para que el caller
     * (fetchNewTicket) lo devuelva sin re-parsear.
     */
    private function handleCeeYaPoseeTa(string $wsn): TicketDeAcceso
    {
        $cuit = $this->config->cuit;
        $deadline = ($this->clock)()->modify('+' . $this->ceeYaPoseeTaPollSeconds . ' seconds');
        $intervalUs = $this->ceeYaPoseeTaPollIntervalMs * 1000;
        $existing = null;

        while (($this->clock)() < $deadline) {
            $existing = $this->cache->load($cuit, $wsn);
            if ($existing !== null) {
                return $existing;
            }
            usleep($intervalUs);
        }

        // Ultima lectura por las dudas
        $existing = $this->cache->load($cuit, $wsn);
        if ($existing !== null) {
            return $existing;
        }

        $lapsoHomo = '10 min (homologacion)';
        $lapsoProd = '2 min (produccion)';
        throw new WsaaCeeYaPoseeTaException(sprintf(
            'WSAA reporto "CEE ya posee un TA valido" para (cuit=%s, wsn=%s) y tras '
            . '%.0f s de polling el cache no contiene un TA vigente. Posible causa: '
            . 'otro proceso (externo a este SDK) adquirio un TA y no lo publico, o el '
            . 'cache fue vaciado mientras el TA seguia vigente en ARCA. ARCA documenta '
            . 'preventivos de %s y %s (sujetos a cambio).',
            $cuit,
            $wsn,
            $this->ceeYaPoseeTaPollSeconds,
            $lapsoHomo,
            $lapsoProd
        ));
    }

    /**
     * Cuando el polling encuentra un TA en el cache, lo devolvemos
     * directo. Este metodo ya no se usa (se reemplaz\u00f3 por el retorno
     * directo de TicketDeAcceso en handleCeeYaPoseeTa), pero queda
     * documentado por si se quiere reintroducir una via de cache
     * de credenciales en una fase futura.
     */
    private function ticketToCredentialsXml(TicketDeAcceso $t): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<loginCmsResponse xmlns="http://wsaa.view.sua.dvadac.desein.afip.gov">'
            . '<credentials>'
            . '<token>' . htmlspecialchars($t->token, ENT_XML1) . '</token>'
            . '<sign>' . htmlspecialchars($t->sign, ENT_XML1) . '</sign>'
            . '<expirationTime>' . $t->expirationTimeUtc->format('Y-m-d\TH:i:sP') . '</expirationTime>'
            . '</credentials>'
            . '</loginCmsResponse>';
    }

    /**
     * Parsea el XML de credenciales que devuelve WSAA. Acepta fechas
     * con offset ISO 8601 o el formato ARCA "AAAA-MM-DDTHH:MM:SS.fff-03:00"
     * y SIEMPRE devuelve la expiration en UTC.
     *
     * @param string $credentialsXml Fragmento <loginCmsResponse>.
     */
    private function parseCredentials(string $credentialsXml, string $wsn, DateTimeImmutable $now): TicketDeAcceso
    {
        $prevUseErrors = libxml_use_internal_errors(true);
        $prevEntityLoader = null;
        try {
            $xml = new SimpleXMLElement($credentialsXml);
        } catch (Throwable $e) {
            throw new WsaaException(
                'WSAA loginCms: respuesta XML no parseable: ' . $e->getMessage(),
                0,
                $e
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prevUseErrors);
        }

        $token = isset($xml->credentials->token) ? (string) $xml->credentials->token : '';
        $sign  = isset($xml->credentials->sign)  ? (string) $xml->credentials->sign  : '';
        // ARCA real (homo/prod) devuelve expirationTime dentro de <header>.
        // Algunos tests/fixtures legacy lo ubicaban dentro de <credentials>;
        // mantenemos ese fallback para no romper suites existentes.
        $rawExp = isset($xml->header->expirationTime) ? (string) $xml->header->expirationTime
               : (isset($xml->credentials->expirationTime) ? (string) $xml->credentials->expirationTime : '');

        if ($token === '' || $sign === '' || $rawExp === '') {
            throw new WsaaException('WSAA loginCms: credenciales incompletas (token/sign/expirationTime faltantes)');
        }

        try {
            $expUtc = TicketDeAcceso::normalizeExpiration($rawExp);
        } catch (Throwable $e) {
            throw new WsaaException(
                'WSAA loginCms: expirationTime con formato invalido (' . $rawExp . '): ' . $e->getMessage(),
                0,
                $e
            );
        }

        return new TicketDeAcceso(
            cuit: $this->config->cuit,
            wsn: $wsn,
            token: $token,
            sign: $sign,
            expirationTimeUtc: $expUtc,
            source: $this->config->env === Config::ENV_PROD ? 'wsaa' : 'wsfe',
        );
    }
}
