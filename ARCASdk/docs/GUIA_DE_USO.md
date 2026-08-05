# Manual de uso del SDK `Rbbsoft\ArcaSdk`

*Guía para developers PHP que necesitan emitir comprobantes electrónicos contra ARCA a través de la clase `ArcaSdk`.*

---

## Acerca de este manual

**Versión del manual:** 3.0.
**Versión del SDK documentada:** `v0.5.1`.
**Fecha de redacción:** 5 de agosto de 2026.
**Audiencia:** developers PHP con experiencia básica en PHP 8.1+, Composer, MySQL y nociones elementales de SOAP. El manual no requiere conocimiento previo de la facturación electrónica argentina ni del SDK; ambos se introducen desde cero.

**Cambio de paradigma en v2.0.** A diferencia de la v1.x, esta versión está **organizada por tareas**, no por temas del SDK. Si tu pregunta es "¿cómo emito una factura?" vas a la sección 0. Si es "¿qué tipo de comprobante emito a un monotributista con CUIT?" vas a la sección 1. Si es "¿cómo lleno el array `$data`?" vas a la sección 2. El material avanzado (RG 5616, padrón A13, reconciliación, índices de DB, operación en producción) vive al final o en apéndices, para no trabar a quien recién arranca.

**Fuentes oficiales consultadas.** Este manual se redactó sobre la documentación oficial publicada por ARCA en `https://www.afip.gob.ar/ws/`. Los manuales PDF utilizados como referencia se depositaron, con su SHA-256 y fecha de descarga, en el apéndice H. La URL principal de consulta es `https://www.afip.gob.ar/ws/`.

**Aviso legal.** La información incluida en este manual se ofrece con fines exclusivamente didácticos. Ante cualquier discrepancia entre lo expresado aquí y la documentación oficial de ARCA, prevalece la documentación oficial vigente al momento de la operación.

**Aviso sobre los datos de ejemplo.** A partir de la versión 3.0 de este manual, el repositorio no contiene ningún CUIT real. Los snippets y los ejemplos de `examples/` exhiben los CUITs como placeholders textuales: `<CUIT_EMISOR>` y `<CUIT_RECEPTOR>`. Antes de ejecutar cualquier snippet contra el SDK, el lector debe reemplazar cada placeholder por un CUIT real válido (once dígitos, sin guiones) correspondiente a su propio emisor y a su contraparte. La validación del formato y de la presencia del CUIT se realiza en tiempo de ejecución; ante valores vacíos o mal formados, los ejemplos emiten una `RuntimeException` con el mensaje de la variable de entorno faltante. La variable `ARCA_CUIT` define el emisor; la variable `ARCA_CUIT_RECEPTOR` define el receptor; ambas se leen del archivo `.env` del proyecto o del entorno del proceso. El lector es responsable de configurar el `.env` con sus CUITs reales antes de invocar el SDK.

---

## Tabla de contenidos

