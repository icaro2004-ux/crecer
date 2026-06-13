<?php
// CRECER — Logout
require __DIR__ . '/includes/auth.php';
logout_usuario();
header('Location: /crecer/index.php');
exit;
