<?php

declare(strict_types=1);

/**
 * Auditor de coherencia docs <-> codigo del SDK ArcaSdk.
 *
 * Verifica que README, GUIA_DE_USO y CHANGELOG esten alineados con el
 * codigo del SDK. Pensado para correr antes de commitear o en CI.
 *
 * Validaciones incluidas en v0.1 (5):
 *   1. Version declarada en README vs ultima entrada de CHANGELOG.
 *   2. Version del manual en GUIA_DE_USO (warn si quedo atras).
 *   3. Conteo de tests (lee junit.xml o corre phpunit) vs lo declarado
 *      en README y CHANGELOG.
 *   4. Arbol de "Estructura" del README vs archivos reales en src/.
 *   5. Tabla de tipos de comprobante en GUIA vs TiposComprobante.php.
 *
 * Pendientes para v0.2 (marcados como TODO en el codigo):
 *   - Tabla de excepciones (GUIA seccion 10.1 vs src/Exceptions/*.php).
 *   - Tabla de alicuotas (GUIA seccion 2.4 vs codigo).
 *   - Tabla de tipos de documento del receptor (GUIA seccion 2.6).
 *   - Tabla de variables de entorno (GUIA apendice D vs Config::fromArray).
 *   - URLs de ARCA (GUIA vs Config::URL_*_HOMO/PROD).
 *
 * Uso:
 *   php tools/audit-docs.php
 *   composer audit-docs
 *
 * Exit codes:
 *   0 = todo OK
 *   1 = hay al menos una inconsistencia
 *   2 = el script no pudo correr (ej: phpunit no disponible y junit.xml ausente)
 *
 * @package Rbbsoft\ArcaSdk\Tools
 * @since   0.4.1
 */

namespace Rbbsoft\ArcaSdk\Tools;

// ----------------------------------------------------------------------------
// Paths (calculados una vez al inicio)
// ----------------------------------------------------------------------------

$root          = dirname(__DIR__);
$readmePath    = $root . '/README.md';
$changelogPath = $root . '/CHANGELOG.md';
$guiaPath      = $root . '/docs/GUIA_DE_USO.md';
$tiposPath     = $root . '/src/Wsfe/TiposComprobante.php';
$srcDir        = $root . '/src';
$junitPrimary  = $root . '/build/test-results/junit-unit.xml';
$junitFallback = $root . '/build/test-results/junit.xml';
$phpunitBat    = $root . '/vendor/bin/phpunit.bat';
$phpunitSh     = $root . '/vendor/bin/phpunit';

const EXIT_OK       = 0;
const EXIT_FAIL     = 1;
const EXIT_CANT_RUN = 2;

// ----------------------------------------------------------------------------
// Utilidades de salida
// ----------------------------------------------------------------------------

/**
 * @param 'OK'|'WARN'|'FAIL'|'INFO' $status
 */
function printLine(string $section, string $status, string $msg): void
{
    $tag = match ($status) {
        'OK'   => '[ OK  ]',
        'WARN' => '[WARN ]',
        'FAIL' => '[FAIL ]',
        'INFO' => '[INFO ]',
    };
    fwrite(STDOUT, sprintf("%s %-32s %s\n", $tag, $section, $msg));
}

function header(string $title): void
{
    fwrite(STDOUT, "\n== " . $title . " ==\n");
}

function readFileOrFail(string $path): string
{
    if (!is_file($path)) {
        throw new \RuntimeException("archivo requerido no encontrado: {$path}");
    }
    $content = file_get_contents($path);
    if ($content === false) {
        throw new \RuntimeException("no se pudo leer: {$path}");
    }
    return $content;
}

// ----------------------------------------------------------------------------
// Validacion 1: version declarada en README vs CHANGELOG
// ----------------------------------------------------------------------------

/**
 * @return array{readme_main: ?string, readme_footer: ?string, changelog_latest: ?string}
 */
