# Mantenimiento del SDK

Este documento describe las tareas de mantenimiento del proyecto `Rbbsoft\ArcaSdk` que no son parte del manual de uso del operador (`docs/GUIA_DE_USO.md`). Está orientado al developer que mantiene el SDK.

---

## Auditor de coherencia docs ↔ código

Cada vez que se cambia algo en el SDK, algo de lo que documentan `README.md`, `docs/GUIA_DE_USO.md` y `CHANGELOG.md` se desactualiza. El script `tools/audit-docs.php` automatiza la detección de esas desactualizaciones.

### Uso

```bash
# Forma directa
php tools/audit-docs.php

# Forma via composer
composer audit-docs
```

### Exit codes

| Código | Significado |
|--------|-------------|
| `0` | Todo OK. |
| `1` | Hay al menos una inconsistencia. Ver la salida para el detalle. |
| `2` | El script no pudo correr (ej: `phpunit` no disponible y `junit.xml` ausente). |

### Validaciones incluidas (v0.1)

1. **Versión declarada en `README.md`** (línea 8 y footer línea 81) vs la última entrada de `CHANGELOG.md`.
2. **Versión del manual en `docs/GUIA_DE_USO.md`** (línea 9 del frontmatter) — flag como `WARN` si quedó atrás de la versión del SDK.
3. **Conteo de tests** en `README.md` y en la última entrada de `CHANGELOG.md` vs la suite real. Lee `build/test-results/junit-unit.xml` (preferido), `build/test-results/junit.xml` (alternativa) o corre `vendor/bin/phpunit --testsuite unit` como último recurso.
4. **Árbol de "Estructura"** del `README.md` (sección "Estructura", bloque entre triple backticks) vs los archivos `.php` reales en `src/`. Detecta archivos reales que no están listados y listados que no existen.
5. **Tabla de tipos de comprobante** en `docs/GUIA_DE_USO.md` (§1.3 y apéndice A) vs las constantes en `src/Wsfe/TiposComprobante.php`.

### Validaciones pendientes (v0.2)

Marcadas como `TODO v0.2` en el código. Cubrirán:

- Tabla de excepciones (GUIA §10.1 vs `src/Exceptions/*.php`).
- Tabla de alícuotas (GUIA §2.4 vs alícuotas aceptadas en `src/Wsfe/Comprobante.php` e `src/Wsfe/IvaCalculator.php`).
- Tabla de tipos de documento del receptor (GUIA §2.6 vs validación en `Comprobante::fromArray()`).
- Tabla de variables de entorno (GUIA apéndice D vs claves aceptadas por `Config::fromArray()`).
- URLs de ARCA (GUIA vs constantes `Config::URL_*_HOMO/PROD`).

### Cuándo correrlo

- Antes de hacer commit de cambios que tocan `src/`, `README.md`, `docs/GUIA_DE_USO.md` o `CHANGELOG.md`.
- Después de bumpear la versión (sea patch, minor o major).
- Antes de pedir code review.
- En CI (futuro, no está integrado todavía).

### Ejemplo de salida

```
Rbbsoft\ArcaSdk - auditor de coherencia docs<->codigo (v0.1)

== Validacion 1: version declarada en README vs CHANGELOG ==
[ OK  ] readme.estado                    v0.4.0
[ OK  ] readme.footer                    v0.4.0

== Validacion 2: version del manual en GUIA_DE_USO ==
[ OK  ] guia.version_manual              2.1
[ OK  ] guia.version_vs_sdk              manual 2.1, SDK v0.4.0

== Validacion 3: conteo de tests ==
[INFO ] tests.count                      559 tests / 2713 assertions
[ OK  ] tests.readme                     559 / 2713
[ OK  ] tests.changelog                  v0.4.0: 559 / 2713

== Validacion 4: arbol de "Estructura" del README vs src/ ==
[INFO ] tree.readme_block                40 archivos PHP listados en el arbol
[ OK  ] tree.coverage                    los 40 archivos reales coinciden con el arbol

== Validacion 5: tipos de comprobante (GUIA vs TiposComprobante.php) ==
[ OK  ] tipos.coverage                   los 8 codigos coinciden

RESULTADO: OK (0 inconsistencias)
```

Cuando hay inconsistencias, cada bloque `FAIL` lista los items problema (un path por línea, un código de tipo por línea, etc.).

### Cómo agregar una nueva validación

1. Crear la función de check (ej. `checkXxx(): bool`).
2. Devolver `true` si todo OK, `false` si hay cualquier inconsistencia.
3. Usar las funciones helper `header()`, `printLine($section, $status, $msg)` con `$status` ∈ {`OK`, `WARN`, `FAIL`, `INFO`}.
4. Agregar la llamada a la nueva función en `main()`.
5. Actualizar la lista de validaciones de este documento.

### Limitaciones conocidas

- La validación 4 parsea el árbol ASCII del README heurísticamente. Si el formato del árbol cambia drásticamente (cambio de indentación, distinto carácter de caja), hay que ajustar el parser.
- La validación 5 matchea regex simples contra las tablas markdown. Tablas con formato muy exótico pueden no matchear.
- El script no es autocorrector: solo reporta. Las correcciones quedan a criterio del developer.
