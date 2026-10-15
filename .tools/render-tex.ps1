# Compile standalone TikZ sources to PNG previews for the web blog.
# Usage: pwsh -File render-tex.ps1
$ErrorActionPreference = 'Continue'
$src = 'D:\HOME\PERSONAL_PROJECTS\tex'
$out = 'D:\HOME\WEB_ROOT\.tools\render-out'
New-Item -ItemType Directory -Force -Path $out | Out-Null

$jobs = @(
  @{ name = 'bkg1';   tex = "$src\background\bkg1\document.tex";      extra = '';                                        dpi = 110 },
  @{ name = 'bkg2.1'; tex = "$src\background\bkg2.1\document.tex";    extra = '';                                        dpi = 110 },
  @{ name = 'bkg2.2'; tex = "$src\background\bkg2.2\document.tex";    extra = '';                                        dpi = 110 },
  @{ name = 'bkg3';   tex = "$src\background\bkg3\document.tex";      extra = '';                                        dpi = 110 },
  @{ name = 'bkg4';   tex = "$src\background\bkg4\bkg4.tex";          extra = '';                                        dpi = 60  },
  @{ name = 'bkg4.2'; tex = "$src\background\bkg4.2\bkg4.2.tex";      extra = '';                                        dpi = 60  },
  @{ name = 'bkg5';   tex = "$src\background\bkg5\bkg5.tex";          extra = '';                                        dpi = 60  },
  @{ name = 'bkg5.2'; tex = "$src\background\bkg5.2\bkg5.2.tex";      extra = '';                                        dpi = 60  },
  @{ name = 'bkg6';   tex = "$src\background\bkg6\bkg6.tex";          extra = '';                                        dpi = 60  },
  @{ name = 'bkg6.2'; tex = "$src\background\bkg6.2\bkg6.2.tex";      extra = '';                                        dpi = 60  },
  @{ name = 'bkg7';   tex = "$src\background\bkg7\bkg7.tex";          extra = '';                                        dpi = 60  },
  @{ name = 'bkg7.2'; tex = "$src\background\bkg7.2\bkg7.2.tex";      extra = '';                                        dpi = 60  },
  @{ name = 'poster4'; tex = "$src\poster\poster4\poster4.tex";       extra = "$src\poster\poster4\images_png";          dpi = 45  },
  @{ name = 'poster5'; tex = "$src\poster\poster5\poster5.tex";       extra = "$src\poster\poster5\images_png";          dpi = 45  }
)

foreach ($j in $jobs) {
  $work = Join-Path $out ("build-" + ($j.name -replace '[^A-Za-z0-9]', '_'))
  New-Item -ItemType Directory -Force -Path $work | Out-Null
  $texFile = Join-Path $work 'main.tex'
  Copy-Item -LiteralPath $j.tex -Destination $texFile -Force
  if ($j.extra -ne '') {
    Get-ChildItem -LiteralPath $j.extra -File | ForEach-Object { Copy-Item -LiteralPath $_.FullName -Destination $work -Force }
  }
  Push-Location $work
  # twice: pgf/tikz remember-picture needs two passes
  foreach ($pass in 1, 2) {
    & xelatex -interaction=nonstopmode -halt-on-error -file-line-error 'main.tex' *> "xelatex-pass$pass.log"
  }
  $ok = Test-Path (Join-Path $work 'main.pdf')
  if ($ok) {
    $pngBase = Join-Path $out ('png-' + $j.name)
    & pdftoppm -png -r $j.dpi 'main.pdf' $pngBase *> 'pdftoppm.log'
    Write-Output ("OK   {0,-8} png={1}" -f $j.name, ((Get-ChildItem "$pngBase*.png" -ErrorAction SilentlyContinue).Count))
  } else {
    Write-Output ("FAIL {0,-8} see {1}" -f $j.name, (Join-Path $work 'xelatex-pass1.log'))
  }
  Pop-Location
}
Write-Output "DONE"
