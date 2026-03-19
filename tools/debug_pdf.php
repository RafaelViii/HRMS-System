<?php
session_start();
$_SESSION['user'] = ['id' => 1, 'role' => 'admin'];
$_GET['id'] = 1;
require __DIR__ . '/../modules/payroll/pdf_payslip.php';
