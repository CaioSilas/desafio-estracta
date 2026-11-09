<?php

declare(strict_types=1);

namespace ProvaSpider\Extracao;

use DOMDocument;

/**
 * Utilitários de decodificação e normalização de HTML.
 *
 * O portal mistura codificações: o formulário é servido em ISO-8859-1 e o resultado
 * em UTF-8, ambos sem charset no cabeçalho Content-Type. Por isso a codificação é
 * resolvida na ordem: cabeçalho HTTP, meta charset e, por fim, validação de UTF-8
 * com retorno para Windows-1252 (superconjunto prático de ISO-8859-1).
 */
final class Html
{
    public static function paraUtf8(string $bytes, ?string $contentType = null): string
    {
        $charset = self::charsetDeclarado($contentType ?? '')
            ?? self::charsetDoMeta($bytes);

        if ($charset !== null && in_array($charset, ['utf-8', 'utf8'], true) && mb_check_encoding($bytes, 'UTF-8')) {
            return $bytes;
        }

        if ($charset !== null && !in_array($charset, ['utf-8', 'utf8'], true)) {
            $origem = in_array($charset, ['iso-8859-1', 'latin1', 'us-ascii'], true) ? 'Windows-1252' : $charset;
            if (in_array(strtolower($origem), array_map('strtolower', mb_list_encodings()), true)) {
                return mb_convert_encoding($bytes, 'UTF-8', $origem);
            }
        }

        return mb_check_encoding($bytes, 'UTF-8') ? $bytes : mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
    }

    /**
     * Carrega HTML já em UTF-8. Caracteres não ASCII são convertidos em entidades
     * numéricas para que o libxml não reinterprete o texto conforme um meta charset
     * divergente da codificação real.
     */
    public static function carregar(string $htmlUtf8): DOMDocument
    {
        $ascii = mb_encode_numericentity($htmlUtf8, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');

        $dom = new DOMDocument();
        $anterior = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML($ascii, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($anterior);
        }

        return $dom;
    }

    /**
     * Converte espaços não separáveis, quebras e tabulações em espaço simples,
     * colapsa sequências e remove bordas. Texto vazio vira null.
     */
    public static function normalizarTexto(?string $texto): ?string
    {
        if ($texto === null) {
            return null;
        }

        $texto = str_replace("\u{00A0}", ' ', $texto);
        $texto = trim(preg_replace('/\s+/u', ' ', $texto) ?? '');

        return $texto === '' ? null : $texto;
    }

    private static function charsetDeclarado(string $contentType): ?string
    {
        return preg_match('/charset\s*=\s*["\']?([\w.:-]+)/i', $contentType, $m) === 1 ? strtolower($m[1]) : null;
    }

    private static function charsetDoMeta(string $bytes): ?string
    {
        $cabeca = substr($bytes, 0, 4096);

        return preg_match('/<meta[^>]+charset\s*=\s*["\']?([\w.:-]+)/i', $cabeca, $m) === 1 ? strtolower($m[1]) : null;
    }
}
