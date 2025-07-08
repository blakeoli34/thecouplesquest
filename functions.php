<?php
// functions.php - Core functions for The Couples Quest

function registerPlayer($inviteCode, $gender, $firstName) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Check if invite code exists and is valid
        $stmt = $pdo->prepare("SELECT * FROM invite_codes WHERE code = ? AND is_used = FALSE");
        $stmt->execute([$inviteCode]);
        $invite = $stmt->fetch();
        
        if (!$invite) {
            return ['success' => false, 'message' => 'Invalid or expired invite code.'];
        }
        
        // Find or create game
        $stmt = $pdo->prepare("SELECT * FROM games WHERE invite_code = ?");
        $stmt->execute([$inviteCode]);
        $game = $stmt->fetch();
        
        if (!$game) {
            // Create new game
            $stmt = $pdo->prepare("INSERT INTO games (invite_code, status) VALUES (?, 'waiting')");
            $stmt->execute([$inviteCode]);
            $gameId = $pdo->lastInsertId();
        } else {
            $gameId = $game['id'];
        }
        
        // Check if someone with this gender already joined
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM players WHERE game_id = ? AND gender = ?");
        $stmt->execute([$gameId, $gender]);
        $count = $stmt->fetchColumn();
        
        if ($count > 0) {
            return ['success' => false, 'message' => 'Someone with this gender has already joined this game.'];
        }
        
        // Generate device ID
        $deviceId = Config::generateDeviceId();
        
        // Register player
        $stmt = $pdo->prepare("INSERT INTO players (game_id, device_id, first_name, gender) VALUES (?, ?, ?, ?)");
        $stmt->execute([$gameId, $deviceId, $firstName, $gender]);
        
        // Check if both players have joined
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM players WHERE game_id = ?");
        $stmt->execute([$gameId]);
        $playerCount = $stmt->fetchColumn();
        
        if ($playerCount == 2) {
            // Mark invite code as used
            $stmt = $pdo->prepare("UPDATE invite_codes SET is_used = TRUE WHERE code = ?");
            $stmt->execute([$inviteCode]);
        }
        
        return ['success' => true, 'device_id' => $deviceId];
        
    } catch (Exception $e) {
        error_log("Error registering player: " . $e->getMessage());
        return ['success' => false, 'message' => 'Registration failed. Please try again.'];
    }
}

function getPlayerByDeviceId($deviceId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("
            SELECT p.id as player_id, p.device_id, p.first_name, p.gender, p.score, p.fcm_token, p.joined_at,
                   g.id as game_id, g.invite_code, g.duration_days, g.start_date, g.end_date, g.status, g.created_at
            FROM players p 
            JOIN games g ON p.game_id = g.id 
            WHERE p.device_id = ?
        ");
        $stmt->execute([$deviceId]);
        $result = $stmt->fetch();
        
        if ($result) {
            // Rename player_id back to id for compatibility
            $result['id'] = $result['player_id'];
        }
        
        return $result;
    } catch (Exception $e) {
        error_log("Error getting player: " . $e->getMessage());
        return null;
    }
}

function getGamePlayers($gameId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("
            SELECT id, device_id, first_name, gender, score, fcm_token, joined_at, game_id
            FROM players 
            WHERE game_id = ? 
            ORDER BY joined_at ASC
        ");
        $stmt->execute([$gameId]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Error getting game players: " . $e->getMessage());
        return [];
    }
}

function updateScore($gameId, $playerId, $pointsToAdd, $modifiedBy) {
    try {
        $pdo = Config::getDatabaseConnection();
        $pdo->beginTransaction();
        
        // Get current score
        $stmt = $pdo->prepare("SELECT score FROM players WHERE id = ?");
        $stmt->execute([$playerId]);
        $currentScore = $stmt->fetchColumn();
        
        $newScore = $currentScore + $pointsToAdd;
        
        // Update score
        $stmt = $pdo->prepare("UPDATE players SET score = ? WHERE id = ?");
        $stmt->execute([$newScore, $playerId]);
        
        // Record history
        $stmt = $pdo->prepare("
            INSERT INTO score_history (game_id, player_id, modified_by_player_id, old_score, new_score, points_changed) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$gameId, $playerId, $modifiedBy, $currentScore, $newScore, $pointsToAdd]);
        
        $pdo->commit();
        return ['success' => true];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error updating score: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to update score.'];
    }
}

