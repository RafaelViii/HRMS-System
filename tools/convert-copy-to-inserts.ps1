param(
  [Parameter(Mandatory=$true)] [string]$InputPath,
  [Parameter(Mandatory=$true)] [string]$OutputPath
)

if (!(Test-Path $InputPath)) {
  Write-Error "Input file not found: $InputPath"
  exit 1
}

$inCopy = $false
$table = ''
$cols = @()

# Prepare output (overwrite if exists)
"" | Set-Content -Path $OutputPath -Encoding UTF8

function Append-Line([string]$line) {
  Add-Content -Path $OutputPath -Value $line -Encoding UTF8
}

Get-Content -LiteralPath $InputPath | ForEach-Object {
  $line = $_

  if (-not $inCopy) {
    # Detect COPY start: COPY schema.table (col1, col2, ...) FROM stdin;
    if ($line -match '^COPY\s+([^\s]+)\s*\(([^)]*)\)\s+FROM\s+stdin;\s*$') {
      $table = $matches[1]
      $cols = $matches[2].Split(',') | ForEach-Object { $_.Trim() }
      Append-Line ("-- Data for $table (converted from COPY to INSERT)")
      $inCopy = $true
      return
    } else {
      Append-Line $line
      return
    }
  } else {
    # In COPY body
    if ($line.Trim() -eq '\.') {
      # COPY end
      $inCopy = $false
      return
    }

    # If a comment sneaks in, treat as copy end (defensive)
    if ($line -match '^--') {
      $inCopy = $false
      Append-Line $line
      return
    }

    # If a new COPY header appears unexpectedly, close current and start new
    if ($line -match '^COPY\s+([^\s]+)\s*\(([^)]*)\)\s+FROM\s+stdin;\s*$') {
      # Close previous copy implicitly
      $inCopy = $false
      # Now treat this line as a fresh COPY header
      $table = $matches[1]
      $cols = $matches[2].Split(',') | ForEach-Object { $_.Trim() }
      Append-Line ("-- Data for $table (converted from COPY to INSERT)")
      $inCopy = $true
      return
    }

    # Convert data row (tab-delimited, \N for NULL)
    $fields = $line -split "`t"
    if ($fields.Count -ne $cols.Count) {
      # If unexpected field count, emit as comment to avoid breaking
      Append-Line ("-- WARN: Skipped row due to unexpected column count for ${table}: ${line}")
      return
    }

    $vals = @()
    for ($i = 0; $i -lt $fields.Count; $i++) {
      $v = $fields[$i]
      if ($v -eq '\\N') {
        $vals += 'NULL'
      } else {
        # Escape single quotes by doubling them
        $escaped = $v -replace "'", "''"
        $vals += "'${escaped}'"
      }
    }
    $colsJoined = ($cols -join ', ')
    $valsJoined = ($vals -join ', ')
    Append-Line ("INSERT INTO $table ($colsJoined) VALUES ($valsJoined);")
  }
}

Write-Host "Converted: $InputPath -> $OutputPath"
