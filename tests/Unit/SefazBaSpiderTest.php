<?php

declare(strict_types=1);

use ProvaSpider\Exception\CadastroNaoEncontradoException;
use ProvaSpider\Exception\ConsultaException;
use ProvaSpider\Exception\EntradaInvalidaException;
use ProvaSpider\Exception\FalhaHttpException;
use ProvaSpider\Exception\PortalIndisponivelException;
use ProvaSpider\Exception\RespostaInesperadaException;
use ProvaSpider\Http\HttpResponse;
use ProvaSpider\Spider\SefazBaSpider;
use ProvaSpider\Tests\Support\TransporteFalso;
use ProvaSpider\ValueObject\CadastroContribuinte;
use ProvaSpider\ValueObject\Consulta;

const URL_RESULTADO = 'https://portal.sefaz.ba.gov.br/scripts/cadastro/cadastroBa/result.asp';
const URL_VAZIA = 'https://portal.sefaz.ba.gov.br/contribuinte/consultas/consulta_vazia.htm';
const URL_CNPJ_INCORRETO = 'https://portal.sefaz.ba.gov.br/contribuinte/consultas/cnpj_incorreto.htm';

function paginaFormulario(): HttpResponse
{
    return new HttpResponse(200, fixture('formulario.html'), SefazBaSpider::URL, ['Content-Type' => 'text/html']);
}

function consultarCom(TransporteFalso $transporte, string $cnpj = '01.234.567/0001-89'): CadastroContribuinte
{
    return (new SefazBaSpider($transporte))->consultar(new Consulta($cnpj));
}

it('abre o formulário e envia a consulta por CNPJ na mesma sessão de transporte', function () {
    $transporte = new TransporteFalso(
        paginaFormulario(),
        new HttpResponse(200, fixture('cadastro-completo.html'), URL_RESULTADO),
    );

    consultarCom($transporte);

    [$formulario, $consulta] = $transporte->requisicoes;
    expect($formulario->metodo)->toBe('GET')
        ->and($formulario->url)->toBe(SefazBaSpider::URL)
        ->and($consulta->metodo)->toBe('POST')
        ->and($consulta->url)->toBe(URL_RESULTADO)
        ->and($consulta->formulario)->toBe(['sefp' => '1', 'estado' => 'BA', 'B1' => 'CNPJ  ->', 'CGC' => '01234567000189'])
        ->and($consulta->cabecalhos)->toBe(['Referer' => SefazBaSpider::URL]);
});

it('retorna o objeto de valor mapeado a partir da resposta', function () {
    $transporte = new TransporteFalso(
        paginaFormulario(),
        new HttpResponse(200, fixture('cadastro-completo.html'), URL_RESULTADO),
    );

    $cadastro = consultarCom($transporte);

    expect($cadastro)->toBeInstanceOf(CadastroContribuinte::class)
        ->and($cadastro->cnpj)->toBe('01234567000189')
        ->and($cadastro->inscricaoEstadual)->toBe('000123456')
        ->and($cadastro->razaoSocial)->toBe('EMPRESA FICTÍCIA DE AÇÚCAR E CAFÉ LTDA');
});

it('decodifica resposta em ISO-8859-1 e preserva zeros do CNPJ', function () {
    $transporte = new TransporteFalso(
        paginaFormulario(),
        new HttpResponse(200, fixture('cadastro-campos-ausentes.html'), URL_RESULTADO),
    );

    $cadastro = consultarCom($transporte, '00.000.001/0001-00');

    expect($cadastro->cnpj)->toBe('00000001000100')
        ->and($cadastro->razaoSocial)->toBe('JOSÉ DA CONCEIÇÃO MÜLLER ME')
        ->and($cadastro->nomeFantasia)->toBeNull();
});

it('lança CadastroNaoEncontrado quando o portal redireciona para consulta vazia', function () {
    $transporte = new TransporteFalso(paginaFormulario(), new HttpResponse(200, fixture('consulta-vazia.html'), URL_VAZIA));

    consultarCom($transporte);
})->throws(CadastroNaoEncontradoException::class, 'Nenhum cadastro encontrado na SEFAZ-BA para o CNPJ 01234567000189');

