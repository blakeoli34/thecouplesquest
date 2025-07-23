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
        
        // Get all cards and populate game decks
        $stmt = $pdo->prepare("SELECT * FROM cards ORDER BY card_type, card_name");
        $stmt->execute();
        $cards = $stmt->fetchAll();
        
        foreach ($cards as $card) {
            // Only add cards with quantity > 0
            if ($card['quantity'] > 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO game_decks (game_id, card_id, remaining_quantity) 
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$gameId, $card['id'], $card['quantity']]);
            }
        }
        
        // Give players their serve cards
        $players = getGamePlayers($gameId);
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
        
        // Get serve cards for this player's gender
        $genderField = ($playerGender === 'male') ? 'serve_to_her' : 'serve_to_him';
        
        $stmt = $pdo->prepare("
            SELECT c.id, c.quantity 
            FROM cards c
            JOIN game_decks gd ON c.id = gd.card_id
            WHERE gd.game_id = ? AND c.card_type = 'serve' AND c.$genderField = 1
        ");
        $stmt->execute([$gameId]);
        $serveCards = $stmt->fetchAll();
        
        foreach ($serveCards as $card) {
            // Add to player's hand
            $stmt = $pdo->prepare("
                INSERT INTO player_cards (game_id, player_id, card_id, card_type, quantity)
                VALUES (?, ?, ?, 'serve', ?)
            ");
            $stmt->execute([$gameId, $playerId, $card['id'], $card['quantity']]);
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
                   c.timer
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
        $genderWhere = "";
        if ($cardType === 'snap') {
            $genderWhere = ""; // Remove gender restriction since snap cards are inherently for females
        } elseif ($cardType === 'dare') {
            $genderWhere = ""; // Remove gender restriction since dare cards are inherently for males
        } elseif ($cardType === 'spicy' || $cardType === 'chance') {
            $genderField = ($player['gender'] === 'male') ? 'for_him' : 'for_her';
            $genderWhere = "AND c.$genderField = 1";
        }
        
        // Get available cards from deck
        $stmt = $pdo->prepare("
            SELECT gd.card_id, gd.remaining_quantity, c.card_name
            FROM game_decks gd
            JOIN cards c ON gd.card_id = c.id
            WHERE gd.game_id = ? AND c.card_type = ? AND gd.remaining_quantity > 0 $genderWhere
            ORDER BY RAND()
            LIMIT ?
        ");
        $stmt->execute([$gameId, $cardType, $quantity]);
        $availableCards = $stmt->fetchAll();
        
        $drawnCards = [];
        foreach ($availableCards as $card) {
            $drawQuantity = min(1, $card['remaining_quantity']);
            
            // Remove from deck
            $stmt = $pdo->prepare("
                UPDATE game_decks 
                SET remaining_quantity = remaining_quantity - ? 
                WHERE game_id = ? AND card_id = ?
            ");
            $stmt->execute([$drawQuantity, $gameId, $card['card_id']]);
            
            // Add to player hand - force add even if at limit for veto penalties
            $success = addCardToHand($gameId, $playerId, $card['card_id'], $cardType, $drawQuantity, true);
            
            if ($success) {
                $drawnCards[] = $card['card_name'];
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
        
        // Handle completion based on card type
        if (($playerCard['card_type'] === 'serve' || $playerCard['card_type'] === 'accepted_serve') 
            && $playerCard['card_points']) {
            updateScore($gameId, $playerId, $playerCard['card_points'], $playerId);
            $pointsAwarded = $playerCard['card_points'];
        }
        
        // Remove card from player's hand
        if ($playerCard['quantity'] > 1) {
            $stmt = $pdo->prepare("UPDATE player_cards SET quantity = quantity - 1 WHERE id = ?");
            $stmt->execute([$playerCardId]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM player_cards WHERE id = ?");
            $stmt->execute([$playerCardId]);
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
        
        // Handle veto based on card type
        switch ($playerCard['card_type']) {
            case 'serve':
            case 'accepted_serve':
                // Apply original serve card veto penalties
                if ($playerCard['veto_subtract']) {
                    updateScore($gameId, $playerId, -$playerCard['veto_subtract'], $playerId);
                    $penalties[] = "Lost {$playerCard['veto_subtract']} points";
                }
                
                if ($playerCard['veto_steal']) {
                    $stmt = $pdo->prepare("SELECT id FROM players WHERE game_id = ? AND id != ?");
                    $stmt->execute([$gameId, $playerId]);
                    $opponentId = $stmt->fetchColumn();
                    
                    if ($opponentId) {
                        updateScore($gameId, $playerId, -$playerCard['veto_steal'], $playerId);
                        updateScore($gameId, $opponentId, $playerCard['veto_steal'], $playerId);
                        $penalties[] = "Lost {$playerCard['veto_steal']} points to opponent";
                    }
                }
                
                if ($playerCard['veto_draw_chance']) {
                    drawCards($gameId, $playerId, 'chance', $playerCard['veto_draw_chance']);
                    $penalties[] = "Drew {$playerCard['veto_draw_chance']} chance card(s)";
                }
                
                if ($playerCard['veto_draw_snap_dare']) {
                    $player = getPlayerById($playerId);
                    $drawType = ($player['gender'] === 'female') ? 'snap' : 'dare';
                    drawCards($gameId, $playerId, $drawType, $playerCard['veto_draw_snap_dare']);
                    $penalties[] = "Drew {$playerCard['veto_draw_snap_dare']} {$drawType} card(s)";
                }
                
                if ($playerCard['veto_draw_spicy']) {
                    drawCards($gameId, $playerId, 'spicy', $playerCard['veto_draw_spicy']);
                    $penalties[] = "Drew {$playerCard['veto_draw_spicy']} spicy card(s)";
                }
                break;
                
            case 'snap':
            case 'dare':
                // Lose 3 points, return to deck
                updateScore($gameId, $playerId, -3, $playerId);
                $penalties[] = "Lost 3 points";
                returnCardToDeck($gameId, $playerCard['card_id'], 1);
                break;
                
            case 'spicy':
            case 'chance':
                // Just return to deck
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
?>