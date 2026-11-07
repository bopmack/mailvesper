<?php

namespace App\Services\Contacts;

/** Streams a CSV file: detects the delimiter, strips a UTF-8 BOM, and can resume from a byte offset. */
class CsvReader
{
    /** @var resource */
    private $fh;

    private string $delimiter;

    public function __construct(string $path)
    {
        $fh = fopen($path, 'r');
        if ($fh === false) {
            throw new \RuntimeException('Cannot open the uploaded file.');
        }
        $this->fh = $fh;
        $first = (string) fgets($fh);
        rewind($fh);
        $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
        arsort($counts);
        $this->delimiter = (string) array_key_first($counts);
    }

    public function delimiter(): string
    {
        return $this->delimiter;
    }

    /** @return list<string> */
    public function header(): array
    {
        rewind($this->fh);
        $row = $this->row() ?? [];
        if (isset($row[0])) {
            $row[0] = (string) preg_replace('/^\xEF\xBB\xBF/', '', $row[0]);
        }

        return array_map(fn ($c) => trim((string) $c), $row);
    }

    /** @return list<string>|null the next row, or null at the end */
    public function row(): ?array
    {
        while (($r = fgetcsv($this->fh, null, $this->delimiter, '"', '')) !== false) {
            if ($r === [null] || $r === ['']) {
                continue; // blank line
            }

            return array_map(fn ($c) => $this->utf8((string) $c), $r);
        }

        return null;
    }

    public function seek(int $offset): void
    {
        fseek($this->fh, $offset);
    }

    public function offset(): int
    {
        return (int) ftell($this->fh);
    }

    /** Excel on Windows often saves Latin-1; convert so names keep their accents. */
    private function utf8(string $s): string
    {
        return mb_check_encoding($s, 'UTF-8') ? $s : (string) mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }

    public function __destruct()
    {
        if (is_resource($this->fh)) {
            fclose($this->fh);
        }
    }
}
