param([string]$Php = 'D:\php\php.exe', [string]$OutputDirectory = '')
$ErrorActionPreference = 'Stop'
$root = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$json = & $Php (Join-Path $root 'tests/bridge.php') rules
if ($LASTEXITCODE -ne 0) { throw 'Could not load game content.' }
$rules = $json | ConvertFrom-Json
$out = if ($OutputDirectory) { [System.IO.Path]::GetFullPath($OutputDirectory) } else { Join-Path $root 'docs/content-review/media' }
$null = New-Item -ItemType Directory -Force -Path $out
Add-Type -AssemblyName System.Drawing
$font = [System.Drawing.Font]::new('Malgun Gothic', 12)
$brush = [System.Drawing.SolidBrush]::new([System.Drawing.Color]::FromArgb(30,30,30))

foreach ($kind in @('pixels', 'devices')) {
    $samples = if ($kind -eq 'pixels') { $rules.'image-data'.rules.samples } else { $rules.'device-matching'.rules.devices }
    $columns = 6
    $tileWidth = 190
    $tileHeight = 214
    $bitmap = [System.Drawing.Bitmap]::new($columns * $tileWidth, [int][Math]::Ceiling($samples.Count / $columns) * $tileHeight)
    $g = [System.Drawing.Graphics]::FromImage($bitmap)
    $g.Clear([System.Drawing.Color]::White)
    for ($i = 0; $i -lt $samples.Count; $i++) {
        $sample = $samples[$i]
        $x = ($i % $columns) * $tileWidth + 15
        $y = [int][Math]::Floor($i / $columns) * $tileHeight + 8
        if ($kind -eq 'pixels') {
            $palette = @{}
            foreach ($color in $rules.'image-data'.rules.palette) { $palette[$color.index] = [System.Drawing.ColorTranslator]::FromHtml($color.hex) }
            $size = if ($sample.size) { [int]$sample.size } else { 16 }
            $cell = 160 / $size
            for ($r = 0; $r -lt $size; $r++) {
                for ($c = 0; $c -lt $size; $c++) {
                    $colorBrush = [System.Drawing.SolidBrush]::new($palette[[string]$sample.data[$r][$c]])
                    $g.FillRectangle($colorBrush, [int]($x + $c * $cell), [int]($y + $r * $cell), [int]$cell, [int]$cell)
                    $colorBrush.Dispose()
                }
            }
            $label = $sample.title
        } else {
            $photo = [System.Drawing.Image]::FromFile((Join-Path $root "assets/device-matching/images/$($sample.id).jpg"))
            $scale = [Math]::Min(160 / $photo.Width, 160 / $photo.Height)
            $w = [int]($photo.Width * $scale)
            $h = [int]($photo.Height * $scale)
            $g.DrawImage($photo, [int]($x + (160 - $w) / 2), [int]($y + (160 - $h) / 2), $w, $h)
            $photo.Dispose()
            $label = $sample.name
        }
        $g.DrawRectangle([System.Drawing.Pens]::LightGray, [int]$x, [int]$y, 160, 160)
        $g.DrawString($label, $font, $brush, [single]$x, [single]($y + 166))
    }
    $bitmap.Save((Join-Path $out "$kind.png"), [System.Drawing.Imaging.ImageFormat]::Png)
    $g.Dispose()
    $bitmap.Dispose()
}
$font.Dispose()
$brush.Dispose()
Write-Output "Rendered pixel and device contact sheets in $out"