it('reconhece a ausência de cadastro pelo conteúdo mesmo sem redirecionamento', function () {
    $transporte = new TransporteFalso(paginaFormulario(), new HttpResponse(200, fixture('consulta-vazia.html'), URL_RESULTADO));

    consultarCom($transporte);
})->throws(CadastroNaoEncontradoException::class);

it('lança EntradaInvalida quando o portal rejeita o dígito verificador', function () {
    $transporte = new TransporteFalso(paginaFormulario(), new HttpResponse(200, fixture('cnpj-incorreto.html'), URL_CNPJ_INCORRETO));

    consultarCom($transporte, '11.111.111/1111-11');
})->throws(EntradaInvalidaException::class, 'dígito verificador incorreto');

it('lança RespostaInesperada para HTML sem cadastro reconhecível', function () {
    $transporte = new TransporteFalso(paginaFormulario(), new HttpResponse(200, fixture('inesperado.html'), URL_RESULTADO));

    consultarCom($transporte);
})->throws(RespostaInesperadaException::class);

it('lança RespostaInesperada quando o formulário mudou e não tem o campo de CNPJ', function () {
    $transporte = new TransporteFalso(new HttpResponse(200, fixture('inesperado.html'), SefazBaSpider::URL));

    try {
        consultarCom($transporte);
        $this->fail('Deveria lançar RespostaInesperadaException.');
    } catch (RespostaInesperadaException $e) {
        expect($e->getMessage())->toContain('Formulário de consulta por CNPJ não encontrado')
            ->and($transporte->requisicoes)->toHaveCount(1); // não chega a enviar a consulta
    }
});

it('lança RespostaInesperada quando o cadastro devolvido é de outro CNPJ', function () {
    $transporte = new TransporteFalso(paginaFormulario(), new HttpResponse(200, fixture('cadastro-completo.html'), URL_RESULTADO));

    consultarCom($transporte, '98.765.432/0001-10');
})->throws(RespostaInesperadaException::class, 'retornou o cadastro do CNPJ 01234567000189 ao consultar o CNPJ 98765432000110');

it('lança FalhaHttp com o status quando o portal responde erro', function (int $status, bool $naConsulta) {
    $erro = new HttpResponse($status, 'erro', $naConsulta ? URL_RESULTADO : SefazBaSpider::URL);
    $transporte = $naConsulta ? new TransporteFalso(paginaFormulario(), $erro) : new TransporteFalso($erro);

    try {
        consultarCom($transporte);
        $this->fail('Deveria lançar FalhaHttpException.');
    } catch (FalhaHttpException $e) {
        expect($e->status)->toBe($status)
            ->and($e->getMessage())->toContain("HTTP {$status}");
    }
})->with([
    'formulário 404' => [404, false],
    'consulta 500' => [500, true],
    'consulta 403' => [403, true],
]);

it('lança PortalIndisponivel para status transitório', function (int $status) {
    $transporte = new TransporteFalso(paginaFormulario(), new HttpResponse($status, '', URL_RESULTADO));

    consultarCom($transporte);
})->with([502, 503, 504])->throws(PortalIndisponivelException::class);

it('propaga a falha de transporte sem converter em cadastro vazio', function () {
    $transporte = new TransporteFalso(new PortalIndisponivelException('Portal indisponível: timeout'));

    consultarCom($transporte);
})->throws(PortalIndisponivelException::class, 'timeout');

it('agrupa todas as falhas de consulta sob ConsultaException', function () {
    expect(is_subclass_of(CadastroNaoEncontradoException::class, ConsultaException::class))->toBeTrue()
        ->and(is_subclass_of(EntradaInvalidaException::class, ConsultaException::class))->toBeTrue()
        ->and(is_subclass_of(FalhaHttpException::class, ConsultaException::class))->toBeTrue()
        ->and(is_subclass_of(PortalIndisponivelException::class, ConsultaException::class))->toBeTrue()
        ->and(is_subclass_of(RespostaInesperadaException::class, ConsultaException::class))->toBeTrue();
});
