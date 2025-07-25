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
        
        // Get current scores before update
        $stmt = $pdo->prepare("SELECT id, score FROM players WHERE game_id = ?");
        $stmt->execute([$gameId]);
        $oldScores = $stmt->fetchAll();
        
        // Get current score for the player being updated
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

        // Check for lead changes before committing
        checkAndNotifyLeadChange($gameId, $oldScores);
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
        
        return ['success' => true, 'timer_id' => $pdo->lastInsertId()];
        
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

function deleteTimer($timerId, $gameId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("DELETE FROM timers WHERE id = ? AND game_id = ?");
        $stmt->execute([$timerId, $gameId]);
        return ['success' => true];
    } catch (Exception $e) {
        error_log("Error deleting timer: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to delete timer.'];
    }
}

function processExpiredTimers($gameId) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Get expired timers with associated chance effects
        $stmt = $pdo->prepare("
            SELECT t.id as timer_id, ace.id as effect_id, ace.effect_type, ace.player_id, ace.chance_card_id
            FROM timers t
            JOIN active_chance_effects ace ON t.id = ace.timer_id
            WHERE t.game_id = ? AND t.end_time <= UTC_TIMESTAMP() AND t.is_active = TRUE
        ");
        $stmt->execute([$gameId]);
        $expiredEffects = $stmt->fetchAll();
        
        foreach ($expiredEffects as $effect) {
            if ($effect['effect_type'] === 'recurring_timer') {
                // Use the stored score value from the effect
                $pointsToSubtract = $effect['effect_value'] ?: 1;
                updateScore($gameId, $effect['player_id'], -$pointsToSubtract, $effect['player_id']);
                
                // Get the repeat interval from the original card
                $stmt = $pdo->prepare("SELECT repeat_count FROM cards WHERE id = ?");
                $stmt->execute([$effect['chance_card_id']]);
                $interval = $stmt->fetchColumn();
                
                $newTimer = createTimer($gameId, $effect['player_id'], 'Clock Siphon', $interval);
                if ($newTimer['success']) {
                    $stmt = $pdo->prepare("UPDATE active_chance_effects SET timer_id = ? WHERE id = ?");
                    $stmt->execute([$newTimer['timer_id'], $effect['effect_id']]);
                }
            } else {
                // Regular timer effect - auto-complete the chance card
                removeActiveChanceEffect($effect['effect_id']);
                
                // Remove the chance card from player's hand
                $stmt = $pdo->prepare("DELETE FROM player_cards WHERE game_id = ? AND player_id = ? AND card_id = ?");
                $stmt->execute([$gameId, $effect['player_id'], $effect['chance_card_id']]);
            }
            
            // Mark timer as inactive
            $stmt = $pdo->prepare("UPDATE timers SET is_active = FALSE WHERE id = ?");
            $stmt->execute([$effect['timer_id']]);
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Error processing expired timers: " . $e->getMessage());
        return false;
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

function checkAndNotifyLeadChange($gameId, $oldScores) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Get current scores
        $stmt = $pdo->prepare("SELECT id, first_name, score, fcm_token FROM players WHERE game_id = ? ORDER BY id ASC");
        $stmt->execute([$gameId]);
        $players = $stmt->fetchAll();
        
        if (count($players) !== 2) {
            error_log("Not exactly 2 players found");
            return;
        }
        
        // Determine old leader
        $oldLeader = null;
        if ($oldScores[0]['score'] > $oldScores[1]['score']) {
            $oldLeader = $oldScores[0]['id'];
        } elseif ($oldScores[1]['score'] > $oldScores[0]['score']) {
            $oldLeader = $oldScores[1]['id'];
        }
        
        // Determine new leader
        $newLeader = null;
        if ($players[0]['score'] > $players[1]['score']) {
            $newLeader = $players[0]['id'];
        } elseif ($players[1]['score'] > $players[0]['score']) {
            $newLeader = $players[1]['id'];
        }
        
        // Check if leadership changed
        if ($oldLeader !== $newLeader && $newLeader !== null) {
            $leader = $players[0]['score'] > $players[1]['score'] ? $players[0] : $players[1];
            $follower = $players[0]['score'] > $players[1]['score'] ? $players[1] : $players[0];
            
            // Send notification to follower
            if ($follower['fcm_token']) {
                $difference = $leader['score'] - $follower['score'];
                $result = sendPushNotification(
                    $follower['fcm_token'],
                    'Lead Change!',
                    $leader['first_name'] . ' has taken the lead! You\'re behind by ' . $difference . ' point' . ($difference === 1 ? '' : 's') . '.'
                );
            } else {
                error_log("No FCM token for follower");
            }
        } else {
            error_log("No lead change detected");
        }
    } catch (Exception $e) {
        error_log("Error checking lead change: " . $e->getMessage());
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

function markPlayerReadyForNewGame($gameId, $playerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("UPDATE players SET ready_for_new_game = TRUE WHERE game_id = ? AND id = ?");
        $stmt->execute([$gameId, $playerId]);
        
        // Check if both players are ready
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM players WHERE game_id = ? AND ready_for_new_game = TRUE");
        $stmt->execute([$gameId]);
        $readyCount = $stmt->fetchColumn();
        
        return ['success' => true, 'both_ready' => $readyCount >= 2];
    } catch (Exception $e) {
        error_log("Error marking player ready: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to mark ready.'];
    }
}

function resetGameForNewRound($gameId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $pdo->beginTransaction();
        
        // Reset players: scores to 0, ready status to false
        $stmt = $pdo->prepare("UPDATE players SET score = 0, ready_for_new_game = FALSE WHERE game_id = ?");
        $stmt->execute([$gameId]);
        
        // Reset game: status to waiting, clear dates and duration
        $stmt = $pdo->prepare("UPDATE games SET status = 'waiting', duration_days = NULL, start_date = NULL, end_date = NULL WHERE id = ?");
        $stmt->execute([$gameId]);
        
        // Clear timers
        $stmt = $pdo->prepare("DELETE FROM timers WHERE game_id = ?");
        $stmt->execute([$gameId]);
        
        // Clear score history
        $stmt = $pdo->prepare("DELETE FROM score_history WHERE game_id = ?");
        $stmt->execute([$gameId]);
        
        $pdo->commit();
        return ['success' => true];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error resetting game: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to reset game.'];
    }
}

function getNewGameReadyStatus($gameId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("
            SELECT first_name, ready_for_new_game 
            FROM players 
            WHERE game_id = ? 
            ORDER BY id ASC
        ");
        $stmt->execute([$gameId]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Error getting ready status: " . $e->getMessage());
        return [];
    }
}

function initializeDigitalGame($gameId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $players = getGamePlayers($gameId);
        
        // Get all cards
        $stmt = $pdo->prepare("SELECT * FROM cards ORDER BY card_type, card_name");
        $stmt->execute();
        $cards = $stmt->fetchAll();
        
        foreach ($players as $player) {
            foreach ($cards as $card) {
                if ($card['quantity'] > 0) {
                    $shouldInclude = false;
                    
                    if ($card['card_type'] === 'serve') {
                        // Males get serve_to_her cards, females get serve_to_him cards
                        if (($player['gender'] === 'male' && $card['serve_to_her'] == 1) ||
                            ($player['gender'] === 'female' && $card['serve_to_him'] == 1)) {
                            $shouldInclude = true;
                        }
                    } elseif (in_array($card['card_type'], ['snap', 'dare'])) {
                        // Snap cards are for females only, Dare cards are for males only
                        if ($card['card_type'] === 'snap' && $player['gender'] === 'female') {
                            $shouldInclude = true;
                        } elseif ($card['card_type'] === 'dare' && $player['gender'] === 'male') {
                            $shouldInclude = true;
                        }
                    } else {
                        // Chance and Spicy cards - include for appropriate gender
                        $forHer = $card['for_her'] == 1;
                        $forHim = $card['for_him'] == 1;
                        
                        if ($forHer && $forHim) {
                            // Universal card - give to both players
                            $shouldInclude = true;
                        } elseif ($player['gender'] === 'female' && $forHer && !$forHim) {
                            // Female-only card
                            $shouldInclude = true;
                        } elseif ($player['gender'] === 'male' && $forHim && !$forHer) {
                            // Male-only card
                            $shouldInclude = true;
                        }
                    }
                    
                    if ($shouldInclude) {
                        // Check if deck entry already exists to prevent duplicates
                        $stmt = $pdo->prepare("SELECT id FROM game_decks WHERE game_id = ? AND player_id = ? AND card_id = ?");
                        $stmt->execute([$gameId, $player['id'], $card['id']]);
                        if (!$stmt->fetch()) {
                            $stmt = $pdo->prepare("
                                INSERT INTO game_decks (game_id, player_id, card_id, remaining_quantity) 
                                VALUES (?, ?, ?, ?)
                            ");
                            $stmt->execute([$gameId, $player['id'], $card['id'], $card['quantity']]);
                        }
                    }
                }
            }
        }
        
        // Give initial serve cards to player hands (not decks)
        foreach ($players as $player) {
            giveInitialServeCards($gameId, $player['id'], $player['gender']);
        }
        
        return ['success' => true];
    } catch (Exception $e) {
        error_log("Error initializing digital game: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to initialize cards'];
    }
}

function giveInitialServeCards($gameId, $playerId, $playerGender) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Get serve cards for this player's gender from their deck
        $genderField = ($playerGender === 'male') ? 'serve_to_her' : 'serve_to_him';
        
        $stmt = $pdo->prepare("
            SELECT gd.card_id, gd.remaining_quantity, c.quantity as original_quantity
            FROM game_decks gd
            JOIN cards c ON gd.card_id = c.id
            WHERE gd.game_id = ? AND gd.player_id = ? AND c.card_type = 'serve' AND c.$genderField = 1
        ");
        $stmt->execute([$gameId, $playerId]);
        $serveCards = $stmt->fetchAll();
        
        foreach ($serveCards as $card) {
            // Add to player's hand
            $stmt = $pdo->prepare("
                INSERT INTO player_cards (game_id, player_id, card_id, card_type, quantity)
                VALUES (?, ?, ?, 'serve', ?)
            ");
            $stmt->execute([$gameId, $playerId, $card['card_id'], $card['remaining_quantity']]);
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Error giving initial serve cards: " . $e->getMessage());
        return false;
    }
}

function getPlayerCards($gameId, $playerId, $cardType = null) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        $sql = "
            SELECT pc.*, c.card_name, c.card_description, c.card_points,
                   c.serve_to_her, c.serve_to_him, c.for_her, c.for_him,
                   c.extra_spicy, c.veto_subtract, c.veto_steal,
                   c.veto_draw_chance, c.veto_draw_snap_dare, c.veto_draw_spicy,
                   c.timer, c.challenge_modify, c.snap_modify, c.dare_modify, c.spicy_modify,
                   c.before_next_challenge, c.veto_modify, c.score_modify
            FROM player_cards pc
            JOIN cards c ON pc.card_id = c.id
            WHERE pc.game_id = ? AND pc.player_id = ?
        ";
        
        $params = [$gameId, $playerId];
        
        if ($cardType) {
            $sql .= " AND pc.card_type = ?";
            $params[] = $cardType;
        }
        
        $sql .= " ORDER BY c.card_name";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Error getting player cards: " . $e->getMessage());
        return [];
    }
}

function serveCard($gameId, $fromPlayerId, $toPlayerId, $cardId) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Verify player has this serve card
        $stmt = $pdo->prepare("
            SELECT quantity FROM player_cards 
            WHERE game_id = ? AND player_id = ? AND card_id = ? AND card_type = 'serve'
        ");
        $stmt->execute([$gameId, $fromPlayerId, $cardId]);
        $quantity = $stmt->fetchColumn();
        
        if (!$quantity || $quantity < 1) {
            throw new Exception("Card not available to serve");
        }
        
        // Get card details for notification
        $stmt = $pdo->prepare("SELECT card_name FROM cards WHERE id = ?");
        $stmt->execute([$cardId]);
        $cardName = $stmt->fetchColumn();
        
        // Get sender name for notification
        $stmt = $pdo->prepare("SELECT first_name FROM players WHERE id = ?");
        $stmt->execute([$fromPlayerId]);
        $senderName = $stmt->fetchColumn();
        
        // Get recipient FCM token
        $stmt = $pdo->prepare("SELECT fcm_token FROM players WHERE id = ?");
        $stmt->execute([$toPlayerId]);
        $recipientToken = $stmt->fetchColumn();
        
        // Remove card from sender's hand
        if ($quantity > 1) {
            $stmt = $pdo->prepare("
                UPDATE player_cards 
                SET quantity = quantity - 1 
                WHERE game_id = ? AND player_id = ? AND card_id = ? AND card_type = 'serve'
            ");
        } else {
            $stmt = $pdo->prepare("
                DELETE FROM player_cards 
                WHERE game_id = ? AND player_id = ? AND card_id = ? AND card_type = 'serve'
            ");
        }
        $stmt->execute([$gameId, $fromPlayerId, $cardId]);
        
        // Add card directly to recipient's hand as accepted_serve
        $success = addCardToHand($gameId, $toPlayerId, $cardId, 'accepted_serve', 1, true);
        
        if (!$success) {
            return ['success' => false, 'message' => 'Failed to add card to hand'];
        }
        
        // Send push notification
        if ($recipientToken) {
            sendPushNotification(
                $recipientToken,
                "You've been served!",
                "$senderName has served you the $cardName card, check it out in your hand."
            );
        }
        
        return ['success' => true];
        
    } catch (Exception $e) {
        error_log("Error serving card: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function drawCards($gameId, $playerId, $cardType, $quantity = 1) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Get player info for gender restrictions
        $player = getPlayerById($playerId);
        
        // Build gender restriction
        if ($cardType === 'spicy' || $cardType === 'chance') {
            $genderField = ($player['gender'] === 'male') ? 'for_him' : 'for_her';
            $genderWhere = "AND (c.$genderField = 1 OR (c.for_her = 1 AND c.for_him = 1))";
        }
        
        // Get available cards from deck
        $stmt = $pdo->prepare("
            SELECT gd.card_id, gd.remaining_quantity, c.card_name, c.*
            FROM game_decks gd
            JOIN cards c ON gd.card_id = c.id
            WHERE gd.game_id = ? AND gd.player_id = ? AND c.card_type = ? AND gd.remaining_quantity > 0
            ORDER BY RAND()
            LIMIT ?
        ");
        $stmt->execute([$gameId, $playerId, $cardType, $quantity]);
        $availableCards = $stmt->fetchAll();
        
        $drawnCards = [];
        foreach ($availableCards as $card) {
            $drawQuantity = min(1, $card['remaining_quantity']);
            
            // Remove from deck
            $stmt = $pdo->prepare("
                UPDATE game_decks 
                SET remaining_quantity = remaining_quantity - ? 
                WHERE game_id = ? AND player_id = ? AND card_id = ?
            ");
            $stmt->execute([$drawQuantity, $gameId, $playerId, $card['card_id']]);
            
            // Add to player hand
            $success = addCardToHand($gameId, $playerId, $card['card_id'], $cardType, $drawQuantity, true);
            
            if ($success) {
                $drawnCards[] = $card['card_name'];
                
                // Process chance cards immediately
                if ($cardType === 'chance') {
                    $effects = processChanceCard($gameId, $playerId, $card);
                }
            }
            
            if (count($drawnCards) >= $quantity) break;
        }
        
        return $drawnCards;
    } catch (Exception $e) {
        error_log("Error drawing cards: " . $e->getMessage());
        return [];
    }
}

function addCardToHand($gameId, $playerId, $cardId, $cardType, $quantity = 1, $forceAdd = false) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Check current hand count for this type (limit 5, unless forced)
        if (!$forceAdd) {
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(quantity), 0) as total 
                FROM player_cards 
                WHERE game_id = ? AND player_id = ? AND card_type = ?
            ");
            $stmt->execute([$gameId, $playerId, $cardType]);
            $currentCount = $stmt->fetchColumn();
            
            if ($currentCount >= 5) {
                return false; // Hand full
            }
            
            $addQuantity = min($quantity, 5 - $currentCount);
        } else {
            $addQuantity = $quantity;
        }
        
        // Check if player already has this card
        $stmt = $pdo->prepare("
            SELECT id, quantity FROM player_cards 
            WHERE game_id = ? AND player_id = ? AND card_id = ? AND card_type = ?
        ");
        $stmt->execute([$gameId, $playerId, $cardId, $cardType]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            // Update existing
            $stmt = $pdo->prepare("
                UPDATE player_cards 
                SET quantity = quantity + ? 
                WHERE id = ?
            ");
            $stmt->execute([$addQuantity, $existing['id']]);
        } else {
            // Insert new
            $stmt = $pdo->prepare("
                INSERT INTO player_cards (game_id, player_id, card_id, card_type, quantity)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$gameId, $playerId, $cardId, $cardType, $addQuantity]);
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Error adding card to hand: " . $e->getMessage());
        return false;
    }
}

function completeHandCard($gameId, $playerId, $cardId, $playerCardId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $pdo->beginTransaction();
        
        // Get card details
        $stmt = $pdo->prepare("
            SELECT pc.*, c.*
            FROM player_cards pc
            JOIN cards c ON pc.card_id = c.id
            WHERE pc.id = ? AND pc.player_id = ? AND pc.game_id = ?
        ");
        $stmt->execute([$playerCardId, $playerId, $gameId]);
        $playerCard = $stmt->fetch();
        
        if (!$playerCard) {
            throw new Exception("Card not found in player's hand");
        }
        
        $pointsAwarded = 0;
        
        // Handle chance cards
        if ($playerCard['card_type'] === 'chance') {
            $effects = processChanceCard($gameId, $playerId, $playerCard);
            $result = ['success' => true, 'effects' => $effects, 'message' => implode(', ', $effects)];

            // Get timer IDs directly
            $stmt = $pdo->prepare("SELECT id FROM timers WHERE game_id = ? AND player_id = ? AND description = ?");
            $stmt->execute([$gameId, $playerId, $playerCard['card_name']]);
            $timerIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Delete timers
            foreach ($timerIds as $timerId) {
                deleteTimer($timerId, $gameId);
            }
            
            // Remove active effects
            $stmt = $pdo->prepare("DELETE FROM active_chance_effects WHERE game_id = ? AND player_id = ? AND chance_card_id = ?");
            $stmt->execute([$gameId, $playerId, $playerCard['card_id']]);
        }
        
        // Handle serve cards with points
        if (($playerCard['card_type'] === 'serve' || $playerCard['card_type'] === 'accepted_serve') 
            && $playerCard['card_points']) {
            
            // Check for blocking effects
            if (hasBlockingChanceCard($gameId, $playerId)) {
                $blockingCards = getBlockingChanceCardNames($gameId, $playerId);
                throw new Exception("Complete your chance card first: " . implode(', ', $blockingCards));
            }
            
            // Apply challenge modifiers
            $finalPoints = $playerCard['card_points'];
            $challengeEffects = getActiveChanceEffects($gameId, 'challenge_modify');

            foreach ($challengeEffects as $effect) {
                // Check if this effect applies to this player
                $appliesToThisPlayer = ($effect['target_player_id'] == $playerId) || 
                                      ($effect['target_player_id'] == null && $effect['player_id'] == $playerId);
                
                if ($appliesToThisPlayer) {
                    switch ($effect['effect_value']) {
                        case 'half':
                            if ($finalPoints > 1) {
                                $finalPoints = floor($finalPoints / 2);
                            }
                            break;
                        case 'zero':
                            $finalPoints = 0;
                            break;
                        case 'opponent_double':
                            $finalPoints *= 2;
                            break;
                        case 'opponent_extra_point':
                            $finalPoints += 1;
                            break;
                        case 'challenge_reward_opponent':
                            $opponentId = getOpponentPlayerId($gameId, $playerId);
                            updateScore($gameId, $opponentId, $finalPoints, $playerId);
                            $finalPoints = 0;
                            break;
                    }
                    // Remove the effect after applying it
                    removeActiveChanceEffect($effect['id']);
                    break; // Only apply first matching effect
                }
            }

            updateScore($gameId, $playerId, $finalPoints, $playerId);
            $pointsAwarded = $finalPoints;
        }
        
        // Remove card from player's hand
        if ($playerCard['quantity'] > 1) {
            $stmt = $pdo->prepare("UPDATE player_cards SET quantity = quantity - 1 WHERE id = ?");
            $stmt->execute([$playerCardId]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM player_cards WHERE id = ?");
            $stmt->execute([$playerCardId]);
        }

        // After completing serve/snap/dare/spicy cards, check for and activate next modifier
        if (in_array($playerCard['card_type'], ['accepted_serve', 'snap', 'dare', 'spicy'])) {
            $effectType = $playerCard['card_type'] === 'accepted_serve' ? 'challenge_modify' : $playerCard['card_type'] . '_modify';
            $modifyEffects = getActiveChanceEffects($gameId, $effectType, $playerId);
            
            foreach ($modifyEffects as $effect) {
                // Remove the chance card from hand and active effect
                $stmt = $pdo->prepare("DELETE FROM player_cards WHERE game_id = ? AND player_id = ? AND card_id = ?");
                $stmt->execute([$gameId, $playerId, $effect['chance_card_id']]);
                removeActiveChanceEffect($effect['id']);
            }
            
            // Check for next modifier card in hand and activate it
            $fieldName = $playerCard['card_type'] === 'accepted_serve' ? 'challenge_modify' : $effectType;
            $stmt = $pdo->prepare("
                SELECT pc.*, c.* FROM player_cards pc
                JOIN cards c ON pc.card_id = c.id
                WHERE pc.game_id = ? AND pc.player_id = ? AND c.card_type = 'chance' AND c.{$fieldName} = 1
                ORDER BY pc.id ASC LIMIT 1
            ");
            $stmt->execute([$gameId, $playerId]);
            $nextModifier = $stmt->fetch();
            
            // Only activate next modifier if no timer-based challenge effects are active
            $timerEffects = getActiveChanceEffects($gameId, 'timer_effect', $playerId);
            $hasActiveTimerEffect = false;
            foreach ($timerEffects as $timerEffect) {
                // Check if any timer effect modifies challenges
                $stmt2 = $pdo->prepare("SELECT challenge_modify, score_modify FROM cards WHERE id = ?");
                $stmt2->execute([$timerEffect['chance_card_id']]);
                $timerCard = $stmt2->fetch();
                if ($timerCard['challenge_modify'] && $timerCard['score_modify'] === 'challenge_reward_opponent') {
                    $hasActiveTimerEffect = true;
                    break;
                }
            }

            if (!$hasActiveTimerEffect && $nextModifier) {
                $modifyValue = $nextModifier['double_it'] ? 'double' : 'modify';
                if ($playerCard['card_type'] === 'accepted_serve') {
                    $modifyValue = $nextModifier['score_modify'] !== 'none' ? $nextModifier['score_modify'] : 'modify';
                }
                addActiveChanceEffect($gameId, $playerId, $nextModifier['card_id'], $effectType, $modifyValue);
            }
            
            checkRecurringEffectCompletion($gameId, $playerId, $playerCard['card_type']);
        }
        
        $pdo->commit();
        return ['success' => true, 'points_awarded' => $pointsAwarded];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error completing hand card: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function vetoHandCard($gameId, $playerId, $cardId, $playerCardId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $pdo->beginTransaction();
        
        // Get card details
        $stmt = $pdo->prepare("
            SELECT pc.*, c.*
            FROM player_cards pc
            JOIN cards c ON pc.card_id = c.id
            WHERE pc.id = ? AND pc.player_id = ? AND pc.game_id = ?
        ");
        $stmt->execute([$playerCardId, $playerId, $gameId]);
        $playerCard = $stmt->fetch();
        
        if (!$playerCard) {
            throw new Exception("Card not found in player's hand");
        }
        
        $penalties = [];
        
        // Apply veto modifiers
        $vetoEffects = getActiveChanceEffects($gameId, 'veto_modify');
        $vetoMultiplier = 1;
        $vetoSkipped = false;

        foreach ($vetoEffects as $effect) {
            $appliesToThisPlayer = ($effect['target_player_id'] == $playerId) || 
                                    ($effect['target_player_id'] == null && $effect['player_id'] == $playerId);
            
            if ($appliesToThisPlayer) {
                switch ($effect['effect_value']) {
                    case 'double':
                    case 'opponent_double':
                        $vetoMultiplier = 2;
                        removeActiveChanceEffect($effect['id']);
                        break;
                    case 'skip':
                        $vetoSkipped = true;
                        removeActiveChanceEffect($effect['id']);
                        break;
                    case 'opponent_reward':
                        if ($playerCard['card_points']) {
                            $opponentId = getOpponentPlayerId($gameId, $playerId);
                            updateScore($gameId, $opponentId, $playerCard['card_points'], $playerId);
                            $penalties[] = "Opponent gained {$playerCard['card_points']} points";
                        }
                        removeActiveChanceEffect($effect['id']);
                        break;
                }
                break;
            }
        }
        
        // Apply veto penalties
        if (!$vetoSkipped) {
            if ($playerCard['veto_subtract']) {
                $penaltyPoints = $playerCard['veto_subtract'] * $vetoMultiplier;
                updateScore($gameId, $playerId, -$penaltyPoints, $playerId);
                $penalties[] = "Lost {$penaltyPoints} points";
            }
            
            if ($playerCard['veto_steal']) {
                $penaltyPoints = $playerCard['veto_steal'] * $vetoMultiplier;
                $opponentId = getOpponentPlayerId($gameId, $playerId);
                
                if ($opponentId) {
                    updateScore($gameId, $playerId, -$penaltyPoints, $playerId);
                    updateScore($gameId, $opponentId, $penaltyPoints, $playerId);
                    $penalties[] = "Lost {$penaltyPoints} points to opponent";
                }
            }
            
            if ($playerCard['veto_draw_chance']) {
                $drawCount = $playerCard['veto_draw_chance'] * $vetoMultiplier;
                drawCards($gameId, $playerId, 'chance', $drawCount);
                $penalties[] = "Drew {$drawCount} chance card(s)";
            }
            
            if ($playerCard['veto_draw_snap_dare']) {
                $drawCount = $playerCard['veto_draw_snap_dare'] * $vetoMultiplier;
                $player = getPlayerById($playerId);
                $drawType = ($player['gender'] === 'female') ? 'snap' : 'dare';
                drawCards($gameId, $playerId, $drawType, $drawCount);
                $penalties[] = "Drew {$drawCount} {$drawType} card(s)";
            }
            
            if ($playerCard['veto_draw_spicy']) {
                $drawCount = $playerCard['veto_draw_spicy'] * $vetoMultiplier;
                drawCards($gameId, $playerId, 'spicy', $drawCount);
                $penalties[] = "Drew {$drawCount} spicy card(s)";
            }
        } else {
            $penalties[] = "Veto penalty skipped";
        }
        
        // Handle non-veto card types
        switch ($playerCard['card_type']) {
            case 'snap':
            case 'dare':
                if (!$vetoSkipped) {
                    updateScore($gameId, $playerId, -3, $playerId);
                    $penalties[] = "Lost 3 points";
                }
                returnCardToDeck($gameId, $playerCard['card_id'], 1);
                break;
                
            case 'spicy':
            case 'chance':
                returnCardToDeck($gameId, $playerCard['card_id'], 1);
                $penalties[] = "Card returned to deck";
                break;
        }
        
        // Remove from hand
        if ($playerCard['quantity'] > 1) {
            $stmt = $pdo->prepare("UPDATE player_cards SET quantity = quantity - 1 WHERE id = ?");
            $stmt->execute([$playerCardId]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM player_cards WHERE id = ?");
            $stmt->execute([$playerCardId]);
        }
        
        
        $pdo->commit();

        if ($vetoMultiplier > 1 || $vetoSkipped) {
            // Check for next veto modifier card in hand and activate it
            $stmt = $pdo->prepare("
                SELECT pc.*, c.* FROM player_cards pc
                JOIN cards c ON pc.card_id = c.id
                WHERE pc.game_id = ? AND pc.player_id = ? AND c.card_type = 'chance' AND c.veto_modify != 'none'
                ORDER BY pc.id ASC LIMIT 1
            ");
            $stmt->execute([$gameId, $playerId]);
            $nextModifier = $stmt->fetch();
            
            if ($nextModifier) {
                $targetId = (strpos($nextModifier['veto_modify'], 'opponent') !== false) ? 
                        getOpponentPlayerId($gameId, $playerId) : $playerId;
                addActiveChanceEffect($gameId, $playerId, $nextModifier['card_id'], 'veto_modify', 
                                    $nextModifier['veto_modify'], $targetId);
            }
        }
        return ['success' => true, 'penalties' => $penalties];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error vetoing hand card: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function returnCardToDeck($gameId, $cardId, $quantity = 1) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        $stmt = $pdo->prepare("SELECT id FROM game_decks WHERE game_id = ? AND card_id = ?");
        $stmt->execute([$gameId, $cardId]);
        $deckCard = $stmt->fetch();
        
        if ($deckCard) {
            $stmt = $pdo->prepare("UPDATE game_decks SET remaining_quantity = remaining_quantity + ? WHERE id = ?");
            $stmt->execute([$quantity, $deckCard['id']]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO game_decks (game_id, card_id, remaining_quantity) VALUES (?, ?, ?)");
            $stmt->execute([$gameId, $cardId, $quantity]);
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Error returning card to deck: " . $e->getMessage());
        return false;
    }
}

function executeCardEffects($card, $gameId, $playerId) {
    $effects = [];
    
    // For serve cards, add to hand with original card type
    if ($card['card_type'] === 'serve') {
        // Add serve card to player's hand for completion later
        $result = addCardToHand($gameId, $playerId, $card['card_id'], 'accepted_serve', 1);
        if ($result) {
            $effects[] = "Added \"{$card['card_name']}\" to your hand";
        }
    }
    
    return $effects;
}

function getPlayerById($playerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("SELECT * FROM players WHERE id = ?");
        $stmt->execute([$playerId]);
        return $stmt->fetch();
    } catch (Exception $e) {
        error_log("Error getting player by ID: " . $e->getMessage());
        return null;
    }
}

// Chance Card Effects
function addActiveChanceEffect($gameId, $playerId, $chanceCardId, $effectType, $effectValue = null, $targetPlayerId = null, $timerId = null, $expiresAt = null) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("
            INSERT INTO active_chance_effects (game_id, player_id, chance_card_id, effect_type, effect_value, target_player_id, timer_id, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$gameId, $playerId, $chanceCardId, $effectType, $effectValue, $targetPlayerId, $timerId, $expiresAt]);
        return $pdo->lastInsertId();
    } catch (Exception $e) {
        error_log("Error adding active chance effect: " . $e->getMessage());
        return false;
    }
}

function getActiveChanceEffects($gameId, $effectType = null, $playerId = null) {
    try {
        $pdo = Config::getDatabaseConnection();
        $sql = "SELECT * FROM active_chance_effects WHERE game_id = ?";
        $params = [$gameId];
        
        if ($effectType) {
            $sql .= " AND effect_type = ?";
            $params[] = $effectType;
        }
        
        if ($playerId) {
            $sql .= " AND (player_id = ? OR target_player_id = ?)";
            $params[] = $playerId;
            $params[] = $playerId;
        }
        
        $sql .= " ORDER BY created_at ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Error getting active chance effects: " . $e->getMessage());
        return [];
    }
}

function removeActiveChanceEffect($effectId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("DELETE FROM active_chance_effects WHERE id = ?");
        $stmt->execute([$effectId]);
        return true;
    } catch (Exception $e) {
        error_log("Error removing active chance effect: " . $e->getMessage());
        return false;
    }
}

function clearExpiredChanceEffects($gameId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("DELETE FROM active_chance_effects WHERE game_id = ? AND expires_at IS NOT NULL AND expires_at <= NOW()");
        $stmt->execute([$gameId]);
        return true;
    } catch (Exception $e) {
        error_log("Error clearing expired chance effects: " . $e->getMessage());
        return false;
    }
}

function processChanceCard($gameId, $playerId, $cardData) {
    error_log("Processing chance card: " . $cardData['card_name'] . " - challenge_modify: " . $cardData['challenge_modify'] . " - score_modify: " . $cardData['score_modify']);

    if (isset($cardData['card_id'])) {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("SELECT * FROM cards WHERE id = ?");
        $stmt->execute([$cardData['card_id']]);
        $cardData = $stmt->fetch();
    }

    $effects = [];
    
    // Immediate effects
    if ($cardData['score_add']) {
        $effects[] = "Gained {$cardData['score_add']} points";
    }
    
    if ($cardData['score_subtract']) {
        $effects[] = "Lost {$cardData['score_subtract']} points";
    }
    
    if ($cardData['score_steal']) {
        $effects[] = "Stole {$cardData['score_steal']} points from opponent";
    }
    
    if ($cardData['draw_snap_dare']) {
        $player = getPlayerById($playerId);
        $drawType = ($player['gender'] === 'female') ? 'snap' : 'dare';
        $drawnCards = drawCards($gameId, $playerId, $drawType, $cardData['draw_snap_dare']);
        if (!empty($drawnCards)) {
            $effects[] = "Drew {$cardData['draw_snap_dare']} {$drawType} card(s): " . implode(', ', $drawnCards);
        }
    }
    
    if ($cardData['draw_spicy']) {
        $drawnCards = drawCards($gameId, $playerId, 'spicy', $cardData['draw_spicy']);
        if (!empty($drawnCards)) {
            $effects[] = "Drew {$cardData['draw_spicy']} spicy card(s): " . implode(', ', $drawnCards);
        }
    }
    
    // Store pending effects
    if ($cardData['before_next_challenge']) {
    $existingEffect = getActiveChanceEffects($gameId, 'before_next_challenge', $playerId);
    if (empty($existingEffect)) {
        addActiveChanceEffect($gameId, $playerId, $cardData['id'], 'before_next_challenge');
        $effects[] = "Must complete before next challenge";
    } else {
        $effects[] = "Challenge blocker already active - complete current one first";
    }
    }

    if ($cardData['challenge_modify']) {
    $existingEffect = getActiveChanceEffects($gameId, 'challenge_modify', $playerId);
    if (empty($existingEffect)) {
        $modifyValue = $cardData['score_modify'] !== 'none' ? $cardData['score_modify'] : 'modify';
        addActiveChanceEffect($gameId, $playerId, $cardData['id'], 'challenge_modify', $modifyValue);
        $effects[] = "Next challenge modified";
    } else {
        $effects[] = "Challenge modifier already active - complete current one first";
    }
    }

    if ($cardData['opponent_challenge_modify']) {
    $existingEffect = getActiveChanceEffects($gameId, 'challenge_modify');
    $opponentEffect = false;
    foreach ($existingEffect as $effect) {
        if ($effect['target_player_id'] == getOpponentPlayerId($gameId, $playerId)) {
            $opponentEffect = true;
            break;
        }
    }
    
    if (!$opponentEffect) {
        $modifyValue = $cardData['score_modify'] !== 'none' ? $cardData['score_modify'] : 'modify';
        $opponentId = getOpponentPlayerId($gameId, $playerId);
        addActiveChanceEffect($gameId, $playerId, $cardData['id'], 'challenge_modify', $modifyValue, $opponentId);
        $effects[] = "Opponent's next challenge modified";
    } else {
        $effects[] = "Opponent challenge modifier already active";
    }
    }

    if ($cardData['veto_modify'] !== 'none') {
    $existingEffect = getActiveChanceEffects($gameId, 'veto_modify', $playerId);
    if (empty($existingEffect)) {
        $targetId = (strpos($cardData['veto_modify'], 'opponent') !== false) ? getOpponentPlayerId($gameId, $playerId) : $playerId;
        addActiveChanceEffect($gameId, $playerId, $cardData['id'], 'veto_modify', $cardData['veto_modify'], $targetId);
        $effects[] = "Next veto modified: {$cardData['veto_modify']}";
    } else {
        $effects[] = "Veto modifier already active - complete current one first";
    }
    }

    if ($cardData['snap_modify']) {
        // Check if there's already an active effect
        $existingEffect = getActiveChanceEffects($gameId, 'snap_modify', $playerId);
        if (empty($existingEffect)) {
            addActiveChanceEffect($gameId, $playerId, $cardData['id'], 'snap_modify', $cardData['double_it'] ? 'double' : 'modify');
            $effects[] = "Next snap card modified";
        } else {
            $effects[] = "Snap modifier already active - complete current one first";
        }
    }

    if ($cardData['dare_modify']) {
        // Check if there's already an active effect
        $existingEffect = getActiveChanceEffects($gameId, 'dare_modify', $playerId);
        if (empty($existingEffect)) {
            addActiveChanceEffect($gameId, $playerId, $cardData['id'], 'dare_modify', $cardData['double_it'] ? 'double' : 'modify');
            $effects[] = "Next dare card modified";
        } else {
            $effects[] = "Dare modifier already active - complete current one first";
        }
    }

    if ($cardData['spicy_modify']) {
        // Check if there's already an active effect
        $existingEffect = getActiveChanceEffects($gameId, 'spicy_modify', $playerId);
        if (empty($existingEffect)) {
            addActiveChanceEffect($gameId, $playerId, $cardData['id'], 'spicy_modify', $cardData['double_it'] ? 'double' : 'modify');
            $effects[] = "Next spicy card modified";
        } else {
            $effects[] = "Spicy modifier already active - complete current one first";
        }
    }

    // Handle recurring effects (Clock Siphon)
    if ($cardData['repeat_count']) {
        $timerResult = createTimer($gameId, $playerId, $cardData['card_name'], $cardData['repeat_count']);
        
        if ($timerResult['success']) {
            $scoreValue = $cardData['score_subtract'] ?: 1; // Use card's subtract value or default to 1
            addActiveChanceEffect($gameId, $playerId, $cardData['id'], 'recurring_timer', $scoreValue, null, $timerResult['timer_id']);
        }
    }
    
    // Handle timer-based effects
    if ($cardData['timer']) {
        $timerResult = createTimer($gameId, $playerId, $cardData['card_name'], $cardData['timer']);
        
        if ($timerResult['success']) {
            if ($cardData['repeat_count']) {
                // Recurring timer (Clock Siphon)
                addActiveChanceEffect($gameId, $playerId, $cardData['id'], 'recurring_timer', $cardData['repeat_count'], null, $timerResult['timer_id']);
            } else {
                // Regular timer-based effect
                addActiveChanceEffect($gameId, $playerId, $cardData['id'], 'timer_effect', null, null, $timerResult['timer_id']);
            }
        }
    }

    // Auto-complete cards with only immediate effects
    $hasOnlyImmediateEffects = ($cardData['score_add'] || $cardData['score_subtract'] || $cardData['score_steal'] || $cardData['draw_snap_dare'] || $cardData['draw_spicy']) &&
        !$cardData['before_next_challenge'] &&
        !$cardData['challenge_modify'] &&
        !$cardData['opponent_challenge_modify'] &&
        $cardData['veto_modify'] === 'none' &&
        !$cardData['snap_modify'] &&
        !$cardData['dare_modify'] &&
        !$cardData['spicy_modify'] &&
        !$cardData['timer'] &&
        !$cardData['repeat_count'];

    if ($hasOnlyImmediateEffects) {
        // Remove from player's hand
        $stmt = $pdo->prepare("DELETE FROM player_cards WHERE game_id = ? AND player_id = ? AND card_id = ?");
        $stmt->execute([$gameId, $playerId, $cardData['id']]);
        $effects[] = "Card auto-completed";
    }
    
    return $effects;
}

function getOpponentPlayerId($gameId, $playerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("SELECT id FROM players WHERE game_id = ? AND id != ?");
        $stmt->execute([$gameId, $playerId]);
        return $stmt->fetchColumn();
    } catch (Exception $e) {
        error_log("Error getting opponent ID: " . $e->getMessage());
        return null;
    }
}

function checkRecurringEffectCompletion($gameId, $playerId, $cardType) {
   if (!in_array($cardType, ['snap', 'dare', 'spicy'])) {
       return;
   }
   
   $pdo = Config::getDatabaseConnection();
   
   // Find and remove any recurring timer effects (Clock Siphon)
   $recurringEffects = getActiveChanceEffects($gameId, 'recurring_timer', $playerId);
   foreach ($recurringEffects as $effect) {
       // Get the player_card_id and call completeHandCard
       $stmt = $pdo->prepare("SELECT id FROM player_cards WHERE game_id = ? AND player_id = ? AND card_id = ?");
       $stmt->execute([$gameId, $playerId, $effect['chance_card_id']]);
       $playerCardId = $stmt->fetchColumn();

       if ($playerCardId) {
           completeHandCard($gameId, $playerId, $effect['chance_card_id'], $playerCardId);
       }
   }
}

function hasBlockingChanceCard($gameId, $playerId) {
    $blockingEffects = getActiveChanceEffects($gameId, 'before_next_challenge', $playerId);
    return !empty($blockingEffects);
}

function getBlockingChanceCardNames($gameId, $playerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("
            SELECT c.card_name 
            FROM active_chance_effects ace
            JOIN cards c ON ace.chance_card_id = c.id
            WHERE ace.game_id = ? AND ace.player_id = ? AND ace.effect_type = 'before_next_challenge'
        ");
        $stmt->execute([$gameId, $playerId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        error_log("Error getting blocking card names: " . $e->getMessage());
        return [];
    }
}
?>