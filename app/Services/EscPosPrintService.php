<?php

namespace App\Services;

use App\Models\Printer;
use Illuminate\Support\Str;

class EscPosPrintService
{
    protected string $exePath;

    public function __construct()
    {
        $this->exePath = storage_path('scripts/render_receipt.exe');
    }

    public function cleanLine(string $text): string
    {
        $text = preg_replace('/[\x1B\x1D][\x00-\x7F]{1,4}/u', '', $text) ?? preg_replace('/[\x1B\x1D].{1,4}/s', '', $text);
        $text = preg_replace('/[\x00-\x08\x0B-\x1F\x7F-\x9F]/u', '', $text) ?? $text;

        return trim($text);
    }

    public function preparePayload(
        Printer $printer,
        string $text,
        string $documentTitle = '',
        string $documentSubtitle = '',
        bool $forceRaster = false
    ): string {
        return $this->renderTextToEscPosRaster($printer, $text, $documentTitle, $documentSubtitle);
    }

    public function renderTextToEscPosRaster(
        Printer $printer,
        string $text,
        string $documentTitle = '',
        string $documentSubtitle = ''
    ): string {
        $is58mm = $printer->paper_size === '58mm';

        $documentTitle = $this->cleanLine($documentTitle);
        $documentSubtitle = $this->cleanLine($documentSubtitle);

        $rawLines = explode("\n", str_replace("\r", '', $text));

        $firstLine = $this->cleanLine($rawLines[0] ?? '');
        if (! empty($documentTitle) && str_contains(strtolower($firstLine), strtolower($documentTitle))) {
            $documentTitle = '';
        }

        $linesData = [];

        if (! empty($documentTitle)) {
            $linesData[] = [
                'type' => 'text',
                'text' => $documentTitle,
                'font_size' => $is58mm ? 20 : 26,
                'is_bold' => true,
                'is_center' => true,
            ];
        }

        if (! empty($documentSubtitle)) {
            $linesData[] = [
                'type' => 'text',
                'text' => $documentSubtitle,
                'font_size' => $is58mm ? 16 : 20,
                'is_bold' => true,
                'is_center' => true,
            ];
        }

        $inHeaderZone = true;
        foreach ($rawLines as $line) {
            $line = rtrim($line);
            $cleanText = $this->cleanLine($line);

            if (preg_match('/^(=+|-+)$/', $cleanText)) {
                $inHeaderZone = false;
                $linesData[] = ['type' => 'divider', 'left' => $cleanText[0]];
                continue;
            }

            if ($cleanText === '') {
                $linesData[] = ['type' => 'blank'];
                continue;
            }

            // 1. Check 4-Column Table Header: ITEM QTY PRICE AMOUNT
            if (preg_match('/^ITEM(\t|\s{2,})QTY(\t|\s{2,})PRICE(\t|\s{2,})AMOUNT$/ui', $cleanText)) {
                $linesData[] = [
                    'type' => 'receipt_row',
                    'item_name' => 'ITEM',
                    'qty' => 'QTY',
                    'price' => 'PRICE',
                    'amount' => 'AMOUNT',
                    'is_bold' => true,
                    'font_size' => 17,
                ];
                continue;
            }

            // 2. Check 4-Column Item Rows: Item Name, Qty, Price, Amount
            if (preg_match('/^(.+?)(\t|\s{2,})([+\-]?\d+(?:\.\d+)?|QTY)(\t|\s{2,})([+\-]?[0-9,\.]+)(\t|\s{2,})([+\-]?[0-9,\.]+)$/u', $cleanText, $m4)) {
                $linesData[] = [
                    'type' => 'receipt_row',
                    'item_name' => trim($m4[1]),
                    'qty' => trim($m4[3]),
                    'price' => trim($m4[5]),
                    'amount' => trim($m4[7]),
                    'font_size' => 17,
                ];
                continue;
            }

            // 3. Check Metadata Rows (Key : Value alignment)
            if (preg_match('/^(Order No|Order|Type|Table|Waiter|Cashier|Guest|Customer|Pax|Staff|Time|Date|Invoice|Partner|Pickup|Address)\s*:\s*(.+)$/ui', $cleanText, $mMeta)) {
                $linesData[] = [
                    'type' => 'meta_row',
                    'left' => trim($mMeta[1]),
                    'right' => trim($mMeta[2]),
                ];
                continue;
            }

            // 4. Check Totals & Summary Breakdown Rows (Subtotal, Discount, Service Charge, Tax, TOTAL AMOUNT, Cash, Change, etc.)
            if (preg_match('/^(Subtotal|TOTAL AMOUNT|TOTAL|Discount.*|Tax.*|Commercial Tax.*|Service Charge.*|Service.*|Change.*|Balance.*|Paid.*|Cash.*|Payment.*|[\p{L}\p{N}\s%\(\)\-]+):\s*(.+)$/ui', $cleanText, $mTot)) {
                $label = trim($mTot[1]).':';
                $val = trim($mTot[2]);
                $isGrandTotal = str_contains(strtoupper($label), 'TOTAL');

                $linesData[] = [
                    'type' => 'item_row',
                    'left' => $label,
                    'right' => $val,
                    'is_bold' => $isGrandTotal,
                    'font_size' => 17,
                ];
                continue;
            }

            // 5. Kitchen 2-Column Item Rows (ITEM \t QTY)
            if (preg_match('/^(.+?)(\t|\s{2,})([+\-]?\d+(?:\.\d+)?|QTY)\s*$/ui', $cleanText, $matches)) {
                $leftPart = trim($matches[1]);
                $rightPart = trim($matches[3]);

                $linesData[] = [
                    'type' => 'item_row',
                    'left' => $leftPart,
                    'right' => $rightPart,
                ];
                continue;
            }

            // 6. Generic Text Line
            $isVoucherTitle = str_contains($cleanText, 'VOUCHER') || Str::startsWith($cleanText, '***');
            $isTitle = preg_match('/^===.*===$/u', $cleanText) || str_contains($cleanText, 'KITCHEN') || str_contains($cleanText, 'ORDER') || str_contains($cleanText, 'BILL') || str_contains($cleanText, 'BRANCH') || str_contains($cleanText, 'OUTLET');
            $isMeta = preg_match('/^(Order|Table|Type|Pax|Staff|Time|Customer|Partner|Pickup|Reason|ALL ITEMS):/ui', $cleanText);
            $isHeader = preg_match('/^>>\s*(ADDED|CANCELLED)\s*<</ui', $cleanText);
            $isIndented = Str::startsWith($line, '  ') || Str::startsWith($cleanText, '+ ') || Str::startsWith($cleanText, 'Note:');
            $isCenter = $inHeaderZone || $isTitle || $isVoucherTitle || Str::contains(strtolower($cleanText), 'thank you') || Str::contains(strtolower($cleanText), 'please visit') || Str::contains(strtolower($cleanText), 'tel:');

            $fSize = $isVoucherTitle ? 15 : ($inHeaderZone ? 18 : ($isTitle ? ($is58mm ? 18 : 22) : ($isHeader ? ($is58mm ? 15 : 18) : ($isMeta ? ($is58mm ? 14 : 17) : 17))));

            $linesData[] = [
                'type' => 'text',
                'text' => $cleanText,
                'font_size' => $fSize,
                'is_bold' => $inHeaderZone ? true : ($isVoucherTitle ? false : ($isTitle || $isHeader)),
                'is_center' => $isCenter,
                'is_indented' => $isIndented,
            ];
        }

        $payloadJson = json_encode([
            'paper_size' => $printer->paper_size ?? '80mm',
            'lines' => $linesData,
        ], JSON_UNESCAPED_UNICODE);

        if (file_exists($this->exePath)) {
            $descriptorspec = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = proc_open(escapeshellarg($this->exePath), $descriptorspec, $pipes);

            if (is_resource($process)) {
                fwrite($pipes[0], $payloadJson);
                fclose($pipes[0]);

                $binaryPayload = stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                fclose($pipes[2]);

                proc_close($process);

                if (! empty($binaryPayload)) {
                    return $binaryPayload;
                }
            }
        }

        return $this->fallbackEscPosPayload($printer, $text, $documentTitle, $documentSubtitle);
    }

    public function fallbackEscPosPayload(
        Printer $printer,
        string $text,
        string $documentTitle = 'ORDER KITCHEN TICKET',
        string $documentSubtitle = 'TICKET'
    ): string {
        $paperWidth = $printer->paper_size === '58mm' ? 32 : 42;
        $titleText = ! empty($documentTitle) ? $documentTitle : 'ORDER KITCHEN TICKET';

        $header = "\x1B\x40"
            ."\x1B\x61\x01"
            ."\x1B\x21\x30"
            ."*** ".$titleText." ***\n"
            ."\x1B\x21\x00"
            .str_repeat('=', $paperWidth)."\n"
            ."\x1B\x61\x00";

        $footer = "\x1B\x61\x01"
            ."\n"
            .str_repeat('=', $paperWidth)."\n"
            ."\x1B\x21\x00"
            ."\x1B\x61\x00"
            ."\n\n\n\n"
            ."\x1D\x56\x00";

        return $header.$text."\n".$footer;
    }
}
