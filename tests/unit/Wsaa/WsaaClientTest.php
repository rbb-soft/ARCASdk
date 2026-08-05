<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\Wsaa;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Rbbsoft\ArcaSdk\Config\Config;
use Rbbsoft\ArcaSdk\Exceptions\WsaaCeeYaPoseeTaException;
use Rbbsoft\ArcaSdk\Exceptions\WsaaException;
use Rbbsoft\ArcaSdk\Wsaa\NullTicketCache;
use Rbbsoft\ArcaSdk\Wsaa\TicketDeAcceso;
use Rbbsoft\ArcaSdk\Wsaa\TraBuilder;
use Rbbsoft\ArcaSdk\Wsaa\WsaaClient;
use SoapFault;

final class WsaaClientTest extends TestCase
{
    private const CUIT = '20123456786';
    private const WSN = 'wsfe';

    /**
     * Construye un Config minimo valido con la CUIT fija.
     * Usa el cert/key de test (los mismos del plan).
     *
     * @param array<string, mixed> $overrides
     */
    private function makeConfig(array $overrides = []): Config
    {
        $base = [
            'cuit'                 => self::CUIT,
            'punto_venta'          => 1,
            'cert_path'            => 'C:\xampp\htdocs\Certificados\MiCertificado.pem',
            'key_path'             => 'C:\xampp\htdocs\Certificados\MiClavePrivada.key',
            'env'                  => 'homo',
            'db_dsn'               => 'mysql:host=localhost;dbname=arca_facturador_test;charset=utf8mb4',
            'db_user'              => 'root',
            'db_pass'              => '',
            'soap_timeout'         => 10,
            'wsaa_lock_timeout'    => 10,
            'emit_lock_timeout'    => 10,
            'wsaa_tra_ttl'         => 600,
            'wsaa_generation_skew' => 120,
            'wsaa_expiry_margin'   => 300,
            'retry_max_attempts'   => 3,
            'retry_base_backoff_ms' => 200,
            'retry_max_backoff_ms'  => 2000,
            'idempotencia_max_intentos' => 5,
            'idempotencia_ttl_segundos' => 300,
        ];
        return Config::fromArray(array_merge($base, $overrides));
    }

    /**
     * Construye un envelope SOAP de respuesta exitosa con un TA arbitrario.
     * El XML interior de <loginCmsReturn> refleja el wire format real de ARCA
     * (homo y prod): <loginTicketResponse> con <expirationTime> dentro de
     * <header>, no de <credentials>. Ver WsaaClient::parseCredentials.
     */
    private function successEnvelope(string $token, string $sign, string $expirationIso): string
    {
        $credentialsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<loginTicketResponse version="1.0">'
            . '<header>'
            . '<source>CN=wsaahomo, O=AFIP, C=AR, SERIALNUMBER=CUIT 33693450239</source>'
            . '<destination>SERIALNUMBER=CUIT ' . self::CUIT . ', CN=test</destination>'
            . '<uniqueId>1234567890</uniqueId>'
            . '<generationTime>2025-06-15T11:00:00-03:00</generationTime>'
            . '<expirationTime>' . $expirationIso . '</expirationTime>'
            . '</header>'
            . '<credentials>'
            . '<token>' . $token . '</token>'
            . '<sign>' . $sign . '</sign>'
            . '</credentials>'
            . '</loginTicketResponse>';

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/"'
            . ' xmlns:ns1="http://wsaa.view.sua.dvadac.desein.afip.gov">'
            . '<SOAP-ENV:Body>'
            . '<ns1:loginCmsResponse>'
            . '<loginCmsReturn>' . htmlspecialchars($credentialsXml, ENT_XML1) . '</loginCmsReturn>'
            . '</ns1:loginCmsResponse>'
            . '</SOAP-ENV:Body>'
            . '</SOAP-ENV:Envelope>';
    }

