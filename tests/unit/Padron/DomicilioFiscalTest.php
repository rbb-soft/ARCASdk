<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\Padron;

use PHPUnit\Framework\TestCase;
use Rbbsoft\ArcaSdk\Padron\DomicilioFiscal;

/**
 * Cubre el DTO DomicilioFiscal: parsing desde el array de la respuesta
 * del padron, con todos los campos o solo los minimos.
 */
final class DomicilioFiscalTest extends TestCase
{
    public function test_fromArray_con_todos_los_campos(): void
    {
        $data = [
            'calle'                => 'Av Siempre Viva',
            'numero'               => '742',
            'piso'                 => '3',
            'departamento'         => 'B',
            'codigoPostal'         => 'C1414BBD',
            'localidad'            => 'CABA',
            'provincia'            => '0',
            'descripcionProvincia' => 'CAPITAL FEDERAL',
        ];

        $d = DomicilioFiscal::fromArray($data);

        $this->assertSame('Av Siempre Viva', $d->calle);
        $this->assertSame('742', $d->numero);
        $this->assertSame('3', $d->piso);
        $this->assertSame('B', $d->departamento);
        $this->assertSame('C1414BBD', $d->codigoPostal);
        $this->assertSame('CABA', $d->localidad);
        $this->assertSame('0', $d->provincia);
        $this->assertSame('CAPITAL FEDERAL', $d->descripcionProvincia);
    }

    public function test_fromArray_con_domicilio_minimo(): void
    {
        // Solo calle y numero: el resto ausente. Todos los campos
        // opcionales deben caer a null sin lanzar.
        $data = [
            'calle'  => 'Una Calle',
            'numero' => '100',
        ];

        $d = DomicilioFiscal::fromArray($data);

        $this->assertSame('Una Calle', $d->calle);
        $this->assertSame('100', $d->numero);
        $this->assertNull($d->piso);
        $this->assertNull($d->departamento);
        $this->assertNull($d->codigoPostal);
        $this->assertNull($d->localidad);
        $this->assertNull($d->provincia);
        $this->assertNull($d->descripcionProvincia);
    }

    public function test_fromArray_con_domicilio_vacio(): void
    {
        // Respuesta con <domicilio/> vacio: el DTO debe tolerarlo.
        $d = DomicilioFiscal::fromArray([]);
        $this->assertNull($d->calle);
        $this->assertNull($d->numero);
        $this->assertNull($d->piso);
        $this->assertNull($d->departamento);
        $this->assertNull($d->codigoPostal);
        $this->assertNull($d->localidad);
        $this->assertNull($d->provincia);
        $this->assertNull($d->descripcionProvincia);
    }
}
