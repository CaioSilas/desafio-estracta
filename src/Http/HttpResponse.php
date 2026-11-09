<?php

declare(strict_types=1);

namespace ProvaSpider\Http;

/**
 * Resposta final de uma requisição, após eventuais redirecionamentos.
 * O corpo é mantido em bytes: a decodificação de caracteres é feita na extração.
 */
final class HttpResponse
{
    /** @var array<string, string> */
    public readonly array $cabecalhos;

    /**
     * @param string $urlEfetiva URL que produziu esta resposta (a última, se houve redirecionamento).
     * @param array<string, string> $cabecalhos Nomes em qualquer caixa; normalizados para minúsculas.
     */
    public function __construct(
        public readonly int $status,
        public readonly string $corpo,
        public readonly string $urlEfetiva,
        array $cabecalhos = [],
    ) {
        $this->cabecalhos = array_change_key_case($cabecalhos, CASE_LOWER);
    }

    public function cabecalho(string $nome): ?string
    {
        return $this->cabecalhos[strtolower($nome)] ?? null;
    }
}
