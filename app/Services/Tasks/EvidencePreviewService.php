<?php

namespace App\Services\Tasks;

use DOMDocument;
use DOMXPath;
use ZipArchive;

class EvidencePreviewService
{
    public function documentHtml(string $path, string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $lines = $this->extractLines($path, $extension);
        $title = htmlspecialchars($filename, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $content = $lines === []
            ? '<div class="empty">A readable text preview could not be generated for this document.</div>'
            : implode('', array_map(
                fn (string $line) => '<p>'.htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p>',
                $lines,
            ));

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>'.$title.'</title><style>body{margin:0;background:#f1f5f9;color:#334155;font:15px/1.65 Arial,sans-serif}'
            .'.page{box-sizing:border-box;max-width:850px;min-height:100vh;margin:0 auto;padding:48px 56px;background:#fff;box-shadow:0 8px 32px rgba(15,23,42,.1)}'
            .'h1{margin:0 0 28px;padding-bottom:14px;border-bottom:1px solid #e2e8f0;color:#0f172a;font-size:18px}p{margin:0 0 12px;white-space:pre-wrap}'
            .'.empty{padding:48px 20px;text-align:center;color:#64748b}@media(max-width:640px){.page{padding:28px 22px}}</style></head>'
            .'<body><main class="page"><h1>'.$title.'</h1>'.$content.'</main></body></html>';
    }

    /** @return list<string> */
    private function extractLines(string $path, string $extension): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return [];
        }

        $entries = match ($extension) {
            'docx' => ['word/document.xml'],
            'xlsx' => ['xl/sharedStrings.xml'],
            'pptx' => $this->matchingEntries($zip, '#^ppt/slides/slide\d+\.xml$#'),
            default => [],
        };

        $lines = [];
        foreach ($entries as $entry) {
            $stat = $zip->statName($entry);
            if ($stat === false || ($stat['size'] ?? 0) > 5 * 1024 * 1024) {
                continue;
            }

            $xml = $zip->getFromName($entry);
            if (! is_string($xml)) {
                continue;
            }

            $lines = [...$lines, ...$this->xmlLines($xml, $extension)];
            if (count($lines) >= 2000) {
                break;
            }
        }

        $zip->close();

        return array_slice($lines, 0, 2000);
    }

    /** @return list<string> */
    private function matchingEntries(ZipArchive $zip, string $pattern): array
    {
        $entries = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (is_string($name) && preg_match($pattern, $name) === 1) {
                $entries[] = $name;
            }
        }

        natsort($entries);

        return array_values($entries);
    }

    /** @return list<string> */
    private function xmlLines(string $xml, string $extension): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return [];
        }

        $xpath = new DOMXPath($document);
        $containers = $extension === 'xlsx'
            ? $xpath->query('//*[local-name()="si"]')
            : $xpath->query('//*[local-name()="p"]');
        $lines = [];

        foreach ($containers ?: [] as $container) {
            $parts = [];
            foreach ($xpath->query('.//*[local-name()="t"]', $container) ?: [] as $textNode) {
                $parts[] = $textNode->textContent;
            }
            $line = trim(implode('', $parts));
            if ($line !== '') {
                $lines[] = mb_substr($line, 0, 5000);
            }
        }

        return $lines;
    }
}
