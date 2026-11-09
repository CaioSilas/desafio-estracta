<?php

declare(strict_types=1);

namespace ProvaSpider\Exception;

/**
 * O portal rejeitou a entrada da consulta (por exemplo, dígito verificador do CNPJ incorreto).
 */
final class EntradaInvalidaException extends ConsultaException
{
}
