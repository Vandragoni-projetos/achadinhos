<?php
require_once __DIR__ . '/../config/database.php';

logout();
header('Location: login.php');
exit;
