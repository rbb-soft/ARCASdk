# Rbbsoft\ArcaSdk

SDK PHP PSR-4 standalone para facturación electrónica contra ARCA (ex AFIP). Autenticación WSAA y factura electrónica WSFE para Facturas A/B/C/M y Notas de Crédito A/B/C/M, con idempotencia atómica, recuperación de zombies y retry policy incluidos.

- **Guía de uso completa:** [docs/GUIA_DE_USO.md](docs/GUIA_DE_USO.md)
- **Mantenimiento del proyecto (auditor docs ↔ código, etc.):** [docs/MAINTENANCE.md](docs/MAINTENANCE.md)
- **Historial de versiones:** [CHANGELOG.md](CHANGELOG.md)
- **Manuales oficiales de ARCA:** [docs/ARCA/](docs/ARCA/)
- **Estado:** v0.6.0 — single-tenant, un CUIT por proceso PHP-FPM. Multi-tenant está explícitamente fuera de alcance.

## Quickstart

```php
use Rbbsoft\ArcaSdk\ArcaSdk;
use Rbbsoft\ArcaSdk\Config\Config;

$config = Config::fromArray([
    'env'         => 'homo',
    'cuit'        => '<CUIT_EMISOR>',
    'punto_venta' => 1,
    'cert_path'   => 'C:\xampp\Certificados\MiCertificado.pem',
    'key_path'    => 'C:\xampp\Certificados\MiClavePrivada.key',
    'db_dsn'      => 'mysql:host=127.0.0.1;dbname=arca_facturador;charset=utf8mb4',
    'db_user'     => 'arca_user',
    'db_pass'     => '...',
]);

$arca = ArcaSdk::getInstance($config);

$respuesta = $arca->emitirFactura('a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d', [
    'concepto'                 => 1,
    'receptor_documento_tipo'  => 80,
    'receptor_documento_nro'   => '<CUIT_RECEPTOR>',
    'receptor_condicion_iva'   => 'RI',
    'mon_id'                   => 'PES',
    'mon_cotiz'                => '1.00',
    'items' => [
        ['importe_gravado' => '1000000.00', 'alicuota_iva' => '21'],
    ],
]);

echo $respuesta->cae;
```

Para la instalación detallada, las variables de entorno, los códigos de error, el diagnóstico, las operaciones administrativas y todo lo demás, ver la [guía de uso completa](docs/GUIA_DE_USO.md).

## Validación en entorno de homologación (ARCA)

El SDK se prueba de manera regular contra el entorno de **homologación de ARCA** (wsfev1 homo, `https://wswhomo.afip.gov.ar/...`). La homologación es un ambiente de prueba provisto por ARCA que replica los contratos y reglas de validación del ambiente productivo, pero no tiene validez fiscal y no se persiste más allá de un periodo breve.

La validación cubre dos ejes:

1. **Suite automatizada de tests**: 559 tests / 2 713 assertions, ejecutada con PHPUnit. La suite cubre el armado de payloads, el parseo de respuestas, la clasificación de errores, la política de retry, la idempotencia atómica y la recuperación de emisiones zombie. No requiere conectividad con ARCA: se ejecuta offline contra dobles de prueba (`SoapClientDouble`).

2. **Emisión real de los ocho tipos de comprobante soportados** mediante los scripts de `examples/`. Cada invocación genera un comprobante con CAE asignado por ARCA homologación, persistido en `arca_emisiones_idempotencia`. Los tipos validados son:

| Tipo de comprobante         | Código | Total  | CAE asignado por ARCA homo |
|-----------------------------|:------:|-------:|---------------------------:|
| Factura A                   |   1    | 121,00 | 86310728860034             |
| Factura B                   |   6    | 121,00 | 86310728860050             |
| Factura C                   |  11    | 100,00 | 86310728860063             |
| Factura M                   |  51    | 100,00 | 86310728860089             |
| Nota de Crédito A           |   3    | 121,00 | 86310728860092             |
| Nota de Crédito B           |   8    | 121,00 | 86310728860123             |
| Nota de Crédito C           |  13    | 100,00 | 86310728860136             |
| Nota de Crédito M           |  53    | 100,00 | 86310728860152             |

Las emisiones se realizaron con punto de venta 2, CUIT emisor y CUIT receptor configurados por el operador en su `.env` (variables `ARCA_CUIT` y `ARCA_CUIT_RECEPTOR`) y fecha 2026-08-04. La sección 0 de la guía de uso documenta el contrato de las variables de entorno. Los CUITs reales no se versionan en el repositorio.

Para reproducir la validación:

```bash
# Suite automatizada
composer install
.\vendor\bin\phpunit --testdox

# Emisión real de un comprobante contra ARCA homologación
# (requiere .env configurado y certificados en el path declarado)
php examples/factura_a_basica.php 0 1 0
```

**Alcance de la validación**: el SDK se considera validado únicamente en el entorno de homologación descripto. **No se ha emitido contra el ambiente productivo de ARCA**; la presente versión (v0.6.0) no se encuentra en producción. Antes de cualquier pase a producción se requiere una corrida de homologación con el CUIT y el punto de venta definitivos, junto con la verificación manual de los CAE resultantes.

## Estructura

