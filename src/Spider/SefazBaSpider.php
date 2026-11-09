<?php

declare(strict_types=1);

namespace ProvaSpider\Spider;

use ProvaSpider\Contract\Spider;
use ProvaSpider\Exception\CadastroNaoEncontradoException;
use ProvaSpider\Exception\EntradaInvalidaException;
use ProvaSpider\Exception\FalhaHttpException;
use ProvaSpider\Exception\PortalIndisponivelException;
use ProvaSpider\Exception\RespostaInesperadaException;
use ProvaSpider\Extracao\ExtratorCadastro;
use ProvaSpider\Extracao\FormularioConsulta;
use ProvaSpider\Extracao\Html;
use ProvaSpider\Http\GuzzleTransport;
use ProvaSpider\Http\HttpRequest;
use ProvaSpider\Http\HttpResponse;
use ProvaSpider\Http\HttpTransport;
use ProvaSpider\Http\TransporteComTentativas;
use ProvaSpider\ValueObject\CadastroContribuinte;
use ProvaSpider\ValueObject\Consulta;

/**
 * Consulta pública ao cadastro de contribuintes do ICMS da Bahia, por CNPJ.
 *
 * Fluxo observado no portal:
 *  1. GET consultaBa.asp: devolve o formulário e abre a sessão ASP (cookie ASPSESSIONID...).
 *  2. POST result.asp com os campos do formulário e o CNPJ sem máscara (campo CGC).
 *     - Cadastro encontrado: 200 com a página de dados.
 *     - Sem cadastro: 302 para .../consulta_vazia.htm ("Nenhum registro encontrado").
 *     - Dígito verificador inválido: 302 para .../cnpj_incorreto.htm.
 */
final class SefazBaSpider implements Spider
{
    public const URL = 'https://portal.sefaz.ba.gov.br/scripts/cadastro/cadastroBa/consultaBa.asp';

    private readonly HttpTransport $transporte;
    private readonly ExtratorCadastro $extrator;

    public function __construct(?HttpTransport $transporte = null, ?ExtratorCadastro $extrator = null)
    {
        $this->transporte = $transporte ?? new TransporteComTentativas(new GuzzleTransport());
        $this->extrator = $extrator ?? new ExtratorCadastro();
    }

    public function consultar(Consulta $consulta): CadastroContribuinte
    {
        $paginaInicial = $this->exigirSucesso($this->transporte->enviar(HttpRequest::get(self::URL)));
        $formulario = FormularioConsulta::aPartirDoHtml($this->html($paginaInicial), $paginaInicial->urlEfetiva);

        $resposta = $this->transporte->enviar(new HttpRequest(
            $formulario->metodo,
            $formulario->acao,
            $formulario->camposPara($consulta->cnpj),
            ['Referer' => $paginaInicial->urlEfetiva],
        ));
        $html = $this->html($this->exigirSucesso($resposta));

        if ($this->indicaSemCadastro($resposta->urlEfetiva, $html)) {
            throw new CadastroNaoEncontradoException(
                sprintf('Nenhum cadastro encontrado na SEFAZ-BA para o CNPJ %s.', $consulta->cnpj)
            );
        }

        if ($this->indicaCnpjRejeitado($resposta->urlEfetiva, $html)) {
            throw new EntradaInvalidaException(
                sprintf('O portal rejeitou o CNPJ %s: dígito verificador incorreto.', $consulta->cnpj)
            );
        }

        $cadastro = $this->extrator->extrair($html);

        if ($cadastro->cnpj !== $consulta->cnpj) {
            throw new RespostaInesperadaException(sprintf(
                'O portal retornou o cadastro do CNPJ %s ao consultar o CNPJ %s.',
                $cadastro->cnpj ?? '(ausente)',
                $consulta->cnpj,
            ));
        }

        return $cadastro;
    }

    private function exigirSucesso(HttpResponse $resposta): HttpResponse
    {
        if (in_array($resposta->status, TransporteComTentativas::STATUS_TRANSITORIOS, true)) {
            throw new PortalIndisponivelException(
                sprintf('Portal da SEFAZ-BA indisponível: HTTP %d em %s.', $resposta->status, $resposta->urlEfetiva)
            );
        }

        if ($resposta->status < 200 || $resposta->status >= 300) {
            throw new FalhaHttpException(
                sprintf('O portal da SEFAZ-BA respondeu HTTP %d em %s.', $resposta->status, $resposta->urlEfetiva),
                $resposta->status,
            );
        }

        return $resposta;
    }

    private function html(HttpResponse $resposta): string
    {
        return Html::paraUtf8($resposta->corpo, $resposta->cabecalho('Content-Type'));
    }

    private function indicaSemCadastro(string $url, string $html): bool
    {
        return str_contains(strtolower($url), 'consulta_vazia')
            || preg_match('/nenhum\s+registro\s+encontrado/iu', $html) === 1;
    }

    private function indicaCnpjRejeitado(string $url, string $html): bool
    {
        return str_contains(strtolower($url), 'cnpj_incorreto')
            || preg_match('/cnpj\s+incorreto/iu', $html) === 1;
    }
}
