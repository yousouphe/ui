<?php
require_once __DIR__ . '/config/functions.php';
session_unset();
session_destroy();
session_start();
flash('success', 'Logged out successfully.');
redirect_to('login.php');
