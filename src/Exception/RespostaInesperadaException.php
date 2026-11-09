<?php

declare(strict_types=1);

namespace ProvaSpider\Exception;

/**
 * A resposta do portal não corresponde a nenhuma estrutura conhecida (layout alterado, página de erro, dados incoerentes).
 */
final class RespostaInesperadaException extends ConsultaException
{
}
