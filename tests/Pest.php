<?php

declare(strict_types=1);

/** Bytes de uma fixture sintética em tests/Fixtures/sefaz-ba. */
function fixture(string $nome): string
{
    $caminho = __DIR__ . '/Fixtures/sefaz-ba/' . $nome;
    $conteudo = file_get_contents($caminho);

    if ($conteudo === false) {
        throw new RuntimeException("Fixture não encontrada: {$caminho}");
    }

    return $conteudo;
}
