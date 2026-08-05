<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Wsaa;

use DateTimeImmutable;
use DateTimeZone;
use Rbbsoft\ArcaSdk\Asn1\Asn1Builder;
use Rbbsoft\ArcaSdk\Exceptions\WsaaException;

/**
 * Helper PKCS#7 detached construido a mano para que ARCA/WSAA lo acepte.
 *
 * Por que no usamos openssl_pkcs7_sign() directamente:
 *  - En PHP 8.2 sobre Windows / XAMPP, openssl_pkcs7_sign() produce
 *    un SignedData con digestEncryptionAlgorithm = rsaEncryption
 *    (OID 1.2.840.113549.1.1.1, generico) en lugar de
 *    sha256WithRSAEncryption (OID 1.2.840.113549.1.1.11) que ARCA
 *    exige. El rechazo de WSAA es "Firma invalida o algoritmo no
 *    soportado".
 *  - openssl_pkcs7_sign() ademas envuelve la salida en S/MIME
 *    multipart/signed que en este build no se puede parsear
 *    confiablemente con openssl_pkcs7_verify() / openssl_pkcs7_read().
 *  - openssl_sign() SI produce sha256WithRSAEncryption cuando se le
 *    pasa OPENSSL_ALGO_SHA256. Asi que armamos el PKCS#7 a mano
 *    con este builder ASN.1 y usamos openssl_sign() solo para
 *    calcular la firma RSA-SHA256 del bloque signedAttrs.
 *
 * Estructura del CMS que construimos (RFC 5652):
 *  - ContentInfo { OID signedData, [0] EXPLICIT SignedData }
 *  - SignedData { v1, SET { SHA-256 }, encapContentInfo, certs[0] IMPLICIT, signerInfos }
 *  - SignerInfo { v1, issuerAndSerial, digestAlg, signedAttrs[0] IMPLICIT,
 *                  digestEncAlg (sha256WithRSAEncryption), encDigest }
 *  - authAttrs (3, en este orden): contentType, signingTime, messageDigest
 *
 * El firmado: por RFC 5652 § 5.4, lo que se firma es el DER del
 * SET OF Attribute (con tag 0x31), NO la version con tag [0] (0xA0)
 * que va serializada en el SignerInfo. La verificacion reemplaza
 * el 0xA0 por 0x31 y verifica contra esos bytes. Confirma
 * experimentalmente: openssl_sign() del [0] TLV da "bad signature"
 * en openssl smime -verify; openssl_sign() del SET OF Attribute
 * (tag 0x31) verifica OK.
 *
 * No es final: las suites de testing pueden extenderlo con un double
 * que no firme (capturando el TRA en una propiedad publica).
 */