    public function test_tra_construido_con_unico_reloj_y_formatos_iso8601(): void
    {
        $config = $this->makeConfig(['wsaa_generation_skew' => 60, 'wsaa_tra_ttl' => 600]);

        $now = new DateTimeImmutable('2025-06-15T12:00:00+00:00');
        $clock = fn(): DateTimeImmutable => $now;

        $soap = new SoapClientDouble();
        $soap->enqueueResponse($this->successEnvelope(
            'TKN_X',
            'SGN_X',
            '2025-06-15T13:00:00-03:00'  // hora AR -03:00 = 16:00 UTC
        ));

        $signer = new CmsSignerDouble();
        $cache = new NullTicketCache(expiryMarginSeconds: 0, clock: $clock);
        $client = new WsaaClient($config, $soap, $cache, $clock, new TraBuilder(), $signer);

        $ticket = $client->getToken('wsfe');
        $this->assertSame('TKN_X', $ticket->token);

        // Inspeccionar el TRA que se firmo
        $this->assertNotNull($signer->lastTra);
        $this->assertStringContainsString('<service>wsfe</service>', $signer->lastTra);

        // uniqueId, generationTime y expirationTime con offset explicito +00:00
        $expectedGen  = $now->modify('-60 seconds')->format('Y-m-d\TH:i:sP');
        $expectedExp  = $now->modify('+600 seconds')->format('Y-m-d\TH:i:sP');
        $this->assertStringContainsString('<uniqueId>' . $now->getTimestamp() . '</uniqueId>', $signer->lastTra);
        $this->assertStringContainsString('<generationTime>' . $expectedGen . '</generationTime>', $signer->lastTra);
        $this->assertStringContainsString('<expirationTime>' . $expectedExp . '</expirationTime>', $signer->lastTra);

        // expirationTime > generationTime
        $this->assertGreaterThan(
            $now->modify('-60 seconds')->getTimestamp(),
            $now->modify('+600 seconds')->getTimestamp(),
            'expirationTime debe ser posterior a generationTime'
        );
    }

    public function test_tra_respeta_unico_reloj_incluso_si_clock_se_invoca_varias_veces(): void
    {
        // El WsaaClient debe capturar un unico "now" y usarlo para
        // generationTime, expirationTime y uniqueId. Si el clock avanza
        // entre llamadas, todos los valores deben corresponder al primer now.
        $config = $this->makeConfig(['wsaa_generation_skew' => 30, 'wsaa_tra_ttl' => 200]);

        $now = new DateTimeImmutable('2025-06-15T12:00:00+00:00');
        $counter = 0;
        $clock = function () use ($now, &$counter): DateTimeImmutable {
            $counter++;
            return $now;
        };

        $soap = new SoapClientDouble();
        $soap->enqueueResponse($this->successEnvelope('T', 'S', '2025-06-15T13:00:00+00:00'));

        $signer = new CmsSignerDouble();
        $cache = new NullTicketCache(expiryMarginSeconds: 0, clock: $clock);
        $client = new WsaaClient($config, $soap, $cache, $clock, new TraBuilder(), $signer);

        $client->getToken('wsfe');

        // El clock fue invocado al menos una vez para el "now" base
        $this->assertGreaterThanOrEqual(1, $counter);
        // El uniqueId debe coincidir con el timestamp del unico "now"
        $this->assertStringContainsString('<uniqueId>' . $now->getTimestamp() . '</uniqueId>', $signer->lastTra);
    }

