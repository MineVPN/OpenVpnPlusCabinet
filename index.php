<?php
/**
 * OpenVPN+ — точка входа. Пускает в панель или на форму входа.
 */

require_once __DIR__ . '/includes/ovp_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (ovp_session_invalid_reason() === '') {
    require __DIR__ . '/cabinet.php';
} else {
    header('Location: login.php');
    exit();
}
