<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\Wsaa;

use Rbbsoft\ArcaSdk\Wsaa\CmsSigner;

/**
 * CmsSigner de testing. NO firma nada. En lugar de eso:
 *  - Captura el contenido (TRA) en $this->lastTra para inspeccion.
 *  - Devuelve el contenido como base64 (o un prefijo tag para que el
 *    SoapClient double lo pueda leer tal cual).
 *
 * Es un drop-in replacement de CmsSigner para WsaaClient.
 */
final class CmsSignerDouble extends CmsSigner
{
    /** @var string[] */
    public array $signedContents = [];

    public ?string $lastTra = null;

    /** Si esta seteado, se devuelve como base64 en lugar del contenido. */
    public ?string $forcedOutputB64 = null;

    /**
     * No firma: devuelve base64 del contenido o un output forzado.
     *
     * La firma debe matchear exactamente la del padre (incluyendo
     * el parametro opcional $now) porque PHP exige compatibilidad
     * de firma entre override y metodo base cuando se cambia el
     * numero/tipo de parametros.
     *
     * @param string|null $passphrase
     * @param \DateTimeImmutable|null $now Sin uso (solo presente para match de firma).
     */
    public function sign(
        string $content,
        string $certPath,
        string $keyPath,
        ?string $passphrase = null,
        ?\DateTimeImmutable $now = null,
    ): string {
        $this->signedContents[] = $content;
        $this->lastTra = $content;
        if ($this->forcedOutputB64 !== null) {
            return $this->forcedOutputB64;
        }
        return 'CMS_DUMMY_B64::' . base64_encode($content);
    }
}
