<?php

declare(strict_types=1);

namespace ProvaSpider\Tests\Support;

use LogicException;
use ProvaSpider\Http\HttpRequest;
use ProvaSpider\Http\HttpResponse;
use ProvaSpider\Http\HttpTransport;
use Throwable;

/**
 * Transporte em memória: devolve respostas (ou lança exceções) na ordem
 * enfileirada e registra as requisições recebidas.
 */
final class TransporteFalso implements HttpTransport
{
    /** @var list<HttpRequest> */
    public array $requisicoes = [];

    /** @var list<HttpResponse|Throwable> */
    private array $fila;

    public function __construct(HttpResponse|Throwable ...$respostas)
    {
        $this->fila = array_values($respostas);
    }

    public function enviar(HttpRequest $requisicao): HttpResponse
    {
        $this->requisicoes[] = $requisicao;

        $proxima = array_shift($this->fila);
        if ($proxima === null) {
            throw new LogicException('TransporteFalso: nenhuma resposta enfileirada para ' . $requisicao->url);
        }
        if ($proxima instanceof Throwable) {
            throw $proxima;
        }

        return $proxima;
    }
}
