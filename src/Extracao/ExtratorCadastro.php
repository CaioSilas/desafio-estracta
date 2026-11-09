<?php

declare(strict_types=1);

namespace ProvaSpider\Extracao;

use DOMElement;
use DOMXPath;
use ProvaSpider\Exception\RespostaInesperadaException;
use ProvaSpider\ValueObject\CadastroContribuinte;

/**
 * Extrai o cadastro da página de resultado (result.asp) da SEFAZ-BA.
 *
 * A página é uma sequência de tabelas em que cada célula traz um rótulo em
 * negrito terminado em ":" seguido do valor (ex.: <b>CNPJ:</b>&nbsp;...). Os
 * mesmos rótulos se repetem em seções diferentes (endereço do estabelecimento,
 * de correspondência e do contador), então cada rótulo é indexado pela seção
 * corrente, definida pelo último <th> visto. Comentários HTML, que o portal usa
 * para esconder campos antigos, são ignorados pelo DOM.
 */
final class ExtratorCadastro
{
    public const SECAO_EMPRESA = 'dados da empresa';
    public const SECAO_COMPLEMENTARES = 'informações complementares';

    /**
     * @throws RespostaInesperadaException Se a página não tiver a estrutura de um cadastro.
     */
    public function extrair(string $htmlUtf8): CadastroContribuinte
    {
        $campos = $this->camposPorSecao($htmlUtf8);
        $empresa = $campos[self::SECAO_EMPRESA] ?? [];
        $complementares = $campos[self::SECAO_COMPLEMENTARES] ?? [];

        if (!array_key_exists('cnpj', $empresa)) {
            throw new RespostaInesperadaException(
                'A resposta do portal não contém a seção "Dados da empresa" com o CNPJ; o layout pode ter mudado.'
            );
        }

        return new CadastroContribuinte(
            inscricaoEstadual: $this->inscricaoEstadual($empresa['inscrição estadual'] ?? null),
            cnpj: $this->cnpj($empresa['cnpj']),
            razaoSocial: $empresa['razão social'] ?? null,
            nomeFantasia: $empresa['nome fantasia'] ?? null,
            situacaoCadastral: $complementares['situação cadastral vigente'] ?? null,
            endereco: $this->endereco($empresa),
            atividadeEconomica: $complementares['atividade econômica principal'] ?? null,
            regimeApuracao: $complementares['forma de pagamento'] ?? null,
        );
    }

    /**
     * Rótulos e valores agrupados por seção. Rótulos em minúsculas, sem ":".
     * Valores já normalizados; vazio vira null. Na mesma seção vale a primeira ocorrência.
     *
     * @return array<string, array<string, string|null>>
     */
    public function camposPorSecao(string $htmlUtf8): array
    {
        $xpath = new DOMXPath(Html::carregar($htmlUtf8));
        $campos = [];
        $secao = '';
        /** @var array{0: string, 1: string}|null $aguardandoValor */
        $aguardandoValor = null;

        // Somente células "folha": células de layout que contêm tabelas inteiras são ignoradas.
        foreach ($xpath->query('//th[not(.//table)] | //td[not(.//table)]') ?: [] as $celula) {
            if (!$celula instanceof DOMElement) {
                continue;
            }

            $texto = Html::normalizarTexto($celula->textContent);

            if (strtolower($celula->nodeName) === 'th') {
                $secao = mb_strtolower($texto ?? '');
                $aguardandoValor = null;
                continue;
            }

            $negrito = $xpath->query('.//b', $celula)->item(0);
            $rotulo = $negrito !== null ? Html::normalizarTexto($negrito->textContent) : null;

            if ($rotulo !== null && str_ends_with($rotulo, ':')) {
                $chave = mb_strtolower(rtrim($rotulo, ': '));
                $valor = $this->valorAposRotulo($texto ?? '', $rotulo);
                if (!array_key_exists($chave, $campos[$secao] ?? [])) {
                    $campos[$secao][$chave] = $valor;
                }
                // Rótulo sem valor na mesma célula (ex.: atividade econômica): o valor vem na próxima célula.
                $aguardandoValor = $valor === null ? [$secao, $chave] : null;
                continue;
            }

            if ($rotulo !== null) {
                // Subtítulo em negrito sem ":" encerra qualquer espera por valor.
                $aguardandoValor = null;
                continue;
            }

            if ($texto !== null && $aguardandoValor !== null) {
                [$secaoPendente, $chavePendente] = $aguardandoValor;
                if ($campos[$secaoPendente][$chavePendente] === null) {
                    $campos[$secaoPendente][$chavePendente] = $texto;
                }
                $aguardandoValor = null;
            }
        }

        return $campos;
    }

    private function valorAposRotulo(string $texto, string $rotulo): ?string
    {
        $posicao = mb_strpos($texto, $rotulo);
        $valor = $posicao === false ? $texto : mb_substr($texto, $posicao + mb_strlen($rotulo));

        return Html::normalizarTexto($valor);
    }

    private function cnpj(?string $valor): string
    {
        $digitos = preg_replace('/\D+/', '', $valor ?? '') ?? '';
        if (strlen($digitos) !== 14) {
            throw new RespostaInesperadaException(
                sprintf('CNPJ publicado pelo portal em formato inesperado: "%s".', $valor ?? '')
            );
        }

        return $digitos;
    }

    /**
     * O portal publica a inscrição com máscara e sufixos, ex.: "000.478.696 NO (Inscrição Única)".
     * Mantém apenas os dígitos do número, preservando zeros à esquerda.
     */
    private function inscricaoEstadual(?string $valor): ?string
    {
        if ($valor === null || preg_match('/^\d[\d.\/-]*/', $valor, $m) !== 1) {
            return null;
        }

        return preg_replace('/\D+/', '', $m[0]);
    }

    /**
     * Compõe o endereço do estabelecimento (não o de correspondência nem o do contador):
     * "LOGRADOURO, NÚMERO, COMPLEMENTO - BAIRRO - MUNICÍPIO/UF - CEP 00000-000".
     * Partes ausentes são omitidas; sem nenhuma parte, retorna null.
     *
     * @param array<string, string|null> $empresa
     */
    private function endereco(array $empresa): ?string
    {
        $preenchido = static fn (?string $parte): bool => $parte !== null && $parte !== '';

        $linha = implode(', ', array_filter([
            $empresa['logradouro'] ?? null,
            $empresa['número'] ?? null,
            $empresa['complemento'] ?? null,
        ], $preenchido));

        $municipio = $empresa['município'] ?? null;
        $uf = $empresa['uf'] ?? null;
        $localidade = $municipio !== null && $uf !== null ? "{$municipio}/{$uf}" : ($municipio ?? $uf);

        $cep = $empresa['cep'] ?? null;

        $partes = array_filter([
            $linha,
            $empresa['bairro/distrito'] ?? null,
            $localidade,
            $cep !== null ? "CEP {$cep}" : null,
        ], $preenchido);

        return $partes === [] ? null : implode(' - ', $partes);
    }
}