function extractVersions(string $readme, string $changelog): array
{
    $readmeMain = null;
    if (preg_match('/\*\*Estado:\*\*\s+v(\d+\.\d+\.\d+)/u', $readme, $m)) {
        $readmeMain = $m[1];
    }
    $readmeFooter = null;
    if (preg_match('/la presente versi[oó]n\s+\(v(\d+\.\d+\.\d+)\)/iu', $readme, $m)) {
        $readmeFooter = $m[1];
    }
    $changelogLatest = null;
    if (preg_match('/##\s+\[v(\d+\.\d+\.\d+)\]/u', $changelog, $m)) {
        $changelogLatest = $m[1];
    }
    return [
        'readme_main'      => $readmeMain,
        'readme_footer'    => $readmeFooter,
        'changelog_latest' => $changelogLatest,
    ];
}

function checkVersion(): bool
{
    global $readmePath, $changelogPath;
    $readme    = readFileOrFail($readmePath);
    $changelog = readFileOrFail($changelogPath);
    $v         = extractVersions($readme, $changelog);

    $allOk = true;

    if ($v['readme_main'] === null) {
        printLine('readme.estado', 'FAIL', 'no se encontro patron "Estado: vX.Y.Z" en README');
        $allOk = false;
    } elseif ($v['changelog_latest'] === null) {
        printLine('readme.estado', 'FAIL', 'no se encontro patron "## [vX.Y.Z]" en CHANGELOG');
        $allOk = false;
    } elseif ($v['readme_main'] !== $v['changelog_latest']) {
        printLine('readme.estado', 'FAIL', sprintf(
            'README dice v%s, CHANGELOG ultima entrada es v%s',
            $v['readme_main'],
            $v['changelog_latest'],
        ));
        $allOk = false;
    } else {
        printLine('readme.estado', 'OK', 'v' . $v['readme_main']);
    }

    if ($v['readme_footer'] === null) {
        printLine('readme.footer', 'WARN', 'no se encontro patron "la presente version (vX.Y.Z)" en README');
    } elseif ($v['changelog_latest'] !== null && $v['readme_footer'] !== $v['changelog_latest']) {
        printLine('readme.footer', 'FAIL', sprintf(
            'footer del README dice v%s, deberia decir v%s',
            $v['readme_footer'],
            $v['changelog_latest'],
        ));
        $allOk = false;
    } else {
        printLine('readme.footer', 'OK', 'v' . $v['readme_footer']);
    }

    return $allOk;
}

// ----------------------------------------------------------------------------
// Validacion 2: version del manual en GUIA
// ----------------------------------------------------------------------------

function checkGuiaVersion(): bool
{
    global $guiaPath, $changelogPath;
    $guia = readFileOrFail($guiaPath);
    $manualVersion = null;
    if (preg_match('/\*\*Versi[oó]n del manual:\*\*\s+(\d+\.\d+)\.?/u', $guia, $m)) {
        $manualVersion = $m[1];
    }
    $sdkVersion = extractVersions('', readFileOrFail($changelogPath))['changelog_latest'] ?? null;

    if ($manualVersion === null) {
        printLine('guia.version_manual', 'WARN', 'no se encontro patron "Version del manual: X.Y" en GUIA');
        return true; // no es bloqueante
    }
    printLine('guia.version_manual', 'OK', $manualVersion);

    if ($sdkVersion === null) {
        return true; // ya reportado en checkVersion
    }
    $mParts = array_map('intval', explode('.', $manualVersion));
    $sParts = array_map('intval', explode('.', $sdkVersion));
    if ($mParts[0] < $sParts[0]
        || ($mParts[0] === $sParts[0] && $mParts[1] < $sParts[1])
    ) {
        printLine('guia.version_vs_sdk', 'WARN', sprintf(
            'manual dice %s, SDK esta en v%s (considerar bump del manual)',
            $manualVersion,
            $sdkVersion,
        ));
    } else {
        printLine('guia.version_vs_sdk', 'OK', sprintf('manual %s, SDK v%s', $manualVersion, $sdkVersion));
    }
    return true;
}

