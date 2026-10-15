<?php

namespace App\Services;

use InvalidArgumentException;

class QrisDynamicService
{
    public function inspectStatic(string $payload): array
    {
        // Spasi di nama merchant adalah bagian dari nilai TLV dan tidak boleh
        // dihapus. Hanya karakter pemisah hasil scanner yang dibersihkan.
        $payload = strtoupper((string) preg_replace('/[\r\n\t\x00]+/', '', trim($payload)));
        if (! str_starts_with($payload, '000201') || strlen($payload) < 20) {
            throw new InvalidArgumentException('Payload QRIS tidak valid.');
        }

        $elements = $this->parse($payload);
        $tags = array_column($elements, 'tag');
        foreach (['00', '01', '52', '53', '58', '59', '60', '63'] as $tag) {
            if (! in_array($tag, $tags, true)) {
                throw new InvalidArgumentException('Struktur QRIS tidak lengkap.');
            }
        }
        if (($this->value($elements, '01') ?? '') !== '11') {
            throw new InvalidArgumentException('Upload QRIS statis yang belum memiliki nominal.');
        }
        if (! collect($elements)->contains(fn (array $item): bool => (int) $item['tag'] >= 26 && (int) $item['tag'] <= 51)) {
            throw new InvalidArgumentException('Informasi merchant QRIS tidak ditemukan.');
        }

        $declaredCrc = $this->value($elements, '63');
        if ($declaredCrc === null || ! hash_equals($this->crc16(substr($payload, 0, -4)), $declaredCrc)) {
            throw new InvalidArgumentException('Checksum QRIS tidak valid. Upload QRIS asli dari merchant.');
        }

        return [
            'payload' => $payload,
            'merchant_name' => $this->value($elements, '59') ?? '',
            'merchant_city' => $this->value($elements, '60') ?? '',
        ];
    }

    public function convert(string $staticPayload, int $amount): string
    {
        if ($amount < 1) {
            throw new InvalidArgumentException('Nominal QRIS tidak valid.');
        }

        $template = $this->inspectStatic($staticPayload);
        $elements = $this->parse($template['payload']);
        $result = [];
        $amountInserted = false;

        foreach ($elements as $element) {
            if (in_array($element['tag'], ['54', '55', '56', '57', '63'], true)) {
                continue;
            }
            if ($element['tag'] === '01') {
                $result[] = ['tag' => '01', 'value' => '12'];
                continue;
            }
            if ($element['tag'] === '58' && ! $amountInserted) {
                $result[] = ['tag' => '54', 'value' => (string) $amount];
                $amountInserted = true;
            }
            $result[] = $element;
        }

        if (! $amountInserted) {
            throw new InvalidArgumentException('Tag nominal QRIS tidak dapat dibuat.');
        }

        $content = collect($result)
            ->map(fn (array $element): string => $element['tag'].str_pad((string) strlen($element['value']), 2, '0', STR_PAD_LEFT).$element['value'])
            ->implode('');
        $crcInput = $content.'6304';

        return $crcInput.$this->crc16($crcInput);
    }

    private function parse(string $payload): array
    {
        $elements = [];
        $position = 0;
        $length = strlen($payload);
        while ($position < $length) {
            if ($position + 4 > $length) {
                throw new InvalidArgumentException('Format TLV QRIS tidak valid.');
            }
            $tag = substr($payload, $position, 2);
            $valueLength = substr($payload, $position + 2, 2);
            if (! ctype_digit($tag) || ! ctype_digit($valueLength)) {
                throw new InvalidArgumentException('Format TLV QRIS tidak valid.');
            }
            $valueLength = (int) $valueLength;
            $valueStart = $position + 4;
            if ($valueStart + $valueLength > $length) {
                throw new InvalidArgumentException('Panjang payload QRIS tidak valid.');
            }
            $elements[] = [
                'tag' => $tag,
                'value' => substr($payload, $valueStart, $valueLength),
            ];
            $position = $valueStart + $valueLength;
        }

        return $elements;
    }

    private function value(array $elements, string $tag): ?string
    {
        foreach ($elements as $element) {
            if ($element['tag'] === $tag) return $element['value'];
        }

        return null;
    }

    private function crc16(string $value): string
    {
        $crc = 0xffff;
        for ($i = 0, $length = strlen($value); $i < $length; $i++) {
            $crc ^= ord($value[$i]) << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000)
                    ? (($crc << 1) ^ 0x1021) & 0xffff
                    : ($crc << 1) & 0xffff;
            }
        }

        return str_pad(strtoupper(dechex($crc & 0xffff)), 4, '0', STR_PAD_LEFT);
    }
}
