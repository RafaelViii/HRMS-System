<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/utils.php';
require_access('documents', 'documents', 'write');
$pdo = get_db_conn();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf'] ?? '')) {
  $eid = (int)($_POST['employee_id'] ?? 0);
  $title = trim($_POST['title'] ?? '');
  $type = trim($_POST['doc_type'] ?? 'other');
  if ($eid && $title && isset($_FILES['file'])) {
    // Basic validation: allow common safe types and max ~10MB
    $maxSize = 10 * 1024 * 1024; // 10MB
    $okTypes = ['pdf','jpg','jpeg','png'];
    $fileOk = true;
    $errMsg = '';
    if (($_FILES['file']['size'] ?? 0) > $maxSize) { $fileOk = false; $errMsg = 'File too large.'; }
    $ext = strtolower(pathinfo($_FILES['file']['name'] ?? '', PATHINFO_EXTENSION));
    if (!in_array($ext, $okTypes, true)) { $fileOk = false; $errMsg = 'Invalid file type.'; }
    $dest = $fileOk ? handle_upload($_FILES['file'], __DIR__ . '/../../assets/uploads') : null;
    if ($dest) {
      $uid = $_SESSION['user']['id'] ?? null;
      try {
        $stmt = $pdo->prepare('INSERT INTO documents (title, doc_type, file_path, created_by) VALUES (:title, :type, :path, :uid) RETURNING id');
        $stmt->execute([':title'=>$title, ':type'=>$type, ':path'=>$dest, ':uid'=>$uid]);
        $docId = (int)($stmt->fetchColumn() ?: 0);
        $as = $pdo->prepare('INSERT INTO document_assignments (document_id, employee_id) VALUES (:doc, :emp)');
        $as->execute([':doc'=>$docId, ':emp'=>$eid]);
        audit('upload_document', $title);
        flash_success('Changes have been saved');
      } catch (Throwable $e) {
        sys_log('DB2303', 'Execute failed: documents/doc_assign insert - ' . $e->getMessage(), ['module'=>'employees','file'=>__FILE__,'line'=>__LINE__]);
        flash_error('Changes could not be saved');
      }
    } else {
      sys_log('GEN4301', 'File upload failed', ['module'=>'employees','file'=>__FILE__,'line'=>__LINE__]);
      flash_error($errMsg ?: 'Changes could not be saved');
    }
  }
}
header('Location: ' . BASE_URL . '/modules/employees/view?id=' . (int)($_POST['employee_id'] ?? 0));
exit;
