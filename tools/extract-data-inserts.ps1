param(
  [Parameter(Mandatory=$true)] [string]$InputPath,
  [Parameter(Mandatory=$true)] [string]$OutputPath
)

if (!(Test-Path -LiteralPath $InputPath)) {
  Write-Error "Input file not found: $InputPath"
  exit 1
}

$inCopy = $false
$table = ''
$cols = @()
$setvals = New-Object System.Collections.Generic.List[string]
$tableInserts = @{}

# Tables to skip (not present in current schema)
$skipTables = @(
  'public.roles_meta',
  'public.roles_meta_permissions'
)

# Dependency order (parents before children)
$orderedTables = @(
  'public.users',
  'public.departments',
  'public.positions',
  'public.employees',
  'public.documents',
  'public.document_assignments',
  'public.payroll_periods',
  'public.payroll',
  'public.performance_reviews',
  'public.recruitment_templates',
  'public.recruitment_template_fields',
  'public.recruitment_template_files',
  'public.recruitment',
  'public.recruitment_files',
  'public.audit_logs',
  'public.notifications',
  'public.notification_reads',
  'public.leave_requests',
  'public.leave_request_actions',
  'public.attendance',
  'public.pdf_templates',
  'public.system_logs',
  'public.action_reversals',
  'public.access_templates',
  'public.access_template_permissions',
  'public.user_access_permissions',
  'public.schema_migrations',
  # backup tables (order independent)
  'public.users_backup',
  'public.departments_backup',
  'public.positions_backup',
  'public.employees_backup',
  'public.payroll_backup',
  'public.leave_requests_backup'
)

Get-Content -LiteralPath $InputPath | ForEach-Object {
  $line = $_

  # Capture setval statements for later
  if ($line -match "^SELECT\s+pg_catalog\.setval\((.+)\);\s*$") {
    $setvals.Add($line)
  }

  if (-not $inCopy) {
    # Detect COPY start: COPY schema.table (col1, col2, ...) FROM stdin;
    if ($line -match '^COPY\s+([^\s]+)\s*\(([^)]*)\)\s+FROM\s+stdin;\s*$') {
      $table = $matches[1]
      $cols = $matches[2].Split(',') | ForEach-Object { $_.Trim() }
      $inCopy = $true
      # initialize bucket
      if (-not $tableInserts.ContainsKey($table)) { $tableInserts[$table] = New-Object System.Collections.Generic.List[string] }
      return
    } else {
      # Skip non-data lines
      return
    }
  } else {
    # In COPY body
    if ($line.Trim() -eq '\.') {
      # COPY end
      $inCopy = $false
      return
    }

    # Ignore comments inside COPY (defensive)
    if ($line -match '^--') { return }

    if ($skipTables -contains $table) { return }

    # Convert data row (tab-delimited, \N for NULL)
    $fields = $line -split "`t", -1
    if ($fields.Count -ne $cols.Count) {
      $tableInserts[$table].Add("-- WARN: Skipped row due to unexpected column count for ${table}: ${line}")
      return
    }

    $vals = @()
    for ($i = 0; $i -lt $fields.Count; $i++) {
      $v = $fields[$i]
      if ($v -eq '\N') {
        $vals += 'NULL'
      } else {
        $escaped = $v -replace "'", "''"
        $vals += "'${escaped}'"
      }
    }
    $colsJoined = ($cols -join ', ')
    $valsJoined = ($vals -join ', ')
    $override = ''
    if ($cols -contains 'id') { $override = ' OVERRIDING SYSTEM VALUE' }
    $insert = "INSERT INTO $table ($colsJoined)$override VALUES ($valsJoined) ON CONFLICT DO NOTHING;"
    $tableInserts[$table].Add($insert)
  }
}

# Write output in dependency order
"-- Data-only INSERTs generated from COPY blocks`nSET standard_conforming_strings = on;`nSET client_encoding = 'UTF8';`nBEGIN;" | Set-Content -Path $OutputPath -Encoding UTF8

function OutLine([string]$line) { Add-Content -Path $OutputPath -Value $line -Encoding UTF8 }

foreach ($t in $orderedTables) {
  if ($tableInserts.ContainsKey($t) -and $tableInserts[$t].Count -gt 0) {
    OutLine "`n-- Data for $t (converted from COPY to INSERT)"
    $tableInserts[$t] | ForEach-Object { OutLine $_ }
    $tableInserts.Remove($t) | Out-Null
  }
}

# Any remaining tables not in the ordered list
foreach ($kv in $tableInserts.GetEnumerator() | Sort-Object Key) {
  if ($kv.Value.Count -gt 0) {
    OutLine "`n-- Data for $($kv.Key) (converted from COPY to INSERT)"
    $kv.Value | ForEach-Object { OutLine $_ }
  }
}

# Append sequence setvals at the end
if ($setvals.Count -gt 0) {
  OutLine "`n-- Sequence positions"
  foreach ($sv in $setvals) { OutLine $sv }
}

OutLine "COMMIT;"

Write-Host "Created data-only INSERTs: $OutputPath"
