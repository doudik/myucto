<?php

declare(strict_types=1);

namespace MyInvoice\Service\Stock;

/**
 * Umístění skladové karty (`stock_items.location_text`, migrace 2026): volný text,
 * kde kus fyzicky je. Jediné pravidlo pro formulář karty, editor produktu i import
 * katalogu: ořezat mezery, prázdný text = null, nejvýš {@see MAX_LENGTH} znaků.
 */
final class StockItemLocation
{
    public const MAX_LENGTH = 100;

    /**
     * Normalizuje hodnotu z požadavku. Klíč, který v `$input` není, se nevrací,
     * takže úprava bez něj umístění nemění.
     *
     * @param array<string,mixed> $input
     * @return array{location_text?:?string}
     * @throws \InvalidArgumentException
     */
    public static function fromInput(array $input): array
    {
        if (!array_key_exists('location_text', $input)) {
            return [];
        }
        return ['location_text' => self::normalize($input['location_text'])];
    }

    /** @throws \InvalidArgumentException */
    public static function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_scalar($value)) {
            throw new \InvalidArgumentException('Umístění musí být text.');
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > self::MAX_LENGTH || preg_match('/[\x00-\x1F\x7F]/', $text) === 1) {
            throw new \InvalidArgumentException('Umístění má nejvýš ' . self::MAX_LENGTH . ' znaků na jednom řádku.');
        }
        return $text;
    }
}
