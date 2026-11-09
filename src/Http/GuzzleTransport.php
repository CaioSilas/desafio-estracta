<?php

declare(strict_types=1);

namespace ProvaSpider\Http;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\TooManyRedirectsException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\RedirectMiddleware;
use ProvaSpider\Exception\FalhaHttpException;
use ProvaSpider\Exception\PortalIndisponivelException;

/**
 * Transporte baseado em Guzzle, com tempo limite, limite de redirecionamentos
 * e um cookie jar por instância (mantém a sessão ASP entre o formulário e o resultado).
 */
final class GuzzleTransport implements HttpTransport
{
    public const USER_AGENT = 'Mozilla/5.0 (compatible; DesafioEstracta/1.0)';

    private readonly ClientInterface $client;

    /**
     * @param float $tempoLimite Tempo máximo total de cada requisição, em segundos.
     * @param float $tempoLimiteConexao Tempo máximo para estabelecer a conexão, em segundos.
     * @param callable|null $handler Handler Guzzle alternativo (ex.: MockHandler nos testes).
     */
    public function __construct(
        public readonly float $tempoLimite = 20.0,
        public readonly float $tempoLimiteConexao = 10.0,
        public readonly int $maxRedirecionamentos = 3,
        ?callable $handler = null,
    ) {
        $this->client = new Client([
            'handler' => HandlerStack::create($handler),
            'cookies' => new CookieJar(),
            'timeout' => $this->tempoLimite,
            'connect_timeout' => $this->tempoLimiteConexao,
            'http_errors' => false,
            'allow_redirects' => [
                'max' => $this->maxRedirecionamentos,
                'strict' => false,
                'referer' => true,
                'protocols' => ['https', 'http'],
                'track_redirects' => true,
            ],
            'headers' => [
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'pt-BR,pt;q=0.9',
            ],
        ]);
    }

    public function enviar(HttpRequest $requisicao): HttpResponse
    {
        $opcoes = ['headers' => $requisicao->cabecalhos];
        if ($requisicao->formulario !== []) {
            $opcoes['form_params'] = $requisicao->formulario;
        }

        try {
            $resposta = $this->client->request($requisicao->metodo, $requisicao->url, $opcoes);
        } catch (ConnectException $e) {
            // Inclui falha de DNS, conexão recusada e tempo limite esgotado.
            throw new PortalIndisponivelException(
                sprintf('Portal indisponível (%s): %s', $requisicao->url, $e->getMessage()),
                0,
                $e,
            );
        } catch (TooManyRedirectsException $e) {
            throw new FalhaHttpException(
                sprintf('Limite de %d redirecionamentos excedido em %s.', $this->maxRedirecionamentos, $requisicao->url),
                null,
                $e,
            );
        } catch (GuzzleException $e) {
            throw new FalhaHttpException(
                sprintf('Falha de transporte em %s: %s', $requisicao->url, $e->getMessage()),
                null,
                $e,
            );
        }

        $historico = $resposta->getHeader(RedirectMiddleware::HISTORY_HEADER);
        $urlEfetiva = $historico === [] ? $requisicao->url : (string) end($historico);

        $cabecalhos = [];
        foreach ($resposta->getHeaders() as $nome => $valores) {
            $cabecalhos[$nome] = implode(', ', $valores);
        }

        return new HttpResponse($resposta->getStatusCode(), (string) $resposta->getBody(), $urlEfetiva, $cabecalhos);
    }
}
