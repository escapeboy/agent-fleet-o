<?php

namespace App\Domain\Memory\Services;

class DocumentTextExtractor
{
    /**
     * Extract text content from a file based on its MIME type.
     *
     * Supported: PDF, TXT, MD, CSV.
     */
    public function extract(string $filePath, string $mimeType): string
    {
        return match (true) {
            str_contains($mimeType, 'pdf') => $this->extractPdf($filePath),
            str_contains($mimeType, 'csv') => $this->extractCsv($filePath),
            default => file_get_contents($filePath), // TXT, MD, plain text
        };
    }

    private function extractPdf(string $filePath): string
    {
        if (! class_exists(\Smalot\PdfParser\Parser::class)) {
            throw new \RuntimeException('PDF parsing requires smalot/pdfparser. Install with: composer require smalot/pdfparser');
        }

        $parser = new \Smalot\PdfParser\Parser;
        $pdf = $parser->parseFile($filePath);

        return $pdf->getText();
    }

    private function extractCsv(string $filePath): string
    {
        $handle = fopen($filePath, 'r');
        if (! $handle) {
            throw new \RuntimeException('Could not open CSV file.');
        }

        $headers = fgetcsv($handle);
        if (! $headers) {
            fclose($handle);

            return '';
        }

        $lines = [];
        while (($row = fgetcsv($handle)) !== false) {
            // Handle rows with different column counts gracefully
            if (count($row) === count($headers)) {
                $lines[] = implode(', ', array_combine($headers, $row));
            } else {
                $lines[] = implode(', ', $row);
            }
        }

        fclose($handle);

        return implode("\n", $lines);
    }
}
