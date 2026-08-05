<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Rbbsoft\ArcaSdk\Config\Config;

/**
 * Tests de Config para los campos del padron A13 (Phase 2 -
 * integracion). Cubre la resolucion de URL por env y la presencia de
 * las constantes publicas.
 */
final class ConfigTest extends TestCase
{
    private const CUIT = '20123456786';

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeConfig(array $overrides = []): Config
    {
        $base = [
            'cuit'        => self::CUIT,
            'punto_venta' => 1,
            'cert_path'   => 'C:\xampp\htdocs\Certificados\MiCertificado.pem',
            'key_path'    => 'C:\xampp\htdocs\Certificados\MiClavePrivada.key',
            'db_dsn'      => 'mysql:host=localhost;dbname=arca_facturador_test;charset=utf8mb4',
            'db_user'     => 'root',
            'db_pass'     => '',
        ];
        return Config::fromArray(array_merge($base, $overrides));
    }

    public function test_config_padronUrl_se_resuelve_a_HOMO_en_env_homo(): void
    {
        $cfg = $this->makeConfig(['env' => 'homo']);
        $this->assertSame(Config::URL_PADRON_HOMO, $cfg->padronUrl,
            'En env=homo Config::padronUrl debe ser URL_PADRON_HOMO');
    }

    public function test_config_padronUrl_se_resuelve_a_PROD_en_env_prod(): void
    {
        $cfg = $this->makeConfig(['env' => 'prod']);
        $this->assertSame(Config::URL_PADRON_PROD, $cfg->padronUrl,
            'En env=prod Config::padronUrl debe ser URL_PADRON_PROD');
    }

    public function test_constantes_URL_PADRON_son_strings_no_vacias_y_distintas(): void
    {
        $this->assertIsString(Config::URL_PADRON_HOMO);
        $this->assertIsString(Config::URL_PADRON_PROD);
        $this->assertNotEmpty(Config::URL_PADRON_HOMO);
        $this->assertNotEmpty(Config::URL_PADRON_PROD);
        $this->assertNotSame(Config::URL_PADRON_HOMO, Config::URL_PADRON_PROD,
            'Las URLs homo y prod del padron deben ser distintas');
        $this->assertStringContainsString('personaServiceA13', Config::URL_PADRON_HOMO);
        $this->assertStringContainsString('personaServiceA13', Config::URL_PADRON_PROD);
    }

    public function test_config_padronUrl_usa_misma_politica_que_wsaa_y_wsfe(): void
    {
        // Consistencia: padronUrl debe cambiar con env igual que wsaaUrl
        // y wsfeUrl. Si uno cambia y el otro no, hay drift.
        $cfgHomo = $this->makeConfig(['env' => 'homo']);
        $cfgProd = $this->makeConfig(['env' => 'prod']);

        $this->assertNotSame($cfgHomo->wsaaUrl, $cfgProd->wsaaUrl);
        $this->assertNotSame($cfgHomo->wsfeUrl, $cfgProd->wsfeUrl);
        $this->assertNotSame($cfgHomo->padronUrl, $cfgProd->padronUrl,
            'padronUrl debe cambiar entre homo y prod, igual que wsaaUrl y wsfeUrl');
    }
}
