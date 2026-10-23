<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * 取込ファイルの CSV を読む。文字コードは UTF-8（BOM あり・なし）と Shift_JIS（CP932）を受け付け、
 * 読めないファイルや必須列の不足は、ファイル名を付けた検証エラー（files）にする。
 */
class CsvFileReader
{
    /**
     * CSVを1行ずつ読む。ファイル全体を一度にメモリへ読み込まない。キーは行番号（ヘッダーが1行目）。
     *
     * @param  array<int, string|array<int, string>>  $requiredHeaders
     * @return \Generator<int, array<string, string>>
     */
    public function rows(UploadedFile $file, array $requiredHeaders, string $delimiter = ','): \Generator
    {
        $stream = $this->openCsv($file);
        try {
            $headers = fgetcsv($stream, separator: $delimiter);
            if ($headers === false) {
                throw ValidationException::withMessages(['files' => [$file->getClientOriginalName().'にヘッダーがありません。']]);
            }

            $headers = array_map($this->normalize(...), $headers);
            foreach ($requiredHeaders as $required) {
                $alternatives = (array) $required;
                if (count(array_intersect($alternatives, $headers)) === 0) {
                    throw ValidationException::withMessages([
                        'files' => [$file->getClientOriginalName().'に必須列「'.implode(' または ', $alternatives).'」がありません。'],
                    ]);
                }
            }

            $rowNumber = 1;
            while (($values = fgetcsv($stream, separator: $delimiter)) !== false) {
                $rowNumber++;
                if ($values === [null] || $values === []) {
                    continue;
                }
                $values = array_pad($values, count($headers), '');

                yield $rowNumber => array_combine($headers, array_slice($values, 0, count($headers)));
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * 種類の判定のため、ヘッダー行だけを読む。文字コードは取込本体と同じくファイル全体で判定する。
     *
     * @return array<int, string|null>|false
     */
    public function headerRow(UploadedFile $file): array|false
    {
        $stream = $this->openFile($file);
        try {
            if ($this->isUtf8($stream)) {
                rewind($stream);

                return fgetcsv($stream, separator: ',');
            }
            rewind($stream);
            $line = fgets($stream);
            if ($line === false) {
                return false;
            }
            $header = fopen('php://memory', 'r+');
            fwrite($header, mb_convert_encoding($line, 'UTF-8', 'SJIS-win'));
            rewind($header);
            $row = fgetcsv($header, separator: ',');
            fclose($header);

            return $row;
        } finally {
            fclose($stream);
        }
    }

    /**
     * CSVを読むストリームを開く。UTF-8 でなければ Shift_JIS（CP932）として、行の区切りで分けた塊ごとに UTF-8 に変換した一時ストリームを返す。
     * 一時ストリームは 2 MiB を超えると一時ファイルに移るので、ファイル全体をメモリに持たない。
     * 改行のバイト（0x0A）は UTF-8 の多バイト文字にも Shift_JIS の2バイト目にも現れないので、行で区切って変換しても結果は変わらない。
     *
     * @return resource
     */
    private function openCsv(UploadedFile $file)
    {
        $source = $this->openFile($file);
        if ($this->isUtf8($source)) {
            rewind($source);

            return $source;
        }

        rewind($source);
        $converted = fopen('php://temp', 'r+');
        foreach ($this->lineBlocks($source) as $block) {
            fwrite($converted, mb_convert_encoding($block, 'UTF-8', 'SJIS-win'));
        }
        fclose($source);
        rewind($converted);

        return $converted;
    }

    /** @return resource */
    private function openFile(UploadedFile $file)
    {
        $stream = @fopen($file->getRealPath(), 'r');
        if ($stream === false) {
            throw ValidationException::withMessages(['files' => [$file->getClientOriginalName().'を読み込めません。']]);
        }

        return $stream;
    }

    /** @param resource $stream ファイル全体が UTF-8 として正しいか、行の区切りで分けた塊ごとに確かめる */
    private function isUtf8($stream): bool
    {
        foreach ($this->lineBlocks($stream) as $block) {
            if (! mb_check_encoding($block, 'UTF-8')) {
                return false;
            }
        }

        return true;
    }

    /**
     * ストリームを約1 MiBずつ、改行の直後で区切って読む（最後の塊は改行で終わらないことがある）。
     *
     * @param  resource  $stream
     * @return \Generator<int, string>
     */
    private function lineBlocks($stream): \Generator
    {
        $carry = '';
        while (! feof($stream)) {
            $chunk = fread($stream, 1024 * 1024);
            if ($chunk === false) {
                break;
            }
            $buffer = $carry.$chunk;
            $end = strrpos($buffer, "\n");
            if ($end === false) {
                $carry = $buffer;

                continue;
            }
            $carry = substr($buffer, $end + 1);

            yield substr($buffer, 0, $end + 1);
        }
        if ($carry !== '') {
            yield $carry;
        }
    }

    public function normalize(?string $value): string
    {
        return preg_replace('/[\\x{FEFF}\\r\\n]/u', '', trim((string) $value)) ?? '';
    }
}
