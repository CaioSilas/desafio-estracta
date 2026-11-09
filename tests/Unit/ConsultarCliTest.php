<?php

declare(strict_types=1);

/**
 * Executa bin/consultar.php em um processo PHP separado.
 *
 * @return array{codigo: int, saida: string, erro: string}
 */
function executarCli(string ...$argumentos): array
{
    $comando = array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/bin/consultar.php'], $argumentos);
    $processo = proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $saida = stream_get_contents($pipes[1]);
    $erro = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['codigo' => proc_close($processo), 'saida' => (string) $saida, 'erro' => (string) $erro];
}

it('escreve o uso em stderr e sai com código 1 sem argumento', function () {
    $resultado = executarCli();

    expect($resultado['codigo'])->toBe(1)
        ->and($resultado['saida'])->toBe('')
        ->and($resultado['erro'])->toContain('Uso: php bin/consultar.php <cnpj>');
});

it('rejeita CNPJ com formato inválido antes de acessar a rede', function () {
    $resultado = executarCli('123');

    expect($resultado['codigo'])->toBe(1)
        ->and($resultado['saida'])->toBe('')
        ->and($resultado['erro'])->toContain('O CNPJ deve ter 14 dígitos.');
});
