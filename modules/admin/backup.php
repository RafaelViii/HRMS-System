<?php
require_once __DIR__ . '/../../includes/auth.php';
require_access('system', 'backup_restore', 'manage');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
$pdo = get_db_conn();

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf'] ?? '')) {
  $fmt = $_POST['format'] ?? 'csv';
  $tables = ['users','departments','positions','employees','documents','document_assignments','attendance','leave_requests','payroll_periods','payroll','performance_reviews','recruitment','audit_logs','notifications','notification_reads'];
  $timestamp = date('Ymd_His');
  $zipName = 'backup_' . $timestamp . '.zip';
  $zipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $zipName;
  $zip = new ZipArchive();
  if ($zip->open($zipPath, ZipArchive::CREATE) === TRUE) {
    foreach ($tables as $t) {
      if ($fmt === 'csv') {
        // Stream CSV using PDO; fetch header then rows
        $stmt = $pdo->query('SELECT * FROM "' . str_replace('"','""',$t) . '"');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $csv = '';
        if (!empty($rows)) {
          $header = array_keys($rows[0]);
          $csv .= implode(',', array_map(fn($h)=>'"'.str_replace('"','""',$h).'"', $header)) . "\n";
          foreach ($rows as $row) {
            $csv .= implode(',', array_map(function($v){
              if ($v === null) return '';
              $s = (string)$v;
              $s = str_replace(["\r","\n"], ' ', $s);
              $s = str_replace('"','""',$s);
              return '"' . $s . '"';
            }, $row)) . "\n";
          }
        }
        $zip->addFromString($t . '.csv', $csv);
      } else {
        // Simple SQL dump for table data (PostgreSQL-compatible quoting)
        $stmt = $pdo->query('SELECT * FROM "' . str_replace('"','""',$t) . '"');
        $sql = '';
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        if (!empty($rows)) {
          $cols = array_map(fn($c)=>'"'.str_replace('"','""',$c).'"', array_keys($rows[0]));
          foreach ($rows as $row) {
            $vals = [];
            foreach ($row as $v) {
              if ($v === null) { $vals[] = 'NULL'; }
              else {
                $s = (string)$v;
                $s = str_replace("'", "''", $s);
                $vals[] = "'".$s."'";
              }
            }
            $sql .= 'INSERT INTO ' . '"' . str_replace('"','""',$t) . '"' . ' ('.implode(',', $cols).') VALUES ('.implode(',', $vals).');' . "\n";
          }
        }
        $zip->addFromString($t . '.sql', $sql);
      }
    }
    $zip->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="'.$zipName.'"');
    readfile($zipPath);
    @unlink($zipPath);
    exit;
  } else {
    $msg = 'Could not create backup archive.';
  }
}
?>
<?php require_once __DIR__ . '/../../includes/header.php'; ?>
<div class="mb-3">
  <a class="btn btn-outline inline-flex items-center gap-2" href="<?= BASE_URL ?>/modules/admin/management">
    <span>&larr;</span>
    <span>Back to Management Hub</span>
  </a>
</div>
<div class="max-w-2xl">
  <h1 class="text-xl font-semibold mb-3">Backup / Export Database</h1>
  <?php if ($msg): ?><div class="mb-3 p-2 rounded bg-red-50 text-red-700 border border-red-200"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <form method="post" class="bg-white p-4 rounded shadow space-y-4">
    <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
    <div>
      <label class="form-label required">Format</label>
      <select name="format" class="border rounded px-3 py-2">
        <option value="csv">CSV (.csv)</option>
        <option value="sql">SQL Inserts (.sql)</option>
      </select>
    </div>
  <button class="btn btn-primary">Download Backup (ZIP)</button>
  </form>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
