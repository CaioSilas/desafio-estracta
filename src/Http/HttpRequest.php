<?php

declare(strict_types=1);

namespace ProvaSpider\Http;

/**
 * Requisição independente da biblioteca HTTP, para que o spider possa ser
 * testado com um transporte substituto.
 */
final class HttpRequest
{
    /**
     * @param array<string, string> $formulario Campos enviados como application/x-www-form-urlencoded.
     * @param array<string, string> $cabecalhos
     */
    public function __construct(
        public readonly string $metodo,
        public readonly string $url,
        public readonly array $formulario = [],
        public readonly array $cabecalhos = [],
    ) {
    }

    /** @param array<string, string> $cabecalhos */
    public static function get(string $url, array $cabecalhos = []): self
    {
        return new self('GET', $url, [], $cabecalhos);
    }

    /**
     * @param array<string, string> $formulario
     * @param array<string, string> $cabecalhos
     */
    public static function post(string $url, array $formulario, array $cabecalhos = []): self
    {
        return new self('POST', $url, $formulario, $cabecalhos);
    }
}