function setGameDuration($gameId, $durationDays) {
    try {
        $pdo = Config::getDatabaseConnection();
        $timezone = new DateTimeZone('America/New_York'); // Change to your timezone
        
        $startDate = new DateTime('now');
        $endDate = clone $startDate;
        $endDate->add(new DateInterval('P' . $durationDays . 'D'));
        
        $stmt = $pdo->prepare("
            UPDATE games 
            SET duration_days = ?, start_date = ?, end_date = ?, status = 'active' 
            WHERE id = ?
        ");
        $stmt->execute([
            $durationDays, 
            $startDate->format('Y-m-d H:i:s'), 
            $endDate->format('Y-m-d H:i:s'), 
            $gameId
        ]);
        
        return ['success' => true];
        
    } catch (Exception $e) {
        error_log("Error setting game duration: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to set game duration.'];
    }
}

function getScoreHistory($gameId, $hours = 24) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("
            SELECT sh.*, p1.first_name as player_name, p2.first_name as modified_by_name
            FROM score_history sh
            JOIN players p1 ON sh.player_id = p1.id
            JOIN players p2 ON sh.modified_by_player_id = p2.id
            WHERE sh.game_id = ? AND sh.timestamp >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            ORDER BY sh.timestamp DESC
        ");
        $stmt->execute([$gameId, $hours]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Error getting score history: " . $e->getMessage());
        return [];
    }
}

function createTimer($gameId, $playerId, $description, $durationMinutes) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Create times in UTC
        $startTime = new DateTime('now', new DateTimeZone('UTC'));
        $endTime = clone $startTime;
        $endTime->add(new DateInterval('PT' . $durationMinutes . 'M'));
        
        $stmt = $pdo->prepare("
            INSERT INTO timers (game_id, player_id, description, duration_minutes, start_time, end_time) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $gameId, 
            $playerId, 
            $description, 
            $durationMinutes, 
            $startTime->format('Y-m-d H:i:s'), 
            $endTime->format('Y-m-d H:i:s')
        ]);
        
        return ['success' => true];
        
    } catch (Exception $e) {
        error_log("Error creating timer: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to create timer.'];
    }
}

function getActiveTimers($gameId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("
            SELECT t.*, p.first_name, p.gender 
            FROM timers t
            JOIN players p ON t.player_id = p.id
            WHERE t.game_id = ? AND t.is_active = TRUE AND t.end_time > UTC_TIMESTAMP()
            ORDER BY t.end_time ASC
        ");
        $stmt->execute([$gameId]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Error getting active timers: " . $e->getMessage());
        return [];
    }
}

function sendPushNotification($fcmToken, $title, $body, $data = []) {
    if (!$fcmToken || empty($fcmToken)) {
        error_log("FCM: No token provided");
        return false;
    }
    
    // Check if we have the required FCM configuration
    if (!defined('Config::FCM_PROJECT_ID') || !Config::FCM_PROJECT_ID || Config::FCM_PROJECT_ID === 'your-firebase-project-id') {
        error_log("FCM: Project ID not configured properly");
        return false;
    }

    $data = [
        'title' => $title,
        'body' => $body
    ];
    
    // FCM v1 API endpoint
    $url = 'https://fcm.googleapis.com/v1/projects/' . Config::FCM_PROJECT_ID . '/messages:send';
    
    // Ensure data is an object/map, not an array
    if (empty($data)) {
        $data = new stdClass(); // Empty object
    } elseif (is_array($data) && array_values($data) === $data) {
        // Convert indexed array to associative array
        $data = (object)$data;
    }
    
    $message = [
        'message' => [
            'token' => $fcmToken,
            'data' => $data,
        ]
    ];
    
    // Get OAuth 2.0 access token
    $accessToken = getAccessToken();
    if (!$accessToken) {
        error_log("Failed to get FCM access token");
        return false;
    }
    
    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Add timeout
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // Add connection timeout
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        error_log("FCM cURL error: $curlError");
        return false;
    }
    
    if ($httpCode !== 200) {
        error_log("FCM API error: HTTP $httpCode - $result");
        
        // Try to parse the error for more specific logging
        $errorData = json_decode($result, true);
        if ($errorData && isset($errorData['error'])) {
            error_log("FCM error details: " . $errorData['error']['message']);
            
            // Handle token errors specifically
            if (isset($errorData['error']['details'])) {
                foreach ($errorData['error']['details'] as $detail) {
                    if (isset($detail['errorCode']) && in_array($detail['errorCode'], ['UNREGISTERED', 'INVALID_ARGUMENT'])) {
                        error_log("FCM token appears to be invalid or unregistered");
                        // You might want to remove this token from the database
                    }
                }
            }
        }
        return false;
    }
    
    error_log("FCM notification sent successfully to token: " . substr($fcmToken, 0, 20) . "...");
    return true;
}

