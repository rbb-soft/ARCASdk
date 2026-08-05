<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Padron;

use Rbbsoft\ArcaSdk\Config\Config;
use SoapClient;

/**
 * Fabrica de \SoapClient para el padron A13. Encapsula:
 *  - URL (PADRON homo / prod) + WSDL
 *  - Timeout (Config::soapTimeout)
 *  - Politica de cache WSDL por ambiente (homo: WSDL_CACHE_NONE,
 *    prod: WSDL_CACHE_DISK, respetando ini soap.wsdl_cache_ttl).
 *
 * Politica WSDL por ambiente (replica del patron de Wsfe/SoapClientFactory):
 *  - homo: cache_wsdl = WSDL_CACHE_NONE, para que cualquier cambio del
 *    contrato se refleje inmediatamente. Tambien fija los ini de PHP
 *    soap.wsdl_cache_enabled=0 / soap.wsdl_cache_ttl=0 durante la vida
 *    de la peticion (es global a proceso; los cambiamos en runtime
 *    alrededor de new \SoapClient()).
 *  - prod: cache_wsdl = WSDL_CACHE_DISK, ttl 86400 s por default en
 *    php.ini. Procedimiento de invalidacion documentado: borrar el
 *    archivo de cache (sys_get_temp_dir() + "wsdl-*-tmp") cuando ARCA
 *    cambie el contrato.
 */
final class PadronSoapClientFactory
{
    private ?string $wsdlPath;

    public function __construct(
        private readonly Config $config,
        ?string $wsdlPath = null,
    ) {
        $this->wsdlPath = $wsdlPath;
    }

    /**
     * @return array<string, mixed>
     */
    public function optionsForPadron(): array
    {
        $opts = [
            'soap_version'       => SOAP_1_1,
            'connection_timeout' => $this->config->soapTimeout,
            // trace=1 es obligatorio para que __getLastResponse() devuelva
            // el body crudo incluso cuando SoapClient no lanza SoapFault.
            // Sin esto, el PadronClient::callSoap() clasifica la respuesta
            // como empty_body aunque ARCA haya devuelto un envelope valido.
            'trace'              => 1,
        ];
        if ($this->config->env === Config::ENV_HOMO) {
            $opts['cache_wsdl'] = WSDL_CACHE_NONE;
        } else {
            $opts['cache_wsdl'] = WSDL_CACHE_DISK;
        }
        return $opts;
    }

    /**
     * Construye un \SoapClient para el padron A13.
     *
     * Si el WSDL es remoto y el ambiente es homo, modificamos
     * temporalmente ini soap.wsdl_cache_enabled/ttl para garantizar
     * que no se use cache (cache_wsdl per-instance no siempre
     * prevalece si ini esta habilitado).
     */
    public function create(): SoapClient
    {
        $wsdl = $this->resolveWsdl();
        $opts = $this->optionsForPadron();
        return $this->createSoapClient($wsdl, $opts);
    }

    /**
     * Resolucion defensiva del WSDL (mismo patron que
     * Container::defaultSoapFactory()):
     *  - Si $wsdlPath fue inyectado, se respeta tal cual.
     *  - Si no, se toma $config->padronUrl.
     *  - Si el valor es una URL HTTP/HTTPS y ya termina en `?WSDL`
     *    (caso de las constantes Config::URL_PADRON_*), se usa tal
     *    cual para no duplicar el query string. Si no lo trae, se
     *    agrega.
     *  - Si el valor NO es una URL (path local tipo
     *    `tempnam(...) . '.xml'`, frecuente en tests), se respeta
     *    tal cual sin agregarle `?WSDL` (un path local no es una URL
     *    y SoapClient lo maneja como `file://` sin query string).
     *
     * Testeable via Reflection (no hay API SoapClient para conocer
     * la URL WSDL tras la construccion).
     */
    private function resolveWsdl(): string
    {
        $base = $this->wsdlPath ?? $this->config->padronUrl;
        if (str_contains($base, '://') && !str_contains($base, '?WSDL')) {
            return $base . '?WSDL';
        }
        return $base;
    }

    /**
     * Crea un SoapClient garantizando la politica de cache configurada.
     * Test-friendly: extraido para poder sobreescribir ini.
     */
    private function createSoapClient(string $wsdl, array $opts): SoapClient
    {
        $prevEnabled = ini_get('soap.wsdl_cache_enabled');
        $prevTtl     = ini_get('soap.wsdl_cache_ttl');

        if ($this->config->env === Config::ENV_HOMO) {
            @ini_set('soap.wsdl_cache_enabled', '0');
            @ini_set('soap.wsdl_cache_ttl', '0');
        }
        try {
            return new SoapClient($wsdl, $opts);
        } finally {
            @ini_set('soap.wsdl_cache_enabled', $prevEnabled === false ? '1' : $prevEnabled);
            @ini_set('soap.wsdl_cache_ttl',     $prevTtl === false ? '86400' : $prevTtl);
        }
    }
}
