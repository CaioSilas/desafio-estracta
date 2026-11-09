<?php

declare(strict_types=1);

namespace ProvaSpider\Exception;

/**
 * O portal não respondeu ou declarou indisponibilidade (conexão, tempo limite, 502/503/504). Falha recuperável: pode ser repetida.
 */
final class PortalIndisponivelException extends ConsultaException
{
}
