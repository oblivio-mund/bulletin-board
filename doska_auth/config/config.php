<?php
declare(strict_types=1);

// Database configuration. For production, move these values to environment variables.
const DB_HOST = '127.0.0.1';
const DB_PORT = '3306';
const DB_NAME = 'doska_obyavleniy';
const DB_USER = 'root';
const DB_PASS = '';

const APP_NAME = 'Доска объявлений';

// Session hardening.
ini_set('session.use_only_cookies', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');

session_start();
