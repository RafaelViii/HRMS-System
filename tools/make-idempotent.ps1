param(
  [Parameter(Mandatory=$true)] [string]$InputPath,
  [Parameter(Mandatory=$true)] [string]$OutputPath
)

if (!(Test-Path $InputPath)) {
  Write-Error "Input file not found: $InputPath"
  exit 1
}

"" | Set-Content -Path $OutputPath -Encoding UTF8

function Out-Line([string]$line) { Add-Content -Path $OutputPath -Value $line -Encoding UTF8 }

Get-Content -LiteralPath $InputPath | ForEach-Object {
  $l = $_

  # CREATE TYPE -> CREATE TYPE IF NOT EXISTS
  if ($l -match '^CREATE\s+TYPE\s+(public\.)' -and $l -notmatch 'IF\s+NOT\s+EXISTS') {
    $l = $l -replace '^CREATE\s+TYPE\s+', 'CREATE TYPE IF NOT EXISTS '
    Out-Line $l; return
  }

  # CREATE TABLE -> CREATE TABLE IF NOT EXISTS
  if ($l -match '^CREATE\s+TABLE\s+(public\.)' -and $l -notmatch 'IF\s+NOT\s+EXISTS') {
    $l = $l -replace '^CREATE\s+TABLE\s+', 'CREATE TABLE IF NOT EXISTS '
    Out-Line $l; return
  }

  # CREATE INDEX -> CREATE INDEX IF NOT EXISTS
  if ($l -match '^CREATE\s+INDEX\s+' -and $l -notmatch 'IF\s+NOT\s+EXISTS') {
    $l = $l -replace '^CREATE\s+INDEX\s+', 'CREATE INDEX IF NOT EXISTS '
    Out-Line $l; return
  }

  # CREATE TRIGGER -> wrap with DO block to swallow duplicate_object
  if ($l -match '^CREATE\s+TRIGGER\s+' -and $l.Trim().EndsWith(';')) {
    Out-Line ("DO $$ BEGIN ${l} EXCEPTION WHEN duplicate_object THEN NULL; END $$;")
    return
  }

  # ALTER TABLE ... ADD CONSTRAINT -> wrap with DO block
  if ($l -match '^ALTER\s+TABLE\s+ONLY\s+public\.' -and $l -match '\bADD\s+CONSTRAINT\b' -and $l.Trim().EndsWith(';')) {
    Out-Line ("DO $$ BEGIN ${l} EXCEPTION WHEN duplicate_object THEN NULL; END $$;")
    return
  }

  # ALTER TABLE ... ALTER COLUMN ... ADD GENERATED ALWAYS AS IDENTITY -> wrap
  if ($l -match '^ALTER\s+TABLE\s+public\.' -and $l -match '\bALTER\s+COLUMN\b' -and $l -match '\bADD\s+GENERATED\b' -and $l.Trim().EndsWith(';')) {
    Out-Line ("DO $$ BEGIN ${l} EXCEPTION WHEN duplicate_object THEN NULL; WHEN others THEN NULL; END $$;")
    return
  }

  # INSERT INTO ... VALUES (...) ; -> add ON CONFLICT DO NOTHING
  if ($l -match '^INSERT\s+INTO\s+' -and $l.Trim().EndsWith(';')) {
    $l = $l -replace '\)\s*;\s*$', ') ON CONFLICT DO NOTHING;'
    Out-Line $l; return
  }

  Out-Line $l
}

Write-Host "Idempotent SQL written to: $OutputPath"
