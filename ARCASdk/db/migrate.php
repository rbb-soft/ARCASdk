<?php
/**
 * Migrador CLI idempotente del schema del SDK.
 *
 * Uso:
 *   php db/migrate.php                          # usa .env en cwd o variables de entorno
 *   ARCA_DB_DSN=... ARCA_DB_USER=... ARCA_DB_PASS=... php db/migrate.php
 *
 * Lee el archivo sql/schema.sql, divide por sentencias (respetando strings
 * y comentarios) y aplica cada una con PDO. Es seguro ejecutarlo multiples
 * veces: todas las sentencias usan IF NOT EXISTS.
 */

declare(strict_types=1);

// Autoload del SDK.
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_readable($autoload)) {
    fwrite(STDERR, "ERROR: vendor/autoload.php no encontrado. Ejecute 'composer install'.\n");
    exit(2);
}
require $autoload;

use Rbbsoft\ArcaSdk\Config\Config;
use Rbbsoft\ArcaSdk\Exceptions\ConfigException;

// -----------------------------------------------------------------------------
// Carga de configuracion desde .env (modo simple) o variables de entorno
// -----------------------------------------------------------------------------
function loadEnv(string $path): array
{
    if (!is_readable($path)) {
        return [];
    }
    $vars = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$k, $v] = array_map('trim', explode('=', $line, 2) + [1 => '']);
        // Quitar comillas envolventes si las hay.
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
            $v = substr($v, 1, -1);
        }
        $vars[$k] = $v;
    }
    return $vars;
}

$envFile = __DIR__ . '/../.env';
$envVars = loadEnv($envFile);
$get = static fn(string $k, string $default = ''): string => (string) ($_ENV[$k] ?? $_SERVER[$k] ?? getenv($k) ?: ($envVars[$k] ?? $default));

$dsn  = $get('ARCA_DB_DSN', '');
$user = $get('ARCA_DB_USER', 'root');
$pass = $get('ARCA_DB_PASS', '');

if ($dsn === '') {
    fwrite(STDERR, "ERROR: ARCA_DB_DSN no configurado. Use .env o variable de entorno.\n");
    exit(2);
}

// -----------------------------------------------------------------------------
// Conexion PDO
// -----------------------------------------------------------------------------
try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "ERROR de conexion: " . $e->getMessage() . "\n");
    exit(1);
}

$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
echo "[migrate] driver={$driver}\n";

if ($driver !== 'mysql') {
    fwrite(STDERR, "ERROR: solo se soporta mysql/mariadb. Detectado: {$driver}\n");
    exit(1);
}

// -----------------------------------------------------------------------------
// Cargar y aplicar schema.sql
// -----------------------------------------------------------------------------
$schemaPath = __DIR__ . '/../sql/schema.sql';
if (!is_readable($schemaPath)) {
    fwrite(STDERR, "ERROR: no se encuentra sql/schema.sql\n");
    exit(2);
}

$sql = file_get_contents($schemaPath);

// Split por ';' respetando strings y comentarios de linea (-- ...).
$sentencias = [];
$buffer = '';
$inString = false;
$stringChar = '';
$len = strlen($sql);
for ($i = 0; $i < $len; $i++) {
    $c = $sql[$i];
    $next = $i + 1 < $len ? $sql[$i + 1] : '';
    if ($inString) {
        $buffer .= $c;
        if ($c === '\\' && $next !== '') {
            $buffer .= $next;
            $i++;
            continue;
        }
        if ($c === $stringChar) {
            $inString = false;
        }
        continue;
    }
    if ($c === "'" || $c === '"') {
        $inString = true;
        $stringChar = $c;
        $buffer .= $c;
        continue;
    }
    if ($c === '-' && $next === '-') {
        // comentario de linea: descartar hasta \n
        while ($i < $len && $sql[$i] !== "\n") {
            $i++;
        }
        continue;
    }
    if ($c === ';') {
        $stmt = trim($buffer);
        if ($stmt !== '') {
            $sentencias[] = $stmt;
        }
        $buffer = '';
        continue;
    }
    $buffer .= $c;
}
$resto = trim($buffer);
if ($resto !== '') {
    $sentencias[] = $resto;
}

echo "[migrate] " . count($sentencias) . " sentencias a aplicar\n";

foreach ($sentencias as $idx => $stmt) {
    $preview = preg_replace('/\s+/', ' ', $stmt);
    $preview = substr($preview, 0, 80);
    try {
        $pdo->exec($stmt);
        echo sprintf("[migrate] [%02d] OK  %s\n", $idx + 1, $preview);
    } catch (PDOException $e) {
        // Si la tabla ya existe (42S01) o la columna ya existe (42S21)
        // no es fatal en migracion idempotente: ALTER ADD COLUMN sin
        // IF NOT EXISTS re-corre limpio si la columna ya esta.
        if ($e->getCode() === '42S01' || $e->getCode() === '42S21') {
            echo sprintf("[migrate] [%02d] SKIP (ya existe)  %s\n", $idx + 1, $preview);
            continue;
        }
        fwrite(STDERR, sprintf("[migrate] [%02d] ERROR: %s\n", $idx + 1, $e->getMessage()));
        fwrite(STDERR, "SQL: " . $preview . "\n");
        exit(1);
    }
}

echo "[migrate] OK\n";
