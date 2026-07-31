param (
    [string]$jsonPath,
    [string]$outputPath
)

Add-Type -AssemblyName System.Drawing

if (-not (Test-Path $jsonPath)) {
    Write-Error "JSON file not found: $jsonPath"
    exit 1
}

$data = Get-Content -Path $jsonPath -Raw -Encoding UTF8 | ConvertFrom-Json

$is58mm = $data.paper_size -eq '58mm'
$width = if ($is58mm) { 384 } else { 576 }
$padding = 20
$usableWidth = $width - ($padding * 2)

$fontFamilyLatin = "Segoe UI"
$fontFamilyMyanmar = "Myanmar Text"

# Pre-calculate height
$calculatedHeight = $padding * 2

foreach ($item in $data.lines) {
    if ($item.type -eq 'divider') {
        $calculatedHeight += 18
    } elseif ($item.type -eq 'blank') {
        $calculatedHeight += 14
    } elseif ($item.type -eq 'item_row') {
        $calculatedHeight += 44
    } elseif ($item.type -eq 'text') {
        $fSize = if ($item.font_size) { [int]$item.font_size } else { 18 }
        $calculatedHeight += ($fSize + 18)
    }
}

$calculatedHeight += 32

$bmp = New-Object System.Drawing.Bitmap($width, $calculatedHeight)
$g = [System.Drawing.Graphics]::FromImage($bmp)
$g.Clear([System.Drawing.Color]::White)
$g.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::SingleBitPerPixelGridFit

$brush = [System.Drawing.Brushes]::Black
$pen = New-Object System.Drawing.Pen([System.Drawing.Color]::Black, 2)

$currentY = $padding

foreach ($item in $data.lines) {
    if ($item.type -eq 'blank') {
        $currentY += 14
        continue
    }

    if ($item.type -eq 'divider') {
        $currentY += 4
        $yLine = [int]$currentY
        $g.DrawLine($pen, $padding, $yLine, ($width - $padding), $yLine)
        $currentY += 14
        continue
    }

    if ($item.type -eq 'item_row') {
        $leftText = $item.left
        $rightText = $item.right

        $fontL = New-Object System.Drawing.Font($fontFamilyMyanmar, 18, [System.Drawing.FontStyle]::Regular)
        $fontR = New-Object System.Drawing.Font($fontFamilyLatin, 18, [System.Drawing.FontStyle]::Bold)

        $g.DrawString($leftText, $fontL, $brush, $padding, $currentY)

        if ($rightText) {
            $sizeR = $g.MeasureString($rightText, $fontR)
            $rx = $width - $padding - $sizeR.Width
            $g.DrawString($rightText, $fontR, $brush, $rx, $currentY)
        }

        $currentY += 44
        continue
    }

    if ($item.type -eq 'text') {
        $fSize = if ($item.font_size) { [int]$item.font_size } else { 18 }
        $style = if ($item.is_bold) { [System.Drawing.FontStyle]::Bold } else { [System.Drawing.FontStyle]::Regular }

        $hasUnicode = $item.text -match '[\u1000-\u109F]'
        $fontFamily = if ($hasUnicode) { $fontFamilyMyanmar } else { $fontFamilyLatin }
        $font = New-Object System.Drawing.Font($fontFamily, $fSize, $style)

        $tx = $padding
        if ($item.is_center) {
            $sizeT = $g.MeasureString($item.text, $font)
            $tx = [Math]::Max($padding, [int](($width - $sizeT.Width) / 2))
        } elseif ($item.is_indented) {
            $tx = $padding + 24
        }

        $g.DrawString($item.text, $font, $brush, $tx, $currentY)
        $currentY += ($fSize + 18)
    }
}

$bmp.Save($outputPath, [System.Drawing.Imaging.ImageFormat]::Png)
$g.Dispose()
$bmp.Dispose()

Write-Host "RENDER_SUCCESS"