- [0. Quickstart: tu primera factura en 5 minutos](#0-quickstart-tu-primera-factura-en-5-minutos)
- [1. Eligiendo el comprobante correcto](#1-eligiendo-el-comprobante-correcto)
- [2. Llenando el array `$data` (referencia de campos)](#2-llenando-el-array-data-referencia-de-campos)
- [3. La respuesta del SDK](#3-la-respuesta-del-sdk)
- [4. Manejando errores (guía rápida)](#4-manejando-errores-guía-rápida)
- [5. Anatomía interna (qué hace el SDK por vos)](#5-anatomía-interna-qué-hace-el-sdk-por-vos)
- [6. Configuración del entorno](#6-configuración-del-entorno)
- [7. Certificado digital](#7-certificado-digital)
- [8. Padrón A13 (consulta de datos del emisor)](#8-padrón-a13-consulta-de-datos-del-emisor)
- [9. Operación y mantenimiento](#9-operación-y-mantenimiento)
- [10. Diagnóstico avanzado](#10-diagnóstico-avanzado)
- [Apéndice A — Tabla de referencia rápida de tipos de comprobante](#apéndice-a--tabla-de-referencia-rápida-de-tipos-de-comprobante)
- [Apéndice B — Tabla completa de excepciones](#apéndice-b--tabla-completa-de-excepciones)
- [Apéndice C — Snippets copy-paste por tipo](#apéndice-c--snippets-copy-paste-por-tipo)
- [Apéndice D — Variables de entorno completas](#apéndice-d--variables-de-entorno-completas)
- [Apéndice E — Conceptos de facturación (manual vs electrónica)](#apéndice-e--conceptos-de-facturación-manual-vs-electrónica)
- [Apéndice F — RG 5616 (detalle legal/histórico)](#apéndice-f--rg-5616-detalle-legalhistórico)
- [Apéndice G — Glosario](#apéndice-g--glosario)
- [Apéndice H — Fuentes oficiales y SHA-256](#apéndice-h--fuentes-oficiales-y-sha-256)

---

## 0. Quickstart: tu primera factura en 5 minutos

Esta sección asume que ya tenés instalado PHP 8.1+, Composer y MySQL. Si te falta algo, mirá la sección 6 ("Configuración del entorno") y volvé.

### 0.1. Requisitos previos

Para emitir tu primera factura necesitás:

1. **PHP 8.1+** con las extensiones `soap`, `openssl`, `simplexml`, `libxml`, `pdo_mysql` y `bcmath` habilitadas.
2. **MySQL 8.x o MariaDB 10.4+** con una base de datos accesible.
3. **Composer 2.x** para instalar las dependencias.
4. **Un certificado X.509 emitido por ARCA** (ver sección 7).
5. **El CUIT emisor** (once dígitos, sin guiones) asociado al certificado.

### 0.2. Los 4 pasos

```
   1. Instalar el SDK           -> composer install
   2. Configurar el .env        -> 7 variables mínimas
   3. Crear las tablas          -> php db/migrate.php
   4. Emitir una factura        -> $arca->emitirFactura(...)
```

### 0.3. El snippet completo copy-paste

Creá un archivo `mi_primera_factura.php` con este contenido, reemplazando los valores de `<TU_CUIT>`, los paths del certificado y los datos del receptor por los tuyos:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/ARCASdk/vendor/autoload.php';

use Rbbsoft\ArcaSdk\ArcaSdk;
use Rbbsoft\ArcaSdk\Config\Config;

// 1. Cargar el .env (parseo mínimo; en producción usá vlucas/phpdotenv o similar).
$env = [];
foreach (file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    [$k, $v] = array_map('trim', explode('=', $line, 2) + [1 => '']);
    if (strlen($v) >= 2 && $v[0] === '"' && $v[-1] === '"') $v = substr($v, 1, -1);
    $env[$k] = $v;
}

// 2. Construir el Config (el SDK no lee el .env directamente).
$config = Config::fromArray([
    'env'         => $env['ARCA_ENV']         ?? 'homo',
    'cuit'        => $env['ARCA_CUIT'],
    'punto_venta' => (int) ($env['ARCA_PUNTO_VENTA'] ?? 1),
    'cert_path'   => $env['ARCA_CERT_PATH'],
    'key_path'    => $env['ARCA_KEY_PATH'],
    'db_dsn'      => $env['ARCA_DB_DSN'],
    'db_user'     => $env['ARCA_DB_USER'],
    'db_pass'     => $env['ARCA_DB_PASS'],
]);

// 3. Obtener el Singleton.
$arca = ArcaSdk::getInstance($config);

// 4. Emitir una Factura C (la más simple para un primer smoke test).
$externalId = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';  // generá uno nuevo con UuidFactory::v4()
$data = [
    'concepto'                => 1,                  // 1=Productos, 2=Servicios, 3=Ambos
    'receptor_documento_tipo' => 80,                 // 80=CUIT
    'receptor_documento_nro'  => '<CUIT_RECEPTOR>',  // CUIT real del cliente
    'receptor_condicion_iva'  => 'MT',               // Monotributo
    'mon_id'                  => 'PES',
    'mon_cotiz'               => '1.00',
    'items' => [
        ['importe_gravado' => '100.00', 'alicuota_iva' => '21'],
    ],
];

try {
    $respuesta = $arca->emitirFactura($externalId, $data);
    echo "OK\n";
    echo "  CAE:    {$respuesta->cae}\n";
    echo "  Vence:  {$respuesta->caeFchVto}\n";
    echo "  Número: {$respuesta->cbteNro}\n";
    echo "  Total:  {$respuesta->montoTotal}\n";
} catch (\Throwable $e) {
    echo "ERROR: " . get_class($e) . ": {$e->getMessage()}\n";
    exit(1);
}
```

Ejecutá:

```bash
php mi_primera_factura.php
```

Si todo está bien, vas a ver un CAE de catorce dígitos. Si no, mirá la sección 4 ("Manejando errores").

### 0.4. ¿Qué acaba de pasar?

Cuando llamaste a `$arca->emitirFactura($externalId, $data)`, el SDK hizo, en orden:

1. Validó que `$externalId` sea un UUID v4 y que `$data` respete el esquema de Factura C.
2. Verificó si ya existía una emisión con ese `external_id`. Como era la primera vez, no había nada.
3. Creó una fila en la tabla `arca_emisiones_idempotencia` en estado `en_curso`.
4. Solicitó (o reutilizó de cache) un **Ticket de Acceso (TA)** al web service WSAA de ARCA. Este paso es invisible para vos: el SDK lo hace solo y cachea el TA por doce horas.
5. Llamó a `FECAESolicitar` con tu comprobante contra el web service WSFE de ARCA.
6. ARCA aprobó y devolvió un CAE.
7. El SDK actualizó la fila a `emitido` y te devolvió la respuesta.

Todo eso pasó en menos de 5 segundos (la primera vez puede demorar más por la solicitud del TA).

**Próximos pasos.** Leé la sección 1 para entender qué tipo de comprobante emitir según el receptor. Leé la sección 2 para conocer todos los campos del array `$data`. Leé la sección 4 para saber qué hacer si algo falla.

---

## 1. Eligiendo el comprobante correcto

Elegir el comprobante equivocado es la causa número uno de rechazos. Esta sección es la única que necesitás leer antes de tu segunda factura.

### 1.1. Tabla de decisión (respondé en este orden)

| Pregunta | Si la respuesta es… | Entonces emitís |
|----------|---------------------|-----------------|
| ¿Estás anulando o rectificando una factura previa? | **Sí** | Una **Nota de Crédito** (sección 1.5) |
| ¿El receptor es **Monotributo con CUIT**? | **Sí** | Una **Factura M** |
| ¿El receptor es **Consumidor Final** o **Monotributo sin CUIT**? | **Sí** | Una **Factura C** |
| ¿Vos sos **Responsable Inscripto** y el receptor también? | **Sí** | Una **Factura A** |
| ¿Cualquier otro caso? | **—** | Una **Factura B** (la más común) |

### 1.2. Diagrama de flujo

```mermaid
flowchart TD
    A[¿Anula una factura previa?] -->|Sí| B[Nota de Crédito]
    A -->|No| C[¿Receptor es Monotributo con CUIT?]
    C -->|Sí| D[Factura M]
    C -->|No| E[¿Receptor es CF o Monotributo sin CUIT?]
    E -->|Sí| F[Factura C]
    E -->|No| G[¿Vos y el receptor son RI?]
    G -->|Sí| H[Factura A]
    G -->|No| I[Factura B]
```

### 1.3. Tabla resumen A/B/C/M y NC A/B/C/M

| Tipo | Código ARCA | ¿Discrimina IVA? | ¿Requiere CUIT del receptor? | Condición del receptor esperada | Método del SDK |
|------|-------------|------------------|------------------------------|--------------------------------|----------------|
| Factura A | 1 | Sí | Sí | `RI` | `emitirFactura` |
| Factura B | 6 | Sí | No (acepta DNI) | `RI`, `CF`, `MT`, `EX`, `NC` | `emitirFactura` |
| Factura C | 11 | No | No (acepta cualquier doc.) | `CF`, `MT` | `emitirFactura` |
| Factura M | 51 | No | Sí | `MT` | `emitirFactura` |
| Nota de Crédito A | 3 | Sí | Sí | `RI` | `emitirNotaCredito` |
| Nota de Crédito B | 8 | Sí | No (acepta DNI) | `RI`, `CF`, `MT`, `EX`, `NC` | `emitirNotaCredito` |
| Nota de Crédito C | 13 | No | No | `CF`, `MT` | `emitirNotaCredito` |
| Nota de Crédito M | 53 | No | Sí | `MT` | `emitirNotaCredito` |

### 1.4. Errores típicos al elegir

- **Elegir C en lugar de M para un monotributo con CUIT.** ARCA lo rechaza con código 10017 ("El tipo de comprobante no es válido para el CUIT"). Si el monotributo tiene CUIT, va M; si no, va C.
- **Elegir A en lugar de B porque "es más formal".** La A solo se emite entre Responsables Inscriptos. Si el receptor es Monotributo, CF o Exento, va B (no A).
- **Elegir B en lugar de A entre dos RI.** La A discrimina IVA y permite al receptor computar el crédito fiscal. La B no. Si ambos son RI, va A.
- **Anular con el mismo tipo de comprobante.** La NC tiene tipo propio (NC A = 3, NC B = 8, etc.) y debe referenciar la factura original. Ver sección 2.9.

### 1.5. Notas de Crédito: la regla mnemotécnica

La NC tiene exactamente el mismo tipo de letra que la factura que anula: **NC A anula Factura A**, **NC B anula Factura B**, y así. La única diferencia operativa es:

1. Se llama a `emitirNotaCredito` en lugar de `emitirFactura`.
2. Se agrega el campo `cbtes_asoc` (ver sección 2.9) que referencia la factura que se anula.

---

## 2. Llenando el array `$data` (referencia de campos)

Esta sección es la referencia de campos que consultás cada vez que armás un nuevo comprobante. Si necesitás un snippet copy-paste listo, saltá al apéndice C.

### 2.1. Plantilla mínima

```php
$data = [
    'concepto'                => 1,                // 1=Productos, 2=Servicios, 3=Ambos
    'receptor_documento_tipo' => 80,               // 80=CUIT, 96=DNI, 99=Sin identificar
    'receptor_documento_nro'  => '<CUIT_RECEPTOR>',  // CUIT real del cliente
    'receptor_condicion_iva'  => 'MT',             // RI | CF | MT | EX | NC
    'mon_id'                  => 'PES',            // código de moneda
    'mon_cotiz'               => '1.00',           // cotización respecto del peso
    'items' => [
        ['importe_gravado' => '1000.00', 'alicuota_iva' => '21'],
    ],
];
```

### 2.2. Campos obligatorios según el tipo de comprobante

| Campo | Factura A/B/C/M | Nota de Crédito | Servicio (concepto 2 o 3) |
|-------|-----------------|-----------------|---------------------------|
| `concepto` | ✓ | ✓ | ✓ |
| `receptor_documento_tipo` | ✓ | ✓ | ✓ |
| `receptor_documento_nro` | ✓ | ✓ | ✓ |
| `receptor_condicion_iva` | ✓ | ✓ | ✓ |
| `mon_id` | opcional (default `PES`) | opcional | opcional |
| `mon_cotiz` | opcional (default `1.00`) | opcional | opcional |
| `items` | ✓ | ✓ | ✓ |
| `importe_no_gravado` | opcional (default `0.00`) | opcional | opcional |
| `importe_exento` | opcional (default `0.00`) | opcional | opcional |
| `importe_otros_tributos` | opcional (default `0.00`) | opcional | opcional |
| `servicio_desde` | — | — | ✓ (formato `YYYYMMDD`) |
| `servicio_hasta` | — | — | ✓ (formato `YYYYMMDD`) |
| `vencimiento_pago` | — | — | opcional |
| `cbtes_asoc` | — | ✓ | — |
| `cbte_tipo` | opcional (se infiere del método) | opcional | opcional |
| `punto_venta` | opcional (usa el del `Config`) | opcional | opcional |

### 2.3. Los ítems

Cada elemento del array `items` es un array asociativo con dos campos:

| Clave | Tipo | Descripción |
|-------|------|-------------|
| `importe_gravado` | string | Importe gravado por el ítem. Cadena con dos decimales, por ejemplo `"1000.00"`. |
| `alicuota_iva` | string | Alícuota de IVA. Ver tabla 2.5. |

El SDK acepta ítems con diferentes alícuotas en el mismo comprobante; los agrupa internamente para producir el cierre de IVA correcto.

### 2.4. Tabla de alícuotas aceptadas

| Valor en `$data` | Significado |
|------------------|-------------|
| `"21"` | 21% — alícuota general |
| `"10.5"` | 10,5% — alícuota reducida (alimentos, medicamentos, transporte) |
| `"27"` | 27% — alícuota incrementada (servicios digitales, energía) |
| `"5"` | 5% — alícuota intermedia (uso específico según catálogo ARCA) |
| `"2.5"` | 2,5% — alícuota reducida adicional (uso específico según catálogo ARCA) |
| `"0"` | 0% — exento (el ítem está exento de IVA, sin monto de IVA) |

**No existe `"NG"`** como alícuota. Si tenés importes que no están gravados por IVA, usá el campo `importe_no_gravado` (sección 2.10), no una alícuota inexistente.

Si mandás una alícuota fuera de esta lista, `Comprobante::fromArray` lanza `ValidationException` con la lista de las permitidas.

### 2.5. Tabla de condiciones de IVA del receptor

| Valor en `$data` | Significado |
|------------------|-------------|
| `RI` | Responsable Inscripto |
| `CF` | Consumidor Final |
| `MT` | Monotributo |
| `EX` | Exento |
| `NC` | No Categorizado |

> **Nota.** La condición `SC` (Sujeto no Categorizado) figura en listados históricos de ARCA pero el SDK actual **no la acepta**. Usá `NC` (No Categorizado) en su lugar.

El SDK traduce automáticamente estos códigos al ID interno que ARCA exige en el campo `CondicionIVAReceptorId` (ver apéndice F sobre RG 5616).

### 2.6. Tabla de tipos de documento del receptor

| Código | Significado |
|--------|-------------|
| 80 | CUIT |
| 86 | CUIL |
| 87 | CDI |
| 89 | LE |
| 90 | LC |
| 91 | CI Extranjera |
| 92 | En trámite |
| 93 | Acta de nacimiento |
| 94 | Pasaporte |
| 95 | CI Buenos Aires |
| 96 | DNI |
| 99 | Otro (sin identificación) |

**Regla:** para Factura A y Factura M, el código debe ser 80 (CUIT). El SDK valida esta restricción antes de llamar a ARCA.

### 2.7. Servicios vs productos

| `concepto` | Significado | Campos adicionales obligatorios |
|------------|-------------|----------------------------------|
| `1` | Productos | (ninguno) |
| `2` | Servicios | `servicio_desde`, `servicio_hasta` (formato `YYYYMMDD`) |
| `3` | Productos y Servicios | `servicio_desde`, `servicio_hasta` |

`servicio_hasta` debe ser igual o posterior a `servicio_desde`. Opcionalmente podés agregar `vencimiento_pago` para fechas de pago posteriores.

### 2.8. Notas de crédito: el campo `cbtes_asoc`

Para `emitirNotaCredito` el campo `cbtes_asoc` es **obligatorio** y referencia la factura que se anula:

```php
'cbtes_asoc' => [
    [
        'tipo'        => 6,   // 6 = Factura B (el tipo de la factura que anulás)
        'punto_venta' => 1,   // el mismo PV de la factura original
        'nro'         => 123, // el número de la factura que anulás
    ],
],
```

El `tipo` del `cbtes_asoc` debe ser compatible con el tipo de NC (NC A → Factura A = 1, NC B → Factura B = 6, etc.). El SDK valida esta compatibilidad antes de llamar a ARCA.

### 2.9. Importes no gravados y exentos

Hay tres campos que afectan importes más allá del IVA discriminado:

| Campo | Cuándo se usa | Default |
|-------|---------------|---------|
| `importe_no_gravado` | Montos que no tributan IVA (no es lo mismo que exento: el "no gravado" no es IVA pero sí es parte del total) | `"0.00"` |
| `importe_exento` | Ítems con alícuota 0% (exentos de IVA) | `"0.00"` |
| `importe_otros_tributos` | Otros tributos (IIBB, impuestos internos) que se suman al total | `"0.00"` |

**Regla práctica:** si tu comprobante tiene sólo ítems gravados, no necesitás estos campos. Si una parte del total no tributa IVA (por ejemplo, una bonificación o un interés), usá `importe_no_gravado`.

---

## 3. La respuesta del SDK

Cuando una emisión termina bien, el SDK devuelve un **DTO inmutable** (`Rbbsoft\ArcaSdk\Wsfe\ComprobanteEmitido`). La forma canónica de la respuesta es el DTO, con propiedades tipadas y de solo lectura. Para los callers que necesiten la forma histórica snake_case, el DTO expone el método `asArray()` (ver sección 3.3).

### 3.1. Tabla de propiedades del DTO

| Propiedad | Tipo | Descripción |
|-----------|------|-------------|
| `$comprobante->cae` | string | El Código de Autorización Electrónico. Cadena de catorce dígitos. |
| `$comprobante->caeFchVto` | string | La fecha de vencimiento del CAE en formato `YYYYMMDD`. |
| `$comprobante->cbteNro` | int | El número de comprobante asignado por ARCA. |
| `$comprobante->cbteFch` | string | La fecha del comprobante en formato `YYYY-MM-DD`. |
| `$comprobante->montoTotal` | string | El monto total del comprobante. |
| `$comprobante->montoNeto` | string | El monto neto gravado. Ver sección 3.2. |
| `$comprobante->montoIva` | string | El monto total de IVA. Ver sección 3.2. |
| `$comprobante->cbteTipo` | int | El tipo de comprobante (1, 6, 11, 51, 3, 8, 13 o 53). |
| `$comprobante->puntoVenta` | int | El punto de venta del comprobante. |
| `$comprobante->monId` | string | El código de moneda (`PES`, `DOL`, etc.). |
| `$comprobante->monCotiz` | string | La cotización respecto del peso. |
| `$comprobante->cuit` | int | El CUIT emisor. |
| `$comprobante->receptorDocumentoTipo` | int\|null | El código de tipo de documento del receptor (80=CUIT, 96=DNI, etc.). |
| `$comprobante->receptorDocumentoNro` | string\|null | El número de documento del receptor. |
| `$comprobante->receptorCondicionIva` | string\|null | La condición de IVA del receptor (`RI`, `CF`, `MT`, etc.). |
| `$comprobante->items` | array | Los ítems normalizados del comprobante. |
| `$comprobante->observaciones` | array | Las observaciones de ARCA (típicamente vacío en una respuesta aprobada). |
| `$comprobante->origen` | string\|null | `'nuevo'` en emisión nueva, `'zombie_consultar'` / `'zombie_reemit'` si fue recuperado del path zombie, `null` en replay. |
| `$comprobante->externalId` | string\|null | El `external_id` que pasaste en la llamada. |
| `$comprobante->resultado` | string | El resultado reportado por ARCA. `'A'` significa aprobado. |
| `$comprobante->cbtesAsoc` | array | Solo para NC: el array `cbtes_asoc` que enviaste. |

### 3.2. Particularidades por tipo de comprobante

| Tipo | `$montoNeto` | `$montoIva` |
|------|--------------|-------------|
| Factura A, B, NC A, NC B | Suma de los gravados (con IVA discriminado) | IVA discriminado (21% / 10,5% / etc.) |
| Factura C, M, NC C, NC M | Igual a `$montoTotal` (no se discrimina IVA) | `'0.00'` (no se discrimina IVA) |

**Importante:** el DTO **siempre** incluye `$montoNeto` y `$montoIva`, incluso en C y M. La diferencia es semántica: en C y M son el total sin discriminar.

### 3.3. Compat con la forma array snake_case histórica

Si tu código existente espera el array asociativo snake_case, podés obtenerlo con el método `asArray()`:

```php
$resp = $arca->emitirFactura($externalId, $data);
$array = $resp->asArray();
echo $array['cae'];              // '86310728945116'
echo $array['cae_fch_vto'];      // '20260814'
echo $array['cbte_nro'];         // 4
```

Las claves del array devuelto por `asArray()` son: `cae`, `cae_fch_vto`, `cbte_nro`, `cbte_fch`, `monto_total`, `monto_neto`, `monto_iva`, `cbte_tipo`, `punto_venta`, `mon_id`, `mon_cotiz`, `cuit`, `receptor_documento_tipo`, `receptor_documento_nro`, `receptor_condicion_iva`, `items`, `observaciones`, `origen`, `external_id`, `resultado`, `cbtes_asoc`. Esta forma se ofrece como compat temporal y no se garantiza estable a futuro: el nuevo código debería usar las propiedades del DTO directamente.

### 3.4. Respuesta idempotente

Si llamás a `emitirFactura` dos veces con el mismo `external_id` y los mismos datos, el SDK te devuelve **un DTO con los mismos valores** sin volver a llamar a ARCA. Esto se llama **idempotencia** y es una de las propiedades clave del SDK. La sección 5.2 la explica en detalle. **Nota:** el campo `$origen` puede diferir entre la primera llamada (`'nuevo'`) y la replay (`null`), pero el resto de los campos contractuales (CAE, número, fecha, importes) son idénticos.

### 3.5. Generando el PDF del comprobante

Una vez emitido, el SDK puede generar el PDF oficial del comprobante con QR ARCA embebido. Hay dos puntos de entrada:

**Forma recomendada (wrapper del orquestador):**

```php
$resp = $arca->emitirFactura($externalId, $data);
$pdfPath = $arca->generarPdf($resp);
echo "PDF: {$pdfPath}\n";
```

`generarPdf()` acepta el DTO directamente (forma canonica) o un array mergeado con la forma historica (`ComprobanteEmitido::fromArray($resp->asArray())` se hace por dentro). El segundo parametro opcional es un directorio destino custom; si lo pasás, se ignora el generador del Container y se construye uno nuevo con ese destino (útil para escribir en un path por tenant, un disco de red, etc.). Si lo omitís, se usa el del Container, que escribe en `comprobantes/` relativo a `getcwd()`.

**Forma directa (sin pasar por el orquestador):**

```php
use Rbbsoft\ArcaSdk\Pdf\ComprobantePdfGenerator;

$gen = new ComprobantePdfGenerator('C:/mis/pdfs');
$pdfPath = $gen->generar($resp);   // acepta DTO o array
```

El `ComprobantePdfGenerator` también acepta un array con la forma snake_case histórica, lo que permite regenerar PDFs de comprobantes a partir de un snapshot de la DB:

```php
$snapshot = [
    'cbte_tipo'   => 11,
    'cbte_nro'    => 4,
    'cbte_fch'    => '2026-08-04',
    'cae'         => '86310728945116',
    'cae_fch_vto' => '20260814',
    'monto_total' => '100.00',
    'monto_neto'  => '100.00',
    'monto_iva'   => '0.00',
    'mon_id'      => 'PES',
    'mon_cotiz'   => '1.00',
    'punto_venta' => 2,
    'cuit'        => 20123456786,                     // CUIT emisor ficticio, formato valido
    'receptor_documento_tipo' => 80,
    'receptor_documento_nro'  => '30912345676',      // CUIT receptor ficticio, formato valido
    'receptor_condicion_iva'  => 'MT',
    'items' => [
        ['importe_gravado' => '100.00', 'alicuota_iva' => '21'],
    ],
];
$pdfPath = (new ComprobantePdfGenerator())->generar($snapshot);
```

**Estructura del QR (spec oficial ARCA v1).** El QR que el SDK embebe en el PDF codifica la URL `https://www.arca.gob.ar/fe/qr/?p=<base64(json)>`, donde el JSON tiene exactamente 13 campos en este orden:

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `ver` | int | Versión del payload (siempre `1`). |
| `fecha` | string | Fecha del comprobante en `YYYY-MM-DD`. |
| `cuit` | int | CUIT emisor (11 dígitos). |
| `ptoVta` | int | Punto de venta. |
| `tipoCmp` | int | Tipo de comprobante. |
| `nroCmp` | int | Número de comprobante. |
| `importe` | int | Monto total **multiplicado por 100** (sin punto decimal, BCMath). |
| `moneda` | string | Código de moneda (`PES`, `DOL`, etc.). |
| `ctz` | int | Cotización **multiplicada por 1.000.000** (sin punto decimal, BCMath). |
| `tipoDocRec` | int | (Opcional) Tipo de documento del receptor. Se omite si no hay receptor. |
| `nroDocRec` | int | (Opcional) Número de documento del receptor (sin guiones, hasta 20 dígitos). Se omite si no hay receptor. |
| `tipoCodAut` | string | Tipo de autorización (siempre `'E'` para CAE; nunca CAEA). |
| `codAut` | int | CAE como entero de 14 dígitos. |

Las conversiones numéricas (importe × 100, ctz × 10⁶) se hacen con BCMath para mantener la regla del SDK de no usar `float` para importes contractuales. El Base64 es estándar (`+/=`), no `base64url`. La spec oficial está en `docs/ARCA/QRespecificaciones.pdf` (2 páginas).

---

## 4. Manejando errores (guía rápida)

El SDK traduce todos los modos de fallo a **excepciones tipadas**. Esta sección cubre las cinco excepciones que vas a ver con más frecuencia. Para la tabla completa, mirá el apéndice B.

### 4.1. Las 5 excepciones más frecuentes

| Excepción | Cuándo se dispara | Acción |
|-----------|-------------------|--------|
| `ValidationException` | El `external_id` no es UUID v4, o el array `$data` no respeta el esquema | Corregir los datos. **No reintentar** con los mismos datos. |
| `CbteRechazadoException` | ARCA rechazó el comprobante (`Resultado='R'`) | Leer `observaciones` (cada una tiene `codigo` y `mensaje`). **No reintentar** con los mismos datos; ajustar y usar `external_id` nuevo. |
| `IdempotencyConflictException` | El `external_id` ya existe pero con datos distintos | Decidir: usar `external_id` nuevo, o reintentar con los mismos datos. |
| `WsaaException` / `WsfeException` | Fallo de red, timeout, SoapFault | El SDK ya reintentó. Si llega al usuario, esperar unos minutos y reintentar con `external_id` nuevo. |
| `MaxIdempotencyAttemptsException` | El SDK rechazó la emisión más de 5 veces seguidas por motivos de negocio | Investigar la causa raíz (observaciones en la base de datos) y considerar `resetExternalId()`. |

### 4.2. Patrón de manejo copy-paste

```php
use Rbbsoft\ArcaSdk\ArcaSdk;
use Rbbsoft\ArcaSdk\Exceptions\ValidationException;
use Rbbsoft\ArcaSdk\Exceptions\CbteRechazadoException;
use Rbbsoft\ArcaSdk\Exceptions\IdempotencyConflictException;
use Rbbsoft\ArcaSdk\Exceptions\ArcaException;

try {
    $respuesta = $arca->emitirFactura($externalId, $data);
    // OK: usar $respuesta->cae, $respuesta->cbteNro, etc.
    // Si necesitas la forma snake_case historica: $respuesta->asArray().
} catch (ValidationException $e) {
    // Datos mal formados. Corregir y reintentar con el mismo external_id.
    error_log('Datos inválidos: ' . $e->getMessage());
} catch (CbteRechazadoException $e) {
    // ARCA rechazó el comprobante. NO reintentar con los mismos datos.
    foreach ($e->observaciones as $obs) {
        error_log("[{$obs['codigo']}] {$obs['mensaje']}");
    }
    // Ajustar $data y reintentar con un external_id NUEVO.
} catch (IdempotencyConflictException $e) {
    // El external_id ya se usó con datos distintos.
    // Decisión: external_id nuevo, o reintentar con los datos originales.
    error_log('Conflicto de idempotencia: ' . $e->getMessage());
} catch (ArcaException $e) {
    // Captura genérica de cualquier excepción del SDK.
    // Si la sección 4.1 no la cubre, ver el apéndice B.
    error_log('Error ARCA: ' . get_class($e) . ': ' . $e->getMessage());
}
```

### 4.3. Tabla de diagnóstico rápido

| Síntoma | Causa probable | Acción |
|---------|----------------|--------|
| `ConfigException: CUIT debe tener 11 digitos sin guiones` | El `ARCA_CUIT` está mal formateado | Verificar el `.env` y eliminar guiones o espacios. |
| `ConfigException: cert_path no legible: …` | El path del certificado no existe o PHP no tiene permisos | Verificar el path y los permisos del usuario de Apache/PHP-FPM. |
| `ValidationException: ... no es un UUID v4` | El `external_id` no tiene el formato correcto | Usá `UuidFactory::v4()` para generarlo. |
| `IdempotencyConflictException` al reintentar | Reusaste el mismo `external_id` con datos distintos | Usar un `external_id` nuevo, o reintentar con los mismos datos. |
| `CbteRechazadoException` con `observaciones[].codigo=10016` | El número o fecha no corresponde con el próximo a autorizar | Esperar o ajustar la fecha del comprobante. |
| `CbteRechazadoException` con `observaciones[].codigo=10017` | El tipo de comprobante no es válido para el CUIT | Verificar la sección 1 ("Eligiendo el comprobante correcto"). |
| `CbteRechazadoException` con `observaciones[].codigo=10246` | Falta la condición de IVA del receptor (RG 5616) | Verificar `receptor_condicion_iva`; ver apéndice F. |
| La primera llamada demora más de 30 segundos | El SDK está solicitando el TA al WSAA por primera vez | Esperar. Las llamadas siguientes serán más rápidas (TA cacheado). |
| `WsaaCeeYaPoseeTaException` repetido en homologación | ARCA lockeó el CEE tras una emisión reciente | Esperar 10 minutos (homo) o 2 minutos (prod) entre emisiones. |

---

## 5. Anatomía interna (qué hace el SDK por vos)

Esta sección es para cuando ya emitiste tu primera factura y querés entender mejor qué pasa por debajo. **Si recién arrancás, podés saltearla y volver más tarde.**

### 5.1. El viaje de una emisión

```mermaid
sequenceDiagram
    participant App as Tu aplicación
    participant SDK as SDK ArcaSdk
    participant DB as Base de datos
    participant WSAA as WSAA (ARCA)
    participant WSFE as WSFE (ARCA)

    App->>SDK: emitirFactura(externalId, data)
    SDK->>SDK: Validar externalId y $data
    alt externalId nuevo
        SDK->>DB: Crear fila en_curso
        SDK->>SDK: Tomar lock de emision
        alt No hay TA vigente
            SDK->>WSAA: Solicitar Ticket de Acceso
            WSAA-->>SDK: TA (token, sign)
            SDK->>DB: Cachear TA
        end
        SDK->>WSFE: Consultar último comprobante autorizado
        WSFE-->>SDK: último número
        SDK->>WSFE: FECAESolicitar
        alt ARCA aprueba
            WSFE-->>SDK: CAE, número, fecha de vto
            SDK->>DB: Marcar fila como emitido
            SDK-->>App: Respuesta con CAE
        else ARCA rechaza
            WSFE-->>SDK: Resultado='R' con observaciones
            SDK->>DB: Marcar fila como fallido
            SDK-->>App: Excepción CbteRechazadoException
        end
    else externalId ya emitido
        SDK->>DB: Leer fila emitido
        SDK-->>App: Respuesta cacheada (sin llamar a ARCA)
    end
```

### 5.2. El `external_id` y la idempotencia

El `external_id` es la pieza más importante de la integración. Es un **UUID v4** (formato hexadecimal de 32 dígitos con guiones, generado aleatoriamente) que identifica de manera única cada intento de emisión lógica.

**Tres reglas de oro:**

1. **Generá el `external_id` antes de llamar al SDK**, en el mismo paso que persistís la venta en tu base de datos. Si la llamada al SDK falla, tenés el `external_id` guardado y podés reintentar con el mismo.
2. **No reutilices el `external_id` con datos de negocio distintos.** Si la misma venta se intenta emitir dos veces, usá el mismo `external_id` con los mismos datos. Si es una venta distinta, generá un `external_id` nuevo.
3. **Mezclar ambos casos (mismo `external_id` con datos distintos) es un error de programación.** El SDK lo rechaza con `IdempotencyConflictException` antes de tocar la red.

**Cómo generar un UUID v4 sin dependencias:**

```php
function generarUuidV4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
```

El SDK incluye `Rbbsoft\ArcaSdk\Idempotencia\UuidFactory::v4()` con la misma implementación.

### 5.3. El Singleton

`ArcaSdk` es un Singleton: la primera llamada a `ArcaSdk::getInstance($config)` lo construye; las siguientes reutilizan esa instancia. Esto permite que la conexión a la base de datos, el cache de TA y el logger vivan durante todo el proceso PHP.

**Implicaciones prácticas:**

- Toda tu aplicación comparte la misma instancia. Si llamás a `emitirFactura` desde dos módulos, ambos usan la misma configuración y la misma conexión.
- Un segundo `getInstance` con configuración incompatible (CUIT distinto, por ejemplo) lanza `ConfigException`. Esto es por diseño: el SDK es single-tenant en esta versión.
- `ArcaSdk::resetInstance()` destruye el Singleton. Solo se usa en tests automatizados.

### 5.4. La base de datos

El SDK necesita dos tablas en tu MySQL/MariaDB:

| Tabla | Para qué sirve |
|-------|----------------|
| `arca_ticket_acceso` | Cache del Ticket de Acceso. Permite que un solo worker pague el costo de solicitar el TA cada doce horas, mientras el resto lo reutiliza. |
| `arca_emisiones_idempotencia` | Idempotencia y recuperación. Guarda el estado de cada emisión (`en_curso`, `emitido`, `fallido`) y la huella de los datos. |

Ambas tablas se crean con `php db/migrate.php` (idempotente). El usuario no necesita interactuar con ellas directamente; la descripción sirve para entender qué hace el SDK y para depurar situaciones atípicas.

### 5.5. El Ticket de Acceso (TA)

El TA es la credencial temporal que ARCA te entrega una vez autenticado. Tiene una vigencia de doce horas y el SDK lo renueva automáticamente cuando vence. **No tenés que hacer nada para gestionarlo**: el SDK lo cachea en la tabla `arca_ticket_acceso` y lo reutiliza entre workers.

---

## 6. Configuración del entorno

### 6.1. Instalación

```bash
# 1. Clonar o copiar el SDK en el directorio de tu aplicación.
#    (Ya está en C:\xampp\htdocs\ARCASdk\ si seguiste el quickstart.)

# 2. Instalar dependencias.
cd ARCASdk
composer install

# 3. Crear el archivo de configuración a partir de la plantilla.
cp .env.example .env

# 4. Editar el .env con tus datos (ver sección 6.2).

# 5. Crear las tablas en la base de datos.
php db/migrate.php
```

### 6.2. El archivo `.env` (mínimo viable)

Para que el SDK arranque, el `.env` necesita estas siete variables. El resto son opcionales (ver apéndice D).

```ini
# Ambiente: 'homo' para homologación (testing), 'prod' para producción.
ARCA_ENV=homo

# CUIT emisor (once dígitos, sin guiones). Reemplace <CUIT_EMISOR>
# por su CUIT real de homologación o producción.
ARCA_CUIT=<CUIT_EMISOR>

# CUIT receptor usado por los examples de `examples/`. Reemplace
# <CUIT_RECEPTOR> por el CUIT real del cliente al que se emite.
ARCA_CUIT_RECEPTOR=<CUIT_RECEPTOR>

# Punto de venta (1 a 99998). El mismo que diste de alta en ARCA.
ARCA_PUNTO_VENTA=2

# Paths absolutos al certificado (.pem) y a la clave privada (.key).
# Mantener FUERA del docroot del servidor web.
ARCA_CERT_PATH=C:/xampp/Certificados/homo/MiCertificado.pem
ARCA_KEY_PATH=C:/xampp/Certificados/homo/MiClavePrivada.key

# DSN PDO y credenciales de la base de datos.
ARCA_DB_DSN=mysql:host=127.0.0.1;dbname=arca_facturador;charset=utf8mb4
ARCA_DB_USER=arca_user
ARCA_DB_PASS=tu_password
```

### 6.3. Construcción del `Config`

El SDK no lee el `.env` directamente. Tu aplicación debe parsearlo y construir un `Config`:

```php
$config = Config::fromArray([
    'env'         => 'homo',
    'cuit'        => '20123456786',  // CUIT emisor ficticio, formato valido
    'punto_venta' => 2,
    'cert_path'   => 'C:/xampp/Certificados/homo/MiCertificado.pem',
    'key_path'    => 'C:/xampp/Certificados/homo/MiClavePrivada.key',
    'db_dsn'      => 'mysql:host=127.0.0.1;dbname=arca_facturador;charset=utf8mb4',
    'db_user'     => 'arca_user',
    'db_pass'     => 'tu_password',
]);

$arca = ArcaSdk::getInstance($config);
```

Las claves del array son las mismas que las variables del `.env` pero sin el prefijo `ARCA_` y usando `snake_case`. El `Config` es **inmutable**: una vez construido, no se puede modificar.

### 6.4. Variables avanzadas

Las variables del `.env` cubren más casos de los que se necesitan para empezar. Para la lista completa con propósitos y valores por defecto, mirá el apéndice D. Las que más probablemente vayas a tocar son:

| Variable | Default | Para qué sirve |
|----------|---------|----------------|
| `ARCA_SOAP_TIMEOUT` | `30` | Timeout SOAP contra ARCA en segundos. |
| `ARCA_RETRY_MAX_ATTEMPTS` | `3` | Cuántas veces reintentar errores transitorios. |
| `ARCA_IDEMPOTENCIA_MAX_INTENTOS` | `5` | Cuántas veces reintentar rechazos de ARCA antes de bloquear el external_id. |
| `ARCA_IDEMPOTENCIA_TTL_SEGUNDOS` | `300` | Cuánto tiempo se considera vigente una fila `en_curso` antes de poder ser reconciliada. |

---

## 7. Certificado digital

### 7.1. Qué es y para qué lo necesita el SDK

El **certificado X.509** es un archivo criptográfico que identifica al CUIT emisor. El SDK lo usa para firmar digitalmente las solicitudes que envía a ARCA. Sin él, ARCA no te entrega el Ticket de Acceso y no podés emitir comprobantes.

Necesitás **dos archivos** por cada ambiente (homologación y producción):

- **Certificado** (extensión convencional `.pem`): texto con cabeceras `-----BEGIN CERTIFICATE-----`.
- **Clave privada** (extensión convencional `.key`): texto con cabeceras `-----BEGIN PRIVATE KEY-----` o `-----BEGIN RSA PRIVATE KEY-----`.

### 7.2. Cómo obtenerlo (resumen)

El proceso completo se detalla en el manual oficial `WSAA_Generacion_certificados_digitales.pdf` (apéndice H). A grandes rasgos:

1. **Generar localmente un CSR (Certificate Signing Request)** con OpenSSL:
   ```bash
   openssl req -new -newkey rsa:2048 -nodes \
     -keyout MiClavePrivada.key \
     -out SolicitudCertificado.csr \
     -subj "/C=AR/ST=Buenos Aires/L=CABA/O=<SU_RAZON_SOCIAL>/CN=<SU_CUIT>"
   ```
2. **Solicitar a ARCA** que lo firme desde el portal correspondiente:
   - Homologación: portal **WSASS** (Autoservicio).
   - Producción: portal **Administrador de Certificados Digitales**.
3. **Descargar el `.crt`** firmado por ARCA y combinarlo con la clave privada. Si el `.crt` ya es PEM, renombralo a `.pem`.

### 7.3. Diagnóstico rápido del certificado

| Síntoma | Causa probable | Acción |
|---------|----------------|--------|
| "Certificado revocado" al primer uso | Se solicitó un nuevo certificado para el mismo CUIT y revocó el anterior | Generar un nuevo CSR y repetir. |
| "Firma inválida" en la primera llamada a WSAA | El `.pem` y el `.key` no corresponden al mismo par | Regenerar el CSR o re-extraer del `.pfx`. |
| "El certificado no corresponde al CUIT emisor" | El `CN` del certificado no coincide con `ARCA_CUIT` | Verificar que el `.pem` se generó con el CUIT correcto. |
| "Certificado ha expirado" | Superó la fecha de vencimiento (típicamente 2-3 años) | Solicitar un certificado nuevo. |
| "Passthrough header no presente" | Estás usando cert de homo contra endpoints de prod (o viceversa) | Verificar que `ARCA_ENV` coincida con el ambiente del cert. |
| `cert_path no legible: …` al construir el `Config` | El path no existe o PHP no tiene permisos | Verificar el path y los permisos del archivo. |
| "Computador no autorizado a acceder al servicio `ws_X`" al pedir el TA | El DN del certificado está dado de alta en ARCA para otros WSNs pero **no** para `ws_X`. Cada servicio (`wsfe`, `ws_sr_padron_a13`, `wsmtxca`, etc.) se habilita por separado en la Admin de Relaciones. | Ir a ARCA → Admin de Relaciones → agregar el servicio al DN. Esperar 5-10 min a que propague. Reintentar. Ver sección 7.5. |

### 7.4. Buenas prácticas de seguridad

- **Almacenar los archivos fuera del docroot del servidor web.** En XAMPP sobre Windows, una convención segura es `C:\xampp\Certificados\` (hermano de `htdocs`, no dentro). En Linux, típicamente `/etc/arca/` o `/var/secrets/arca/` con permisos `700`.
- **Restringir los permisos del archivo de clave privada.** En Linux, `chmod 600 MiClavePrivada.key`. En Windows, denegar el acceso a todos los usuarios excepto al que corre el servicio de Apache.
- **No versionar los archivos en Git.** El archivo `.gitignore` del repositorio debe contener reglas que excluyan `*.pem`, `*.key` y `.env`.
- **Mantener una copia de respaldo offline.** Si los archivos se pierden, hay que generar un nuevo CSR y repetir el proceso de solicitud ante ARCA, lo que puede demorar varios días.
- **No transmitir los archivos por canales inseguros** (correo sin cifrar, mensajería). El `.pem` se puede transmitir; la `.key` no.

### 7.5. WSNs que hay que autorizar en la Admin de Relaciones (obligatorio)

Tener el certificado instalado en ARCA **no alcanza**. Cada servicio web al que el SDK le pega se habilita **por separado** en la **Admin de Relaciones de Clave Fiscal**. Si el DN del certificado está habilitado para `wsfe` pero no para `ws_sr_padron_a13`, la emisión de comprobantes anda y la consulta al padrón A13 da el fault *"Computador no autorizado a acceder al servicio [ws_sr_padron_a13]"*. Cada WSN es independiente.

**Procedimiento para autorizar un WSN al DN del certificado:**

1. Entrar a ARCA con el CUIT del certificado.
2. Ir a **Administración de Relaciones de Clave Fiscal → Administrador de Relaciones**.
3. Buscar el DN del certificado (suele figurar como `SERIANUMBER=CUIT XXXXXXXX, CN=NombreDelComputador`).
4. En la sección "Servicios Habilitados" (o equivalente), **agregar el WSN** que falte.
5. Confirmar. Esperar **5-10 minutos** a que propague.
6. Reintentar el flujo del SDK que estaba fallando.

**WSNs que este SDK necesita tener autorizados en la Admin de Relaciones** (mínimo indispensable para usar todas las features del SDK):

| Feature del SDK | WSN a autorizar | Síntoma si falta |
|---|---|---|
| `emitirFactura`, `emitirNotaCredito` (F A, B, C, M y NC A, B, C, M) | **`wsfe`** | `WsaaException: WSAA loginCms SoapFault: Computador no autorizado a acceder al servicio [wsfe]` |
| `obtenerEmisor($cuit)` (consulta al padrón A13) | **`ws_sr_padron_a13`** | `WsaaException: WSAA loginCms SoapFault: Computador no autorizado a acceder al servicio [ws_sr_padron_a13]` |
| `obtenerEmisor` en la app del usuario (consulta datos de un cliente) | **`ws_sr_padron_a13`** (el mismo de arriba; se reutiliza el TA del emisor para consultar el padrón de cualquier CUIT) | El mismo de arriba |

**WSNs adicionales que el SDK no usa hoy** (no hay que autorizar, pero los menciono porque vas a verlos en ARCA):

- `wsmtxca` — Régimen de información de operaciones internacionales (no utilizado por el SDK).
- `ws_sr_constancia_inscripcion` — **nombre histórico del padrón A13**. ARCA lo rebautizó a `ws_sr_padron_a13`; si lo tenés autorizado pero no el nuevo, el SDK NO funciona. Autorizá directamente el nuevo.
- `wsaa` — servicio de autenticación. Se usa internamente para pedir CUALQUIER TA; ARCA lo da por autorizado por default al pedir cualquier WSN específico, no requiere acción separada.

**Cómo verificar que tu DN está bien autorizado:** el flujo más rápido es correr `examples/factura_c_basica_pdf.php 0 1 0` después de cada cambio. Si emite la factura y la consulta al padrón no tira `Computador no autorizado`, está todo bien. Si el padrón tira el fault, falta `ws_sr_padron_a13`. Si ni siquiera emite, falta `wsfe`.

**Diferencia entre WSAA y la Admin de Relaciones:** el WSAA (el loginCms) es lo que autentica al certificado contra ARCA. La Admin de Relaciones es lo que define **a qué** servicios puede hablar ese certificado una vez autenticado. Las dos cosas son necesarias e independientes.

---

## 8. Padrón A13 (consulta de datos del emisor)

### 8.1. Para qué sirve

Para emitir un comprobante en formato PDF necesitás los datos del emisor: razón social, domicilio fiscal, condición frente al IVA, fecha de inicio de actividades, etc. Esos datos están en el **padrón de ARCA** y el SDK los expone con `obtenerEmisor($cuit)`.

### 8.2. Cómo se usa

```php
$arca = ArcaSdk::getInstance($config);

// Consultar el propio CUIT (típicamente para renderizar el PDF).
$emisor = $arca->obtenerEmisor((int) $config->cuit);

// Consultar el CUIT de un tercero (requiere permiso "Consulta de Padrón" en ARCA).
$cliente = $arca->obtenerEmisor(30123456780);
```

**Importante:** la consulta es **en vivo contra ARCA** cada vez. Si necesitás los datos en cada emisión, persistilos en tu propia base de datos al ejecutar la consulta una vez (típicamente en el setup o con un refresco manual periódico).

### 8.3. El DTO `Emisor`

`obtenerEmisor` devuelve un value object inmutable con esta estructura:

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `cuit` | int | CUIT consultado. |
| `razonSocial` | ?string | Razón social, si es persona jurídica. |
| `apellidoNombre` | ?string | Apellido y nombre, si es persona física (formato `"APELLIDO, NOMBRE"`). |
| `tipoPersona` | ?string | `"FISICA"` o `"JURIDICA"`. |
| `estadoClave` | string | Estado del contribuyente. Habitualmente `"ACTIVO"`. |
| `fechaInscripcion` | ?string | Fecha de inicio de actividades (`YYYY-MM-DD`). |
| `domicilioFiscal` | `DomicilioFiscal` | Sub-objeto con la dirección fiscal. |
| `actividades` | `Actividad[]` | Lista de actividades económicas. |
| `impuestos` | `Impuesto[]` | Lista de impuestos en los que está inscripto. |
| `categoriaMonotributo` | ?string | Categoría de monotributo si aplica; `null` en otro caso. |
| `condicionIva` | ?string | Condición IVA: `"RESPONSABLE_INSCRIPTO"`, `"MONOTRIBUTO"`, `"EXENTO"`, `"NO_CATEGORIZADO"`. |

### 8.4. Excepciones del padrón

- `PadronException`: fallo funcional (CUIT no encontrado, sin permisos).
- `PadronArcaTransientException`: ARCA devolvió código 9999 (transitorio, reintentable).
- `PadronProtocolException`: respuesta malformada. El campo `kind` indica el tipo (`empty_body`, `html_gateway`, `http_5xx`, `structural`, `unknown`).

### 8.5. Responsabilidad de la aplicación

El SDK devuelve los datos del padrón y nada más. **Tres responsabilidades quedan en tu mano:**

1. **Persistencia del `Emisor`.** Decidí dónde guardar el DTO (DB propia, JSON, cache de aplicación).
2. **Datos manuales no presentes en el padrón.** El padrón no expone logo, email, teléfono, web, ni nombre de fantasía. Esos los completás vos.
3. **Generación del PDF.** El SDK incluye `Rbbsoft\ArcaSdk\Pdf\ComprobantePdfGenerator`, que arma un PDF A4 con el QR oficial de ARCA a partir del `ComprobanteEmitido` (o de un array con la forma snake_case histórica). La forma recomendada es invocar `$arca->generarPdf($resp)` desde el orquestador; los detalles de uso están en la sección 3.5. Si se prefiere otro motor de render (`dompdf`, `tcpdf`, etc.) puede seguir consumiéndose el DTO; el SDK no obliga a usar el generador provisto.

---

## 9. Operación y mantenimiento

### 9.1. De homologación a producción: qué se cambia y en qué orden

1. **Solicitar el certificado de producción** (portal "Administrador de Certificados Digitales").
2. **Asociar el certificado al Web Service de Negocio (WSFE) en producción** (portal "Administrador de Relaciones de Clave Fiscal"). Sin esta asociación, ARCA rechaza las llamadas.
3. **Solicitar el alta de los puntos de venta de producción** (los de homo no funcionan en prod).
4. **Actualizar el `.env` de producción**: `ARCA_ENV=prod`, paths del certificado de prod, `ARCA_PUNTO_VENTA` al nuevo PV.
5. **Ejecutar el script de migración en la base de datos de producción** (`php db/migrate.php`). Las tablas son las mismas, pero la base comienza vacía.

Una vez completados los cinco pasos, ejecutá `factura_c_basica.php` contra producción para validar la integración.

### 9.2. Sincronización horaria (NTP)

El servidor donde corre PHP debe tener el reloj sincronizado con un servidor NTP. La generación del TRA del WSAA depende de la hora local; un desfasaje severo produce rechazos. El margen que el SDK aplica (`ARCA_WSAA_GENERATION_SKEW`, default 120 segundos) no sustituye al NTP, solo tolera drift menor.

En XAMPP sobre Windows: **Configuración → Hora e idioma → Fecha y hora → Sincronizar ahora**.

### 9.3. Respaldo de la base de datos

La base de datos del SDK contiene la cache del TA y la tabla de idempotencia con todas las emisiones. Si se pierde, las filas de idempotencia no se pueden reconstruir: las emisiones que estaban `en_curso` al momento de la pérdida quedarían sin trazabilidad. Incluí la base del SDK en la política de respaldos con la misma frecuencia que el resto de las bases operativas.

### 9.4. Tarea programada de reconciliación

`reconciliar()` debe ejecutarse periódicamente (cada 5 o 10 minutos) para limpiar emisiones abandonadas (filas que quedaron en `en_curso` porque el proceso murió). En Linux, un `cron`; en Windows con XAMPP, el Programador de Tareas invocando:

```bash
php -r "require 'C:/xampp/htdocs/ARCASdk/vendor/autoload.php'; \
  \$arca = Rbbsoft\ArcaSdk\ArcaSdk::getInstance(\$config); \
  echo \$arca->reconciliar() . ' filas reconciliadas' . PHP_EOL;"
```

### 9.5. Consideraciones de seguridad

- **El archivo `.env`** contiene la contraseña del usuario de DB. Mantenerlo fuera del docroot, con permisos restrictivos, y fuera del control de versiones.
- **Los archivos del certificado** son credenciales de producción: con ellos, un tercero puede emitir comprobantes a nombre del CUIT. Aplicar el mismo tratamiento que a las claves SSH.
- **El usuario MySQL** del SDK debe tener permisos de `SELECT`, `INSERT`, `UPDATE` y `DELETE` sobre las dos tablas del SDK. No debe tener permisos administrativos (`DROP`, `GRANT`, `SHUTDOWN`).
- **Todas las comunicaciones con ARCA** se hacen sobre HTTPS con TLS 1.2 o superior. Si la aplicación corre detrás de un proxy, configurá el proxy a nivel del sistema o de PHP.

---

## 10. Diagnóstico avanzado

### 10.1. Tabla completa de excepciones

| Excepción | Cuándo se dispara | Acción |
|-----------|-------------------|--------|
| `ConfigException` | `Config` inválido (CUIT mal formado, archivos no legibles, ambiente incorrecto) | Corregir antes de invocar al SDK. |
| `ValidationException` | `external_id` o `$data` inválidos | Corregir los datos. No reintentar con los mismos. |
| `WsaaException` | Fallo de infra WSAA (red, timeout, SoapFault) | El SDK ya reintentó. Si llega al usuario, esperar y reintentar. |
| `WsaaCeeYaPoseeTaException` | El CEE ya tiene un TA vigente | Esperar 10 min (homo) o 2 min (prod). |
| `WsfeException` | Fallo de infra WSFE | Reintento automático del SDK. |
| `WsfeProtocolException` | Respuesta WSFE malformada | Reintento automático del SDK. |
| `WsfeArcaTransientException` | ARCA devolvió código 9999 | Reintento automático del SDK. |
| `CbteRechazadoException` | ARCA rechazó (`Resultado='R'`) | **No reintentar.** Ajustar `$data` y usar `external_id` nuevo. |
| `IdempotencyConflictException` | `external_id` con datos distintos | Decidir: `external_id` nuevo o reintentar con mismos datos. |
| `EmisionEnCursoException` | Otro worker con el lease vigente | Esperar al TTL o ejecutar `reconciliar()`. |
| `IdempotencyStateException` | Estado incoherente de la fila | Revisar manualmente. |
| `MaxIdempotencyAttemptsException` | 5+ rechazos de negocio | Revisar causa raíz; considerar `resetExternalId()`. |
| `ZombieRecoveryFailedException` | Fila zombie no recuperable | Revisar manualmente. |
| `CaeSecuestradoException` | ARCA devolvió comprobante con datos distintos | **No reemitir.** Revisar manualmente. |
| `PadronException` | Fallo funcional del padrón A13 | Verificar CUIT y permisos. |
| `PadronArcaTransientException` | Padrón devolvió código 9999 | Reintento automático. |
| `PadronProtocolException` | Respuesta del padrón malformada | Reintentar solo si `kind` es `empty_body`/`html_gateway`/`http_5xx`. |
| `ArcaException` | Cualquier excepción del SDK | Captura genérica. |

### 10.2. Códigos de error de ARCA más comunes

| Código | Significado | Clasificación del SDK |
|--------|-------------|-----------------------|
| 10016 | El número o fecha del comprobante no corresponde con el próximo a autorizar. | Funcional (rechazo de ARCA, no transitorio). |
| 10017 | El tipo de comprobante no es válido para el CUIT. | Funcional. |
| 10018 | El documento del receptor no es válido. | Funcional. |
| 601 | El comprobante no existe (devuelto por `FECompConsultar`). | No es un error: `consultar()` devuelve `null`. |
| 10246 | "Campo Condición Frente al IVA del receptor es obligatorio conforme a la RG 5616." | Funcional. Verificar `receptor_condicion_iva`. |
| 9999 | Evento u observación transitorio de ARCA. | Transitorio (reintentable). |

### 10.3. Operaciones complementarias

- `esIdempotente(string $externalId): bool`: devuelve `true` si el `external_id` tiene una fila persistida (en cualquier estado).
- `reconciliar(?int $limit = 100): int`: procesa hasta `$limit` filas `en_curso` cuyo TTL expiró, marcándolas como `fallido`. Devuelve la cantidad reconciliada.
- `resetExternalId(string $externalId, string $operator, string $motivo, bool $force = false, bool $forceEmitido = false): void`: operación destructiva que borra la fila de un `external_id`. Pensada para casos administrativos. Registra en el log PSR-3 antes de borrar.
- `consultar(int $cbteTipo, int $puntoVenta, int $cbteNro): ?ComprobanteConsultado`: consulta un comprobante ya emitido contra ARCA. Devuelve `null` si no existe.

### 10.4. Auditoría

El SDK registra las operaciones críticas en el logger PSR-3 que se inyecta al Singleton. Si no se inyecta uno, se utiliza `NullLogger`. Para producción se recomienda inyectar Monolog (o similar) que escriba a un archivo con rotación o a un sistema centralizado de logs.

Las entradas que produce son:

- **Emisión exitosa**: `info` con `external_id`, CAE y número de comprobante.
- **Emisión fallida por negocio**: `warning` con `external_id` y observaciones de ARCA.
- **Emisión fallida por infraestructura**: `error` con `external_id` y descripción de la falla.
- **Reset administrativo**: `info` con la fila completa, operador, motivo, y flags. Emitido **antes** de borrar.
- **Recuperación zombie**: `info` con `external_id`, resultado y CAE si lo hay.

---

## Apéndice A — Tabla de referencia rápida de tipos de comprobante

| Tipo | Código ARCA | Discrimina IVA | Requiere CUIT del receptor | Método del SDK | Condición esperada del receptor |
|------|-------------|----------------|----------------------------|----------------|--------------------------------|
| Factura A | 1 | Sí | Sí | `emitirFactura` | `RI` |
| Factura B | 6 | Sí | No (acepta DNI) | `emitirFactura` | `RI`, `CF`, `MT`, `EX`, `NC` |
| Factura C | 11 | No | No (acepta cualquier doc.) | `emitirFactura` | `CF`, `MT` |
| Factura M | 51 | No | Sí | `emitirFactura` | `MT` |
| Nota de Crédito A | 3 | Sí | Sí | `emitirNotaCredito` | `RI` |
| Nota de Crédito B | 8 | Sí | No (acepta DNI) | `emitirNotaCredito` | `RI`, `CF`, `MT`, `EX`, `NC` |
| Nota de Crédito C | 13 | No | No | `emitirNotaCredito` | `CF`, `MT` |
| Nota de Crédito M | 53 | No | Sí | `emitirNotaCredito` | `MT` |

Para las NC, el `tipo` del `cbtes_asoc` debe coincidir con la factura que anula: NC A con Factura A (tipo 1), NC B con Factura B (tipo 6), NC C con Factura C (tipo 11), NC M con Factura M (tipo 51).

---

## Apéndice B — Tabla completa de excepciones

Ver sección 10.1 (tabla con las 17 excepciones específicas más `ArcaException` como base).

---

## Apéndice C — Snippets copy-paste por tipo

Todos los snippets del presente apéndice exhiben los CUITs como placeholders `<CUIT_RECEPTOR>` y `<CUIT_EMISOR>`. Reemplazar por CUITs reales válidos (once dígitos, sin guiones) antes de invocar el SDK. La validación del formato y de la presencia se realiza en tiempo de ejecución; ante valores vacíos o mal formados, los ejemplos emiten una `RuntimeException` con el mensaje de la variable de entorno faltante.

### C.1. Factura B

```php
$externalId = generarUuidV4();
$data = [
    'concepto'                => 1,
    'receptor_documento_tipo' => 80,             // CUIT
    'receptor_documento_nro'  => '<CUIT_RECEPTOR>',
    'receptor_condicion_iva' => 'MT',
    'mon_id'                  => 'PES',
    'mon_cotiz'               => '1.00',
    'items' => [
        ['importe_gravado' => '1000.00', 'alicuota_iva' => '21'],
    ],
];
$respuesta = $arca->emitirFactura($externalId, $data);
```

### C.2. Factura A

```php
$externalId = generarUuidV4();
$data = [
    'concepto'                => 1,
    'receptor_documento_tipo' => 80,             // CUIT obligatorio para A
    'receptor_documento_nro'  => '<CUIT_RECEPTOR>',
    'receptor_condicion_iva' => 'RI',            // A solo entre RI
    'mon_id'                  => 'PES',
    'mon_cotiz'               => '1.00',
    'items' => [
        ['importe_gravado' => '500.00', 'alicuota_iva' => '21'],
        ['importe_gravado' => '200.00', 'alicuota_iva' => '10.5'],
    ],
];
$respuesta = $arca->emitirFactura($externalId, $data);
```

### C.3. Factura C

```php
$externalId = generarUuidV4();
$data = [
    'concepto'                => 1,
    'receptor_documento_tipo' => 99,             // Sin identificación
    'receptor_documento_nro' => '0',
    'receptor_condicion_iva' => 'CF',            // o 'MT'
    'mon_id'                  => 'PES',
    'mon_cotiz'               => '1.00',
    'items' => [
        ['importe_gravado' => '500.00', 'alicuota_iva' => '21'],
    ],
];
$respuesta = $arca->emitirFactura($externalId, $data);
```

### C.4. Factura M

```php
$externalId = generarUuidV4();
$data = [
    'concepto'                => 1,
    'receptor_documento_tipo' => 80,             // Monotributo con CUIT
    'receptor_documento_nro' => '<CUIT_RECEPTOR>',
    'receptor_condicion_iva' => 'MT',
    'mon_id'                  => 'PES',
    'mon_cotiz'               => '1.00',
    'items' => [
        ['importe_gravado' => '300.00', 'alicuota_iva' => '21'],
    ],
];
$respuesta = $arca->emitirFactura($externalId, $data);
```

### C.5. Nota de Crédito A

```php
$externalId = generarUuidV4();
$data = [
    'concepto'                => 1,
    'receptor_documento_tipo' => 80,
    'receptor_documento_nro' => '<CUIT_RECEPTOR>',
    'receptor_condicion_iva' => 'RI',
    'mon_id'                  => 'PES',
    'mon_cotiz'               => '1.00',
    'items' => [
        ['importe_gravado' => '200.00', 'alicuota_iva' => '21'],
    ],
    'cbtes_asoc' => [
        ['tipo' => 1, 'punto_venta' => 2, 'nro' => 1],  // Factura A que se anula
    ],
];
$respuesta = $arca->emitirNotaCredito($externalId, $data);
```

### C.6. Nota de Crédito B

```php
$externalId = generarUuidV4();
$data = [
    'concepto'                => 1,
    'receptor_documento_tipo' => 80,
    'receptor_documento_nro' => '<CUIT_RECEPTOR>',
    'receptor_condicion_iva' => 'MT',
    'mon_id'                  => 'PES',
    'mon_cotiz'               => '1.00',
    'items' => [
        ['importe_gravado' => '200.00', 'alicuota_iva' => '21'],
    ],
    'cbtes_asoc' => [
        ['tipo' => 6, 'punto_venta' => 2, 'nro' => 1],  // Factura B que se anula
    ],
];
$respuesta = $arca->emitirNotaCredito($externalId, $data);
```

### C.7. Nota de Crédito C

```php
$externalId = generarUuidV4();
$data = [
    'concepto'                => 1,
    'receptor_documento_tipo' => 99,
    'receptor_documento_nro' => '0',
    'receptor_condicion_iva' => 'CF',
    'mon_id'                  => 'PES',
    'mon_cotiz'               => '1.00',
    'items' => [
        ['importe_gravado' => '100.00', 'alicuota_iva' => '21'],
    ],
    'cbtes_asoc' => [
        ['tipo' => 11, 'punto_venta' => 2, 'nro' => 1],  // Factura C que se anula
    ],
];
$respuesta = $arca->emitirNotaCredito($externalId, $data);
```

### C.8. Nota de Crédito M

```php
$externalId = generarUuidV4();
$data = [
    'concepto'                => 1,
    'receptor_documento_tipo' => 80,
    'receptor_documento_nro' => '<CUIT_RECEPTOR>',
    'receptor_condicion_iva' => 'MT',
    'mon_id'                  => 'PES',
    'mon_cotiz'               => '1.00',
    'items' => [
        ['importe_gravado' => '50.00', 'alicuota_iva' => '21'],
    ],
    'cbtes_asoc' => [
        ['tipo' => 51, 'punto_venta' => 2, 'nro' => 1],  // Factura M que se anula
    ],
];
$respuesta = $arca->emitirNotaCredito($externalId, $data);
```

### C.9. Consultar el padrón A13

```php
try {
    $emisor = $arca->obtenerEmisor((int) $config->cuit);
    // $emisor->razonSocial, $emisor->domicilioFiscal->calle, etc.
} catch (\Rbbsoft\ArcaSdk\Exceptions\PadronArcaTransientException $e) {
    // 9999 transitorio: reintentable automáticamente.
} catch (\Rbbsoft\ArcaSdk\Exceptions\PadronProtocolException $e) {
    // Body vacío / HTML / 5xx / estructura inválida.
} catch (\Rbbsoft\ArcaSdk\Exceptions\PadronException $e) {
    // Otros errores funcionales (CUIT no encontrado, sin permisos).
}
```

---

## Apéndice D — Variables de entorno completas

| Variable | Propósito | Por defecto | Valores válidos |
|----------|-----------|-------------|-----------------|
| `ARCA_ENV` | Ambiente: homologación o producción. | (obligatorio) | `homo`, `prod` |
| `ARCA_CUIT` | CUIT emisor, once dígitos sin guiones. | (obligatorio) | Once dígitos numéricos. |
| `ARCA_CUIT_RECEPTOR` | CUIT del receptor usado por los examples. | (obligatorio en examples) | Once dígitos numéricos. |
| `ARCA_PUNTO_VENTA` | Punto de venta por defecto. | (obligatorio) | Entero entre 1 y 99998. |
| `ARCA_CERT_PATH` | Path al certificado (`.pem`). | (obligatorio) | Path absoluto a un archivo legible. |
| `ARCA_KEY_PATH` | Path a la clave privada (`.key`). | (obligatorio) | Path absoluto a un archivo legible. |
| `ARCA_DB_DSN` | DSN PDO para la base de datos. | (obligatorio) | Cadena con sintaxis PDO_MYSQL. |
| `ARCA_DB_USER` | Usuario de la base de datos. | (obligatorio) | Usuario MySQL. |
| `ARCA_DB_PASS` | Contraseña del usuario. | (obligatorio) | Cadena. |
| `ARCA_DB_PERSISTENT` | Si la conexión es persistente. | `false` | `true`, `false`. |
| `ARCA_SOAP_TIMEOUT` | Timeout SOAP en segundos. | `30` | Entero positivo. |
| `ARCA_WSAA_LOCK_TIMEOUT` | Timeout del lock de TA. | `10` | Entero positivo. |
| `ARCA_EMIT_LOCK_TIMEOUT` | Timeout del lock de emisión. | `15` | Entero positivo. |
| `ARCA_WSAA_TRA_TTL` | TTL del TRA en segundos. | `600` | Entero positivo. |
| `ARCA_WSAA_GENERATION_SKEW` | Skew hacia atrás de `generationTime`. | `120` | Entero positivo. |
| `ARCA_WSAA_EXPIRY_MARGIN` | Margen de seguridad del TA. | `300` | Entero positivo. |
| `ARCA_RETRY_MAX_ATTEMPTS` | Máximo de reintentos. | `3` | Entero positivo. |
| `ARCA_RETRY_BASE_BACKOFF_MS` | Backoff base en milisegundos. | `200` | Entero positivo. |
| `ARCA_RETRY_MAX_BACKOFF_MS` | Backoff máximo en milisegundos. | `2000` | Entero positivo. |
| `ARCA_IDEMPOTENCIA_MAX_INTENTOS` | Máximo de intentos de emisión. | `5` | Entero positivo. |
| `ARCA_IDEMPOTENCIA_TTL_SEGUNDOS` | TTL de la fila `en_curso`. | `300` | Entero positivo. |

*El logger PSR-3 se inyecta a través del constructor del Singleton (`ArcaSdk::getInstance`), no mediante variable de entorno. Las URLs de los web services son constantes de la clase `Config` y se resuelven según `ARCA_ENV`.*

---

## Apéndice E — Conceptos de facturación (manual vs electrónica)

| Aspecto | Facturación manual | Facturación electrónica |
|---------|--------------------|--------------------------|
| Soporte | Papel preimpreso, talonario. | Documento digital firmado por ARCA. |
| Numeración | Asignada por ARCA, preimpresa. | Asignada por ARCA, solicitada en cada emisión. |
| Autorización | Previa, al imprimir el talonario. | Posterior, en cada emisión. |
| Validez fiscal | Acreditada por el timbre del talonario. | Acreditada por el CAE. |
| Plazo para emitir | Antes de la operación (talonario preautorizado). | En el momento de la operación (autorización en tiempo real). |
| Almacenamiento | Archivo físico del comprobante firmado. | Almacenamiento digital. |
| Rectificación | Nota de crédito en talonario separado. | Nota de crédito electrónica, referenciada por `cbtes_asoc`. |
| Anulación | Tachar el comprobante físico. | Nota de crédito electrónica. |
| Reimpresión | Desde el talonario original. | Desde el PDF generado o desde la base de datos del usuario. |
| Verificación de validez | Inspección visual del timbre. | Consulta contra ARCA con `FECompConsultar`. |

---

## Apéndice F — RG 5616 (detalle legal/histórico)

La **Resolución General ARCA 5616/2024** estableció un nuevo estándar obligatorio para la identificación de la **Condición frente al IVA del receptor** en los comprobantes electrónicos. Esta sección se ofrece como contexto histórico/legal; los detalles prácticos están en la sección 2.5.

**Aspectos clave para el integrador:**

- **Qué cambia.** ARCA pasó a exigir un campo específico en `FECAESolicitar` con el ID de la condición del receptor según un catálogo estandarizado. Si el comprobante se emite sin este campo (o con un valor no válido), ARCA rechaza la emisión con el código de error 10246 ("Campo Condición Frente al IVA del receptor es obligatorio…").
- **Vigencia.** El campo pasó a ser obligatorio en **homologación desde el 2025-04-06** y en **producción desde el 2025-09-01**. A la fecha de este manual, ya es exigible en ambos ambientes.
- **Cómo se mapea en el SDK.** El integrador sigue usando los códigos abreviados de la sección 2.5 (`RI`, `CF`, `MT`, `EX`, `NC`); el SDK los traduce automáticamente a los IDs internos de ARCA y los envía en el campo que el estándar exige. La tabla de equivalencias se mantiene en el código fuente: `src/Wsfe/WsfeClient.php::IVA_RECEPTOR_ID`.

| Código del SDK | Significado | ID interno de ARCA (post-RG 5616) |
|----------------|-------------|-----------------------------------|
| `RI` | IVA Responsable Inscripto | 1 |
| `CF` | Consumidor Final | 5 |
| `MT` | Monotributo | 6 |
| `EX` | IVA Sujeto Exento | 4 |
| `NC` | Sujeto No Categorizado | 7 |

> **Nota.** Los IDs internos de ARCA **cambiaron** respecto del esquema anterior a la RG 5616. La constante `IVA_RECEPTOR_ID` del SDK está actualizada al catálogo vigente. Si se observa el error 10246 en producción tras una migración de SDK, verificar que la versión desplegada del SDK corresponda a la documentada en este manual.

Para la lista completa de IDs y la mecánica del campo, consultar el método `FEParamGetCondicionIvaReceptor` del WSFEv1 y el manual oficial `WSFEv1_Manual_Desarrollador_v4.5_ARCA.pdf` (apéndice H).

---

## Apéndice G — Glosario

**Alícuota.** Porcentaje de IVA que se aplica a un ítem gravado. Las alícuotas habituales son 21%, 10,5% y 27%.

**ARCA.** Agencia de Recaudación y Control Aduanero. Organismo recaudador nacional argentino. Hasta 2024 se denominó AFIP; en julio de 2024 pasó a denominarse ARCA. Los web services conservan las URLs de AFIP (`afip.gob.ar`).

**CAE.** Código de Autorización Electrónico. Cadena de catorce dígitos que ARCA asigna a cada comprobante electrónico aprobado.

**CMS.** Cryptographic Message Syntax. Estándar criptográfico que ARCA exige para enviar la solicitud de TA al WSAA. El SDK lo implementa internamente.

**Comprobante.** Unidad de facturación electrónica. En este SDK, está representado por la clase `Comprobante` y se construye a partir del array `$data` que pasás a `emitirFactura` o `emitirNotaCredito`.

**Condición frente al IVA.** Categoría fiscal del receptor. Valores: `RI`, `CF`, `MT`, `EX`, `NC`.

**CUIT.** Clave Única de Identificación Tributaria. Identificación tributaria en Argentina. Once dígitos, sin guiones en la configuración del SDK.

**DTO.** Data Transfer Object. Objeto inmutable que transporta datos entre capas. En este SDK: `FilaEmision`, `ComprobanteResponse`, `ComprobanteConsultado`, `ResultadoIva`, `Emisor`.

**Emisión.** Acto de solicitar a ARCA la autorización de un comprobante y obtener el CAE.

**External_id.** Identificador único que tu aplicación asigna a cada intento de emisión lógica. Es un UUID v4.

**Factura.** Comprobante que documenta una venta. Tipos: A, B, C, M.

**Homologación.** Ambiente de testing de ARCA. No genera comprobantes con validez fiscal.

**Idempotencia.** Propiedad por la cual una operación ejecutada dos veces con los mismos datos produce el mismo resultado. En este SDK se materializa con el `external_id`.

**Lock.** Mecanismo de sincronización que garantiza que solo un proceso a la vez ejecute una operación. El SDK usa un *named lock* de MySQL.

**Nota de Crédito (NC).** Comprobante que anula o rectifica una factura previamente emitida.

**Passthrough header.** Header HTTP que ARCA exige para identificar el servicio y el entorno.

**PFX / P12.** Formato binario que contiene un certificado y su clave privada. Ver sección 7.

**Punto de venta.** Número entre 1 y 99998, asignado por ARCA.

**Reintento (retry).** Política que vuelve a intentar automáticamente una operación cuando falló por un motivo transitorio.

**Reconciliación.** Operación administrativa que limpia emisiones abandonadas (filas que quedaron a medio camino porque el proceso murió). Se invoca con `reconciliar()`.

**Respuesta cacheada.** Cuando se llama a `emitirFactura` con un `external_id` ya emitido, el SDK devuelve la respuesta persistida sin volver a llamar a ARCA.

**Singleton.** Patrón de diseño que garantiza una sola instancia de una clase. En este SDK, `ArcaSdk` es un Singleton por proceso PHP.

**SOAP.** Protocolo de comunicación basado en XML que ARCA utiliza para sus web services.

**SOAPFault.** Mensaje de error estándar de SOAP. El SDK lo traduce a excepciones tipadas.

**Ticket de Acceso (TA).** Credencial temporal que el WSAA entrega una vez autenticado. Vigencia de doce horas.

**TLS.** Protocolo de seguridad en la capa de transporte. ARCA exige TLS 1.2 o superior.

**UUID v4.** Identificador universalmente único, versión 4 (generado aleatoriamente).

**WSDL.** Web Services Description Language. Archivo XML que describe el contrato de un web service SOAP.

**WSAA.** Web Service de Autenticación y Autorización. Entrega los Tickets de Acceso.

**WSFE v1.** Web Service de Factura Electrónica v1. Recibe las solicitudes de emisión de comprobantes.

---

## Apéndice H — Fuentes oficiales y SHA-256

| Archivo | Tamaño (bytes) | SHA-256 | Origen |
|---------|----------------|---------|--------|
| `WSASS_como_adherirse.pdf` | 822908 | `4ee99014bf7698fa49a326603653bc7c5dadd4b78efd161114a32475780f3713` | `https://www.afip.gob.ar/ws/WSASS/WSASS_como_adherirse.pdf` |
| `WSASS_manual.pdf` | 1343734 | `3e9c4c83f7b8e87a193d6275e25f36fd6aecff3e7ceee72a537418e35ea6de0b` | `https://www.afip.gob.ar/ws/WSASS/WSASS_manual.pdf` |
| `WSAA_obtener_certificado_produccion.pdf` | 720034 | `a4c026e727830ed87b722e258a3e5f48450246845ca5351ed3cc3d0d45d80f39` | `https://www.afip.gob.ar/ws/WSAA/wsaa_obtener_certificado_produccion.pdf` |
| `WSAA_asociar_certificado_a_wsn_produccion.pdf` | 664044 | `df7e4b59f8b34530668323ea5709603f2bb0cc8d96acdd4be83995147d2c20fc` | `https://www.afip.gob.ar/ws/WSAA/wsaa_asociar_certificado_a_wsn_produccion.pdf` |
| `WSAA_Especificacion_Tecnica_1.2.2.pdf` | 620313 | `ad7a68bc8b3dc3a8899e03457fb3d158274f0320676ac57fbda61479ab8447d6` | `https://www.afip.gob.ar/ws/WSAA/Especificacion_Tecnica_WSAA_1.2.2.pdf` |
| `WSAA_manual_desarrollador.pdf` | 1656151 | `b52436973e46adcf848a14fe2a0595e8566ccf9e3be616a8985d43c7f65dfbff` | `https://www.afip.gob.ar/ws/WSAA/WSAAmanualDev.pdf` |
| `WSAA_Generacion_certificados_digitales.pdf` | 740868 | `bb83de47be03a8d7303e1787232b387d02e7ae9621f2702fbee24bafbcfd7a46` | `https://www.afip.gob.ar/ws/WSAA/WSAA.ObtenerCertificado.pdf` |
| `ADMINREL_Delegar_Webservices.pdf` | 1408878 | `35bf2743c5aa3dae7429b9ae9b6c630ecd22610a1da295c1f70666b84a22f394` | `https://www.afip.gob.ar/ws/WSAA/ADMINREL.DelegarWS.pdf` |
| `WSFEv1_Manual_Desarrollador_v4.5_ARCA.pdf` | 4008639 | `62b1e08e7d9588148eec4c077dd8b5a53b69dcd181a3a97b4519d346c080790e` | `https://www.afip.gob.ar/ws/documentacion/manuales/manual-desarrollador-ARCA-COMPG.pdf` |

**Disclaimer.** Este manual se basa en la documentación oficial de ARCA al 31 de julio de 2026. Ante cualquier discrepancia, prevalece la documentación oficial vigente al momento de la operación. La URL principal de consulta es `https://www.afip.gob.ar/ws/`.

**Licencia del manual.** El contenido de este manual se publica bajo la misma licencia que el SDK: MIT.
