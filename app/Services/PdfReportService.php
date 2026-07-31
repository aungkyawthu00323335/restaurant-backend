<?php

namespace App\Services;

use App\Models\Location;

class PdfReportService
{
    /**
     * Generate HTML document formatted for PDF export and Myanmar Unicode font support.
     */
    public static function renderReportHtml(string $title, array $headers, array $rows, array $summary = [], ?int $outletId = null, string $dateRange = ''): string
    {
        $outlet = null;
        if ($outletId) {
            $outlet = Location::query()->find($outletId);
        }
        $outletName = $outlet?->name ?? 'ALL OUTLETS';
        $outletAddress = $outlet?->address ?? '';
        $outletPhone = $outlet?->phone ?? '';

        $headerHtml = '';
        foreach ($headers as $h) {
            $align = isset($h['align']) ? "text-align: {$h['align']};" : 'text-align: left;';
            $headerHtml .= "<th style=\"{$align}\">{$h['label']}</th>";
        }

        $bodyHtml = '';
        foreach ($rows as $row) {
            $bodyHtml .= '<tr>';
            foreach ($row as $col) {
                $align = isset($col['align']) ? "text-align: {$col['align']};" : 'text-align: left;';
                $style = isset($col['bold']) && $col['bold'] ? 'font-weight: bold;' : '';
                $val = htmlspecialchars((string) ($col['val'] ?? ''));
                $bodyHtml .= "<td style=\"{$align} {$style}\">{$val}</td>";
            }
            $bodyHtml .= '</tr>';
        }

        $summaryHtml = '';
        if (! empty($summary)) {
            $summaryHtml .= '<div class="summary-card">';
            foreach ($summary as $label => $value) {
                $summaryHtml .= "<div class=\"summary-item\"><span class=\"s-label\">{$label}:</span> <span class=\"s-val\">{$value}</span></div>";
            }
            $summaryHtml .= '</div>';
        }

        $now = date('d/m/Y H:i');

        return <<<HTML
<!DOCTYPE html>
<html lang="my">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Pyidaungsu:wght@400;700&family=Inter:wght@400;600;700&display=swap');
        body {
            font-family: 'Pyidaungsu', 'Inter', sans-serif;
            color: #0F172A;
            margin: 0;
            padding: 24px;
            background: #ffffff;
            font-size: 13px;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #E2E8F0;
            padding-bottom: 16px;
            margin-bottom: 20px;
        }
        .header h1 {
            margin: 0;
            font-size: 22px;
            color: #1E293B;
            text-transform: uppercase;
        }
        .header p {
            margin: 4px 0 0 0;
            color: #64748B;
            font-size: 12px;
        }
        .meta-bar {
            display: flex;
            justify-content: space-between;
            margin-bottom: 16px;
            font-size: 12px;
            color: #475569;
            background: #F8FAFC;
            padding: 10px 14px;
            border-radius: 6px;
            border: 1px solid #E2E8F0;
        }
        .summary-card {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            background: #EFF6FF;
            border: 1px solid #BFDBFE;
            border-radius: 8px;
            padding: 14px;
            margin-bottom: 20px;
        }
        .summary-item {
            font-size: 13px;
        }
        .s-label {
            color: #1E40AF;
            font-weight: 600;
        }
        .s-val {
            color: #1E3A8A;
            font-weight: 700;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        th {
            background-color: #F1F5F9;
            color: #334155;
            font-weight: 700;
            padding: 10px 12px;
            border-bottom: 2px solid #CBD5E1;
            font-size: 12px;
        }
        td {
            padding: 9px 12px;
            border-bottom: 1px solid #E2E8F0;
            color: #1E293B;
        }
        tr:nth-child(even) td {
            background-color: #F8FAFC;
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 11px;
            color: #94A3B8;
            border-top: 1px solid #E2E8F0;
            padding-top: 12px;
        }
        @media print {
            body { padding: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>{$outletName}</h1>
        <p>{$outletAddress} {$outletPhone}</p>
        <h2 style="margin: 12px 0 0 0; font-size: 16px; color: #2563EB;">{$title}</h2>
    </div>

    <div class="meta-bar">
        <div><strong>Date Range:</strong> {$dateRange}</div>
        <div><strong>Generated:</strong> {$now}</div>
    </div>

    {$summaryHtml}

    <table>
        <thead>
            <tr>
                {$headerHtml}
            </tr>
        </thead>
        <tbody>
            {$bodyHtml}
        </tbody>
    </table>

    <div class="footer">
        Generated by POS System &bull; Myanmar Unicode Pyidaungsu Font Supported
    </div>
</body>
</html>
HTML;
    }
}
