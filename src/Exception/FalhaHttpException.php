<?php

declare(strict_types=1);

namespace ProvaSpider\Exception;

use Throwable;

/**
 * Falha não recuperável na camada HTTP: status de erro (4xx/5xx não transitório),
 * limite de redirecionamentos excedido ou erro de transporte que não indica
 * indisponibilidade (por exemplo, certificado TLS inválido).
 */
final class FalhaHttpException extends ConsultaException
{
    public function __construct(string $message, public readonly ?int $status = null, ?Throwable $previous = null)
    {
        parent::__construct($message, $status ?? 0, $previous);
    }
}
