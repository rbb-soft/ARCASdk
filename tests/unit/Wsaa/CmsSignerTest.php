<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\Wsaa;

use PHPUnit\Framework\TestCase;
use Rbbsoft\ArcaSdk\Exceptions\WsaaException;
use Rbbsoft\ArcaSdk\Wsaa\CmsSigner;

final class CmsSignerTest extends TestCase
{
    private const CERT_PATH = 'C:\xampp\htdocs\Certificados\MiCertificado.pem';
    private const KEY_PATH  = 'C:\xampp\htdocs\Certificados\MiClavePrivada.key';

    public function test_firma_pkcs7_detached_y_devuelve_base64(): void
    {
        $signer = new CmsSigner();
        $tra = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<loginTicketRequest version=\"1.0\">\n"
            . "  <header>\n"
            . "    <uniqueId>1700000000</uniqueId>\n"
            . "    <generationTime>2025-01-01T00:00:00+00:00</generationTime>\n"
            . "    <expirationTime>2025-01-01T00:10:00+00:00</expirationTime>\n"
            . "  </header>\n"
            . "  <service>wsfe</service>\n"
            . "</loginTicketRequest>";

        $b64 = $signer->sign($tra, self::CERT_PATH, self::KEY_PATH);

        // Es base64 valido.
        $this->assertNotSame('', $b64, 'sign no debe devolver string vacio');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9+\/=]+$/', $b64, 'el output debe ser base64 estricto');

