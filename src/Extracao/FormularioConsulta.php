<?php

declare(strict_types=1);

namespace ProvaSpider\Extracao;

use DOMElement;
use DOMXPath;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use ProvaSpider\Exception\RespostaInesperadaException;

/**
 * Lê da página inicial o formulário de consulta por CNPJ: destino, método,
 * campos ocultos e o botão de envio da linha do CNPJ. Assim, mudanças nos
 * valores ocultos ou no destino são acompanhadas sem alterar o código, e uma
 * mudança estrutural é detectada antes de consultar.
 *
 * Formulário observado (consultaBa.asp): POST result.asp com sefp=1, estado=BA,
 * campo CGC (CNPJ) e botão B1. Os campos CPF e IE pertencem a outras
 * modalidades de consulta e não são enviados.
 */
final class FormularioConsulta
{
    public const CAMPO_CNPJ = 'CGC';

    /**
     * @param array<string, string> $camposFixos Campos ocultos e botão de envio.
     */
    private function __construct(
        public readonly string $metodo,
        public readonly string $acao,
        public readonly array $camposFixos,
    ) {
    }

    /**
     * @throws RespostaInesperadaException Se o formulário de CNPJ não for encontrado.
     */
    public static function aPartirDoHtml(string $htmlUtf8, string $urlPagina): self
    {
        $xpath = new DOMXPath(Html::carregar($htmlUtf8));

        $campoCnpj = $xpath->query(sprintf('//input[@name="%s"]', self::CAMPO_CNPJ))->item(0);
        if (!$campoCnpj instanceof DOMElement) {
            throw new RespostaInesperadaException(
                'Formulário de consulta por CNPJ não encontrado na página inicial do portal.'
            );
        }

        // O formulário real está aninhado de forma inválida em <table>; o libxml o
        // preserva como ancestral, mas o "form anterior" cobre o caso de ser fechado cedo.
        $form = $xpath->query('ancestor::form[1]', $campoCnpj)->item(0)
            ?? $xpath->query('preceding::form[1]', $campoCnpj)->item(0);
        if (!$form instanceof DOMElement) {
            throw new RespostaInesperadaException('Campo de CNPJ encontrado fora de um formulário.');
        }

        $campos = [];
        foreach ($xpath->query('.//input[translate(@type, "HIDEN", "hiden")="hidden"]', $form) ?: [] as $oculto) {
            if ($oculto instanceof DOMElement && $oculto->getAttribute('name') !== '') {
                $campos[$oculto->getAttribute('name')] = $oculto->getAttribute('value');
            }
        }

        $botao = $xpath->query('ancestor::tr[1]//input[translate(@type, "SUBMIT", "submit")="submit"]', $campoCnpj)->item(0);
        if ($botao instanceof DOMElement && $botao->getAttribute('name') !== '') {
            $campos[$botao->getAttribute('name')] = $botao->getAttribute('value');
        }

        $metodo = strtoupper($form->getAttribute('method') ?: 'GET');
        $acao = (string) UriResolver::resolve(new Uri($urlPagina), new Uri($form->getAttribute('action')));

        return new self($metodo, $acao, $campos);
    }

    /** @return array<string, string> */
    public function camposPara(string $cnpj): array
    {
        return $this->camposFixos + [self::CAMPO_CNPJ => $cnpj];
    }
}
