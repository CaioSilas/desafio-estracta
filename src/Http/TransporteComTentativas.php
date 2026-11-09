<?php

declare(strict_types=1);

namespace ProvaSpider\Http;

use Closure;
use InvalidArgumentException;
use ProvaSpider\Exception\PortalIndisponivelException;

/**
 * Decorador que repete apenas falhas recuperáveis: indisponibilidade de conexão
 * (inclui tempo limite) e status transitórios 502, 503 e 504. Demais respostas e
 * exceções são repassadas imediatamente. A consulta é somente leitura, então
 * repetir o POST não tem efeito colateral.
 */
final class TransporteComTentativas implements HttpTransport
{
    public const STATUS_TRANSITORIOS = [502, 503, 504];

    private readonly Closure $aguardar;

    /**
     * @param int $maxTentativas Total de tentativas, incluindo a primeira.
     * @param int $esperaInicialMs Espera antes da 2ª tentativa; dobra a cada nova tentativa.
     * @param callable|null $aguardar Recebe a espera em milissegundos; substituível nos testes.
     */
    public function __construct(
        private readonly HttpTransport $transporte,
        public readonly int $maxTentativas = 3,
        public readonly int $esperaInicialMs = 1000,
        ?callable $aguardar = null,
    ) {
        if ($maxTentativas < 1) {
            throw new InvalidArgumentException('maxTentativas deve ser ao menos 1.');
        }

        $this->aguardar = $aguardar !== null
            ? Closure::fromCallable($aguardar)
            : static function (int $ms): void {
                usleep($ms * 1000);
            };
    }

    public function enviar(HttpRequest $requisicao): HttpResponse
    {
        for ($tentativa = 1; ; $tentativa++) {
            try {
                $resposta = $this->transporte->enviar($requisicao);
            } catch (PortalIndisponivelException $e) {
                if ($tentativa >= $this->maxTentativas) {
                    throw $e;
                }
                ($this->aguardar)($this->espera($tentativa));
                continue;
            }

            if ($tentativa >= $this->maxTentativas || !in_array($resposta->status, self::STATUS_TRANSITORIOS, true)) {
                return $resposta;
            }

            ($this->aguardar)($this->espera($tentativa));
        }
    }

    private function espera(int $tentativa): int
    {
        return $this->esperaInicialMs * (2 ** ($tentativa - 1));
    }
}
