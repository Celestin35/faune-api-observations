<?php

namespace App\Services\Biodiversity\Taxref;

use RuntimeException;

final class CanonicalizationReportWriter
{
    public function __construct(private readonly string $directory)
    {
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Impossible de créer le dossier de rapports : {$directory}");
        }
    }

    public function path(string $file): string
    {
        return rtrim($this->directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$file;
    }

    /** @param array<string, mixed> $data */
    public function json(string $file, array $data): void
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($this->path($file), $encoded."\n") === false) {
            throw new RuntimeException("Impossible d’écrire le rapport {$file}.");
        }
    }

    /** @param list<string> $headers */
    public function csv(string $file, array $headers): CanonicalizationCsvWriter
    {
        return new CanonicalizationCsvWriter($this->path($file), $headers);
    }
}
