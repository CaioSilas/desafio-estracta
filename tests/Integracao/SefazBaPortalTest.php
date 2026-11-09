<?php

declare(strict_types=1);

/*
 * Testes de integração com o portal real da SEFAZ-BA. Não fazem parte da suíte
 * padrão (composer test). Para executar:
 *
 *   SEFAZ_BA_CNPJ=<cnpj cadastrado na Bahia> composer test:integracao
 *
 * Os dados retornados mudam com o tempo, por isso só a estrutura é verificada.
 */

use ProvaSpider\Exception\CadastroNaoEncontradoException;
use ProvaSpider\Exception\EntradaInvalidaException;
use ProvaSpider\Spider\SefazBaSpider;
use ProvaSpider\ValueObject\Consulta;

beforeEach(function () {
    if (getenv('SEFAZ_BA_CNPJ') === false || getenv('SEFAZ_BA_CNPJ') === '') {
        $this->markTestSkipped('Defina SEFAZ_BA_CNPJ para executar a integração com o portal.');
    }
});

it('consulta um CNPJ cadastrado no portal real', function () {
    $consulta = new Consulta((string) getenv('SEFAZ_BA_CNPJ'));

    $cadastro = (new SefazBaSpider())->consultar($consulta);

    expect($cadastro->cnpj)->toBe($consulta->cnpj)
        ->and($cadastro->razaoSocial)->not->toBeNull()
        ->and($cadastro->inscricaoEstadual)->toMatch('/^\d+$/')
        ->and($cadastro->situacaoCadastral)->not->toBeNull();
});

it('informa ausência de cadastro para CNPJ válido não inscrito na Bahia', function () {
    // 11.222.333/0001-81: dígitos verificadores válidos, sem cadastro na SEFAZ-BA (verificado em 11/2026).
    (new SefazBaSpider())->consultar(new Consulta('11.222.333/0001-81'));
})->throws(CadastroNaoEncontradoException::class);

it('informa entrada inválida para CNPJ com dígito verificador incorreto', function () {
    (new SefazBaSpider())->consultar(new Consulta('11.111.111/1111-11'));
})->throws(EntradaInvalidaException::class);