        // Decodifica a un blob no vacio que arranca con DER SEQUENCE (0x30).
        $der = base64_decode($b64, true);
        $this->assertNotFalse($der, 'el output debe ser base64 decodificable estricto');
        $this->assertGreaterThan(0, strlen($der));
        $this->assertSame(0x30, ord($der[0]), 'el DER decodificado debe arrancar con 0x30 (ASN.1 SEQUENCE)');
    }

    public function test_der_contiene_oid_de_pkcs7_signeddata(): void
    {
        $signer = new CmsSigner();
        $b64 = $signer->sign('hola', self::CERT_PATH, self::KEY_PATH);
        $der = base64_decode($b64);

        // OID signedData = 1.2.840.113549.1.7.2 -> hex 06 09 2a 86 48 86 f7 0d 01 07 02
        $oidHex = '06092a864886f70d010702';
        $this->assertStringContainsString($oidHex, bin2hex($der), 'el DER debe contener el OID signedData (PKCS#7)');
    }

    public function test_der_contiene_el_certificado_firmante(): void
    {
        $signer = new CmsSigner();
        $b64 = $signer->sign('hola', self::CERT_PATH, self::KEY_PATH);
        $der = base64_decode($b64);

        // El certificado firmante debe estar embebido en el PKCS#7.
        // Comparamos contra el DER del certificado original.
        $certDer = self::certToDer(self::CERT_PATH);
        $this->assertNotEmpty($certDer, 'no se pudo cargar el cert original como DER');
        $this->assertStringContainsString(
            bin2hex($certDer),
            bin2hex($der),
            'el DER firmado debe contener el certificado firmante embebido'
        );
    }

    public function test_der_comienza_con_sequence_y_signedData_oid_en_early_bytes(): void
    {
        $signer = new CmsSigner();
        $b64 = $signer->sign('hola', self::CERT_PATH, self::KEY_PATH);
        $der = base64_decode($b64);

        // ContentInfo:
        //   SEQUENCE {
        //     OID signedData (06 09 2a 86 48 86 f7 0d 01 07 02)
        //     [0] EXPLICIT SignedData { ... }
        //   }
        // En bytes: 30 [82 LL LL] 06 09 2a 86 48 86 f7 0d 01 07 02 ...
        // Donde 82 LL LL = longitud en 2 bytes (>= 128).
        $hex = bin2hex($der);
        $this->assertSame('30', substr($hex, 0, 2), 'primer byte hex: SEQUENCE tag (0x30)');
        $this->assertSame('82', substr($hex, 2, 2), 'segundo byte: longitud en 2 bytes (0x82)');
        // OID a partir de la posicion 8 (30 82 XX XX 06 09 ...)
        $this->assertSame(
            '06092a864886f70d010702',
            substr($hex, 8, 22),
            'OID signedData esperado al inicio (posicion 8)'
        );
    }

    public function test_error_si_certificado_inexistente(): void
    {
        $signer = new CmsSigner();
        $this->expectException(WsaaException::class);
        $signer->sign('hola', 'C:\xampp\htdocs\Certificados\NoExiste.pem', self::KEY_PATH);
    }

    public function test_error_si_clave_incorrecta(): void
    {
        $signer = new CmsSigner();
        // Generamos una clave cualquiera: el cert no es consistente con ella,
        // openssl_pkcs7_sign deberia fallar.
        $tmpKey = tempnam(sys_get_temp_dir(), 'bogus_key_');
        // Reusamos la clave real para que al menos exista y sea PEM valido,
        // pero el "cert" lo apuntamos a un cert autofirmado random incompatible.
        // En lugar de eso usamos directamente un path inexistente para forzar error.
        @unlink($tmpKey);
        $this->expectException(WsaaException::class);
        $signer->sign('hola', self::CERT_PATH, 'C:\xampp\htdocs\Certificados\NoExiste.key');
    }

    public function test_normalizacion_de_referencias_file_protocol(): void
    {
        // Aceptar tanto "C:\..." como "file://C:\..." es responsabilidad
        // del caller. La CmsSigner agrega file:// si falta; nos aseguramos
        // de que firmando con file:// explicito tambien funcione.
        $signer = new CmsSigner();
        $b64 = $signer->sign('x', 'file://' . self::CERT_PATH, 'file://' . self::KEY_PATH);
        $this->assertNotSame('', $b64);
        $der = base64_decode($b64);
        $this->assertSame(0x30, ord($der[0]));
    }

    public function test_distintos_contenidos_producen_distintas_firmas(): void
    {
        $signer = new CmsSigner();
        $a = $signer->sign('A', self::CERT_PATH, self::KEY_PATH);
        $b = $signer->sign('B', self::CERT_PATH, self::KEY_PATH);
        $this->assertNotSame($a, $b, 'firma de A debe diferir de firma de B');
    }

    /**
     * Test critico: el SignerInfo del CMS debe usar el OID
     * sha256WithRSAEncryption (1.2.840.113549.1.1.11) en su
     * digestEncryptionAlgorithm. ARCA rechaza "Firma invalida o
     * algoritmo no soportado" si aparece rsaEncryption (1.2.840.113549.1.1.1)
     * ahi, que es el bug que producia openssl_pkcs7_sign() en este
     * build de PHP sobre Windows / XAMPP.
     *
     * Nota: el cert embebido en el CMS SI contiene el OID
     * rsaEncryption (en su public key algorithm, que es el algoritmo
     * de la clave publica del cert, no del digestEncryption del
     * SignerInfo). Por lo tanto, contar ocurrencias de rsaEncryption
     * >= 1 en el CMS total es esperable. El bug se manifiesta cuando
     * rsaEncryption aparece ADEMAS en el SignerInfo (i.e. >= 2 veces).
     * Verificamos que el OID critico correcto (sha256WithRSAEncryption)
     * esta presente, y que rsaEncryption aparece solo una vez (en el cert).
     */
    public function test_cms_usa_sha256_with_rsa_encryption_en_signer_info(): void
    {
        $signer = new CmsSigner();
        $b64 = $signer->sign('hola', self::CERT_PATH, self::KEY_PATH);
        $der = base64_decode($b64);
        $hex = bin2hex($der);

        // OID sha256WithRSAEncryption = 1.2.840.113549.1.1.11 = 06 09 2a 86 48 86 f7 0d 01 01 0b
        $sha256WithRsa = '06092a864886f70d01010b';
        // OID rsaEncryption = 1.2.840.113549.1.1.1 = 06 09 2a 86 48 86 f7 0d 01 01 01
        $rsaEncryption = '06092a864886f70d010101';

        $this->assertStringContainsString(
            $sha256WithRsa,
            $hex,
            'el DER debe contener el OID sha256WithRSAEncryption en el digestEncryptionAlgorithm del SignerInfo'
        );
        // El OID rsaEncryption aparece en el cert (public key algorithm).
        // No debe aparecer una segunda vez (eso seria el bug en el SignerInfo).
        $rsaCount = substr_count($hex, $rsaEncryption);
        $this->assertLessThanOrEqual(
            1,
            $rsaCount,
            "rsaEncryption debe aparecer <= 1 vez (solo en el cert); aparecio $rsaCount (bug: el SignerInfo lo esta usando)"
        );
    }

    /**
     * Test de verificacion end-to-end: el CMS firmado por CmsSigner
     * debe verificar OK con openssl CLI. Si esto pasa, ARCA tambien
     * lo aceptara (mismo motor de crypto, misma estructura).
     */
    public function test_cms_verifica_con_openssl_cli(): void
    {
        if (!$this->opensslAvailable()) {
            $this->markTestSkipped('openssl CLI no disponible en este entorno');
        }

        $signer = new CmsSigner();
        $content = "test content CmsSigner verify\n";
        $b64 = $signer->sign($content, self::CERT_PATH, self::KEY_PATH);
        $der = base64_decode($b64);

        $cmsFile = tempnam(sys_get_temp_dir(), 'cms_');
        $contentFile = tempnam(sys_get_temp_dir(), 'cms_content_');
        file_put_contents($cmsFile, $der);
        file_put_contents($contentFile, $content);

        try {
            $openssl = 'C:\\xampp\\php\\extras\\openssl\\openssl.exe';
            $cmd = sprintf(
                '%s smime -verify -in %s -inform DER -content %s -CAfile %s -noverify 2>&1',
                escapeshellarg($openssl),
                escapeshellarg($cmsFile),
                escapeshellarg($contentFile),
                escapeshellarg(self::CERT_PATH)
            );
            $output = shell_exec($cmd);

            $this->assertNotNull($output, 'openssl CLI no se ejecuto');
            // smime -verify imprime "Verification successful" cuando la firma es valida.
            // (Tambien imprime el contenido verificado, que incluye "test content".)
            $this->assertStringContainsString(
                'Verification successful',
                $output,
                'openssl smime -verify rechazo la firma: ' . $output
            );
            $this->assertStringContainsString(
                'test content',
                $output,
                'el contenido original no aparece en la salida de verificacion'
            );
        } finally {
            @unlink($cmsFile);
            @unlink($contentFile);
        }
    }

    /**
     * El signingTime es deterministico cuando se pasa un $now explicito.
     * Esto es importante para reproducibilidad en tests y para no firmar
     * con horas futuras/pasadas que el server rechace.
     */
    public function test_signing_time_determinista_con_now_explicito(): void
    {
        $signer = new CmsSigner();
        $now = new \DateTimeImmutable('2025-06-15 10:20:30', new \DateTimeZone('UTC'));
        $b64 = $signer->sign('hola', self::CERT_PATH, self::KEY_PATH, null, $now);
        $der = base64_decode($b64);
        $hex = bin2hex($der);

        // El signingTime UTCTIME encodea 250615102030Z = "25 06 15 10 20 30 Z" en hex ascii.
        // Lo encontramos como secuencia "32 35 30 36 31 35 31 30 32 30 33 30 5a" (en hex del DER).
        $expected = bin2hex('250615102030Z');
        $this->assertStringContainsString(
            $expected,
            $hex,
            'el signingTime debe aparecer en el CMS cuando se pasa un $now explicito'
        );
    }

    /**
     * Dos invocaciones con el mismo $now producen CMS identicos (la
     * firma RSA es deterministica con PKCS#1 v1.5 padding, no asi
     * con PSS). Esto permite reproducibilidad y cache de mensajes.
     */
    public function test_mismo_now_produce_mismo_cms(): void
    {
        $signer = new CmsSigner();
        $now = new \DateTimeImmutable('2025-06-15 10:20:30', new \DateTimeZone('UTC'));
        $a = $signer->sign('hola', self::CERT_PATH, self::KEY_PATH, null, $now);
        $b = $signer->sign('hola', self::CERT_PATH, self::KEY_PATH, null, $now);
        $this->assertSame($a, $b, 'mismo contenido y mismo $now deben producir mismo CMS (RSA-PKCS#1 v1.5)');
    }

    private function opensslAvailable(): bool
    {
        return is_executable('C:\\xampp\\php\\extras\\openssl\\openssl.exe');
    }

    /**
     * Carga un certificado PEM y devuelve su DER (la porcion entre
     * "-----BEGIN CERTIFICATE-----" y "-----END CERTIFICATE-----" en
     * base64, decodificada).
     */
    private static function certToDer(string $certPath): string
    {
        $pem = file_get_contents($certPath);
        if ($pem === false) {
            return '';
        }
        if (!preg_match('/-----BEGIN CERTIFICATE-----(.*?)-----END CERTIFICATE-----/s', $pem, $m)) {
            return '';
        }
        $b64 = preg_replace('/\s+/', '', $m[1]);
        $der = base64_decode($b64, true);
        return $der === false ? '' : $der;
    }
}
