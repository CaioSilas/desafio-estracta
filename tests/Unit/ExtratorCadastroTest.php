<?php

declare(strict_types=1);

use ProvaSpider\Exception\RespostaInesperadaException;
use ProvaSpider\Extracao\ExtratorCadastro;
use ProvaSpider\Extracao\Html;
use ProvaSpider\ValueObject\CadastroContribuinte;

function extrairFixture(string $nome): CadastroContribuinte
{
    return (new ExtratorCadastro())->extrair(Html::paraUtf8(fixture($nome)));
}

describe('cadastro completo (UTF-8 com entidades)', function () {
    beforeEach(function () {
        $this->cadastro = extrairFixture('cadastro-completo.html');
    });

    it('extrai identificadores sem máscara e com zeros à esquerda', function () {
        expect($this->cadastro->cnpj)->toBe('01234567000189')
            ->and($this->cadastro->inscricaoEstadual)->toBe('000123456');
    });

    it('decodifica acentos literais e entidades e normaliza espaços', function () {
        expect($this->cadastro->razaoSocial)->toBe('EMPRESA FICTÍCIA DE AÇÚCAR E CAFÉ LTDA')
            ->and($this->cadastro->nomeFantasia)->toBe('CAFÉ SÃO JOÃO');
    });

    it('ignora campos que o portal deixa em comentários HTML', function () {
        expect($this->cadastro->razaoSocial)->not->toContain('COMENTARIO');
    });

    it('compõe o endereço do estabelecimento, preservando zeros do número', function () {
        expect($this->cadastro->endereco)
            ->toBe('RUA DAS FLORES, 0042, SALA 3 - JARDIM FICTÍCIO - FEIRA DE SANTANA/BA - CEP 40000-000');
    });

    it('lê a atividade econômica da célula seguinte ao rótulo', function () {
        expect($this->cadastro->atividadeEconomica)
            ->toBe('4721102 - Padaria e confeitaria com predominância de revenda');
    });

    it('mapeia situação cadastral vigente e forma de pagamento (regime de apuração)', function () {
        expect($this->cadastro->situacaoCadastral)->toBe('ATIVO')
            ->and($this->cadastro->regimeApuracao)->toBe('SIMPLES NACIONAL');
    });

    it('serializa o objeto completo para JSON', function () {
        expect(json_decode(json_encode($this->cadastro, JSON_THROW_ON_ERROR), true))->toBe([
            'inscricaoEstadual' => '000123456',
            'cnpj' => '01234567000189',
            'razaoSocial' => 'EMPRESA FICTÍCIA DE AÇÚCAR E CAFÉ LTDA',
            'nomeFantasia' => 'CAFÉ SÃO JOÃO',
            'situacaoCadastral' => 'ATIVO',
            'endereco' => 'RUA DAS FLORES, 0042, SALA 3 - JARDIM FICTÍCIO - FEIRA DE SANTANA/BA - CEP 40000-000',
            'atividadeEconomica' => '4721102 - Padaria e confeitaria com predominância de revenda',
            'regimeApuracao' => 'SIMPLES NACIONAL',
        ]);
    });
});

it('separa por seção rótulos repetidos (empresa, correspondência e contador)', function () {
    $campos = (new ExtratorCadastro())->camposPorSecao(Html::paraUtf8(fixture('cadastro-completo.html')));

    expect($campos['dados da empresa']['cnpj'])->toBe('01.234.567/0001-89')
        ->and($campos['dados da empresa']['município'])->toBe('FEIRA DE SANTANA')
        ->and($campos['endereço de correspondência']['município'])->toBe('SALVADOR')
        ->and($campos['informações do contador']['cnpj'])->toBe('99.999.999/0001-99')
        ->and($campos['informações do contador']['município'])->toBe('ILHEUS');
});

describe('cadastro com campos ausentes (ISO-8859-1)', function () {
    beforeEach(function () {
        $this->cadastro = extrairFixture('cadastro-campos-ausentes.html');
    });

    it('decodifica ISO-8859-1 e preserva zeros à esquerda', function () {
        expect($this->cadastro->cnpj)->toBe('00000001000100')
            ->and($this->cadastro->inscricaoEstadual)->toBe('012345678')
            ->and($this->cadastro->razaoSocial)->toBe('JOSÉ DA CONCEIÇÃO MÜLLER ME');
    });

    it('representa campos vazios ou ausentes como null', function () {
        expect($this->cadastro->nomeFantasia)->toBeNull()
            ->and($this->cadastro->situacaoCadastral)->toBeNull()
            ->and($this->cadastro->regimeApuracao)->toBeNull();
    });

    it('não confunde o subtítulo seguinte com o valor da atividade econômica vazia', function () {
        expect($this->cadastro->atividadeEconomica)->toBeNull();
    });

    it('omite do endereço as partes ausentes', function () {
        expect($this->cadastro->endereco)->toBe('TRAVESSA SÃO BENEDITO, S/N - CAMAÇARI/BA');
    });
});

it('retorna inscrição estadual null quando o valor não começa por número', function () {
    $html = '<table><th>Dados da empresa</th><tr><td><b>CNPJ:</b> 01.234.567/0001-89</td>'
        . '<td><b>Inscrição Estadual:</b> NÃO INFORMADA</td></tr></table>';

    expect((new ExtratorCadastro())->extrair($html)->inscricaoEstadual)->toBeNull();
});

it('retorna endereço null quando nenhuma parte é publicada', function () {
    $html = '<table><th>Dados da empresa</th><tr><td><b>CNPJ:</b> 01.234.567/0001-89</td></tr></table>';

    expect((new ExtratorCadastro())->extrair($html)->endereco)->toBeNull();
});

it('rejeita página sem a seção de dados da empresa', function () {
    extrairFixture('inesperado.html');
})->throws(RespostaInesperadaException::class, 'Dados da empresa');

it('rejeita CNPJ publicado em formato inesperado', function () {
    $html = '<table><th>Dados da empresa</th><tr><td><b>CNPJ:</b> 123</td></tr></table>';

    (new ExtratorCadastro())->extrair($html);
})->throws(RespostaInesperadaException::class, 'CNPJ publicado pelo portal em formato inesperado');