class CmsSigner
{
    /**
     * Firma $content con PKCS#7 detached y devuelve el CMS en base64.
     *
     * @param string                 $content    TRA a firmar (UTF-8).
     * @param string                 $certPath   Ruta al certificado (PEM). Acepta el prefijo "file://".
     * @param string                 $keyPath    Ruta a la clave privada (PEM). Acepta "file://".
     * @param string|null            $passphrase Passphrase de la clave privada (opcional).
     * @param DateTimeImmutable|null  $now        Fecha/hora UTC para el signingTime. Default: now UTC.
     */
    public function sign(
        string $content,
        string $certPath,
        string $keyPath,
        ?string $passphrase = null,
        ?DateTimeImmutable $now = null,
    ): string {
        $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));

        // 1. Cargar el cert como PEM string.
        $certPem = @file_get_contents($certPath);
        if ($certPem === false || $certPem === '') {
            throw new WsaaException(
                "CmsSigner: no se pudo leer el certificado desde '$certPath'"
            );
        }

        // 2. Parsear el cert.
        $cert = @openssl_x509_read($certPem);
        if ($cert === false) {
            throw new WsaaException(
                "CmsSigner: el archivo '$certPath' no contiene un certificado X.509 valido"
            );
        }
        $certInfo = openssl_x509_parse($cert);
        if ($certInfo === false || !isset($certInfo['issuer'], $certInfo['serialNumberHex'])) {
            throw new WsaaException(
                "CmsSigner: no se pudo parsear el certificado X.509 (issuer/serial faltantes)"
            );
        }

        // 3. Cargar la clave privada. openssl_pkey_get_private en este
        //    build de PHP sobre Windows exige "file://" para paths, no
        //    acepta paths crudos con backslashes.
        $key = @openssl_pkey_get_private(
            $this->normalizePemRef($keyPath),
            $passphrase ?? ''
        );
        if ($key === false) {
            throw new WsaaException(
                "CmsSigner: no se pudo cargar la clave privada desde '$keyPath': "
                . (@openssl_error_string() ?: 'verificar ruta y passphrase')
            );
        }

        // 4. Extraer el cert DER para embeber en el CMS.
        $certDer = $this->pemToDer($certPem);
        if ($certDer === '') {
            throw new WsaaException(
                "CmsSigner: no se pudo extraer el DER del certificado PEM"
            );
        }

        // 5. Construir el issuerAndSerial.
        $issuerRdn = $this->buildIssuerRdn($certInfo['issuer']);
        $serialInt = Asn1Builder::integerFromBytes(hex2bin($certInfo['serialNumberHex']));
        $issuerAndSerial = Asn1Builder::sequence($issuerRdn . $serialInt);

        // 6. Digest del TRA.
        $digest = hash('sha256', $content, true);

        // 7. Construir los tres authAttrs (en orden contentType, signingTime,
        //    messageDigest). Aunque RFC 5652 § 5.4 dice que no es obligatorio
        //    ordenarlos, ARCA/WSAA los espera en este orden canonico.
        $attrContentType = $this->buildAttribute(
            '1.2.840.113549.1.9.3',
            Asn1Builder::oid('1.2.840.113549.1.7.1')
        );
        $attrSigningTime = $this->buildAttribute(
            '1.2.840.113549.1.9.5',
            Asn1Builder::utctime($now)
        );
        $attrMessageDigest = $this->buildAttribute(
            '1.2.840.113549.1.9.4',
            Asn1Builder::octetString($digest)
        );

        // RFC 5652 § 5.4: la firma se calcula sobre el DER del SET OF
        // Attribute (tag 0x31). En el SignerInfo, ese SET se reemplaza
        // por el tag IMPLICIT [0] CONSTRUCTED (0xA0), pero el contenido
        // (los bytes del SET sin el tag 0x31) es identico. Verificacion
        // experimental: openssl smime -verify compara contra el SET
        // OF Attribute con 0x31, no contra la version 0xA0.
        $authAttrsRaw = $attrContentType . $attrSigningTime . $attrMessageDigest;
        $authAttrsSet = Asn1Builder::set($authAttrsRaw);

        // signedAttrs TLV que va en el SignerInfo: [0] IMPLICIT CONSTRUCTED
        // aplicado al contenido crudo (no al SET, sino a los Attribute
        // SEQUENCEs concatenados).
        $signedAttrsTlv = Asn1Builder::contextImplicitConstructed(0, $authAttrsRaw);

        // 8. Calcular la firma RSA-SHA256 sobre el SET OF Attribute (con
        //    tag 0x31). Ver RFC 5652 § 5.4 y la nota de clase arriba.
        $sig = '';
        if (!@openssl_sign($authAttrsSet, $sig, $key, OPENSSL_ALGO_SHA256)) {
            throw new WsaaException(
                'CmsSigner: openssl_sign fallo: ' . (@openssl_error_string() ?: 'sin detalle')
            );
        }

        // 9. Construir el SignerInfo.
        $digestAlg = Asn1Builder::sequence(
            Asn1Builder::oid('2.16.840.1.101.3.4.2.1') . Asn1Builder::null()
        );
        $digestEncAlg = Asn1Builder::sequence(
            // OID sha256WithRSAEncryption = 1.2.840.113549.1.1.11.
            // ESTE es el OID que ARCA exige (no 1.2.840.113549.1.1.1
            // = rsaEncryption generico que producia openssl_pkcs7_sign).
            Asn1Builder::oid('1.2.840.113549.1.1.11') . Asn1Builder::null()
        );
        $encDigest = Asn1Builder::octetString($sig);
        $signerInfo = Asn1Builder::sequence(
            Asn1Builder::integer(1)         // version
            . $issuerAndSerial
            . $digestAlg
            . $signedAttrsTlv               // signedAttrs [0] IMPLICIT CONSTRUCTED
            . $digestEncAlg
            . $encDigest
        );

        // 10. Construir el SignedData.
        $digestAlgsSet = Asn1Builder::set($digestAlg);
        $encapContentInfo = Asn1Builder::sequence(
            Asn1Builder::oid('1.2.840.113549.1.7.1')
            . Asn1Builder::contextExplicit(0, Asn1Builder::octetString($content))
        );
        $certificatesSet = "\xA0" . Asn1Builder::encodeLength(strlen($certDer)) . $certDer;
        $signerInfosSet = Asn1Builder::set($signerInfo);
        $signedData = Asn1Builder::sequence(
            Asn1Builder::integer(1)         // version
            . $digestAlgsSet
            . $encapContentInfo
            . $certificatesSet
            . $signerInfosSet
        );

        // 11. Construir el ContentInfo.
        $contentInfo = Asn1Builder::sequence(
            Asn1Builder::oid('1.2.840.113549.1.7.2')
            . Asn1Builder::contextExplicit(0, $signedData)
        );

        return base64_encode($contentInfo);
    }

    /**
     * Construye un Attribute (PKCS#9) como SEQUENCE { OID, SET OF $valueTlv }.
     *
     * @param string $attrOid OID del tipo de atributo (dot notation).
     * @param string $valueTlv TLV ASN.1 del valor (unico). Si necesita SET OF multiple,
     *                          envolverlos en Asn1Builder::set() antes de pasar.
     */
    private function buildAttribute(string $attrOid, string $valueTlv): string
    {
        return Asn1Builder::sequence(
            Asn1Builder::oid($attrOid)
            . Asn1Builder::set($valueTlv)
        );
    }

    /**
     * Construye el RDN del issuer (RDNSequence) a partir del array
     * asociativo que devuelve openssl_x509_parse().
     *
     * El array PHP preserva orden de insercion, que coincide con el
     * orden del cert para los certs X.509 tipicos. Cada componente
     * se codifica como SET { SEQUENCE { OID, value } } donde value
     * es UTF8String o PrintableString.
     *
     * Limitacion: solo se soportan los short names que mapean a OIDs
     * del estandar X.520 / PKCS#9 mas comunes (CN, O, C, ST, L, OU,
     * emailAddress, serialNumber). Si el cert tiene un componente
     * que no este en este mapa, se lanza WsaaException.
     *
     * @param array<string,string> $issuer Salida de openssl_x509_parse()['issuer'].
     */
    private function buildIssuerRdn(array $issuer): string
    {
        $oidMap = [
            'CN'           => '2.5.4.3',                  // commonName
            'O'            => '2.5.4.10',                 // organizationName
            'C'            => '2.5.4.6',                  // countryName
            'ST'           => '2.5.4.8',                  // stateOrProvinceName
            'L'            => '2.5.4.7',                  // localityName
            'OU'           => '2.5.4.11',                 // organizationalUnitName
            'emailAddress' => '1.2.840.113549.1.9.1',     // emailAddress (PKCS#9)
            'E'            => '1.2.840.113549.1.9.1',     // alias de algunos parsers
            'serialNumber' => '2.5.4.5',                  // serialNumber (X.520, sujeto)
        ];

        $rdnSequence = '';
        foreach ($issuer as $shortName => $value) {
            if (!isset($oidMap[$shortName])) {
                throw new WsaaException(
                    "CmsSigner: componente de issuer '$shortName' no soportado (OID desconocido)"
                );
            }
            $attrTypeAndValue = Asn1Builder::sequence(
                Asn1Builder::oid($oidMap[$shortName])
                . $this->encodeDirectoryString((string) $value, $oidMap[$shortName])
            );
            $rdnSequence .= Asn1Builder::set($attrTypeAndValue);
        }
        return Asn1Builder::sequence($rdnSequence);
    }

    /**
     * Codifica un DirectoryString (campo de un AttributeTypeAndValue
     * del issuer) eligiendo el tipo ASN.1 segun X.520:
     *  - countryName (2.5.4.6) -> SIEMPRE PrintableString.
     *  - Otros: PrintableString si el valor es ASCII printable
     *    "seguro" (X.520 PrintableString set: letras, digitos,
     *    espacio y '()+,-./:=?) y longitud <= 128. UTF8String en
     *    cualquier otro caso.
     */
    private function encodeDirectoryString(string $value, string $oid): string
    {
        $printable = $value !== ''
            && strlen($value) <= 128
            && preg_match('/^[A-Za-z0-9 \'()+,\-\.\/:=?]+$/', $value) === 1;

        if ($oid === '2.5.4.6') {
            $tag = "\x13"; // PrintableString (forzado para C)
        } elseif ($printable) {
            $tag = "\x13"; // PrintableString
        } else {
            $tag = "\x0C"; // UTF8String
        }
        return $tag . Asn1Builder::encodeLength(strlen($value)) . $value;
    }

    /**
     * Normaliza la referencia a la clave privada para openssl_pkey_get_private.
     * En este build de PHP/Windows la funcion exige "file://" para paths;
     * agregar el prefijo si falta.
     */
    private function normalizePemRef(string $path): string
    {
        if (str_starts_with($path, 'file://')) {
            return $path;
        }
        return 'file://' . $path;
    }

    /**
     * Extrae el DER de un certificado PEM (el primer bloque
     * CERTIFICATE si hay varios). Devuelve '' si no encuentra el
     * bloque BEGIN/END.
     */
    private function pemToDer(string $pem): string
    {
        if (!preg_match('/-----BEGIN CERTIFICATE-----(.*?)-----END CERTIFICATE-----/s', $pem, $m)) {
            return '';
        }
        $b64 = preg_replace('/\s+/', '', $m[1]);
        $der = base64_decode($b64, true);
        return $der === false ? '' : $der;
    }
}