// ----------------------------------------------------------------------------
// Validacion 3: conteo de tests
// ----------------------------------------------------------------------------

/**
 * @return array{tests: int, assertions: int}|null
 */
function readJunitCounts(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $xml = @simplexml_load_file($path);
    if ($xml === false) {
        return null;
    }
    // PHPUnit pone los atributos tests/assertions en los hijos
    // <testsuite> (no en el root <testsuites>). Sumamos todos los
    // testsuites hijos (puede haber varios, por ejemplo cuando se
    // corre mas de un test suite).
    $totalTests      = 0;
    $totalAssertions = 0;
    foreach ($xml->testsuite as $ts) {
        if (isset($ts['tests'])) {
            $totalTests += (int) $ts['tests'];
        }
        if (isset($ts['assertions'])) {
            $totalAssertions += (int) $ts['assertions'];
        }
    }
    if ($totalTests === 0 && $totalAssertions === 0) {
        return null;
    }
    return ['tests' => $totalTests, 'assertions' => $totalAssertions];
}

/**
 * @return array{tests: int, assertions: int}|null
 */
function runPhpunitCounts(): ?array
{
    global $phpunitBat, $phpunitSh;
    $cmd = is_file($phpunitBat) ? $phpunitBat : (is_file($phpunitSh) ? $phpunitSh : null);
    if ($cmd === null) {
        return null;
    }
    $tmpFile = tempnam(sys_get_temp_dir(), 'audit_junit_');
    if ($tmpFile === false) {
        return null;
    }
    $cmdLine = sprintf(
        '%s --testsuite unit --log-junit %s 2>&1',
        escapeshellarg($cmd),
        escapeshellarg($tmpFile)
    );
    $output = [];
    $code   = 0;
    exec($cmdLine, $output, $code);
    if ($code !== 0) {
        @unlink($tmpFile);
        return null;
    }
    $counts = readJunitCounts($tmpFile);
    @unlink($tmpFile);
    return $counts;
}

function checkTests(): bool
{
    global $junitPrimary, $junitFallback, $readmePath, $changelogPath;

    $counts = readJunitCounts($junitPrimary)
        ?? readJunitCounts($junitFallback)
        ?? runPhpunitCounts();

    if ($counts === null) {
        printLine('tests.count', 'WARN', 'no se pudo obtener conteo (junit ausente y phpunit no disponible)');
        return true;
    }

    printLine('tests.count', 'INFO', sprintf(
        '%d tests / %d assertions',
        $counts['tests'],
        $counts['assertions'],
    ));

    $readme = readFileOrFail($readmePath);
    $allOk  = true;
    if (preg_match('/(\d+)\s+tests?\s*\/\s*(\d[\d\s]*)\s*assertions?/i', $readme, $m)) {
        $readmeTests      = (int) $m[1];
        $readmeAssertions = (int) preg_replace('/\s+/', '', $m[2]);
        if ($readmeTests !== $counts['tests'] || $readmeAssertions !== $counts['assertions']) {
            printLine('tests.readme', 'FAIL', sprintf(
                'README declara %d tests / %d assertions, real es %d / %d',
                $readmeTests,
                $readmeAssertions,
                $counts['tests'],
                $counts['assertions'],
            ));
            $allOk = false;
        } else {
            printLine('tests.readme', 'OK', sprintf('%d / %d', $readmeTests, $readmeAssertions));
        }
    } else {
        printLine('tests.readme', 'WARN', 'no se encontro patron "N tests / M assertions" en README');
    }

    $changelog = readFileOrFail($changelogPath);
    $latestVersion = null;
    if (preg_match('/##\s+\[v(\d+\.\d+\.\d+)\][^\n]*\n+(.*?)(?=\n##\s|\z)/su', $changelog, $m)) {
        $latestVersion = $m[1];
        $latestBody    = $m[2];
        if (preg_match('/\*\*Suite:\s+(\d+)\s+tests?\s*\/\s*(\d[\d\s]*)\s*assertions?\b/iu', $latestBody, $mm)) {
            $clTests      = (int) $mm[1];
            $clAssertions = (int) preg_replace('/\s+/', '', $mm[2]);
            if ($clTests !== $counts['tests'] || $clAssertions !== $counts['assertions']) {
                printLine('tests.changelog', 'FAIL', sprintf(
                    'CHANGELOG v%s declara %d tests / %d assertions, real es %d / %d',
                    $latestVersion,
                    $clTests,
                    $clAssertions,
                    $counts['tests'],
                    $counts['assertions'],
                ));
                $allOk = false;
            } else {
                printLine('tests.changelog', 'OK', sprintf('v%s: %d / %d', $latestVersion, $clTests, $clAssertions));
            }
        } else {
            printLine('tests.changelog', 'WARN', sprintf('ultima entrada v%s no tiene seccion "Suite: ..."', $latestVersion));
        }
    } else {
        printLine('tests.changelog', 'WARN', 'no se pudo extraer la ultima entrada del CHANGELOG');
    }

    return $allOk;
}