function getAccessToken() {
    // Check if we have a cached token that's still valid
    $cacheFile = sys_get_temp_dir() . '/fcm_access_token.json';
    
    if (file_exists($cacheFile)) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached && $cached['expires_at'] > time()) {
            return $cached['access_token'];
        }
    }
    
    // Path to your service account JSON file
    $serviceAccountPath = Config::FCM_SERVICE_ACCOUNT_PATH;
    
    if (!file_exists($serviceAccountPath)) {
        error_log("Service account file not found: $serviceAccountPath");
        return false;
    }
    
    $serviceAccount = json_decode(file_get_contents($serviceAccountPath), true);
    
    if (!$serviceAccount) {
        error_log("Failed to parse service account JSON");
        return false;
    }
    
    // Create JWT
    $header = json_encode(['typ' => 'JWT', 'alg' => 'RS256']);
    $now = time();
    $payload = json_encode([
        'iss' => $serviceAccount['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600
    ]);
    
    $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
    $base64Payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
    
    $signature = '';
    $privateKey = openssl_pkey_get_private($serviceAccount['private_key']);
    
    if (!$privateKey) {
        error_log("Failed to parse private key from service account");
        return false;
    }
    
    if (!openssl_sign($base64Header . "." . $base64Payload, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        error_log("Failed to sign JWT");
        return false;
    }
    
    $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
    $jwt = $base64Header . "." . $base64Payload . "." . $base64Signature;
    
    // Exchange JWT for access token
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        error_log("OAuth cURL error: $curlError");
        return false;
    }
    
    if ($httpCode !== 200) {
        error_log("OAuth token error: HTTP $httpCode - $response");
        return false;
    }
    
    $tokenData = json_decode($response, true);
    
    if (!isset($tokenData['access_token'])) {
        error_log("No access token in response: $response");
        return false;
    }
    
    // Cache the token
    $cacheData = [
        'access_token' => $tokenData['access_token'],
        'expires_at' => time() + $tokenData['expires_in'] - 300 // 5 minutes buffer
    ];
    file_put_contents($cacheFile, json_encode($cacheData));
    
    return $tokenData['access_token'];
}

// Add a new function to test FCM configuration
function testFCMConfiguration() {
    error_log("Testing FCM configuration...");
    
    // Check if constants are defined
    if (!defined('Config::FCM_PROJECT_ID')) {
        error_log("FCM_PROJECT_ID not defined");
        return false;
    }
    
    if (!defined('Config::FCM_SERVICE_ACCOUNT_PATH')) {
        error_log("FCM_SERVICE_ACCOUNT_PATH not defined");
        return false;
    }
    
    // Check if service account file exists
    if (!file_exists(Config::FCM_SERVICE_ACCOUNT_PATH)) {
        error_log("Service account file not found: " . Config::FCM_SERVICE_ACCOUNT_PATH);
        return false;
    }
    
    // Try to get an access token
    $token = getAccessToken();
    if (!$token) {
        error_log("Failed to get access token");
        return false;
    }
    
    error_log("FCM configuration test passed");
    return true;
}

function sendBumpNotification($gameId, $senderPlayerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Get sender info
        $stmt = $pdo->prepare("SELECT first_name FROM players WHERE id = ?");
        $stmt->execute([$senderPlayerId]);
        $senderName = $stmt->fetchColumn();
        
        if (!$senderName) {
            return [
                'success' => false, 
                'message' => 'Sender not found'
            ];
        }
        
        // Get other player's FCM token
        $stmt = $pdo->prepare("
            SELECT fcm_token, first_name, id
            FROM players 
            WHERE game_id = ? AND id != ?
        ");
        $stmt->execute([$gameId, $senderPlayerId]);
        $recipient = $stmt->fetch();
        
        if (!$recipient) {
            return [
                'success' => false, 
                'message' => 'Partner not found in game'
            ];
        }
        
        if (!$recipient['fcm_token']) {
            return [
                'success' => true, 
                'message' => 'Bump sent! (Partner hasn\'t enabled notifications yet)'
            ];
        }
        
        // Send actual FCM notification
        $result = sendPushNotification(
            $recipient['fcm_token'],
            'Bump!',
            $senderName . ' wants to play. Serve them a card.'
        );
        
        return [
            'success' => $result, 
            'message' => $result 
                ? 'Bump sent to ' . $recipient['first_name'] . '! 📱'
                : 'Failed to send notification to ' . $recipient['first_name']
        ];
        
    } catch (Exception $e) {
        error_log("Error sending bump notification: " . $e->getMessage());
        return [
            'success' => false, 
            'message' => 'Failed to send bump: ' . $e->getMessage()
        ];
    }
}

function sendTestNotification($deviceId) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Get player's FCM token
        $stmt = $pdo->prepare("SELECT fcm_token, first_name FROM players WHERE device_id = ?");
        $stmt->execute([$deviceId]);
        $player = $stmt->fetch();
        
        if ($player && $player['fcm_token']) {
            $result = sendPushNotification(
                $player['fcm_token'],
                'Test Notification',
                'Hello ' . $player['first_name'] . '! Notifications are working! 🎉'
            );
            
            return [
                'success' => $result,
                'message' => $result ? 'Test notification sent' : 'Failed to send test notification'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'No FCM token found for this device'
            ];
        }
        
    } catch (Exception $e) {
        error_log("Error sending test notification: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Failed to send test: ' . $e->getMessage()
        ];
    }
}

function updateFcmToken($deviceId, $fcmToken) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("UPDATE players SET fcm_token = ? WHERE device_id = ?");
        $stmt->execute([$fcmToken, $deviceId]);
        return true;
    } catch (Exception $e) {
        error_log("Error updating FCM token: " . $e->getMessage());
        return false;
    }
}
?>