```
├── .env / .env.example
├── README.md / CHANGELOG.md
├── composer.json / phpunit.xml
├── docs/
│   ├── GUIA_DE_USO.md          ← manual de uso
│   └── ARCA/                    ← manuales PDF oficiales de ARCA
│       ├── INDICE.md
│       ├── WSFEv1_Manual_Desarrollador_v4.5_ARCA.pdf
│       ├── QRespecificaciones.pdf   ← spec del QR
│       ├── WSAA_*.pdf (8 manuales)
│       └── ADMINREL_Delegar_Webservices.pdf
├── src/                          ← código del SDK
│   ├── ArcaSdk.php              ← Singleton y orquestador
│   ├── Asn1/                    ← builder ASN.1 DER (genérico, reusable)
│   │   └── Asn1Builder.php
│   ├── Auditoria/               ← audit logger para resets
│   │   └── ResetAuditLogger.php
│   ├── Config/Config.php        ← configuración inmutable
│   ├── Exceptions/              ← excepciones tipadas (18 archivos)
│   ├── Idempotencia/            ← idempotencia atómica
│   │   ├── FilaEmision.php
│   │   ├── IdempotenciaRepository.php
│   │   └── UuidFactory.php
│   ├── Lock/LockManager.php     ← GET_LOCK sobre MySQL
│   ├── Padron/                  ← consulta al padrón A13
│   │   ├── PadronClient.php
│   │   ├── PadronSoapClientFactory.php
│   │   ├── Emisor.php           ← DTO de la respuesta
│   │   ├── DomicilioFiscal.php  ← DTO sub-objeto
│   │   ├── Actividad.php
│   │   └── Impuesto.php
│   ├── Pdf/                     ← generación de PDF con QR oficial ARCA
│   │   └── ComprobantePdfGenerator.php
│   ├── Sdk/Container.php        ← inyección de dependencias
│   ├── Support/                 ← Money (BCMath) y RetryPolicy
│   │   ├── Money.php
│   │   └── RetryPolicy.php
│   ├── Time/Clock.php           ← Clock inyectable
│   ├── Wsaa/                    ← autenticación y ticket cache
│   │   ├── WsaaClient.php
│   │   ├── CmsSigner.php        ← firma PKCS#7 detached
│   │   ├── TraBuilder.php       ← armado del TRA
│   │   ├── TicketDeAcceso.php   ← DTO del TA
│   │   ├── TicketCacheInterface.php
│   │   ├── MysqlTicketCache.php
│   │   └── NullTicketCache.php
│   ├── Wsfe/                    ← facturación electrónica
│   │   ├── WsfeClient.php
│   │   ├── Comprobante.php
│   │   ├── ComprobanteEmitido.php        ← DTO del response
│   │   ├── ComprobanteConsultado.php
│   │   ├── ComprobanteResponse.php
│   │   ├── DummyResponse.php
│   │   ├── IvaCalculator.php
│   │   ├── ResultadoIva.php
│   │   ├── SnapshotComparer.php
│   │   ├── SnapshotValidator.php
│   │   ├── SoapClientFactory.php
│   │   └── TiposComprobante.php
│   └── Zombie/ZombieRecovery.php ← recuperación de emisiones
├── tests/
│   ├── fixtures/                ← fixtures compartidos (smoke)
│   ├── smoke/                   ← scripts de smoke
│   └── unit/                    ← 559 tests / 2 713 assertions
│       ├── ArcaSdk/             ← tests del Singleton y orquestador
│       │   ├── CapturingLogger.php
│       │   ├── ComprobantePdfGeneratorCapturing.php
│       │   ├── GenerarPdfTest.php
│       │   ├── ObtenerEmisorTest.php
│       │   ├── ReconciliacionTest.php
│       │   └── ResetExternalIdTest.php
│       ├── Asn1/                ← tests del builder ASN.1
│       ├── Config/              ← tests de Config
│       ├── Idempotencia/        ← tests de idempotencia
│       ├── Lock/                ← tests del lock manager
│       ├── Padron/              ← tests del padrón A13
│       ├── Sdk/                 ← tests del Container
│       ├── Support/             ← tests de Money y RetryPolicy
│       ├── Wsaa/                ← tests de WSAA + CMS
│       ├── Wsfe/                ← tests de WSFE
│       │   ├── ComprobanteEmitidoTest.php
│       │   ├── ComprobanteResponseTest.php
│       │   ├── ComprobanteTest.php
│       │   ├── IvaCalculatorTest.php
│       │   └── ...
│       ├── Zombie/              ← tests de recuperación zombie
│       ├── ArcaSdkTest.php
│       ├── smoke_config.php
│       └── smoke_wsaa.php
├── examples/                    ← scripts ejecutables de ejemplo
│   ├── factura_a_basica.php
│   ├── factura_b_basica.php
│   ├── factura_c_basica.php
│   ├── factura_c_basica_pdf.php   ← emisión + PDF
│   ├── factura_m_basica.php
│   ├── nota_credito_a_basica.php
│   ├── nota_credito_b_basica.php
│   ├── nota_credito_c_basica.php
│   ├── nota_credito_m_basica.php
│   └── reset_admin.php
├── db/migrate.php                ← CLI de migración
└── sql/schema.sql                ← tablas arca_ticket_acceso, arca_emisiones_idempotencia
```

## Costo de utilización

El SDK se distribuye bajo licencia MIT y su utilización es libre y gratuita, sin distinción entre el entorno de homologación y el entorno de producción. No se requiere el pago de licencia, canon ni suscripción por su empleo en ninguno de los dos entornos.

## Atribución obligatoria

Toda aplicación que utilice el SDK debe incluir, en una sección accesible para el usuario final (por ejemplo, "Acerca de", "Créditos" o "Información legal"), una atribución visible al repositorio oficial del proyecto, con un enlace funcional a la fuente upstream en `https://github.com/rbb-soft/ARCASdk`.

## Licencia

MIT. Texto legal completo en [`LICENSE`](./LICENSE) (versión original en inglés) y [`LICENCIA.md`](./LICENCIA.md) (traducción de referencia al castellano, no oficial).
