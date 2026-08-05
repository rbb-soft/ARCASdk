<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\Sdk;

use LogicException;
use PDO;
use PHPUnit\Framework\TestCase;
use Rbbsoft\ArcaSdk\Config\Config;
use Rbbsoft\ArcaSdk\Sdk\Container;

/**
 * Tests del Container relacionados con el cableado del PDO principal.
 *
 * Convenciones:
 *  - Sin DB real: los tests no llaman a {@see Container::pdo()} cuando
 *    no hay PDO inyectado (la excepción defensiva se mantiene como
 *    rama cubierta), y para los casos donde se necesita un PDO se
 *    usa `sqlite::memory:` (driver casi siempre presente en XAMPP).
 *  - El Config se construye con paths a fixtures dummy en
 *    `tests/unit/fixtures/`. El Container no valida los paths;
 *    `Config::fromArray()` lo hace, y por eso las fixtures tienen
 *    que ser archivos legibles en disco.
 */
final class ContainerTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function makeConfig(): array
    {
        return [
            'env'           => 'homo',
            'cuit'          => '20123456786',  // CUIT emisor ficticio (checksum valido, ver tools/CHANGELOG.md nota v0.5.0)
            'punto_venta'   => 1,
            'cert_path'     => __DIR__ . '/../fixtures/cert.pem',
            'key_path'      => __DIR__ . '/../fixtures/key.key',
            'db_dsn'        => 'mysql:host=127.0.0.1;port=3306;dbname=test;charset=utf8mb4',
            'db_user'       => 'root',
            'db_pass'       => '',
            'db_persistent' => false,
        ];
    }

    private function makeContainer(): Container
    {
        return new Container(Config::fromArray($this->makeConfig()));
    }

    public function test_pdo_sin_inyectar_lanza_logic_exception(): void
    {
        $container = $this->makeContainer();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/PDO no inyectado/');

        $container->pdo();
    }

    public function test_hasPdo_refleja_inyeccion(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite no disponible; no se puede construir el PDO dummy');
        }

        $container = $this->makeContainer();
        $this->assertFalse(
            $container->hasPdo(),
            'Container nuevo, sin inyeccion, debe reportar hasPdo() === false'
        );

        $pdo = new PDO('sqlite::memory:');
        $container->withPdo($pdo);

        $this->assertTrue(
            $container->hasPdo(),
            'Tras withPdo() el Container debe reportar hasPdo() === true'
        );

        // Bonus: pdo() devuelve la misma instancia inyectada.
        $this->assertSame($pdo, $container->pdo());
    }
}