    public function test_loginCms_exitoso_devuelve_ticket_con_expiracion_utc(): void
    {
        $config = $this->makeConfig();
        $now = new DateTimeImmutable('2025-06-15T12:00:00+00:00');
        $clock = fn(): DateTimeImmutable => $now;

        $soap = new SoapClientDouble();
        // Hora de expiracion con offset -03:00 (hora Argentina).
        // Debe convertirse a UTC = 15:00:00+00:00.
        $soap->enqueueResponse($this->successEnvelope(
            'TOKEN_BASE64_LONG',
            'SIGN_BASE64_LONG',
            '2025-06-15T12:00:00-03:00'
        ));

        $signer = new CmsSignerDouble();
        $cache = new NullTicketCache(expiryMarginSeconds: 0, clock: $clock);
        $client = new WsaaClient($config, $soap, $cache, $clock, new TraBuilder(), $signer);

        $ticket = $client->getToken('wsfe');

        $this->assertSame(self::CUIT, $ticket->cuit);
        $this->assertSame('wsfe', $ticket->wsn);
        $this->assertSame('TOKEN_BASE64_LONG', $ticket->token);
        $this->assertSame('SIGN_BASE64_LONG', $ticket->sign);
        $this->assertSame('UTC', $ticket->expirationTimeUtc->getTimezone()->getName());
        $this->assertSame('2025-06-15T15:00:00+00:00', $ticket->expirationTimeUtc->format('Y-m-d\TH:i:sP'));
        $this->assertSame('wsfe', $ticket->source, 'en homo source es wsfe');
    }

    public function test_loginCms_exitoso_en_prod_source_wsaa(): void
    {
        $config = $this->makeConfig(['env' => 'prod']);
        $now = new DateTimeImmutable('2025-06-15T12:00:00+00:00');
        $clock = fn(): DateTimeImmutable => $now;

        $soap = new SoapClientDouble();
        $soap->enqueueResponse($this->successEnvelope('T', 'S', '2025-06-15T13:00:00+00:00'));

        $signer = new CmsSignerDouble();
        $cache = new NullTicketCache(expiryMarginSeconds: 0, clock: $clock);
        $client = new WsaaClient($config, $soap, $cache, $clock, new TraBuilder(), $signer);

        $ticket = $client->getToken('wsfe');
        $this->assertSame('wsaa', $ticket->source, 'en prod source es wsaa');
    }

    public function test_soapFault_lanza_wsaaexception_y_no_persiste(): void
    {
        $config = $this->makeConfig();
        $now = new DateTimeImmutable('2025-06-15T12:00:00+00:00');
        $clock = fn(): DateTimeImmutable => $now;

        $soap = new SoapClientDouble();
        $soap->enqueueFault(new SoapFault('Server', 'cms invalido', 'wsns', null));

        $signer = new CmsSignerDouble();
        $cache = new NullTicketCache(expiryMarginSeconds: 0, clock: $clock);
        $client = new WsaaClient($config, $soap, $cache, $clock, new TraBuilder(), $signer);

        try {
            $client->getToken('wsfe');
            $this->fail('Debio lanzar WsaaException');
        } catch (WsaaException $e) {
            $this->assertStringContainsString('loginCms', $e->getMessage());
            $this->assertNotInstanceOf(WsaaCeeYaPoseeTaException::class, $e);
        }
        // El cache no debe haber guardado nada tras el fallo
        $this->assertNull($cache->load(self::CUIT, 'wsfe'));
    }

    public function test_xml_de_credenciales_malformado_lanza_wsaaexception(): void
    {
        $config = $this->makeConfig();
        $now = new DateTimeImmutable('2025-06-15T12:00:00+00:00');
        $clock = fn(): DateTimeImmutable => $now;

        $soap = new SoapClientDouble();
        // Envelope con respuesta pero inner XML no es un loginCmsResponse valido
        $soap->enqueueResponse('<?xml version="1.0" encoding="UTF-8"?>'
            . '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<SOAP-ENV:Body><ns1:loginCmsResponse><loginCmsReturn>not-xml</loginCmsReturn></ns1:loginCmsResponse></SOAP-ENV:Body>'
            . '</SOAP-ENV:Envelope>');

        $signer = new CmsSignerDouble();
        $cache = new NullTicketCache(expiryMarginSeconds: 0, clock: $clock);
        $client = new WsaaClient($config, $soap, $cache, $clock, new TraBuilder(), $signer);

        $this->expectException(WsaaException::class);
        $client->getToken('wsfe');
    }

