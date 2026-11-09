<?php

declare(strict_types=1);

namespace ProvaSpider\Http;

use ProvaSpider\Exception\FalhaHttpException;
use ProvaSpider\Exception\PortalIndisponivelException;

/**
 * Transporte HTTP substituível. Implementações devem manter a sessão (cookies)
 * entre chamadas da mesma instância e devolver qualquer status HTTP como resposta,
 * sem lançar exceção por status de erro.
 */
interface HttpTransport
{
    /**
     * @throws PortalIndisponivelException Falha de conexão ou tempo limite esgotado.
     * @throws FalhaHttpException Outras falhas de transporte (TLS, excesso de redirecionamentos).
     */
    public function enviar(HttpRequest $requisicao): HttpResponse;
}