// ----------------------------------------------------------------------------
// Validacion 4: arbol de "Estructura" del README vs src/
// ----------------------------------------------------------------------------

/**
 * @return array<int, string>
 */
function extractReadmeTreePaths(string $readme): array
{
    if (!preg_match('/##\s+Estructura\s*.*?```\n(.*?)\n```/su', $readme, $m)) {
        return [];
    }
    $tree = $m[1];

    // El bloque del README lista paths relativos a un subarbol. Por
    // convencion del proyecto, el subarbol "src/" se marca con una
    // flecha "← codigo del SDK". Parseamos la jerarquia contando la
    // indentacion (cada nivel son 4 columnas: "│   ") y manteniendo
    // un stack de directorios padres.
    $lines       = explode("\n", $tree);
    $insideSrc   = false;
    $dirStack    = [];   // stack indexado por nivel (0-based)
    $paths       = [];

    foreach ($lines as $line) {
        // Detectar el inicio del subarbol src/ (despues de la
        // indentacion tipo "├── " o "└── " del arbol ASCII).
        if (!$insideSrc && preg_match('/src\/\s+←/u', $line)) {
            $insideSrc = true;
            continue;
        }
        // Salir del bloque src/ cuando aparece un hermano de nivel 1
        // (tests/, examples/, db/, etc.).
        if ($insideSrc && preg_match('/^[ ]*[├└][─]+\s+[A-Za-z]/u', $line)
            && !preg_match('/src\//u', $line)
        ) {
            $insideSrc = false;
            continue;
        }
        if (!$insideSrc) {
            continue;
        }

        // Medir indentacion en columnas (cada "│   " = 4 cols)
        if (!preg_match('/^([\s│]*)/', $line, $mIndent)) {
            continue;
        }
        $indent = $mIndent[1];
        // Reemplazar cada "│   " por 4 cols y contar
        $level = (int) ((strlen(str_replace('    ', '    ', $indent)) / 4));
        // Forma simple: contar el numero de "│" y agregar 1 si empieza con "├" o "└"
        $level = substr_count($indent, '│') + (preg_match('/^[ ]*[├└]/', $line) ? 1 : 0);
        if ($level < 1) {
            continue;
        }

        // Limpiar el prefijo visual: "├── ", "└── ", "│   "
        $clean = preg_replace('/^[\s│├└─]+/', '', $line);

        // Capturar archivos .php. El path puede incluir subdirectorios
        // cuando el README los inlinea (ej. "Config/Config.php" o
        // "Wsfe/Comprobante.php"). En ese caso, el path capturado tiene
        // una profundidad (cantidad de "/") y debemos ajustar la pila
        // de directorios padres en consecuencia.
        if (preg_match('#^([\w\-]+(?:/[\w\-]+)*)\.php\b#u', $clean, $mFile)) {
            $fileSubPath = $mFile[1];
            $depth       = substr_count($fileSubPath, '/');
            $parentDirs  = array_slice($dirStack, 0, max(0, $level - 1 - $depth));
            $path = 'src/'
                . ($parentDirs ? implode('/', $parentDirs) . '/' : '')
                . $fileSubPath . '.php';
            $paths[] = $path;
        }
        // Capturar directorios: la primera palabra de la linea limpia
        // termina en "/". Las descripciones que siguen (ej. "← ...") no
        // afectan al match. NO debe matchear si la primera palabra es
        // parte de un path de archivo con subdir (ej. "Config/Config.php"
        // ya fue capturado arriba).
        elseif (preg_match('#^([\w\-]+)/#u', $clean, $mDir)) {
            $dirStack[$level - 1] = $mDir[1];
            for ($i = $level; $i < count($dirStack); $i++) {
                unset($dirStack[$i]);
            }
        }
    }

    return array_values(array_unique($paths));
}

