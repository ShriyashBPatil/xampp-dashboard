<?php
require_once __DIR__ . '/includes/core.php';

unset($_SESSION['user']);
set_flash('success', 'You have been logged out successfully.');
header('Location: /dashboard/login.php');
exit;
