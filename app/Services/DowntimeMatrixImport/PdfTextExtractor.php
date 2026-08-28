<?php

namespace App\Services\DowntimeMatrixImport;

use Smalot\PdfParser\Parser;
use Smalot\PdfParser\XObject\Form;

/**
 * Extracts every text-show operation's absolute position (x, y) and
 * reconstructed text from page 1 of a PDF, including text rendered inside
 * Form XObjects.
 *
 * Deliberately does NOT reuse Smalot\PdfParser\Page::getDataTm(): that
 * method only walks a page's own direct content stream commands, it does
 * not recurse into Form XObjects ('Do' operator invocations). The real
 * BFI/BVA sample PDF renders its entire table inside one such Form
 * XObject (confirmed empirically - the page's own getDataTm() returns
 * only the letterhead/notes/signature text, none of the table). This
 * class instead runs its own minimal content-stream tokenizer against
 * both the page's direct content and every Form XObject on it, so table
 * text is never silently missed regardless of which content stream it
 * physically lives in.
 *
 * The tokenizer handles the operator subset actually observed in this
 * document: absolute "a b c d e f Tm" positioning followed by a
 * "[...] TJ" text-show array. No Td/cm/Tj (relative positioning, or the
 * single-string-literal Tj form) is used anywhere in the sample PDF's
 * content streams - a future PDF that uses them would need this class
 * extended, and GridReconstructor is designed to fail loudly (via a
 * thrown exception) rather than silently produce a wrong grid if too few
 * recognizable fragments come back.
 */
class PdfTextExtractor
{
    /**
     * @return array<int, array{text: string, x: float, y: float}>
     */
    public function extractFragments(string $absoluteFilePath): array
    {
        $parser = new Parser();
        $document = $parser->parseFile($absoluteFilePath);
        $pages = $document->getPages();

        if (empty($pages)) {
            throw new \RuntimeException('The PDF has no pages.');
        }

        $page = $pages[0];

        $fragments = $this->extractFromContentStream((string) $page->getContent());

        $seen = [];
        foreach ($page->getXObjects() as $xobject) {
            if (!$xobject instanceof Form) {
                continue;
            }

            $hash = spl_object_id($xobject);
            if (isset($seen[$hash])) {
                continue;
            }
            $seen[$hash] = true;

            $fragments = array_merge($fragments, $this->extractFromContentStream((string) $xobject->getContent()));
        }

        return $fragments;
    }

    /**
     * @return array<int, array{text: string, x: float, y: float}>
     */
    private function extractFromContentStream(string $content): array
    {
        $fragments = [];

        $pattern = '/(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+Tm.*?\[(.*?)\]\s*TJ/s';

        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        foreach ($matches as $match) {
            $x = (float) $match[5];
            $y = (float) $match[6];
            $text = $this->decodeTextShowArray($match[7]);

            if (trim($text) === '') {
                continue;
            }

            $fragments[] = ['text' => $text, 'x' => $x, 'y' => $y];
        }

        return $fragments;
    }

    private function decodeTextShowArray(string $arrayContent): string
    {
        $text = '';

        if (preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)/', $arrayContent, $stringMatches) === false) {
            return '';
        }

        foreach ($stringMatches[1] as $literal) {
            $text .= $this->decodePdfStringLiteral($literal);
        }

        return $text;
    }

    private function decodePdfStringLiteral(string $literal): string
    {
        $decoded = preg_replace_callback('/\\\\([nrtbf()\\\\]|[0-7]{1,3})/', function (array $m) {
            return match ($m[1]) {
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                'b' => "\x08",
                'f' => "\x0C",
                '(' => '(',
                ')' => ')',
                '\\' => '\\',
                default => chr(intval($m[1], 8)),
            };
        }, $literal);

        return $decoded ?? $literal;
    }
}
