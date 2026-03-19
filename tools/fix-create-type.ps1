param(
  [Parameter(Mandatory=$true)] [string]$InputPath,
  [Parameter(Mandatory=$true)] [string]$OutputPath
)

if (!(Test-Path $InputPath)) { Write-Error "Input file not found: $InputPath"; exit 1 }

$inType = $false
"" | Set-Content -Path $OutputPath -Encoding UTF8
function Out-Line([string]$l) { Add-Content -Path $OutputPath -Value $l -Encoding UTF8 }

Get-Content -LiteralPath $InputPath | ForEach-Object {
  $line = $_

  if (-not $inType) {
    if ($line -match '^CREATE\s+TYPE\s+(IF\s+NOT\s+EXISTS\s+)?public\.[^\s]+\s+AS\s+ENUM\s*\(') {
      $inType = $true
      Out-Line 'DO $$ BEGIN'
      # Remove IF NOT EXISTS in case target does not support it
      $fixed = $line -replace '^CREATE\s+TYPE\s+IF\s+NOT\s+EXISTS\s+', 'CREATE TYPE '
      Out-Line $fixed
      return
    } else {
      Out-Line $line
      return
    }
  } else {
    Out-Line $line
    if ($line.Trim() -match '^\);\s*;?\s*$') {
      Out-Line 'EXCEPTION WHEN duplicate_object THEN NULL; END $$;'
      $inType = $false
    }
  }
}

if ($inType) {
  # Ensure block closure if file ended unexpectedly
  Out-Line 'EXCEPTION WHEN duplicate_object THEN NULL; END $$;'
}

Write-Host "Types fixed: $InputPath -> $OutputPath"
