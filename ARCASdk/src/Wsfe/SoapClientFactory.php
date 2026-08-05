<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Wsfe;

use Rbbsoft\ArcaSdk\Config\Config;
use SoapClient;

/**
 * Fabrica de \SoapClient para WSFE. Encapsula:
 *  - URL (WSFE homo / prod) + WSDL
 *  - Timeout (Config::soapTimeout)
 *  - Politica de cache WSDL por ambiente (homo: WSDL_CACHE_NONE,
 *    prod: WSDL_CACHE_DISK, respetando ini soap.wsdl_cache_ttl)
 *
 * Politica WSDL por ambiente (plan maestro, seccion 4 punto 8):
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
final class SoapClientFactory
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
    public function optionsForWsfe(): array
    {
        $opts = [
            'soap_version'       => SOAP_1_1,
            'connection_timeout' => $this->config->soapTimeout,
        ];
        if ($this->config->env === Config::ENV_HOMO) {
            $opts['cache_wsdl'] = WSDL_CACHE_NONE;
        } else {
            $opts['cache_wsdl'] = WSDL_CACHE_DISK;
        }
        return $opts;
    }

    /**
     * Construye un \SoapClient para WSFE.
     *
     * Si el WSDL es remoto y el ambiente es homo, modificamos
     * temporalmente ini soap.wsdl_cache_enabled/ttl para garantizar
     * que no se use cache (cache_wsdl per-instance no siempre
     * prevalece si ini esta habilitado).
     */
    public function create(): SoapClient
    {
        $wsdl = $this->wsdlPath ?? ($this->config->wsfeUrl . '?WSDL');
        $opts = $this->optionsForWsfe();
        return $this->createSoapClient($wsdl, $opts);
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
