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
        
        $startDate = new DateTime('now', $timezone);
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
        $seconds = $durationMinutes * 60;
        $endTime->add(new DateInterval('PT' . $seconds . 'S'));
        
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
        
        $timerId = $pdo->lastInsertId();

        // Add dynamic at job for timer expiration
        $endTimeLocal = clone $endTime;
        $endTimeLocal->setTimezone(new DateTimeZone('America/Indiana/Indianapolis'));

        // Format for at command (without seconds, we'll add sleep for precision)
        $atTime = $endTimeLocal->format('H:i M j, Y');
        $seconds = $endTimeLocal->format('s');

        // Create command that sleeps to the exact second, then executes
        $atCommand = "sleep {$seconds} && /usr/bin/php /var/www/thecouplesquest/cron.php timer_{$timerId}";

        // Schedule the job with at
        $atJob = shell_exec("echo '{$atCommand}' | at {$atTime} 2>&1");

        // Log the at job creation for debugging
        error_log("Created at job for timer {$timerId}: {$atTime} +{$seconds}s - Result: {$atJob}");

        return ['success' => true, 'timer_id' => $timerId];
        
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
        
        // Remove at job - find and remove jobs containing our timer ID
        $atJobs = shell_exec('atq 2>/dev/null') ?: '';
        $lines = explode("\n", trim($atJobs));
        
        foreach ($lines as $line) {
            if (empty($line)) continue;
            
            $parts = explode("\t", $line);
            if (count($parts) >= 1) {
                $jobId = trim($parts[0]);
                
                // Check if this job contains our timer command
                $jobContent = shell_exec("at -c {$jobId} 2>/dev/null | tail -1");
                if (strpos($jobContent, "timer_{$timerId}") !== false) {
                    shell_exec("atrm {$jobId} 2>/dev/null");
                    error_log("Removed at job {$jobId} for timer {$timerId}");
                    break;
                }
            }
        }
        
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
                // Regular timer effect - check what type it is
                $stmt = $pdo->prepare("
                    SELECT c.challenge_modify, c.score_modify, c.veto_modify, c.card_name 
                    FROM cards c 
                    WHERE id = ?
                ");
                $stmt->execute([$effect['chance_card_id']]);
                $cardInfo = $stmt->fetch();
                
                if ($cardInfo['challenge_modify'] && $cardInfo['score_modify'] === 'challenge_reward_opponent') {
                    // This is a timer-based challenge modifier like "Spoiled Wife"
                    // Remove the effect and the card from hand
                    removeActiveChanceEffect($effect['effect_id']);
                    $stmt = $pdo->prepare("DELETE FROM player_cards WHERE game_id = ? AND player_id = ? AND card_id = ?");
                    $stmt->execute([$gameId, $effect['player_id'], $effect['chance_card_id']]);
                } else {
                    // Other timer effects - auto-complete the chance card
                    removeActiveChanceEffect($effect['effect_id']);
                    $stmt = $pdo->prepare("DELETE FROM player_cards WHERE game_id = ? AND player_id = ? AND card_id = ?");
                    $stmt->execute([$gameId, $effect['player_id'], $effect['chance_card_id']]);
                }
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
    
    // Validate token format (basic check)
    if (!preg_match('/^[A-Za-z0-9_-]+:[A-Za-z0-9_-]+$/', $fcmToken) && 
        !preg_match('/^[A-Za-z0-9_-]{140,}$/', $fcmToken)) {
        error_log("FCM: Invalid token format");
        return false;
    }
    
    $data = [
        'title' => $title,
        'body' => $body
    ];
    
    $url = 'https://fcm.googleapis.com/v1/projects/' . Config::FCM_PROJECT_ID . '/messages:send';
    
    // Enhanced message structure - use data-only to prevent duplicates
    $message = [
        'message' => [
            'token' => $fcmToken,
            'data' => $data,
            'webpush' => [
                'headers' => [
                    'TTL' => '3600' // 1 hour TTL
                ]
            ]
        ]
    ];
    
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    
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
        
        $errorData = json_decode($result, true);
        if ($errorData && isset($errorData['error'])) {
            error_log("FCM error details: " . $errorData['error']['message']);
            
            // Handle token errors - mark token as invalid
            if (isset($errorData['error']['details'])) {
                foreach ($errorData['error']['details'] as $detail) {
                    if (isset($detail['errorCode']) && 
                        in_array($detail['errorCode'], ['UNREGISTERED', 'INVALID_ARGUMENT'])) {
                        error_log("FCM token invalid, clearing from database");
                        clearInvalidFcmToken($fcmToken);
                        return false;
                    }
                }
            }
        }
        return false;
    }
    
    error_log("FCM notification sent successfully");
    return true;
}

// Function to clear invalid tokens
function clearInvalidFcmToken($fcmToken) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("UPDATE players SET fcm_token = NULL WHERE fcm_token = ?");
        $stmt->execute([$fcmToken]);
        error_log("Cleared invalid FCM token from database");
    } catch (Exception $e) {
        error_log("Error clearing invalid FCM token: " . $e->getMessage());
    }
}

// Enhanced token update with validation
function updateFcmToken($deviceId, $fcmToken) {
    try {
        // Validate token format
        if (!preg_match('/^[A-Za-z0-9_-]+:[A-Za-z0-9_-]+$/', $fcmToken) && 
            !preg_match('/^[A-Za-z0-9_-]{140,}$/', $fcmToken)) {
            error_log("Invalid FCM token format provided");
            return false;
        }
        
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("UPDATE players SET fcm_token = ?, fcm_token_updated = NOW() WHERE device_id = ?");
        $stmt->execute([$fcmToken, $deviceId]);
        
        error_log("FCM token updated successfully for device: " . substr($deviceId, 0, 8) . "...");
        return true;
    } catch (Exception $e) {
        error_log("Error updating FCM token: " . $e->getMessage());
        return false;
    }
}

// Add retry mechanism for critical notifications
function sendCriticalNotification($fcmToken, $title, $body, $maxRetries = 3) {
    $attempt = 1;
    
    while ($attempt <= $maxRetries) {
        error_log("Sending critical notification (attempt $attempt/$maxRetries)");
        
        if (sendPushNotification($fcmToken, $title, $body)) {
            return true;
        }
        
        if ($attempt < $maxRetries) {
            $delay = $attempt * 2; // Progressive delay
            error_log("Notification failed, retrying in {$delay} seconds");
            sleep($delay);
        }
        
        $attempt++;
    }
    
    error_log("Critical notification failed after $maxRetries attempts");
    return false;
}

