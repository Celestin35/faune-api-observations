<?php

namespace App\Services\Biodiversity\Taxref;

use RuntimeException;

final class CanonicalizationCsvWriter
{
    /** @var resource */
    private $handle;

    /** @param list<string> $headers */
    public function __construct(string $path, array $headers)
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Impossible d’écrire le rapport CSV : {$path}");
        }
        $this->handle = $handle;
        fwrite($this->handle, "\xEF\xBB\xBF");
        fputcsv($this->handle, $headers, ',', '"', '');
    }

    /** @param list<int|float|string|null> $row */
    public function row(array $row): void
    {
        fputcsv($this->handle, $row, ',', '"', '');
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
