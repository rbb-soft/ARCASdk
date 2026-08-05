<?php
declare(strict_types=1);
try {
    $client = new SoapClient('https://wsaahomo.afip.gov.ar/ws/services/LoginCms?WSDL', [
        'connection_timeout' => 10,
        'soap_version'       => SOAP_1_1,
    ]);
    echo "WSDL OK\n";
    echo "Funciones: " . implode(', ', array_keys($client->__getFunctions())) . "\n";
    echo "Tipos declarados: " . count($client->__getTypes()) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