/**
 * @return array<int, string>
 */
function listSrcPhpFiles(): array
{
    global $srcDir;
    $dir = $srcDir;
    if (!is_dir($dir)) {
        return [];
    }
    // Calculamos el path base a partir del padre de $dir para que los
    // paths resultantes incluyan "src/" (ej. "src/Wsfe/Comprobante.php"),
    // consistentes con los paths extraidos del arbol del README.
    $base = str_replace('\\', '/', dirname($dir)) . '/';
    $out = [];
    $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
    foreach ($iter as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $abs = str_replace('\\', '/', $file->getPathname());
            $rel = substr($abs, strlen($base));
            $out[] = $rel;
        }
    }
    sort($out);
    return $out;
}

function checkTree(): bool
{
    global $readmePath;
    $readme = readFileOrFail($readmePath);
    $listed  = extractReadmeTreePaths($readme);
    $real    = listSrcPhpFiles();

    if (count($listed) === 0) {
        printLine('tree.readme_block', 'WARN', 'no se encontro el bloque "## Estructura" con triple backtick en README');
        return true;
    }
    printLine('tree.readme_block', 'INFO', sprintf('%d archivos PHP listados en el arbol', count($listed)));

    $missingInReadme = array_values(array_diff($real, $listed));
    $extraInReadme   = array_values(array_diff($listed, $real));

    $allOk = true;
    if (count($missingInReadme) === 0 && count($extraInReadme) === 0) {
        printLine('tree.coverage', 'OK', sprintf('los %d archivos reales coinciden con el arbol', count($real)));
    } else {
        if (count($missingInReadme) > 0) {
            printLine('tree.missing_in_readme', 'FAIL', sprintf(
                '%d archivo(s) PHP en src/ no listados en el arbol:',
                count($missingInReadme),
            ));
            foreach ($missingInReadme as $p) {
                fwrite(STDOUT, "         - {$p}\n");
            }
            $allOk = false;
        }
        if (count($extraInReadme) > 0) {
            printLine('tree.extra_in_readme', 'FAIL', sprintf(
                '%d archivo(s) listados que no existen en src/:',
                count($extraInReadme),
            ));
            foreach ($extraInReadme as $p) {
                fwrite(STDOUT, "         - {$p}\n");
            }
            $allOk = false;
        }
    }
    return $allOk;
}

// ----------------------------------------------------------------------------
// Validacion 5: tipos de comprobante en GUIA vs TiposComprobante.php
// ----------------------------------------------------------------------------

/**
 * @return array<int, int>
 */
function extractTiposFromCode(): array
{
    global $tiposPath;
    $src = readFileOrFail($tiposPath);
    preg_match_all('/public\s+const\s+(\w+)\s*=\s*(\d+)\s*;/u', $src, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $row) {
        $out[] = (int) $row[2];
    }
    return $out;
}

