<?php

declare(strict_types=1);

use ProvaSpider\Exception\RespostaInesperadaException;
use ProvaSpider\Extracao\FormularioConsulta;
use ProvaSpider\Extracao\Html;

const URL_FORMULARIO = 'https://portal.sefaz.ba.gov.br/scripts/cadastro/cadastroBa/consultaBa.asp';

it('identifica método e destino absoluto do formulário', function () {
    $formulario = FormularioConsulta::aPartirDoHtml(Html::paraUtf8(fixture('formulario.html')), URL_FORMULARIO);

    expect($formulario->metodo)->toBe('POST')
        ->and($formulario->acao)->toBe('https://portal.sefaz.ba.gov.br/scripts/cadastro/cadastroBa/result.asp');
});

it('monta somente os campos da consulta por CNPJ', function () {
    $formulario = FormularioConsulta::aPartirDoHtml(Html::paraUtf8(fixture('formulario.html')), URL_FORMULARIO);

    expect($formulario->camposPara('01234567000189'))->toBe([
        'sefp' => '1',
        'estado' => 'BA',
        'B1' => 'CNPJ  ->',
        'CGC' => '01234567000189',
    ]);
});

it('não envia os campos e botões das consultas por CPF e IE', function () {
    $campos = FormularioConsulta::aPartirDoHtml(Html::paraUtf8(fixture('formulario.html')), URL_FORMULARIO)
        ->camposPara('01234567000189');

    expect($campos)->not->toHaveKeys(['CPF', 'IE', 'B2', 'B3']);
});

it('falha com resposta inesperada quando a página não tem o campo de CNPJ', function () {
    FormularioConsulta::aPartirDoHtml(Html::paraUtf8(fixture('inesperado.html')), URL_FORMULARIO);
})->throws(RespostaInesperadaException::class, 'Formulário de consulta por CNPJ não encontrado');
