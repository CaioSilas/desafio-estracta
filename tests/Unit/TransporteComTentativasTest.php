<?php

declare(strict_types=1);

use ProvaSpider\Exception\FalhaHttpException;
use ProvaSpider\Exception\PortalIndisponivelException;
use ProvaSpider\Http\HttpRequest;
use ProvaSpider\Http\HttpResponse;
use ProvaSpider\Http\TransporteComTentativas;
use ProvaSpider\Tests\Support\TransporteFalso;

function comTentativas(TransporteFalso $falso, array &$esperas, int $maxTentativas = 3): TransporteComTentativas
{
    return new TransporteComTentativas($falso, $maxTentativas, 100, function (int $ms) use (&$esperas): void {
        $esperas[] = $ms;
    });
}

it('repete indisponibilidade até obter resposta, com espera crescente', function () {
    $esperas = [];
    $falso = new TransporteFalso(
        new PortalIndisponivelException('timeout'),
        new HttpResponse(503, '', 'https://x'),
        new HttpResponse(200, 'ok', 'https://x'),
    );

    $resposta = comTentativas($falso, $esperas)->enviar(HttpRequest::get('https://x'));

    expect($resposta->corpo)->toBe('ok')
        ->and($falso->requisicoes)->toHaveCount(3)
        ->and($esperas)->toBe([100, 200]);
});

it('respeita o limite de tentativas e repassa a última indisponibilidade', function () {
    $esperas = [];
    $falso = new TransporteFalso(
        new PortalIndisponivelException('falha 1'),
        new PortalIndisponivelException('falha 2'),
        new PortalIndisponivelException('falha 3'),
    );

    expect(fn () => comTentativas($falso, $esperas)->enviar(HttpRequest::get('https://x')))
        ->toThrow(PortalIndisponivelException::class, 'falha 3');
    expect($falso->requisicoes)->toHaveCount(3);
});

it('devolve o último status transitório quando as tentativas se esgotam', function () {
    $esperas = [];
    $falso = new TransporteFalso(new HttpResponse(503, '', 'https://x'), new HttpResponse(502, '', 'https://x'));

    $resposta = comTentativas($falso, $esperas, maxTentativas: 2)->enviar(HttpRequest::get('https://x'));

    expect($resposta->status)->toBe(502)
        ->and($falso->requisicoes)->toHaveCount(2);
});

it('não repete status de erro não transitório', function (int $status) {
    $esperas = [];
    $falso = new TransporteFalso(new HttpResponse($status, '', 'https://x'));

    $resposta = comTentativas($falso, $esperas)->enviar(HttpRequest::get('https://x'));

    expect($resposta->status)->toBe($status)
        ->and($falso->requisicoes)->toHaveCount(1)
        ->and($esperas)->toBe([]);
})->with([400, 404, 500]);

it('não repete falhas não recuperáveis', function () {
    $esperas = [];
    $falso = new TransporteFalso(new FalhaHttpException('certificado inválido'));

    expect(fn () => comTentativas($falso, $esperas)->enviar(HttpRequest::get('https://x')))
        ->toThrow(FalhaHttpException::class);
    expect($falso->requisicoes)->toHaveCount(1);
});

it('exige ao menos uma tentativa', function () {
    new TransporteComTentativas(new TransporteFalso(), 0);
})->throws(InvalidArgumentException::class);