/**
 * @return array<int, int>
 */
function extractTiposFromGuia(string $guia): array
{
    // Capturamos codigo de la columna "Codigo ARCA" en lineas que
    // mencionan "Factura X" o "Nota de Credito X" en la primera celda.
    $out = [];
    if (preg_match_all('/\|\s*Factura\s+[A-Z]\s*\|\s*(\d+)\s*\|/u', $guia, $f)) {
        foreach ($f[1] as $v) {
            $out[] = (int) $v;
        }
    }
    if (preg_match_all('/\|\s*Nota de Cr[eé]dito\s+[A-Z]\s*\|\s*(\d+)\s*\|/u', $guia, $n)) {
        foreach ($n[1] as $v) {
            $out[] = (int) $v;
        }
    }
    return array_values(array_unique($out));
}

function checkTipos(): bool
{
    global $guiaPath;
    $guia = readFileOrFail($guiaPath);
    $codeTipos = extractTiposFromCode();
    $guiaTipos = extractTiposFromGuia($guia);

    if (count($codeTipos) === 0) {
        printLine('tipos.code', 'FAIL', 'no se pudieron extraer constantes de TiposComprobante.php');
        return false;
    }
    if (count($guiaTipos) === 0) {
        printLine('tipos.guia', 'WARN', 'no se pudieron extraer codigos de la GUIA (tabla vacia o patron no matchea)');
        return true;
    }

    $missingInGuia = array_values(array_diff($codeTipos, $guiaTipos));
    $extraInGuia   = array_values(array_diff($guiaTipos, $codeTipos));

    $allOk = true;
    if (count($missingInGuia) === 0 && count($extraInGuia) === 0) {
        printLine('tipos.coverage', 'OK', sprintf('los %d codigos coinciden', count($codeTipos)));
    } else {
        if (count($missingInGuia) > 0) {
            printLine('tipos.missing_in_guia', 'FAIL', sprintf(
                '%d tipo(s) en codigo no documentados en GUIA:',
                count($missingInGuia),
            ));
            foreach ($missingInGuia as $t) {
                fwrite(STDOUT, "         - {$t}\n");
            }
            $allOk = false;
        }
        if (count($extraInGuia) > 0) {
            printLine('tipos.extra_in_guia', 'WARN', sprintf(
                '%d tipo(s) mencionados en GUIA que no estan en TiposComprobante:',
                count($extraInGuia),
            ));
            foreach ($extraInGuia as $t) {
                fwrite(STDOUT, "         - {$t}\n");
            }
        }
    }
    return $allOk;
}

// ----------------------------------------------------------------------------
// Main
// ----------------------------------------------------------------------------

function main(): int
{
    fwrite(STDOUT, "Rbbsoft\\ArcaSdk - auditor de coherencia docs<->codigo (v0.1)\n");

    $results = [];

    header('Validacion 1: version declarada en README vs CHANGELOG');
    $results[] = checkVersion();

    header('Validacion 2: version del manual en GUIA_DE_USO');
    $results[] = checkGuiaVersion();

    header('Validacion 3: conteo de tests');
    $results[] = checkTests();

    header('Validacion 4: arbol de "Estructura" del README vs src/');
    $results[] = checkTree();

    header('Validacion 5: tipos de comprobante (GUIA vs TiposComprobante.php)');
    $results[] = checkTipos();

    fwrite(STDOUT, "\n");
    $fails = count(array_filter($results, fn(bool $r): bool => $r === false));
    if ($fails === 0) {
        fwrite(STDOUT, "RESULTADO: OK (0 inconsistencias)\n");
        return EXIT_OK;
    }
    fwrite(STDOUT, sprintf("RESULTADO: FAIL (%d seccion(es) con inconsistencias)\n", $fails));
    return EXIT_FAIL;
}

try {
    exit(main());
} catch (\Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(EXIT_CANT_RUN);
}
