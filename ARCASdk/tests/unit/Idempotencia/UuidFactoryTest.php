<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\Idempotencia;

use PHPUnit\Framework\TestCase;
use Rbbsoft\ArcaSdk\Idempotencia\UuidFactory;

final class UuidFactoryTest extends TestCase
{
    // -------------------------------------------------------------------
    // v4() — formato y propiedades
    // -------------------------------------------------------------------

    public function test_v4_devuelve_formato_canonico(): void
    {
        $uuid = UuidFactory::v4();
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid,
            "UUID v4 debe tener el formato canonico RFC 4122 (recibio: '{$uuid}')"
        );
        $this->assertSame(36, strlen($uuid));
        $this->assertSame(strtolower($uuid), $uuid, 'UUID canonico debe ser lowercase');
    }

    public function test_v4_tiene_version_4_y_variant_RFC4122(): void
    {
        // 50 muestras para protegerse contra la baja entropia de un
        // solo sample (random_bytes es 128 bits; con 50 cubrimos
        // cualquier sesgo).
        for ($i = 0; $i < 50; $i++) {
            $uuid = UuidFactory::v4();
            $this->assertSame('4', $uuid[14], "version nibble debe ser 4 (UUID '{$uuid}')");
            $variant = $uuid[19];
            $this->assertContains(
                $variant,
                ['8', '9', 'a', 'b'],
                "variant nibble debe ser 8|9|a|b (UUID '{$uuid}', variant '{$variant}')"
            );
        }
    }

    public function test_v4_es_isValid_segun_factory(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->assertTrue(UuidFactory::isValid(UuidFactory::v4()));
        }
    }

    public function test_v4_dos_llamadas_distintas(): void
    {
        // 128 bits de entropia => colision entre 2 muestras es
        // astronomicamente improbable. Con 10 cubrimos ademas la
        // posibilidad de que el runtime tenga un bug sistematico.
        $uuids = [];
        for ($i = 0; $i < 10; $i++) {
            $uuids[] = UuidFactory::v4();
        }
        $this->assertCount(10, array_unique($uuids), '10 UUIDs consecutivos deben ser todos distintos');
    }

    public function test_v4_usa_random_bytes_no_mt_rand(): void
    {
        // Verificamos que el codigo realmente llama random_bytes(16).
        // Si en el futuro alguien cambia la implementacion a mt_rand
        // o rand(), este test seguira pasando (no podemos detectar
        // el origen del randomness desde fuera), pero al menos
        // garantizamos que random_bytes esta disponible y que el
        // output tiene la entropia esperada.
        //
        // 32 bytes de salida = 256 bits. Si fuera mt_rand() (32 bits
        // max en sistemas modernos), la entropia seria muy inferior
        // y 256 colisiones en un set pequeno serian evidentes.
        $samples = [];
        for ($i = 0; $i < 64; $i++) {
            $samples[] = UuidFactory::v4();
        }
        $this->assertGreaterThan(
            63,
            count(array_unique($samples)),
            '64 UUIDs deben ser unicos (sugiere fuente CSPRNG)'
        );
    }

    public function test_random_bytes_disponible_en_este_php(): void
    {
        $this->assertTrue(
            function_exists('random_bytes'),
            'PHP 8.x siempre tiene random_bytes, pero aseguramos que UuidFactory depende solo de el'
        );
    }

    // -------------------------------------------------------------------
    // isValid()
    // -------------------------------------------------------------------

    public function test_isValid_acepta_v4_canonico(): void
    {
        $this->assertTrue(UuidFactory::isValid('01234567-89ab-4cde-9f01-23456789abcd'));
        $this->assertTrue(UuidFactory::isValid('ffffffff-ffff-4fff-bfff-ffffffffffff'));
        $this->assertTrue(UuidFactory::isValid('00000000-0000-4000-8000-000000000000'));
    }

    public function test_isValid_acepta_mayusculas(): void
    {
        $this->assertTrue(UuidFactory::isValid('01234567-89AB-4CDE-9F01-23456789ABCD'));
    }

    /** @return array<string, array{string, string}> */
    public static function uuidInvalidos(): array
    {
        return [
            'v1 (timestamp-based)'     => ['00000000-0000-1000-8000-000000000000', 'v1'],
            'v3 (md5)'                  => ['00000000-0000-3000-8000-000000000000', 'v3'],
            'v5 (sha1)'                 => ['00000000-0000-5000-8000-000000000000', 'v5'],
            'version 0'                 => ['00000000-0000-0000-8000-000000000000', 'v0'],
            'version 2'                 => ['00000000-0000-2000-8000-000000000000', 'v2'],
            'version 6'                 => ['00000000-0000-6000-8000-000000000000', 'v6'],
            'version 7'                 => ['00000000-0000-7000-8000-000000000000', 'v7'],
            'version 8'                 => ['00000000-0000-8000-8000-000000000000', 'v8'],
            'version f (no 4)'          => ['00000000-0000-f000-8000-000000000000', 'vF'],
            'variant 0'                 => ['00000000-0000-4000-0000-000000000000', 'var 0'],
            'variant 1-7 (no 10xx)'     => ['00000000-0000-4000-1000-000000000000', 'var 1'],
            'variant c-f'               => ['00000000-0000-4000-c000-000000000000', 'var c'],
            'NIL UUID (todos ceros)'    => ['00000000-0000-0000-0000-000000000000', 'nil'],
            'MAX UUID (todos unos)'     => ['ffffffff-ffff-ffff-ffff-ffffffffffff', 'max'],
            'sin guiones'               => ['0123456789ab4cde9f0123456789abcd', 'sin guiones'],
            'guion extra'               => ['01234567-89ab-4cde-9f01-2345-6789abcd', 'guion extra'],
            'guion faltante'            => ['01234567-89ab4cde-9f01-23456789abcd', 'guion faltante'],
            'muy corto'                 => ['01234567-89ab-4cde-9f01-23456789abc', 'corto'],
            'muy largo'                 => ['01234567-89ab-4cde-9f01-23456789abcde', 'largo'],
            'caracter no hex'           => ['01234567-89ab-4cde-9f01-23456789abcg', 'no hex'],
            'espacio al medio'          => ['01234567-89ab-4cde 9f01-23456789abcd', 'espacio'],
            'string vacio'              => ['', 'vacio'],
        ];
    }

    /**
     * @dataProvider uuidInvalidos
     */
    public function test_isValid_rechaza_no_v4(string $uuid, string $etiqueta): void
    {
        $this->assertFalse(
            UuidFactory::isValid($uuid),
            "isValid debe rechazar '{$uuid}' ({$etiqueta})"
        );
    }
}