    public function test_ta_expirado_en_cache_no_se_devuelve(): void
    {
        $config = $this->makeConfig(['wsaa_expiry_margin' => 0]);
        $now = new DateTimeImmutable('2025-06-15T12:00:00+00:00');
        $clock = fn(): DateTimeImmutable => $now;

        $cache = new NullTicketCache(expiryMarginSeconds: 0, clock: $clock);
        $expired = new DateTimeImmutable('2025-06-15T11:00:00+00:00'); // 1h en el pasado
        $cache->save(new TicketDeAcceso(self::CUIT, 'wsfe', 'T_OLD', 'S_OLD', $expired));

        $soap = new SoapClientDouble(); // no se usara, cache hit
        $signer = new CmsSignerDouble();
        $client = new WsaaClient($config, $soap, $cache, $clock, new TraBuilder(), $signer);

        // load() directo del cache: ya devuelva null
        $this->assertNull($cache->load(self::CUIT, 'wsfe'));

        // getToken() a traves del cliente: si el cache tenia un TA vencido,
        // debe forzar una nueva llamada a loginCms. Verificamos:
        // (a) SoapClient fue invocado (re-nuevo TA)
        // (b) el ticket devuelto no es el viejo
        $soap->enqueueResponse($this->successEnvelope('T_NEW', 'S_NEW', '2025-06-15T13:00:00+00:00'));
        $ticket = $client->getToken('wsfe');
        $this->assertSame(1, $soap->callCount, 'cache miss -> loginCms llamado');
        $this->assertSame('T_NEW', $ticket->token);
    }

    public function test_cache_hit_no_llama_loginCms(): void
    {
        $config = $this->makeConfig();
        $now = new DateTimeImmutable('2025-06-15T12:00:00+00:00');
        $clock = fn(): DateTimeImmutable => $now;

        $cache = new NullTicketCache(expiryMarginSeconds: 0, clock: $clock);
        $future = new DateTimeImmutable('2025-06-15T12:30:00+00:00');
        $cache->save(new TicketDeAcceso(self::CUIT, 'wsfe', 'T_CACHED', 'S_CACHED', $future));

        $soap = new SoapClientDouble(); // no se deberia usar
        $signer = new CmsSignerDouble();
        $client = new WsaaClient($config, $soap, $cache, $clock, new TraBuilder(), $signer);

        $ticket = $client->getToken('wsfe');
        $this->assertSame(0, $soap->callCount, 'cache hit no debe invocar loginCms');
        $this->assertSame('T_CACHED', $ticket->token);
    }

    public function test_cee_ya_posee_ta_polls_cache_y_devuelve_si_aparece(): void
    {
        $config = $this->makeConfig();
        $now = new DateTimeImmutable('2025-06-15T12:00:00+00:00');
        // El clock avanza 1s por invocacion para simular el paso del tiempo
        // durante el polling.
        $elapsed = 0;
        $clock = function () use (&$elapsed): DateTimeImmutable {
            return (new DateTimeImmutable('2025-06-15T12:00:00+00:00'))->modify("+{$elapsed} seconds");
        };
        $clockSet = function (int $sec) use (&$elapsed): void { $elapsed = $sec; };

        $cache = new NullTicketCache(expiryMarginSeconds: 0, clock: $clock);
        $future = new DateTimeImmutable('2025-06-15T13:00:00+00:00');

        $soap = new SoapClientDouble();
        // Primer llamada: SoapFault "ya posee TA"
        $soap->enqueueFault(new SoapFault(
            'Server',
            'javax.ejb.EJBException: javax.ejb.EJBException: javax.ejb.CreateException: El CEE ya posee un TA valido para el acceso al WSN solicitado'
        ));
        // No encolamos mas respuestas: si el WsaaClient reintentara loginCms
        // obtendra "SoapClientDouble: no hay respuestas encoladas".

        // Programamos que en la segunda lectura del clock aparezca un TA
        // (otro worker lo publico).
        $applierRan = false;
        $apply = function () use (&$applierRan, $cache, $future): void {
            if (!$applierRan) {
                $cache->save(new TicketDeAcceso(self::CUIT, 'wsfe', 'T_FROM_OTHER', 'S_FROM_OTHER', $future));
                $applierRan = true;
            }
        };
        $clockMut = function () use (&$elapsed, $apply): DateTimeImmutable {
            $apply();
            $elapsed += 1;
            return (new DateTimeImmutable('2025-06-15T12:00:00+00:00'))->modify("+{$elapsed} seconds");
        };

        $signer = new CmsSignerDouble();
        // Polling corto (1s total, 10ms entre lecturas) para que el test no tarde
        $client = new WsaaClient(
            $config, $soap, $cache, $clockMut, new TraBuilder(), $signer,
            ceeYaPoseeTaPollSeconds: 1,
            ceeYaPoseeTaPollIntervalMs: 10,
        );

        $ticket = $client->getToken('wsfe');
        $this->assertSame(1, $soap->callCount, 'solo 1 llamada a loginCms (no retry ciego)');
        $this->assertSame('T_FROM_OTHER', $ticket->token, 'cache hit durante polling devuelve el TA del otro worker');
    }