function getAccessToken() {
    
    $cacheFile = 'tokens/fcm_access_token.json';
    
    // Check if we have a cached token that's still valid
    if ($cacheFile && file_exists($cacheFile)) {
        try {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if ($cached && isset($cached['expires_at']) && $cached['expires_at'] > time()) {
                return $cached['access_token'];
            }
        } catch (Exception $e) {
            error_log("Error reading cached token: " . $e->getMessage());
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
    
    // Try to cache the token if we have a writable location
    if ($cacheFile) {
        try {
            $cacheData = [
                'access_token' => $tokenData['access_token'],
                'expires_at' => time() + $tokenData['expires_in'] - 300 // 5 minutes buffer
            ];
            
            $result = file_put_contents($cacheFile, json_encode($cacheData));
            if ($result === false) {
                error_log("Failed to cache FCM token to: $cacheFile");
            }
        } catch (Exception $e) {
            error_log("Error caching FCM token: " . $e->getMessage());
        }
    } else {
        error_log("No writable directory found for FCM token cache");
    }
    
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
        $stmt = $pdo->prepare("UPDATE games SET status = 'waiting', duration_days = NULL, start_date = NULL, end_date = NULL, game_mode = NULL WHERE id = ?");
        $stmt->execute([$gameId]);
        
        // Clear timers
        $stmt = $pdo->prepare("DELETE FROM timers WHERE game_id = ?");
        $stmt->execute([$gameId]);
        
        // Clear score history
        $stmt = $pdo->prepare("DELETE FROM score_history WHERE game_id = ?");
        $stmt->execute([$gameId]);
        
        // Clear digital game data - THIS WAS MISSING
        $stmt = $pdo->prepare("DELETE FROM game_decks WHERE game_id = ?");
        $stmt->execute([$gameId]);
        
        $stmt = $pdo->prepare("DELETE FROM player_cards WHERE game_id = ?");
        $stmt->execute([$gameId]);
        
        $stmt = $pdo->prepare("DELETE FROM active_chance_effects WHERE game_id = ?");
        $stmt->execute([$gameId]);

        // Clear wheel spins
        $stmt = $pdo->prepare("DELETE FROM wheel_spins WHERE game_id = ?");
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
        
        // Check if player already has serve cards to prevent duplicates
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM player_cards WHERE game_id = ? AND player_id = ? AND card_type = 'serve'");
        $stmt->execute([$gameId, $playerId]);
        $existingCount = $stmt->fetchColumn();
        
        if ($existingCount > 0) {
            return true; // Already has serve cards
        }
        
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
                c.before_next_challenge, c.veto_modify, c.score_modify,
                c.roll_dice, c.dice_condition, c.dice_threshold, c.double_it,
                c.opponent_challenge_modify, c.draw_snap_dare, c.draw_spicy,
                c.score_add, c.score_subtract, c.score_steal, c.repeat_count, c.win_loss
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
        
        // Get the original card data to preserve win_loss property
        $stmt = $pdo->prepare("SELECT win_loss FROM cards WHERE id = ?");
        $stmt->execute([$cardId]);
        $originalCard = $stmt->fetch();

        $success = addCardToHand($gameId, $toPlayerId, $cardId, 'accepted_serve', 1, true);

        // Update the accepted_serve card to preserve win_loss property
        if ($success && $originalCard && $originalCard['win_loss']) {
            $stmt = $pdo->prepare("
                UPDATE player_cards 
                SET win_loss = ? 
                WHERE game_id = ? AND player_id = ? AND card_id = ? AND card_type = 'accepted_serve'
            ");
            $stmt->execute([1, $gameId, $toPlayerId, $cardId]);
        }
        
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
        
        // DEBUG: Force specific card for testing
        $debugGameId = 25; // Set to your test game ID
        $debugCardId = 139;  // Set to the card ID you want to force
        
        $orderBy = "ORDER BY RAND()";
        if ($cardType === 'chance' && $gameId == $debugGameId) {
            $orderBy = "ORDER BY CASE WHEN c.id = $debugCardId THEN 0 ELSE 1 END, RAND()";
        }
        
        // Get available cards from deck
        $stmt = $pdo->prepare("
            SELECT gd.card_id, gd.remaining_quantity, c.card_name, c.*
            FROM game_decks gd
            JOIN cards c ON gd.card_id = c.id
            WHERE gd.game_id = ? AND gd.player_id = ? AND c.card_type = ? AND gd.remaining_quantity > 0
            $genderWhere
            $orderBy
            LIMIT ?
        ");
        $stmt->execute([$gameId, $playerId, $cardType, $quantity]);
        $availableCards = $stmt->fetchAll();
        
        $drawnCards = [];
        $drawnCardDetails = [];
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
                $drawnCardDetails[] = $card; // Store full card details
                
                // Process chance cards immediately
                if ($cardType === 'chance') {
                    $effects = processChanceCard($gameId, $playerId, $card);
                }
            }
            
            if (count($drawnCards) >= $quantity) break;
        }
        
        return ['card_names' => $drawnCards, 'card_details' => $drawnCardDetails];
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
    return executeWithDeadlockRetry(function() use ($gameId, $playerId, $cardId, $playerCardId) {
        $pdo = Config::getDatabaseConnection();
        $pdo->beginTransaction();
        
        try {
            // Initialize response array early
            $response = ['success' => true, 'points_awarded' => 0, 'score_changes' => []];
            
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
                $response['effects'] = $effects;
                $response['message'] = implode(', ', $effects);

                // Delete timers for this card
                $stmt = $pdo->prepare("DELETE FROM timers WHERE game_id = ? AND player_id = ? AND description = ?");
                $stmt->execute([$gameId, $playerId, $playerCard['card_name']]);
                
                // Remove active effects for this card
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
                                $response['score_changes'][] = ['player_id' => $opponentId, 'points' => $finalPoints];
                                $finalPoints = 0;
                                break;
                        }
                        break;
                    }
                }

                // Remove ALL used challenge modifiers - BATCH OPERATION to prevent deadlock
                // Handle challenge modifier effects - check which ones should be removed
                $effectsToRemove = [];
                foreach ($challengeEffects as $effect) {
                    $shouldRemove = false;
                    $effectOwnerId = null;
                    
                    if ($effect['target_player_id'] == $playerId) {
                        // Opponent modifier targeting this player
                        $effectOwnerId = $effect['player_id'];
                        
                        // Check if this is a timer-based effect
                        $stmt = $pdo->prepare("SELECT timer, timer_completion_type FROM cards WHERE id = ?");
                        $stmt->execute([$effect['chance_card_id']]);
                        $cardInfo = $stmt->fetch();
                        
                        // Only remove if it's not timer-based OR if it completes on first trigger
                        if (!$cardInfo['timer'] && !$effect['timer_id']) {
                            // Non-timer effect - remove it
                            $shouldRemove = true;
                        } elseif ($cardInfo['timer'] && $cardInfo['timer_completion_type'] === 'first_trigger') {
                            // Timer-based but completes on first use
                            $shouldRemove = true;
                        }
                        // If timer_completion_type is 'timer_expires', don't remove - let timer handle it
                        
                    } elseif ($effect['player_id'] == $playerId && !$effect['target_player_id']) {
                        // Current player's own modifier - check if it's timer-based
                        $stmt = $pdo->prepare("SELECT timer, timer_completion_type FROM cards WHERE id = ?");
                        $stmt->execute([$effect['chance_card_id']]);
                        $cardInfo = $stmt->fetch();
                        
                        // Only remove if not timer-based or if timer completes on first trigger
                        if (!$cardInfo['timer'] && !$effect['timer_id']) {
                            $shouldRemove = true;
                            $effectOwnerId = $playerId;
                        } elseif ($cardInfo['timer'] && $cardInfo['timer_completion_type'] === 'first_trigger') {
                            $shouldRemove = true;
                            $effectOwnerId = $playerId;
                        }
                        // For timer_expires effects, leave $shouldRemove as false
                    }
                    
                    if ($shouldRemove) {
                        $effectsToRemove[] = $effect;
                    }
                }

                // Remove effects in single batch operation
                if (!empty($effectsToRemove)) {
                    $effectIds = array_column($effectsToRemove, 'id');
                    $placeholders = str_repeat('?,', count($effectIds) - 1) . '?';
                    $stmt = $pdo->prepare("DELETE FROM active_chance_effects WHERE id IN ($placeholders)");
                    $stmt->execute($effectIds);
                }

                // Now handle card removal and next modifier activation
                foreach ($effectsToRemove as $effect) {
                    $effectOwnerId = $effect['player_id'];
                    
                    // Check if this was a timer-based effect
                    $stmt = $pdo->prepare("SELECT timer, timer_completion_type FROM cards WHERE id = ?");
                    $stmt->execute([$effect['chance_card_id']]);
                    $cardInfo = $stmt->fetch();
                    
                    if (!$cardInfo['timer'] && !$effect['timer_id']) {
                        // Non-timer effect - remove from hand
                        $stmt = $pdo->prepare("DELETE FROM player_cards WHERE game_id = ? AND player_id = ? AND card_id = ?");
                        $stmt->execute([$gameId, $effectOwnerId, $effect['chance_card_id']]);
                    } elseif ($cardInfo['timer_completion_type'] === 'first_trigger') {
                        // Timer-based but completes on first use - remove card and timer
                        $stmt = $pdo->prepare("DELETE FROM player_cards WHERE game_id = ? AND player_id = ? AND card_id = ?");
                        $stmt->execute([$gameId, $effectOwnerId, $effect['chance_card_id']]);
                        if ($effect['timer_id']) {
                            $stmt = $pdo->prepare("DELETE FROM timers WHERE id = ?");
                            $stmt->execute([$effect['timer_id']]);
                        }
                    }
                    // For timer_expires effects, the card and effect remain until timer naturally expires
                    
                    // Activate next modifier for the effect owner (only if no challenge modifier is currently active)
                    if ($effectOwnerId == $playerId) {
                        // Check if there's still an active challenge modifier
                        $activeEffects = getActiveChanceEffects($gameId, 'challenge_modify');
                        $hasActiveModifier = false;
                        
                        foreach ($activeEffects as $existing) {
                            if (($existing['target_player_id'] == $playerId) || 
                                ($existing['player_id'] == $playerId && !$existing['target_player_id'])) {
                                $hasActiveModifier = true;
                                break;
                            }
                        }
                        
                        if (!$hasActiveModifier) {
                            // Current player - activate next challenge_modify
                            $stmt = $pdo->prepare("
                                SELECT pc.*, c.* FROM player_cards pc
                                JOIN cards c ON pc.card_id = c.id
                                WHERE pc.game_id = ? AND pc.player_id = ? AND c.card_type = 'chance' AND c.challenge_modify = 1
                                ORDER BY pc.id ASC LIMIT 1
                            ");
                            $stmt->execute([$gameId, $playerId]);
                            $nextModifier = $stmt->fetch();
                            
                            if ($nextModifier) {
                                $modifyValue = $nextModifier['score_modify'] !== 'none' ? $nextModifier['score_modify'] : 'modify';
                                $effectId = addActiveChanceEffect($gameId, $playerId, $nextModifier['card_id'], 'challenge_modify', $modifyValue);
                            
                            // Create timer if this card has one
                            if ($nextModifier['timer']) {
                                $timerResult = createTimer($gameId, $playerId, $nextModifier['card_name'], $nextModifier['timer']);
                                if ($timerResult['success']) {
                                    $stmt = $pdo->prepare("UPDATE active_chance_effects SET timer_id = ? WHERE id = ?");
                                    $stmt->execute([$timerResult['timer_id'], $effectId]);
                                }
                            }
                        } else {
                            // No challenge_modify found, check for opponent_challenge_modify targeting this player
                            $opponentId = getOpponentPlayerId($gameId, $playerId);
                            $stmt = $pdo->prepare("
                                SELECT pc.*, c.* FROM player_cards pc
                                JOIN cards c ON pc.card_id = c.id
                                WHERE pc.game_id = ? AND pc.player_id = ? AND c.card_type = 'chance' AND c.opponent_challenge_modify = 1
                                ORDER BY pc.id ASC LIMIT 1
                            ");
                            $stmt->execute([$gameId, $opponentId]);
                            $nextOpponentModifier = $stmt->fetch();
                            
                            if ($nextOpponentModifier) {
                                $modifyValue = $nextOpponentModifier['score_modify'] !== 'none' ? $nextOpponentModifier['score_modify'] : 'modify';
                                $effectId = addActiveChanceEffect($gameId, $opponentId, $nextOpponentModifier['card_id'], 'challenge_modify', $modifyValue, $playerId);

                                // Create timer if this card has one
                                if ($nextOpponentModifier['timer']) {
                                    $timerResult = createTimer($gameId, $opponentId, $nextOpponentModifier['card_name'], $nextOpponentModifier['timer']);
                                    if ($timerResult['success']) {
                                        $stmt = $pdo->prepare("UPDATE active_chance_effects SET timer_id = ? WHERE id = ?");
                                        $stmt->execute([$timerResult['timer_id'], $effectId]);
                                    }
                                }
                            }
                        }
                    }
                    } else {
                        // Opponent modifier was cleared - activate current player's next challenge_modify
                        $stmt = $pdo->prepare("
                            SELECT pc.*, c.* FROM player_cards pc
                            JOIN cards c ON pc.card_id = c.id
                            WHERE pc.game_id = ? AND pc.player_id = ? AND c.card_type = 'chance' AND c.challenge_modify = 1
                            ORDER BY pc.id ASC LIMIT 1
                        ");
                        $stmt->execute([$gameId, $playerId]);
                        $nextModifier = $stmt->fetch();
                        
                        if ($nextModifier) {
                            $modifyValue = $nextModifier['score_modify'] !== 'none' ? $nextModifier['score_modify'] : 'modify';
                            addActiveChanceEffect($gameId, $playerId, $nextModifier['card_id'], 'challenge_modify', $modifyValue);
                        }
                    }
                }

                $pointsAwarded = $finalPoints;
            }

            // After completing serve/snap/dare/spicy cards, handle modifier effects
            if (in_array($playerCard['card_type'], ['accepted_serve', 'snap', 'dare', 'spicy'])) {
                $effectType = $playerCard['card_type'] === 'accepted_serve' ? 'challenge_modify' : $playerCard['card_type'] . '_modify';
                
                // Get all effects of this type that target this player
                $allEffects = getActiveChanceEffects($gameId, $effectType);
                $usedEffects = [];
                
                foreach ($allEffects as $effect) {
                    if ($effect['target_player_id'] == $playerId || 
                        ($effect['player_id'] == $playerId && !$effect['target_player_id'])) {
                        $usedEffects[] = $effect;
                    }
                }
                
                // Remove used modifiers
                foreach ($usedEffects as $effect) {
                    $stmt = $pdo->prepare("SELECT timer FROM cards WHERE id = ?");
                    $stmt->execute([$effect['chance_card_id']]);
                    $hasTimer = $stmt->fetchColumn();
                    
                    if (!$hasTimer && !$effect['timer_id']) {
                        // Non-timer effect - remove chance card from hand
                        $stmt = $pdo->prepare("DELETE FROM player_cards WHERE game_id = ? AND player_id = ? AND card_id = ?");
                        $stmt->execute([$gameId, $effect['player_id'], $effect['chance_card_id']]);
                    }
                    
                    removeActiveChanceEffect($effect['id']);
                }
                
                // Activate next modifier for each effect owner
                foreach ($usedEffects as $effect) {
                    $effectOwnerId = $effect['player_id'];
                    
                    // Check if this effect owner can use this card type
                    $ownerPlayer = getPlayerById($effectOwnerId);
                    $canUseCardType = true;
                    
                    if ($effectType === 'snap_modify' && $ownerPlayer['gender'] !== 'female') {
                        $canUseCardType = false;
                    } elseif ($effectType === 'dare_modify' && $ownerPlayer['gender'] !== 'male') {
                        $canUseCardType = false;
                    }
                    
                    // Look for next modifier card
                    $cardTypeField = str_replace('_modify', '_modify', $effectType);
                    $stmt = $pdo->prepare("
                        SELECT pc.*, c.* FROM player_cards pc
                        JOIN cards c ON pc.card_id = c.id
                        WHERE pc.game_id = ? AND pc.player_id = ? AND c.card_type = 'chance' AND c.{$cardTypeField} = 1
                        ORDER BY pc.id ASC LIMIT 1
                    ");
                    $stmt->execute([$gameId, $effectOwnerId]);
                    $nextModifier = $stmt->fetch();
                    
                    if ($nextModifier) {
                        $modifyValue = $nextModifier['double_it'] ? 'double' : 'modify';
                        
                        if ($canUseCardType) {
                            // Effect owner can use this card type
                            addActiveChanceEffect($gameId, $effectOwnerId, $nextModifier['card_id'], $effectType, $modifyValue);
                        } else {
                            // Effect owner can't use this card type - target opponent
                            $targetId = getOpponentPlayerId($gameId, $effectOwnerId);
                            addActiveChanceEffect($gameId, $effectOwnerId, $nextModifier['card_id'], $effectType, $modifyValue, $targetId);
                        }
                    }
                }
            }
            
            // Send notification to opponent if this was a served card
            if ($playerCard['card_type'] === 'serve' || $playerCard['card_type'] === 'accepted_serve') {
                $opponentId = getOpponentPlayerId($gameId, $playerId);
                if ($opponentId) {
                    $stmt = $pdo->prepare("SELECT first_name FROM players WHERE id = ?");
                    $stmt->execute([$playerId]);
                    $playerName = $stmt->fetchColumn();
                    
                    $stmt = $pdo->prepare("SELECT fcm_token FROM players WHERE id = ?");
                    $stmt->execute([$opponentId]);
                    $opponentToken = $stmt->fetchColumn();
                    
                    if ($opponentToken) {
                        sendPushNotification(
                            $opponentToken,
                            "Card Completed!",
                            "$playerName completed the {$playerCard['card_name']} card you served them!"
                        );
                    }
                }
            }
            
            // Remove card from player's hand
            $stmt = $pdo->prepare("UPDATE player_cards SET quantity = quantity - 1 WHERE id = ?");
            $stmt->execute([$playerCardId]);

            // Delete the row if quantity is now 0 or less
            $stmt = $pdo->prepare("DELETE FROM player_cards WHERE id = ? AND quantity <= 0");
            $stmt->execute([$playerCardId]);

            // Check for recurring effect completion before committing
            checkRecurringEffectCompletion($gameId, $playerId, $playerCard['card_type']);
            
            $pdo->commit();
            
            // Update points_awarded in response
            $response['points_awarded'] = $pointsAwarded;

            // Add current player score change if they got points
            if ($pointsAwarded > 0) {
                $response['score_changes'][] = ['player_id' => $playerId, 'points' => $pointsAwarded];
            }

            return $response;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Error completing hand card: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    });
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
        $drawnCards = [];
        
        // Apply veto modifiers - check both active effects and new cards
        $vetoEffects = getActiveChanceEffects($gameId, 'veto_modify');
        $vetoMultiplier = 1;
        $vetoSkipped = false;

        // First check active effects
        foreach ($vetoEffects as $effect) {
            $appliesToThisPlayer = false;
            
            if ($effect['effect_value'] === 'opponent_double') {
                $appliesToThisPlayer = ($effect['target_player_id'] == $playerId);
            } else {
                $appliesToThisPlayer = ($effect['player_id'] == $playerId);
            }
            
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
                }
                break;
            }
        }

        // If no active effect found, check for new veto modifier cards
        if ($vetoMultiplier == 1 && !$vetoSkipped) {
            $stmt = $pdo->prepare("
                SELECT pc.*, c.* FROM player_cards pc
                JOIN cards c ON pc.card_id = c.id
                WHERE pc.game_id = ? AND pc.player_id = ? AND c.card_type = 'chance' AND c.veto_modify != 'none'
                ORDER BY pc.id ASC LIMIT 1
            ");
            $stmt->execute([$gameId, $playerId]);
            $vetoModifierCard = $stmt->fetch();
            
            if ($vetoModifierCard) {
                switch ($vetoModifierCard['veto_modify']) {
                    case 'double':
                    case 'opponent_double':
                        $vetoMultiplier = 2;
                        break;
                    case 'skip':
                        $vetoSkipped = true;
                        break;
                }
                
                // Remove the chance card since it was used
                $stmt = $pdo->prepare("DELETE FROM player_cards WHERE game_id = ? AND player_id = ? AND card_id = ?");
                $stmt->execute([$gameId, $playerId, $vetoModifierCard['card_id']]);
            }
        }
        
        // Apply veto penalties
        if (!$vetoSkipped) {
            if ($playerCard['veto_subtract']) {
                $penaltyPoints = $playerCard['veto_subtract'] * $vetoMultiplier;
                $response['score_changes'][] = ['player_id' => $playerId, 'points' => -$penaltyPoints];
                $penalties[] = "Lost {$penaltyPoints} points";
            }

            if ($playerCard['veto_steal']) {
                $penaltyPoints = $playerCard['veto_steal'] * $vetoMultiplier;
                $opponentId = getOpponentPlayerId($gameId, $playerId);
                
                if ($opponentId) {
                    $response['score_changes'][] = ['player_id' => $playerId, 'points' => -$penaltyPoints];
                    $response['score_changes'][] = ['player_id' => $opponentId, 'points' => $penaltyPoints];
                    $penalties[] = "Lost {$penaltyPoints} points to opponent";
                }
            }
            
            if ($playerCard['veto_draw_chance']) {
                $drawCount = $playerCard['veto_draw_chance'] * $vetoMultiplier;
                $drawResult = drawCards($gameId, $playerId, 'chance', $drawCount);
                $drawnCards = array_merge($drawnCards, $drawResult['card_details']);
                $penalties[] = "Drew {$drawCount} chance card(s): " . implode(', ', $drawResult['card_names']);
            }
            
            if ($playerCard['veto_draw_snap_dare']) {
                $drawCount = $playerCard['veto_draw_snap_dare'] * $vetoMultiplier;
                $player = getPlayerById($playerId);
                $drawType = ($player['gender'] === 'female') ? 'snap' : 'dare';
                $drawResult = drawCards($gameId, $playerId, $drawType, $drawCount);
                $drawnCards = array_merge($drawnCards, $drawResult['card_details']);
                $penalties[] = "Drew {$drawCount} {$drawType} card(s): " . implode(', ', $drawResult['card_names']);
            }
            
            if ($playerCard['veto_draw_spicy']) {
                $drawCount = $playerCard['veto_draw_spicy'] * $vetoMultiplier;
                $drawResult = drawCards($gameId, $playerId, 'spicy', $drawCount);
                $drawnCards = array_merge($drawnCards, $drawResult['card_details']);
                $penalties[] = "Drew {$drawCount} spicy card(s): " . implode(', ', $drawResult['card_names']);
            }
        } else {
            $penalties[] = "Veto penalty skipped";
        }

        // Handle Higher Stakes type effects for accepted_serve cards
        if ($playerCard['card_type'] === 'accepted_serve' && $playerCard['card_points']) {
            $challengeEffects = getActiveChanceEffects($gameId, 'challenge_modify', $playerId);
            foreach ($challengeEffects as $effect) {
                if ($effect['effect_value'] === 'opponent_reward') {
                    $opponentId = getOpponentPlayerId($gameId, $playerId);
                    $response['score_changes'][] = ['player_id' => $opponentId, 'points' => $playerCard['card_points']];
                    $penalties[] = "Opponent gained {$playerCard['card_points']} points (Higher Stakes)";
                    removeActiveChanceEffect($effect['id']);
                    break; // Only apply one Higher Stakes effect
                }
            }
        }
        
        // Handle non-veto card types
        switch ($playerCard['card_type']) {
            case 'snap':
            case 'dare':
                if (!$vetoSkipped) {
                    $penaltyPoints = 3 * $vetoMultiplier;
                    $response['score_changes'][] = ['player_id' => $playerId, 'points' => -$penaltyPoints];
                    $penalties[] = "Lost {$penaltyPoints} points";
                }
                returnCardToDeck($gameId, $playerCard['card_id'], 1);
                break;
                
            case 'spicy':
            case 'chance':
                returnCardToDeck($gameId, $playerCard['card_id'], 1);
                $penalties[] = "Card returned to deck";
                break;
        }

        // Check for new veto modifiers to activate
        $stmt = $pdo->prepare("
            SELECT pc.*, c.* FROM player_cards pc
            JOIN cards c ON pc.card_id = c.id
            WHERE pc.game_id = ? AND pc.player_id = ? AND c.card_type = 'chance' AND c.veto_modify != 'none'
            ORDER BY pc.id ASC LIMIT 1
        ");
        $stmt->execute([$gameId, $playerId]);
        $vetoModifierCard = $stmt->fetch();

        // Send notification to opponent if this was a served card
        if ($playerCard['card_type'] === 'accepted_serve') {
            $opponentId = getOpponentPlayerId($gameId, $playerId);
            if ($opponentId) {
                $stmt = $pdo->prepare("SELECT first_name FROM players WHERE id = ?");
                $stmt->execute([$playerId]);
                $playerName = $stmt->fetchColumn();
                
                $stmt = $pdo->prepare("SELECT fcm_token FROM players WHERE id = ?");
                $stmt->execute([$opponentId]);
                $opponentToken = $stmt->fetchColumn();
                
                if ($opponentToken) {
                    sendPushNotification(
                        $opponentToken,
                        "Card Vetoed!",
                        "$playerName vetoed the {$playerCard['card_name']} card you served them!"
                    );
                }
            }
        }
        
        // Remove from hand
        $stmt = $pdo->prepare("UPDATE player_cards SET quantity = quantity - 1 WHERE id = ?");
        $stmt->execute([$playerCardId]);

        // Delete the row if quantity is now 0 or less
        $stmt = $pdo->prepare("DELETE FROM player_cards WHERE id = ? AND quantity <= 0");
        $stmt->execute([$playerCardId]);
        
        
        // Check for recurring effect completion before committing
        checkRecurringEffectCompletion($gameId, $playerId, $playerCard['card_type']);

        $pdo->commit();

        // Handle veto modifier completion and activation of next modifier
        foreach ($vetoEffects as $effect) {
            // Check if this is a timer-based effect
            $stmt = $pdo->prepare("SELECT timer FROM cards WHERE id = ?");
            $stmt->execute([$effect['chance_card_id']]);
            $hasTimer = $stmt->fetchColumn();
            
            if (!$hasTimer && !$effect['timer_id']) {
                // Non-timer veto effect - remove the chance card from hand
                $stmt = $pdo->prepare("DELETE FROM player_cards WHERE game_id = ? AND player_id = ? AND card_id = ?");
                $stmt->execute([$gameId, $effect['player_id'], $effect['chance_card_id']]);
                
                // Check for next veto modifier card in hand and activate it
                $stmt = $pdo->prepare("
                    SELECT pc.*, c.* FROM player_cards pc
                    JOIN cards c ON pc.card_id = c.id
                    WHERE pc.game_id = ? AND pc.player_id = ? AND c.card_type = 'chance' AND c.veto_modify != 'none'
                    ORDER BY pc.id ASC LIMIT 1
                ");
                $stmt->execute([$gameId, $effect['player_id']]);
                $nextModifier = $stmt->fetch();
                
                if ($nextModifier) {
                    $targetId = (strpos($nextModifier['veto_modify'], 'opponent') !== false) ? 
                            getOpponentPlayerId($gameId, $effect['player_id']) : $effect['player_id'];
                    addActiveChanceEffect($gameId, $effect['player_id'], $nextModifier['card_id'], 'veto_modify', 
                                        $nextModifier['veto_modify'], $targetId);
                }
            }
            // Timer-based veto effects stay active until timer expires
        }
        $response = ['success' => true, 'penalties' => $penalties];

        // Track score changes and card draws for frontend animation
        $scoreChanges = [];
        $drawnCards = [];

        // Parse penalties to extract score changes and card draws
        foreach ($penalties as $penalty) {
            if (strpos($penalty, 'Lost') !== false && strpos($penalty, 'points') !== false) {
                preg_match('/Lost (\d+) points/', $penalty, $matches);
                if ($matches) {
                    $scoreChanges[] = ['player_id' => $playerId, 'points' => -intval($matches[1])];
                }
            }
            
            if (strpos($penalty, 'Drew') !== false && strpos($penalty, 'card') !== false) {
                // Parse the actual number of cards drawn from the penalty text
                preg_match('/Drew (\d+) (\w+) card/', $penalty, $matches);
                if ($matches) {
                    $cardCount = intval($matches[1]);
                    $cardType = $matches[2];
                    
                    // Get the specific cards that were drawn
                    $stmt = $pdo->prepare("
                        SELECT c.* FROM player_cards pc 
                        JOIN cards c ON pc.card_id = c.id 
                        WHERE pc.game_id = ? AND pc.player_id = ? AND c.card_type = ?
                        ORDER BY pc.id DESC LIMIT ?
                    ");
                    $stmt->execute([$gameId, $playerId, $cardType, $cardCount]);
                    $recentCards = $stmt->fetchAll();
                    
                    foreach ($recentCards as $card) {
                        $drawnCards[] = $card;
                    }
                }
            }
        }

        if (!empty($scoreChanges)) {
            $response['score_changes'] = $scoreChanges;
        }

        if (!empty($drawnCards)) {
            $response['drawn_cards'] = $drawnCards;
        }

        checkRecurringEffectCompletion($gameId, $playerId, $playerCard['card_type']);

        // Check for new modifiers to activate for snap/dare/spicy cards
        if (in_array($playerCard['card_type'], ['snap', 'dare', 'spicy'])) {
            $effectType = $playerCard['card_type'] . '_modify';
            
            // Check if there are any queued modifiers for this card type
            $stmt = $pdo->prepare("
                SELECT pc.*, c.* FROM player_cards pc
                JOIN cards c ON pc.card_id = c.id
                WHERE pc.game_id = ? AND c.card_type = 'chance' AND c.{$effectType} = 1
                ORDER BY pc.id ASC
            ");
            $stmt->execute([$gameId]);
            $allModifiers = $stmt->fetchAll();
            
            foreach ($allModifiers as $modifier) {
                $ownerPlayer = getPlayerById($modifier['player_id']);
                $canUseCardType = true;
                
                if ($effectType === 'snap_modify' && $ownerPlayer['gender'] !== 'female') {
                    $canUseCardType = false;
                } elseif ($effectType === 'dare_modify' && $ownerPlayer['gender'] !== 'male') {
                    $canUseCardType = false;
                }
                
                $targetPlayerId = $canUseCardType ? $modifier['player_id'] : getOpponentPlayerId($gameId, $modifier['player_id']);
                
                // Check if target already has this modifier active
                $existingEffects = getActiveChanceEffects($gameId, $effectType);
                $hasActiveModifier = false;
                
                foreach ($existingEffects as $existing) {
                    if (($existing['target_player_id'] == $targetPlayerId) || 
                        ($existing['player_id'] == $targetPlayerId && !$existing['target_player_id'])) {
                        $hasActiveModifier = true;
                        break;
                    }
                }
                
                if (!$hasActiveModifier) {
                    $modifyValue = $modifier['double_it'] ? 'double' : 'modify';
                    addActiveChanceEffect($gameId, $modifier['player_id'], $modifier['card_id'], $effectType, $modifyValue, $canUseCardType ? null : $targetPlayerId);
                    break; // Only activate one modifier
                }
            }
        }

        return $response;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error vetoing hand card: " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function processWinLossCard($gameId, $playerId, $cardId, $playerCardId, $isWin) {
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
        
        $opponentId = getOpponentPlayerId($gameId, $playerId);
        $response = ['success' => true, 'score_changes' => []];
        
        if ($isWin) {
            // Player wins: gets points, opponent gets veto penalty
            if ($playerCard['card_points']) {
                $response['score_changes'][] = ['player_id' => $playerId, 'points' => $playerCard['card_points']];
            }
            
            // Apply veto penalties to opponent
            if ($playerCard['veto_subtract']) {
                $response['score_changes'][] = ['player_id' => $opponentId, 'points' => -$playerCard['veto_subtract']];
            }
            if ($playerCard['veto_steal']) {
                $response['score_changes'][] = ['player_id' => $opponentId, 'points' => -$playerCard['veto_steal']];
                $response['score_changes'][] = ['player_id' => $playerId, 'points' => $playerCard['veto_steal']];
            }
            
            // Handle draw penalties for opponent
            if ($playerCard['veto_draw_chance']) {
                drawCards($gameId, $opponentId, 'chance', $playerCard['veto_draw_chance']);
            }
            if ($playerCard['veto_draw_snap_dare']) {
                $opponent = getPlayerById($opponentId);
                $drawType = ($opponent['gender'] === 'female') ? 'snap' : 'dare';
                drawCards($gameId, $opponentId, $drawType, $playerCard['veto_draw_snap_dare']);
            }
            if ($playerCard['veto_draw_spicy']) {
                drawCards($gameId, $opponentId, 'spicy', $playerCard['veto_draw_spicy']);
            }
        } else {
            // Player loses: gets veto penalty, opponent gets points
            if ($playerCard['card_points']) {
                $response['score_changes'][] = ['player_id' => $opponentId, 'points' => $playerCard['card_points']];
            }
            
            // Apply veto penalties to player
            if ($playerCard['veto_subtract']) {
                $response['score_changes'][] = ['player_id' => $playerId, 'points' => -$playerCard['veto_subtract']];
            }
            if ($playerCard['veto_steal']) {
                $response['score_changes'][] = ['player_id' => $playerId, 'points' => -$playerCard['veto_steal']];
                $response['score_changes'][] = ['player_id' => $opponentId, 'points' => $playerCard['veto_steal']];
            }
            // Handle draw penalties for player
            $drawnCards = [];
            if ($playerCard['veto_draw_chance']) {
                $drawResult = drawCards($gameId, $playerId, 'chance', $playerCard['veto_draw_chance']);
                $drawnCards = array_merge($drawnCards, $drawResult['card_details'] ?? []);
            }
            if ($playerCard['veto_draw_snap_dare']) {
                $opponent = getPlayerById($playerId);
                $drawType = ($opponent['gender'] === 'female') ? 'snap' : 'dare';
                $drawResult = drawCards($gameId, $playerId, $drawType, $playerCard['veto_draw_snap_dare']);
                $drawnCards = array_merge($drawnCards, $drawResult['card_details'] ?? []);
            }
            if ($playerCard['veto_draw_spicy']) {
                $drawResult = drawCards($gameId, $playerId, 'spicy', $playerCard['veto_draw_spicy']);
                $drawnCards = array_merge($drawnCards, $drawResult['card_details'] ?? []);
            }

            if (!empty($drawnCards)) {
                $response['drawn_cards'] = $drawnCards;
            }
        }
        
        // Send notification to opponent
        $stmt = $pdo->prepare("SELECT first_name FROM players WHERE id = ?");
        $stmt->execute([$playerId]);
        $playerName = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT fcm_token FROM players WHERE id = ?");
        $stmt->execute([$opponentId]);
        $opponentToken = $stmt->fetchColumn();
        
        if ($opponentToken) {
            $action = $isWin ? 'won' : 'lost';
            sendPushNotification(
                $opponentToken,
                "Card " . ucfirst($action) . "!",
                "$playerName $action the {$playerCard['card_name']} card you served them!"
            );
        }
        
        // Remove card from player's hand
        $stmt = $pdo->prepare("UPDATE player_cards SET quantity = quantity - 1 WHERE id = ?");
        $stmt->execute([$playerCardId]);

        $stmt = $pdo->prepare("DELETE FROM player_cards WHERE id = ? AND quantity <= 0");
        $stmt->execute([$playerCardId]);
        
        $pdo->commit();
        return $response;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error processing win/loss card: " . $e->getMessage());
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
    return executeWithDeadlockRetry(function() use ($effectId) {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("DELETE FROM active_chance_effects WHERE id = ?");
        return $stmt->execute([$effectId]);
    });
}

function clearExpiredChanceEffects($gameId) {
    return executeWithDeadlockRetry(function() use ($gameId) {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("DELETE FROM active_chance_effects WHERE game_id = ? AND expires_at IS NOT NULL AND expires_at <= NOW()");
        return $stmt->execute([$gameId]);
    });
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
        $effects[] = "Will draw {$cardData['draw_snap_dare']} {$drawType} card(s)";
    }

    if ($cardData['draw_spicy']) {
        $effects[] = "Will draw {$cardData['draw_spicy']} spicy card(s)";
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
            $effectId = addActiveChanceEffect($gameId, $playerId, $cardData['id'], 'challenge_modify', $modifyValue);
            
            if ($cardData['timer']) {
                $timerResult = createTimer($gameId, $playerId, $cardData['card_name'], $cardData['timer']);
                if ($timerResult['success']) {
                    $pdo = Config::getDatabaseConnection();
                    $stmt = $pdo->prepare("UPDATE active_chance_effects SET timer_id = ? WHERE id = ?");
                    $stmt->execute([$timerResult['timer_id'], $effectId]);
                }
            }
            $effects[] = "Next challenge modified";
        } else {
            $effects[] = "Challenge modifier queued - will activate when current one is cleared";
        }
    }

    if ($cardData['opponent_challenge_modify']) {
        // Check if opponent already has a challenge modifier active
        $opponentId = getOpponentPlayerId($gameId, $playerId);
        $existingEffect = getActiveChanceEffects($gameId, 'challenge_modify');
        $hasOpponentModifier = false;
        
        foreach ($existingEffect as $effect) {
            if (($effect['target_player_id'] == $opponentId) || 
                ($effect['player_id'] == $opponentId && !$effect['target_player_id'])) {
                $hasOpponentModifier = true;
                break;
            }
        }
        
        if (!$hasOpponentModifier) {
            $modifyValue = $cardData['score_modify'] !== 'none' ? $cardData['score_modify'] : 'modify';
            addActiveChanceEffect($gameId, $playerId, $cardData['id'], 'challenge_modify', $modifyValue, $opponentId);
            $effects[] = "Opponent's next challenge modified";
        } else {
            $effects[] = "Opponent challenge modifier queued";
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
        $player = getPlayerById($playerId);
        
        if ($player['gender'] === 'female') {
            // Female draws snap modifier - affects herself
            $existingEffect = getActiveChanceEffects($gameId, 'snap_modify', $playerId);
            if (empty($existingEffect)) {
                addActiveChanceEffect($gameId, $playerId, $cardData['id'], 'snap_modify', $cardData['double_it'] ? 'double' : 'modify');
                $effects[] = "Next snap card modified";
            } else {
                $effects[] = "Snap modifier queued - will activate when current one is cleared";
            }
        } else {
            // Male draws snap modifier - affects opponent (female)
            $opponentId = getOpponentPlayerId($gameId, $playerId);
            $existingEffect = getActiveChanceEffects($gameId, 'snap_modify');
            $opponentHasModifier = false;
            
            foreach ($existingEffect as $effect) {
                if (($effect['target_player_id'] == $opponentId) || 
                    ($effect['player_id'] == $opponentId && !$effect['target_player_id'])) {
                    $opponentHasModifier = true;
                    break;
                }
            }
            
            if (!$opponentHasModifier) {
                addActiveChanceEffect($gameId, $playerId, $cardData['id'], 'snap_modify', $cardData['double_it'] ? 'double' : 'modify', $opponentId);
                $effects[] = "Opponent's next snap card modified";
            } else {
                $effects[] = "Opponent snap modifier queued";
            }
        }
    }

    if ($cardData['dare_modify']) {
        $player = getPlayerById($playerId);
        
        if ($player['gender'] === 'male') {
            // Male draws dare modifier - affects himself
            $existingEffect = getActiveChanceEffects($gameId, 'dare_modify', $playerId);
            if (empty($existingEffect)) {
                addActiveChanceEffect($gameId, $playerId, $cardData['id'], 'dare_modify', $cardData['double_it'] ? 'double' : 'modify');
                $effects[] = "Next dare card modified";
            } else {
                $effects[] = "Dare modifier queued - will activate when current one is cleared";
            }
        } else {
            // Female draws dare modifier - affects opponent (male)
            $opponentId = getOpponentPlayerId($gameId, $playerId);
            $existingEffect = getActiveChanceEffects($gameId, 'dare_modify');
            $opponentHasModifier = false;
            
            foreach ($existingEffect as $effect) {
                if (($effect['target_player_id'] == $opponentId) || 
                    ($effect['player_id'] == $opponentId && !$effect['target_player_id'])) {
                    $opponentHasModifier = true;
                    break;
                }
            }
            
            if (!$opponentHasModifier) {
                addActiveChanceEffect($gameId, $playerId, $cardData['id'], 'dare_modify', $cardData['double_it'] ? 'double' : 'modify', $opponentId);
                $effects[] = "Opponent's next dare card modified";
            } else {
                $effects[] = "Opponent dare modifier queued";
            }
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
    if ($cardData['timer'] && !$cardData['challenge_modify']) {
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

    // Send custom notification if specified
    if ($cardData['notification_text']) {
        $opponentId = getOpponentPlayerId($gameId, $playerId);
        if ($opponentId) {
            $stmt = $pdo->prepare("SELECT first_name FROM players WHERE id = ?");
            $stmt->execute([$playerId]);
            $playerName = $stmt->fetchColumn();
            
            $stmt = $pdo->prepare("SELECT fcm_token FROM players WHERE id = ?");
            $stmt->execute([$opponentId]);
            $opponentToken = $stmt->fetchColumn();
            
            if ($opponentToken) {
                $customMessage = str_replace('playerName', $playerName, $cardData['notification_text']);
                sendPushNotification(
                    $opponentToken,
                    "Game Modified!",
                    $customMessage
                );
            }
        }
    }

    // Auto-complete cards with only immediate effects OR dice cards with timers
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

    // Special case: dice cards with timers can be auto-completed but also remain available for manual completion
    $isDiceTimerCard = $cardData['roll_dice'] && $cardData['timer'];

    if ($hasOnlyImmediateEffects || $isDiceTimerCard) {
        // For dice timer cards, don't remove from hand - just add effects and keep card available
        if (!$isDiceTimerCard) {
            // Remove from player's hand (normal auto-completion)
            $stmt = $pdo->prepare("DELETE FROM player_cards WHERE game_id = ? AND player_id = ? AND card_id = ?");
            $stmt->execute([$gameId, $playerId, $cardData['id']]);
            $effects[] = "Card auto-completed";
        } else {
            $effects[] = "Timer effects activated - card remains available for dice roll";
        }
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
    $stmt = $pdo->prepare("
        SELECT ace.*, t.id as timer_id 
        FROM active_chance_effects ace
        LEFT JOIN timers t ON ace.timer_id = t.id
        WHERE ace.game_id = ? AND ace.player_id = ? AND ace.effect_type = 'recurring_timer'
    ");
    $stmt->execute([$gameId, $playerId]);
    $recurringEffects = $stmt->fetchAll();
    
    foreach ($recurringEffects as $effect) {
        // Delete the timer if it exists
        if ($effect['timer_id']) {
            $stmt = $pdo->prepare("DELETE FROM timers WHERE id = ?");
            $stmt->execute([$effect['timer_id']]);
            
            // Remove any at jobs for this timer
            $atJobs = shell_exec('atq 2>/dev/null') ?: '';
            $lines = explode("\n", trim($atJobs));
            
            foreach ($lines as $line) {
                if (empty($line)) continue;
                
                $parts = explode("\t", $line);
                if (count($parts) >= 1) {
                    $jobId = trim($parts[0]);
                    $jobContent = shell_exec("at -c {$jobId} 2>/dev/null | tail -1");
                    if (strpos($jobContent, "timer_{$effect['timer_id']}") !== false) {
                        shell_exec("atrm {$jobId} 2>/dev/null");
                        error_log("Removed at job {$jobId} for recurring timer {$effect['timer_id']}");
                        break;
                    }
                }
            }
        }
        
        // Remove the active effect
        $stmt = $pdo->prepare("DELETE FROM active_chance_effects WHERE id = ?");
        $stmt->execute([$effect['id']]);
        
        // Remove the chance card from hand
        $stmt = $pdo->prepare("DELETE FROM player_cards WHERE game_id = ? AND player_id = ? AND card_id = ?");
        $stmt->execute([$gameId, $playerId, $effect['chance_card_id']]);
        
        error_log("Completed Clock Siphon effect for player $playerId");
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

function canPlayerSpinWheel($playerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Use Indianapolis timezone
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $today = (new DateTime('now', $timezone))->format('Y-m-d');
        
        // Check if player has spun today (based on Indianapolis timezone)
        $stmt = $pdo->prepare("
            SELECT spun_at FROM wheel_spins 
            WHERE player_id = ? 
            AND DATE(CONVERT_TZ(spun_at, '+00:00', '-05:00')) = ?
            ORDER BY spun_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$playerId, $today]);
        $todaySpin = $stmt->fetchColumn();
        
        // Can spin if no spin today
        return !$todaySpin;
        
    } catch (Exception $e) {
        error_log("Error checking wheel spin eligibility: " . $e->getMessage());
        return false;
    }
}

function getWheelPrizes() {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("
            SELECT * FROM wheel_prizes 
            WHERE is_active = TRUE 
            ORDER BY id
        ");
        $stmt->execute();
        return $stmt->fetchAll();
        
    } catch (Exception $e) {
        error_log("Error getting wheel prizes: " . $e->getMessage());
        return [];
    }
}

function getDailyWheelPrizes() {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Use Indianapolis timezone for determining "today"
        $timezone = new DateTimeZone('America/Indiana/Indianapolis');
        $today = (new DateTime('now', $timezone))->format('Y-m-d');
        
        // Clean up wheels older than 1 week
        $weekAgo = (new DateTime('-7 days', $timezone))->format('Y-m-d');
        $stmt = $pdo->prepare("DELETE FROM daily_wheels WHERE wheel_date < ?");
        $stmt->execute([$weekAgo]);
        
        // Check if today's wheel already exists
        $stmt = $pdo->prepare("SELECT prizes_json FROM daily_wheels WHERE wheel_date = ?");
        $stmt->execute([$today]);
        $existingWheel = $stmt->fetchColumn();
        
        if ($existingWheel) {
            // Return existing wheel
            return json_decode($existingWheel, true);
        }
        
        // Generate new wheel for today
        $allPrizes = getWheelPrizes();
        if (count($allPrizes) < 6) {
            return []; // Not enough prizes
        }
        
        // Simply select 6 random prizes (no weighting for selection)
        $selectedPrizes = [];
        $availablePrizes = $allPrizes;
        
        for ($i = 0; $i < 6; $i++) {
            if (empty($availablePrizes)) {
                // If we run out of unique prizes, start over with all prizes
                $availablePrizes = $allPrizes;
            }
            
            $randomIndex = array_rand($availablePrizes);
            $selectedPrizes[] = $availablePrizes[$randomIndex];
            
            // Remove selected prize to avoid duplicates (unless we need to repeat)
            unset($availablePrizes[$randomIndex]);
            $availablePrizes = array_values($availablePrizes); // Reindex array
        }
        
        // Store today's wheel
        $prizesJson = json_encode($selectedPrizes);
        $stmt = $pdo->prepare("INSERT INTO daily_wheels (wheel_date, prizes_json) VALUES (?, ?)");
        $stmt->execute([$today, $prizesJson]);
        
        return $selectedPrizes;
        
    } catch (Exception $e) {
        error_log("Error getting daily wheel prizes: " . $e->getMessage());
        return [];
    }
}

function formatPrizeForPlayer($prize, $playerGender) {
    $formattedPrize = $prize;
    
    // Customize display text for snap/dare based on gender
    if ($prize['prize_type'] === 'draw_snap_dare') {
        if ($playerGender === 'female') {
            $formattedPrize['display_text'] = str_replace('Snap/Dare', 'Snap', $prize['display_text']);
        } else {
            $formattedPrize['display_text'] = str_replace('Snap/Dare', 'Dare', $prize['display_text']);
        }
    }
    
    return $formattedPrize;
}

function spinWheel($gameId, $playerId) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Check if player can spin
        if (!canPlayerSpinWheel($playerId)) {
            return ['success' => false, 'message' => 'Wheel already spun today'];
        }
        
        // Get player info for gender formatting
        $stmt = $pdo->prepare("SELECT gender FROM players WHERE id = ?");
        $stmt->execute([$playerId]);
        $playerGender = $stmt->fetchColumn();
        
        // Get today's wheel prizes
        $prizes = getDailyWheelPrizes();
        if (empty($prizes)) {
            return ['success' => false, 'message' => 'Not enough prizes configured'];
        }

        // Format prizes for this player's gender
        $formattedPrizes = array_map(function($prize) use ($playerGender) {
            return formatPrizeForPlayer($prize, $playerGender);
        }, $prizes);
        
        // Create weighted array based on prize weights for landing probability
        $weightedIndexes = [];
        foreach ($formattedPrizes as $index => $prize) {
            $weight = intval($prize['weight']) ?: 1;
            for ($i = 0; $i < $weight; $i++) {
                $weightedIndexes[] = $index;
            }
        }
        
        // Select random weighted index
        $randomWeightedIndex = array_rand($weightedIndexes);
        $winningIndex = $weightedIndexes[$randomWeightedIndex];
        $winningPrize = $formattedPrizes[$winningIndex];
        
        // Record the spin (use original prize data)
        $originalWinningPrize = $prizes[$winningIndex];
        $stmt = $pdo->prepare("
            INSERT INTO wheel_spins (player_id, game_id, prize_type, prize_value, display_text)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $playerId,
            $gameId, 
            $originalWinningPrize['prize_type'],
            $originalWinningPrize['prize_value'],
            $winningPrize['display_text'] // Use formatted display text
        ]);
        
        return [
            'success' => true,
            'prizes' => $formattedPrizes,
            'winning_prize' => $winningPrize,
            'winning_index' => $winningIndex
        ];
        
    } catch (Exception $e) {
        error_log("Error spinning wheel: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to spin wheel'];
    }
}

function executeWithDeadlockRetry($callback, $maxRetries = 3) {
    $attempt = 0;
    while ($attempt < $maxRetries) {
        try {
            return $callback();
        } catch (Exception $e) {
            $attempt++;
            if ($attempt >= $maxRetries || strpos($e->getMessage(), '1205') === false) {
                throw $e; // Re-throw if not a deadlock or max retries reached
            }
            error_log("Deadlock detected, retrying (attempt $attempt/$maxRetries)");
            usleep(rand(100000, 500000)); // Random delay 100-500ms
        }
    }
}
?>