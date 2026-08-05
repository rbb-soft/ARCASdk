<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Exceptions;

/**
 * Error especifico: ARCA respondio que "El CEE ya posee un TA valido" para
 * el (cuit, wsn) solicitado.
 *
 * Politica: el SDK NO repite loginCms a ciegas. Hace un reintento acotado
 * de lectura/polling del cache (porque otro worker puede estar por publicar
 * el TA). Si despues del polling sigue ausente o vencido, lanza esta
 * excepcion accionable: indica posible acquire externo / cache perdido y
 * los lapsos preventivos documentados por ARCA (10 min homologacion,
 * 2 min produccion, sujetos a cambio).
 *
 * Extiende WsaaException para que el codigo de retry pueda seguir
 * distinguiendola con un catch mas especifico.
 */
class WsaaCeeYaPoseeTaException extends WsaaException
{
}
