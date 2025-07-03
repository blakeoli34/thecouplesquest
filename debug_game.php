<?php
// debug_game.php - Add this file to debug the game issues
require_once 'config.php';
require_once 'functions.php';
require_once 'auth.php';

echo "<h1>Game Debug Information</h1>";

// Get device ID
$deviceId = $_GET['device_id'] ?? $_COOKIE['device_id'] ?? null;
echo "<h2>Device ID Detection:</h2>";
echo "URL Parameter: " . ($_GET['device_id'] ?? 'None') . "<br>";
echo "Cookie: " . ($_COOKIE['device_id'] ?? 'None') . "<br>";
echo "Final Device ID: " . ($deviceId ?? 'None') . "<br>";

if (!$deviceId) {
    echo "<p style='color: red;'>No device ID found!</p>";
    exit;
}

// Get player data
echo "<h2>Player Data:</h2>";
$player = getPlayerByDeviceId($deviceId);
if ($player) {
    echo "<pre>" . print_r($player, true) . "</pre>";
} else {
    echo "<p style='color: red;'>No player found for this device ID!</p>";
    exit;
}

// Get all players in game
echo "<h2>All Players in Game:</h2>";
$players = getGamePlayers($player['game_id']);
echo "<pre>" . print_r($players, true) . "</pre>";

// Check player assignment logic
echo "<h2>Player Assignment Logic:</h2>";
$currentPlayer = null;
$opponentPlayer = null;

foreach ($players as $p) {
    if ($p['device_id'] === $deviceId) {
        $currentPlayer = $p;
        echo "Current Player: " . $p['first_name'] . " (" . $p['gender'] . ") - Device: " . $p['device_id'] . "<br>";
    } else {
        $opponentPlayer = $p;
        echo "Opponent Player: " . $p['first_name'] . " (" . $p['gender'] . ") - Device: " . $p['device_id'] . "<br>";
    }
}

if (!$currentPlayer) {
    echo "<p style='color: red;'>Could not identify current player!</p>";
}

if (!$opponentPlayer) {
    echo "<p style='color: red;'>Could not identify opponent player!</p>";
}

// Check game status
echo "<h2>Game Status:</h2>";
echo "Status: " . $player['status'] . "<br>";
echo "Duration: " . ($player['duration_days'] ?? 'Not set') . " days<br>";
echo "Start Date: " . ($player['start_date'] ?? 'Not set') . "<br>";
echo "End Date: " . ($player['end_date'] ?? 'Not set') . "<br>";

// Check for duplicate device IDs
echo "<h2>Device ID Check:</h2>";
$deviceIds = array_column($players, 'device_id');
$duplicates = array_diff_assoc($deviceIds, array_unique($deviceIds));
if (!empty($duplicates)) {
    echo "<p style='color: red;'>Duplicate device IDs found: " . implode(', ', $duplicates) . "</p>";
} else {
    echo "<p style='color: green;'>No duplicate device IDs found.</p>";
}

// Database check
echo "<h2>Database Query Test:</h2>";
try {
    $pdo = Config::getDatabaseConnection();
    $stmt = $pdo->prepare("
        SELECT p.id, p.device_id, p.first_name, p.gender, p.score, p.game_id
        FROM players p 
        WHERE p.game_id = ?
    ");
    $stmt->execute([$player['game_id']]);
    $rawPlayers = $stmt->fetchAll();
    echo "<pre>" . print_r($rawPlayers, true) . "</pre>";
} catch (Exception $e) {
    echo "<p style='color: red;'>Database error: " . $e->getMessage() . "</p>";
}
?>