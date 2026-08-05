# Changelog

Todos los cambios notables de este proyecto se documentan aquí.
Formato basado en [Keep a Changelog](https://keepachangelog.com/es/1.0.0/).

---

## [v0.6.1] — 2026-08-05

**Documentación de instalación vía Composer.** Se documenta el flujo de instalación del SDK como dependencia de un proyecto PHP, tras su publicación en Packagist.

### Cambios

- **`README.md`**: se agrega la sección `## Instalación` entre `## Quickstart` y `## Validación en entorno de homologación (ARCA)`, con los snippets de `composer require rbbsoft/arca-sdk` (integración a proyecto existente) y `composer create-project rbbsoft/arca-sdk test-install` (instalación standalone con smoke test).
- **`docs/GUIA_DE_USO.md`**: se reescribe la sección `6.1. Instalación` con dos variantes (proyecto existente vs standalone) y una nota explicativa sobre los paths en `vendor/rbbsoft/arca-sdk/` (proyecto) vs raíz (standalone).

---

## [v0.6.0] — 2026-08-05

**Reestructuración del repositorio para publicación en Packagist.** El contenido del SDK abandona el subdirectorio `ARCASdk/` y pasa a la raíz del repositorio, conforme a la convención de Composer/Packagist, que requiere `composer.json` en la raíz del paquete.

### Cambios

- **Layout del repositorio.** El contenido completo de `ARCASdk/*` se mueve a la raíz del repositorio: `composer.json`, `src/`, `tests/`, `docs/`, `examples/`, `tools/`, `db/`, `sql/`, `phpunit.xml`, `phpdoc.xml`, `LICENSE`, `LICENCIA.md`, `README.md`, `CHANGELOG.md`. Los archivos de `docs/ARCA/` (manuales PDF oficiales) y `examples/` se conservan con su contenido original.
- **`.gitignore`.** Se adopta el `.gitignore` del SDK como canónico del repositorio. Se conserva la regla del directorio `Certificados/` del wrapper anterior. Se agregan reglas para `COMMIT_MSG.txt` y `COMMIT_MSG_PUBLIC.txt` (mensajes de commit temporales).
- **`README.md` (raíz).** Se elimina el prefijo `ARCASdk/` del árbol de la sección `## Estructura`. El campo `Estado` se actualiza a `v0.6.0`.
- **`docs/GUIA_DE_USO.md`.** Se actualizan los paths a `vendor/autoload.php` en los snippets de instalación (sección 0.3) y en el ejemplo de la tarea programada de reconciliación (sección 9.4). Se elimina la línea `cd ARCASdk` del quickstart de la sección 6.1.

### Compatibilidad

- Sin cambios en la API pública. Los namespaces PHP, los nombres de clases, los archivos del código fuente y los puntos de entrada del SDK no se modifican.
- Las aplicaciones que instalan el SDK vía Composer no se ven afectadas: el paquete se publica como `rbbsoft/arca-sdk` y la API expuesta es la misma.

### Notas de migración

- Quien haya clonado el repositorio del SDK como submódulo o como subdirectorio de su aplicación debe ajustar el `require` correspondiente: el autoloader se resuelve en `vendor/autoload.php` (relativo a la raíz del paquete), no en `ARCASdk/vendor/autoload.php`.
- Quien mantenga un `composer.json` propio con un `repositories` de tipo `path` apuntando a `ARCASdk/` debe actualizar la ruta a la raíz del repositorio.

### Publicación en Packagist

- El paquete queda disponible como `rbbsoft/arca-sdk` (requiere acción manual del mantenedor para el submit inicial y la configuración del webhook de auto-update desde GitHub).
- Las versiones previas (`v0.5.0`, `v0.5.1`) publicadas en el repositorio de GitHub no se modifican; permanecen accesibles como tags históricos.

---

## [v0.5.1] — 2026-08-05

**Limpieza documental.** Se eliminan de la documentación pública las menciones a versiones anteriores del SDK. Se conservan únicamente las referencias a la versión actual (`v0.5.0`) y, cuando son relevantes para el lector de hoy, las referencias a normativa de ARCA (RG 5616) o a la forma histórica del DTO.

### Cambios

- **`README.md` (raíz)**: corrección del campo `Estado`, que figuraba como `v0.4.1`; pasa a `v0.5.0`.
- **`ARCASdk/README.md`**: eliminación de las seis anotaciones `(nuevo v0.3.0)` del árbol de la sección `## Estructura`. En un release público inicial las marcas de novedad histórica no aportan información.
- **`docs/GUIA_DE_USO.md`**: reescritura de nueve referencias a versiones anteriores (compat con la forma snake_case `v0.2.x`, `(no se usa desde v0.3.x)`, `fix de v0.2.3`, etc.) para que la documentación no quede anclada a números de versión previos. La compat con la forma array histórica se describe sin referenciar una versión puntual.
- **`docs/MAINTENANCE.md`**: actualización del ejemplo de salida de `audit-docs.php` al estado actual del release (`v0.5.0`, manual 3.0, 57 archivos), ajuste de los números de línea referenciados del `README.md` y eliminación del sub-versionado del audit script (`v0.1`/`v0.2`).
- **`tools/audit-docs.php`**: actualización del docblock del file header y de la cadena de banner al estado actual; bump del `@since` a `0.5.0`.

### Auditoría

`tools/audit-docs.php` corre en verde para las validaciones 1 (versión declarada en `README.md` vs `CHANGELOG.md`) y 2 (versión del manual vs versión del SDK). Las validaciones 3, 4 y 5 mantienen warnings/fails preexistentes no relacionados con este parche (los warning/FAIL de la validación 5 se deben a la falta de documentación de los tipos 2/7/12 en el `GUIA_DE_USO.md`; los de las validaciones 3 y 4 se explican en issues separados).

---

## [v0.5.0] — 2026-08-05

**Initial public release.** Esta es la primera versión del SDK publicada abiertamente. El proyecto se libera con un único commit en el repositorio público; la historia de desarrollo previa queda consolidada en este release.

### Características

- **Facturación electrónica contra ARCA (ex AFIP)**: implementación PHP PSR-4 standalone del web service `wsfev1` (Facturas A/B/C/M y Notas de Crédito A/B/C/M) y de `ws_sr_padron_a13` (consulta al padrón).
- **Autenticación WSAA**: solicitud y firma PKCS#7 detached del TRA, con caché del Ticket de Acceso (TA) por doce horas, persistencia opcional en MySQL o en memoria.
- **Idempotencia atómica**: cada emisión se identifica por un `external_id` UUID v4. Reintentos con el mismo `external_id` retornan la emisión original sin re-solicitar CAE.
- **Recuperación de zombies**: cuando una emisión queda en estado ambiguo (timeout de red, CEE de ARCA bloqueado, etc.), el SDK reconcilia contra ARCA con `FECompConsultar` antes de reintentar, evitando CAE duplicados.
- **Generación de PDF con QR oficial de ARCA**: el SDK provee `Rbbsoft\ArcaSdk\Pdf\ComprobantePdfGenerator` que arma un PDF A4 con el QR de validación de ARCA a partir de un `ComprobanteEmitido`.
- **Política de retry**: clasificación de fallas en transitorias (reintentar) y funcionales (no reintentar), con backoff exponencial.
- **Logger PSR-3**: integración con cualquier logger compatible (Monolog, etc.).
- **Inyección de dependencias**: el Singleton `ArcaSdk::getInstance` acepta un `Container` opcional para customizar los componentes (PDO, Wsaa, SoapClient, generador de PDF).

### Cambios destacados de este release

- **Sin CUITs reales en el repositorio.** El SDK no contiene ni el CUIT emisor ni el CUIT receptor hardcodeados. Los `examples/` y los `build/debug_*.php` leen los CUITs de variables de entorno (`ARCA_CUIT`, `ARCA_CUIT_RECEPTOR`) y validan el formato en tiempo de ejecución. El operador debe configurar el archivo `.env` con sus CUITs reales antes de invocar los examples. La recomendación oficial de ARCA para el ambiente de homologación es usar el CUIT propio (no se publican CUITs dummy genéricos); la documentación del SDK refleja esa política.
- **Eliminada la clase `Rbbsoft\ArcaSdk\Padron\Cuits`.** La constante pública `CUIT_RECEPTOR_HOMOLOGADO` se removió. Los tests unitarios que la usaban ahora declaran una constante local con un CUIT ficticio de checksum válido.

### Estado del release

- **Versión SDK:** v0.5.0.
- **Versión manual de uso (`docs/GUIA_DE_USO.md`):** 3.0.
- **Suite automatizada:** 559 tests, 2 713 assertions, ejecutada con PHPUnit 10. La suite cubre armado de payloads, parseo de respuestas, clasificación de errores, política de retry, idempotencia atómica y recuperación de emisiones zombie. No requiere conectividad con ARCA: se ejecuta offline contra dobles de prueba (`SoapClientDouble`).
- **Emisión real con CAE validado contra ARCA homologación** para los ocho tipos de comprobante soportados (Facturas A/B/C/M y Notas de Crédito A/B/C/M), con punto de venta 2. Los CUITs utilizados son los del entorno del operador, configurados vía `.env`; no se versionan.
- **Alcance:** single-tenant, un CUIT por proceso PHP-FPM. Multi-tenant está explícitamente fuera de alcance.
- **Estado de producción:** el SDK **no se ha emitido contra el ambiente productivo de ARCA**. Antes de cualquier pase a producción se requiere una corrida de homologación con el CUIT y el punto de venta definitivos, junto con la verificación manual de los CAE resultantes.

### Requisitos

- PHP 8.1+ con extensiones `soap`, `openssl`, `simplexml`, `libxml`, `pdo_mysql` y `bcmath` habilitadas.
- MySQL 8.x o MariaDB 10.4+ con una base de datos accesible.
- Composer 2.x.
- Un certificado X.509 emitido por ARCA (homologación o producción) y su clave privada correspondiente.
- El CUIT emisor asociado al certificado.

### Licencia

MIT. Texto legal completo en `LICENSE` (versión original en inglés) y `LICENCIA.md` (traducción de referencia al castellano, no oficial).
