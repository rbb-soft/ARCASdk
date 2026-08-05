<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Tests\Unit\Padron;

use PHPUnit\Framework\TestCase;
use Rbbsoft\ArcaSdk\Padron\DomicilioFiscal;
use Rbbsoft\ArcaSdk\Padron\Emisor;

/**
 * Cubre el DTO Emisor: parsing desde el array que PadronClient
 * extrae de <persona> en la respuesta del WSDL A13, construccion de
 * razon social y apellido+nombre concatenado, mapeo de campos
 * opcionales a null, y campos que el WSDL A13 no expone
 * (actividades, impuestos, fechaInscripcion, categoriaMonotributo,
 * condicionIva) que quedan en null/[] por diseno.
 */
final class EmisorTest extends TestCase
{
    public function test_fromArray_con_todos_los_campos_del_WSDL_A13(): void
    {
        // Shape de <persona> segun el WSDL real de personaServiceA13:
        // los campos viven a top-level de <persona> (no dentro de un
        // <datosGenerales> como en el WSDL A5 viejo). <domicilio> es
        // una lista de la que se toma el primero.
        $data = [
            'idPersona'                   => 20123456786,
            'tipoPersona'                 => 'JURIDICA',
            'estadoClave'                 => 'ACTIVO',
            'razonSocial'                 => 'ACME S.A.',
            'tipoClave'                   => 'CUIT',
            'tipoDocumento'               => 'CUIT',
            'numeroDocumento'             => '20123456786',
            'formaJuridica'               => 'S.A.',
            'idActividadPrincipal'        => '620100',
            'descripcionActividadPrincipal' => 'Servicios de consultoria',
            'periodoActividadPrincipal'   => '202401',
            'fechaContratoSocial'         => '1998-01-15T00:00:00-03:00',
            'mesCierre'                   => 12,
            'domicilio'                   => [
                'calle'                => 'Av Siempre Viva',
                'numero'               => '742',
                'codigoPostal'         => 'C1414BBD',
                'localidad'            => 'CABA',
                'idProvincia'          => 0,
                'descripcionProvincia' => 'CAPITAL FEDERAL',
            ],
        ];

        $e = Emisor::fromArray($data);

        $this->assertSame(20123456786, $e->cuit);
        $this->assertSame('JURIDICA', $e->tipoPersona);
        $this->assertSame('ACTIVO', $e->estadoClave);
        $this->assertSame('ACME S.A.', $e->razonSocial);
        $this->assertNull($e->apellidoNombre, 'juridica no debe tener apellidoNombre');
        // Campos que el WSDL A13 NO expone y quedan en null/[] por diseno.
        $this->assertNull($e->fechaInscripcion, 'WSDL A13 no expone fechaInscripcion');
        $this->assertNull($e->categoriaMonotributo, 'WSDL A13 no expone categoriaMonotributo');
        $this->assertNull($e->condicionIva, 'WSDL A13 no expone condicionIva');
        $this->assertSame([], $e->actividades, 'WSDL A13 no expone lista de actividades');
        $this->assertSame([], $e->impuestos, 'WSDL A13 no expone lista de impuestos');

        $dom = $e->domicilioFiscal;
        $this->assertInstanceOf(DomicilioFiscal::class, $dom);
        $this->assertSame('Av Siempre Viva', $dom->calle);
        $this->assertSame('742', $dom->numero);
        $this->assertNull($dom->piso);
        $this->assertNull($dom->departamento);
        $this->assertSame('C1414BBD', $dom->codigoPostal);
        $this->assertSame('CABA', $dom->localidad);
        // idProvincia (int) se mapea a string para mantener compat con
        // la firma del DTO.
        $this->assertSame('0', $dom->provincia);
        $this->assertSame('CAPITAL FEDERAL', $dom->descripcionProvincia);
    }

    public function test_fromArray_con_razon_social_minima(): void
    {
        // Respuesta minima: solo idPersona + datos basicos de persona
        // juridica. Domicilio ausente -> todos los campos del DTO null.
        $data = [
            'idPersona'   => 20999999999,
            'tipoPersona' => 'JURIDICA',
            'estadoClave' => 'ACTIVO',
            'razonSocial' => 'Otra Sociedad SRL',
        ];

        $e = Emisor::fromArray($data);

        $this->assertSame(20999999999, $e->cuit);
        $this->assertSame('JURIDICA', $e->tipoPersona);
        $this->assertSame('ACTIVO', $e->estadoClave);
        $this->assertSame('Otra Sociedad SRL', $e->razonSocial);
        $this->assertNull($e->apellidoNombre);
        $this->assertNull($e->fechaInscripcion);
        $this->assertNull($e->categoriaMonotributo);
        $this->assertNull($e->condicionIva);
        $this->assertSame([], $e->actividades);
        $this->assertSame([], $e->impuestos);
        $dom = $e->domicilioFiscal;
        $this->assertNull($dom->calle);
        $this->assertNull($dom->numero);
    }

    public function test_fromArray_con_apellido_y_nombre_concatenados(): void
    {
        // Persona fisica: el WSDL A13 trae <apellido> y <nombre> como
        // campos directos de <persona>. Emisor::fromArray los concatena
        // en apellidoNombre con el formato "apellido, nombre".
        $data = [
            'idPersona'   => 20333333333,
            'tipoPersona' => 'FISICA',
            'estadoClave' => 'ACTIVO',
            'apellido'    => 'PEREZ',
            'nombre'      => 'JUAN CARLOS',
        ];

        $e = Emisor::fromArray($data);

        $this->assertSame(20333333333, $e->cuit);
        $this->assertSame('FISICA', $e->tipoPersona);
        $this->assertSame('ACTIVO', $e->estadoClave);
        $this->assertNull($e->razonSocial, 'fisica no debe tener razonSocial');
        $this->assertSame('PEREZ, JUAN CARLOS', $e->apellidoNombre,
            'apellidoNombre debe ser "apellido, nombre" cuando ambos vienen presentes');
    }

    public function test_fromArray_con_campos_minimos(): void
    {
        // Respuesta del padron reducida al maximo: solo idPersona.
        // Todas las claves opcionales deben caer a null/[] sin lanzar.
        $data = [
            'idPersona' => 20444444444,
        ];

        $e = Emisor::fromArray($data);

        $this->assertSame(20444444444, $e->cuit);
        $this->assertNull($e->razonSocial);
        $this->assertNull($e->apellidoNombre);
        $this->assertNull($e->tipoPersona);
        $this->assertSame('', $e->estadoClave, 'estadoClave ausente -> string vacio');
        $this->assertNull($e->fechaInscripcion);
        $this->assertNull($e->categoriaMonotributo);
        $this->assertNull($e->condicionIva);
        $this->assertSame([], $e->actividades);
        $this->assertSame([], $e->impuestos);
    }

    public function test_fromArray_con_domicilio_vacio_no_lanza(): void
    {
        // <domicilio> presente pero sin hijos: defensivo, no debe
        // lanzar y todos los campos del DTO quedan en null.
        $data = [
            'idPersona' => 20555555555,
            'domicilio' => [],
        ];

        $e = Emisor::fromArray($data);

        $this->assertSame(20555555555, $e->cuit);
        $this->assertNull($e->domicilioFiscal->calle);
        $this->assertNull($e->domicilioFiscal->numero);
    }
}
