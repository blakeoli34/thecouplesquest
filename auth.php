<?php
// auth.php - Enhanced authentication helper
require_once 'config.php';

function getAuthenticatedPlayer() {
    // Method 1: Check URL parameter (for mobile config first launch)
    if (isset($_GET['device_id']) && $_GET['device_id']) {
        $deviceId = $_GET['device_id'];
        $player = getPlayerByDeviceId($deviceId);
        if ($player) {
            // Set cookie for future visits
            setcookie('device_id', $deviceId, time() + (365 * 24 * 60 * 60), '/', '', isset($_SERVER['HTTPS']), true);
            return $player;
        }
    }
    
    // Method 2: Check cookie
    if (isset($_COOKIE['device_id'])) {
        $deviceId = $_COOKIE['device_id'];
        $player = getPlayerByDeviceId($deviceId);
        if ($player) {
            return $player;
        }
    }
    
    // Method 3: Check session (fallback)
    session_start();
    if (isset($_SESSION['device_id'])) {
        $deviceId = $_SESSION['device_id'];
        $player = getPlayerByDeviceId($deviceId);
        if ($player) {
            return $player;
        }
    }
    
    // Method 4: Check localStorage via JavaScript (if available)
    // This would be handled on the frontend
    
    return null;
}

function setPlayerAuth($deviceId) {
    // Set multiple auth methods
    setcookie('device_id', $deviceId, time() + (365 * 24 * 60 * 60), '/', '', isset($_SERVER['HTTPS']), true);
    
    session_start();
    $_SESSION['device_id'] = $deviceId;
    
    return true;
}
?>