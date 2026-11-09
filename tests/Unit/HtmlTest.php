<?php

declare(strict_types=1);

use ProvaSpider\Extracao\Html;

it('mantém HTML em UTF-8 sem alteração', function () {
    $html = '<p>Inscrição Única</p>';

    expect(Html::paraUtf8($html))->toBe($html);
});

it('converte ISO-8859-1 declarado no meta charset para UTF-8', function () {
    $latin1 = mb_convert_encoding('<meta charset="iso-8859-1"><p>CAMAÇARI</p>', 'ISO-8859-1', 'UTF-8');

    expect(Html::paraUtf8($latin1))->toContain('CAMAÇARI');
});

it('dá precedência ao charset do cabeçalho HTTP', function () {
    $latin1 = mb_convert_encoding('<p>JOSÉ</p>', 'ISO-8859-1', 'UTF-8');

    expect(Html::paraUtf8($latin1, 'text/html; charset=ISO-8859-1'))->toContain('JOSÉ');
});

it('detecta bytes que não são UTF-8 mesmo sem charset declarado', function () {
    $latin1 = mb_convert_encoding('<p>AÇÚCAR</p>', 'ISO-8859-1', 'UTF-8');

    expect(Html::paraUtf8($latin1))->toContain('AÇÚCAR');
});

it('ignora meta charset UTF-8 quando os bytes não são UTF-8 válidos', function () {
    $latin1 = mb_convert_encoding('<meta charset="utf-8"><p>MÜLLER</p>', 'ISO-8859-1', 'UTF-8');

    expect(Html::paraUtf8($latin1))->toContain('MÜLLER');
});

it('carrega o DOM sem reinterpretar acentos conforme o meta charset', function () {
    $dom = Html::carregar('<meta charset="iso-8859-1"><p id="x">Situação</p>');

    expect($dom->getElementById('x')?->textContent)->toBe('Situação');
});

it('normaliza espaços, nbsp e quebras de linha', function () {
    expect(Html::normalizarTexto("  AVENIDA \u{00A0}\u{00A0}  EDGARD\r\n\tSANTOS   "))->toBe('AVENIDA EDGARD SANTOS');
});

it('representa texto vazio como null', function (?string $entrada) {
    expect(Html::normalizarTexto($entrada))->toBeNull();
})->with([
    'null' => [null],
    'vazio' => [''],
    'só espaços e nbsp' => ["  \u{00A0} \n "],
]);
