<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use ProvaSpider\Exception\FalhaHttpException;
use ProvaSpider\Exception\PortalIndisponivelException;
use ProvaSpider\Http\GuzzleTransport;
use ProvaSpider\Http\HttpRequest;

const BASE = 'https://portal.sefaz.ba.gov.br/scripts/cadastro/cadastroBa/';

/**
 * Transporte Guzzle real com handler simulado; $registro recebe [requisição, opções] de cada envio.
 *
 * @param array<int, array{0: \Psr\Http\Message\RequestInterface, 1: array<string, mixed>}> $registro
 */
function transporteSimulado(array &$registro, array $respostas, int $maxRedirecionamentos = 3): GuzzleTransport
{
    $mock = new MockHandler($respostas);
    $handler = function ($requisicao, array $opcoes) use ($mock, &$registro) {
        $registro[] = [$requisicao, $opcoes];

        return $mock($requisicao, $opcoes);
    };

    return new GuzzleTransport(tempoLimite: 7.5, tempoLimiteConexao: 2.5, maxRedirecionamentos: $maxRedirecionamentos, handler: $handler);
}

it('mantém o cookie de sessão ASP entre o formulário e o resultado', function () {
    $registro = [];
    $transporte = transporteSimulado($registro, [
        new Response(200, ['Set-Cookie' => 'ASPSESSIONIDFICTICIO=ABC123; secure; path=/'], 'form'),
        new Response(200, [], 'resultado'),
    ]);

    $transporte->enviar(HttpRequest::get(BASE . 'consultaBa.asp'));
    $transporte->enviar(HttpRequest::post(BASE . 'result.asp', ['CGC' => '01234567000189']));

    expect($registro[0][0]->getHeaderLine('Cookie'))->toBe('')
        ->and($registro[1][0]->getHeaderLine('Cookie'))->toBe('ASPSESSIONIDFICTICIO=ABC123');
});

it('envia o formulário codificado como application/x-www-form-urlencoded', function () {
    $registro = [];
    $transporte = transporteSimulado($registro, [new Response(200)]);

    $transporte->enviar(HttpRequest::post(BASE . 'result.asp', ['sefp' => '1', 'CGC' => '01234567000189', 'B1' => 'CNPJ  ->'], ['Referer' => BASE . 'consultaBa.asp']));

    [$requisicao] = $registro[0];
    expect($requisicao->getMethod())->toBe('POST')
        ->and($requisicao->getHeaderLine('Content-Type'))->toBe('application/x-www-form-urlencoded')
        ->and((string) $requisicao->getBody())->toBe('sefp=1&CGC=01234567000189&B1=CNPJ++-%3E')
        ->and($requisicao->getHeaderLine('Referer'))->toBe(BASE . 'consultaBa.asp');
});

it('aplica tempo limite e tempo limite de conexão a cada requisição', function () {
    $registro = [];
    transporteSimulado($registro, [new Response(200)])->enviar(HttpRequest::get(BASE . 'consultaBa.asp'));

    expect($registro[0][1]['timeout'])->toBe(7.5)
        ->and($registro[0][1]['connect_timeout'])->toBe(2.5);
});

it('segue redirecionamentos e informa a URL efetiva', function () {
    $registro = [];
    $transporte = transporteSimulado($registro, [
        new Response(302, ['Location' => 'https://portal.sefaz.ba.gov.br/contribuinte/consultas/consulta_vazia.htm']),
        new Response(200, [], 'Nenhum registro encontrado !'),
    ]);

    $resposta = $transporte->enviar(HttpRequest::post(BASE . 'result.asp', ['CGC' => '01234567000189']));

    expect($resposta->status)->toBe(200)
        ->and($resposta->urlEfetiva)->toBe('https://portal.sefaz.ba.gov.br/contribuinte/consultas/consulta_vazia.htm')
        ->and($registro[1][0]->getMethod())->toBe('GET');
});

it('informa a própria URL quando não há redirecionamento', function () {
    $registro = [];
    $resposta = transporteSimulado($registro, [new Response(200, ['Content-Type' => 'text/html'], 'ok')])
        ->enviar(HttpRequest::get(BASE . 'consultaBa.asp'));

    expect($resposta->urlEfetiva)->toBe(BASE . 'consultaBa.asp')
        ->and($resposta->cabecalho('content-type'))->toBe('text/html')
        ->and($resposta->corpo)->toBe('ok');
});

it('limita a quantidade de redirecionamentos', function () {
    $registro = [];
    $transporte = transporteSimulado($registro, [
        new Response(302, ['Location' => BASE . 'a.asp']),
        new Response(302, ['Location' => BASE . 'b.asp']),
        new Response(302, ['Location' => BASE . 'c.asp']),
    ], maxRedirecionamentos: 2);

    expect(fn () => $transporte->enviar(HttpRequest::get(BASE . 'consultaBa.asp')))
        ->toThrow(FalhaHttpException::class, 'Limite de 2 redirecionamentos excedido');
});

it('devolve status HTTP de erro como resposta, sem lançar exceção', function () {
    $registro = [];
    $resposta = transporteSimulado($registro, [new Response(500, [], 'erro')])
        ->enviar(HttpRequest::get(BASE . 'consultaBa.asp'));

    expect($resposta->status)->toBe(500);
});

it('converte falha de conexão ou tempo limite em indisponibilidade', function () {
    $registro = [];
    $transporte = transporteSimulado($registro, [
        new ConnectException('cURL error 28: Operation timed out', new Request('GET', BASE . 'consultaBa.asp')),
    ]);

    expect(fn () => $transporte->enviar(HttpRequest::get(BASE . 'consultaBa.asp')))
        ->toThrow(PortalIndisponivelException::class, 'Operation timed out');
});

it('converte outras falhas de transporte em falha HTTP não recuperável', function () {
    $registro = [];
    $transporte = transporteSimulado($registro, [
        new RequestException('cURL error 60: SSL certificate problem', new Request('GET', BASE . 'consultaBa.asp')),
    ]);

    expect(fn () => $transporte->enviar(HttpRequest::get(BASE . 'consultaBa.asp')))
        ->toThrow(FalhaHttpException::class, 'SSL certificate problem');
});