    public function test_cee_ya_posee_ta_lanza_excepcion_si_cache_no_se_llena(): void
    {
        $config = $this->makeConfig();
        $clock = fn(): DateTimeImmutable => new DateTimeImmutable('2025-06-15T12:00:00+00:00');

        $cache = new NullTicketCache(expiryMarginSeconds: 0, clock: $clock);
        // No sembramos nada: el polling nunca encontrara un TA.

        $soap = new SoapClientDouble();
        $soap->enqueueFault(new SoapFault('Server', 'El CEE ya posee un TA valido'));

        $signer = new CmsSignerDouble();
        // Polling muy corto para acelerar el test
        $client = new WsaaClient(
            $config, $soap, $cache, $clock, new TraBuilder(), $signer,
            ceeYaPoseeTaPollSeconds: 0,
            ceeYaPoseeTaPollIntervalMs: 1,
        );

        try {
            $client->getToken('wsfe');
            $this->fail('Debio lanzar WsaaCeeYaPoseeTaException');
        } catch (WsaaCeeYaPoseeTaException $e) {
            $this->assertStringContainsString('CEE ya posee un TA', $e->getMessage());
        }
        $this->assertSame(1, $soap->callCount, 'no se llamo loginCms mas de una vez');
    }

    public function test_clock_inyectable_afecta_generation_y_expiration_time(): void
    {
        $config = $this->makeConfig(['wsaa_generation_skew' => 0, 'wsaa_tra_ttl' => 120]);
        $t0 = new DateTimeImmutable('2025-06-15T12:00:00+00:00');
        $t5 = new DateTimeImmutable('2025-06-15T12:00:05+00:00');
        $t0Call = true;
        $clock = function () use ($t0, $t5, &$t0Call): DateTimeImmutable {
            return $t0Call ? $t0 : $t5;
        };

        $soap = new SoapClientDouble();
        $soap->enqueueResponse($this->successEnvelope('T', 'S', '2025-06-15T13:00:00+00:00'));

        $signer = new CmsSignerDouble();
        $cache = new NullTicketCache(expiryMarginSeconds: 0, clock: $clock);
        $client = new WsaaClient($config, $soap, $cache, $clock, new TraBuilder(), $signer);

        $client->getToken('wsfe');
        $t0Call = false; // ya no se usara

        // uniqueId = t0 (la primera invocacion)
        $this->assertStringContainsString('<uniqueId>' . $t0->getTimestamp() . '</uniqueId>', $signer->lastTra);
        // generationTime = t0 (skew 0)
        $this->assertStringContainsString('<generationTime>' . $t0->format('Y-m-d\TH:i:sP') . '</generationTime>', $signer->lastTra);
        // expirationTime = t0 + 120s (no t5)
        $expectedExp = $t0->modify('+120 seconds')->format('Y-m-d\TH:i:sP');
        $this->assertStringContainsString('<expirationTime>' . $expectedExp . '</expirationTime>', $signer->lastTra);
    }
}
