<?php
// cron.php - Fixed version with proper logging and error handling
require_once 'config.php';
require_once 'functions.php';

// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', '/path/to/your/logs/cron_errors.log'); // Update this path

$action = $argv[1] ?? 'all';

// Check if this is a specific timer call
if (isset($argv[1]) && strpos($argv[1], 'timer_') === 0) {
    $timerId = str_replace('timer_', '', $argv[1]);
    $expiredCount = checkExpiredTimers($timerId);
    if ($expiredCount > 0) {
        error_log("Processed timer $timerId");
    }
    exit;
}

// Log cron execution
error_log("Cron job started with action: $action at " . date('Y-m-d H:i:s'));

switch ($action) {
    case 'timers':
        checkExpiredTimers();
        break;
    case 'daily':
        sendDailyNotifications();
        break;
    case 'cleanup':
        cleanupExpiredGames();
        break;
    case 'all':
    default:
        checkExpiredTimers();
        
        // Only send daily notifications at 9 AM
        $currentTime = date('H:i');
        if ($currentTime === '09:00') {
            sendDailyNotifications();
        }
        
        // Cleanup once per day at midnight
        if ($currentTime === '00:00') {
            cleanupExpiredGames();
        }
        break;
}

function sendDailyNotifications() {
    error_log("Starting daily notifications...");
    
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Get all active games with players, including game mode
        $stmt = $pdo->query("
            SELECT g.id as game_id, g.end_date, g.game_mode,
                   p.id, p.first_name, p.gender, p.score, p.fcm_token,
                   opponent.first_name as opponent_name, opponent.score as opponent_score
            FROM games g
            JOIN players p ON g.id = p.game_id
            JOIN players opponent ON g.id = opponent.game_id AND opponent.id != p.id
            WHERE g.status = 'active' AND p.fcm_token IS NOT NULL AND p.fcm_token != ''
        ");
        
        $players = $stmt->fetchAll();
        error_log("Found " . count($players) . " players with FCM tokens for daily notifications");
        
        $notificationsSent = 0;
        
        foreach ($players as $player) {
            try {
                $endDate = new DateTime($player['end_date']);
                $now = new DateTime();
                $daysLeft = $now < $endDate ? $endDate->diff($now)->days : 0;
                
                $scoreStatus = '';
                if ($player['score'] > $player['opponent_score']) {
                    $scoreDiff = $player['score'] - $player['opponent_score'];
                    $pointText = $scoreDiff === 1 ? 'point' : 'points';
                    $scoreStatus = "You're winning by {$scoreDiff} {$pointText}! 🎉";
                } elseif ($player['score'] < $player['opponent_score']) {
                    $scoreDiff = $player['opponent_score'] - $player['score'];
                    $pointText = $scoreDiff === 1 ? 'point' : 'points';
                    $scoreStatus = "You're behind by {$scoreDiff} {$pointText}. Time to catch up! 💪";
                } else {
                    $scoreStatus = "It's a tie! Who will take the lead? 🤝";
                }
                
                // Get card count for digital games
                $cardCountText = '';
                if ($player['game_mode'] === 'digital') {
                    $stmt = $pdo->prepare("SELECT SUM(quantity) as hand_count FROM player_cards WHERE game_id = ? AND player_id = ? AND card_type != 'serve'");
                    $stmt->execute([$player['game_id'], $player['id']]);
                    $handCount = $stmt->fetchColumn() ?: 0;
                    $cardText = 'card';
                    
                    if ($handCount > 0) {
                        if($handCount > 1) {
                            $cardText = 'cards';
                        }
                        $cardCountText = " You have {$handCount} {$cardText} in your hand.";
                    }
                }

                $message = "{$scoreStatus} {$daysLeft} days left in your game with {$player['opponent_name']}.{$cardCountText}";
                
                $result = sendPushNotification(
                    $player['fcm_token'],
                    'Score Update',
                    $message
                );
                
                if ($result) {
                    $notificationsSent++;
                    error_log("Daily notification sent to player {$player['id']} ({$player['first_name']})");
                } else {
                    error_log("Failed to send daily notification to player {$player['id']} ({$player['first_name']})");
                }
                
            } catch (Exception $e) {
                error_log("Error processing daily notification for player {$player['id']}: " . $e->getMessage());
            }
        }
        
        error_log("Daily notifications completed: {$notificationsSent} sent out of " . count($players) . " attempted");
        echo "Daily notifications sent to {$notificationsSent} players\n";
        
    } catch (Exception $e) {
        error_log("Error in sendDailyNotifications: " . $e->getMessage());
        echo "Error sending daily notifications: " . $e->getMessage() . "\n";
    }
}

function checkExpiredTimers($specificTimerId = null) {
    error_log("Checking for expired timers" . ($specificTimerId ? " - timer ID: $specificTimerId" : ""));
    
    try {
        $pdo = Config::getDatabaseConnection();
        
        if ($specificTimerId) {
            // Process specific timer (called from cron)
            $stmt = $pdo->prepare("
                SELECT t.*, p.fcm_token, p.first_name 
                FROM timers t
                JOIN players p ON t.player_id = p.id
                WHERE t.id = ? AND t.is_active = TRUE
            ");
            $stmt->execute([$specificTimerId]);
            $expiredTimers = $stmt->fetchAll();
        } else {
            // Process all expired timers (fallback/manual call)
            $stmt = $pdo->prepare("
                SELECT t.*, p.fcm_token, p.first_name 
                FROM timers t
                JOIN players p ON t.player_id = p.id
                WHERE t.is_active = TRUE AND t.end_time <= UTC_TIMESTAMP()
            ");
            $stmt->execute();
            $expiredTimers = $stmt->fetchAll();
        }
        
        $notificationsSent = 0;
        
        foreach ($expiredTimers as $timer) {
            try {
                // Check if this is a recurring timer (Clock Siphon)
                $stmt = $pdo->prepare("SELECT * FROM active_chance_effects WHERE timer_id = ? AND effect_type = 'recurring_timer'");
                $stmt->execute([$timer['id']]);
                $recurringEffect = $stmt->fetch();
                
                if ($recurringEffect) {
                    // This is a Clock Siphon - subtract point and create new timer
                    $pointsToSubtract = $recurringEffect['effect_value'] ?: 1;
                    
                    // Subtract point
                    $stmt = $pdo->prepare("UPDATE players SET score = score - ? WHERE id = ?");
                    $stmt->execute([$pointsToSubtract, $timer['player_id']]);
                    
                    // Get the repeat interval from the original card
                    $stmt = $pdo->prepare("SELECT repeat_count FROM cards WHERE id = ?");
                    $stmt->execute([$recurringEffect['chance_card_id']]);
                    $interval = $stmt->fetchColumn() ?: 5; // Default to 5 minutes
                    
                    // Create new timer
                    $newTimer = createTimer($timer['game_id'], $timer['player_id'], $timer['description'], $interval);
                    if ($newTimer['success']) {
                        // Update the effect with new timer ID
                        $stmt = $pdo->prepare("UPDATE active_chance_effects SET timer_id = ? WHERE id = ?");
                        $stmt->execute([$newTimer['timer_id'], $recurringEffect['id']]);
                        
                        error_log("Clock Siphon: Subtracted $pointsToSubtract points from player {$timer['player_id']}, created new timer {$newTimer['timer_id']}");
                    }
                } else {
                    // Regular timer - send notification if token exists
                    if ($timer['fcm_token'] && !empty($timer['fcm_token'])) {
                        $result = sendPushNotification(
                            $timer['fcm_token'],
                            'Timer Expired ⏰',
                            $timer['description']
                        );
                        
                        if ($result) {
                            $notificationsSent++;
                            error_log("Timer notification sent for timer {$timer['id']} to {$timer['first_name']}");
                        }
                    }
                    // Check if this timer is linked to a chance effect that should auto-complete
                    $stmt = $pdo->prepare("SELECT * FROM active_chance_effects WHERE timer_id = ?");
                    $stmt->execute([$timer['id']]);
                    $linkedEffect = $stmt->fetch();
                    
                    if ($linkedEffect && $linkedEffect['effect_type'] === 'challenge_modify') {
                        // This is a timer-based challenge modifier - auto-complete it
                        $stmt = $pdo->prepare("DELETE FROM player_cards WHERE game_id = ? AND player_id = ? AND card_id = ?");
                        $stmt->execute([$timer['game_id'], $linkedEffect['player_id'], $linkedEffect['chance_card_id']]);
                        
                        $stmt = $pdo->prepare("DELETE FROM active_chance_effects WHERE id = ?");
                        $stmt->execute([$linkedEffect['id']]);
                        
                        error_log("Auto-completed timer-based challenge modifier for timer {$timer['id']}");
                    }
                }
                
                // Delete the expired timer
                $stmt = $pdo->prepare("DELETE FROM timers WHERE id = ?");
                $stmt->execute([$timer['id']]);

                // Clean up any orphaned chance effects for timers that were just deleted
                $stmt = $pdo->prepare("
                    DELETE FROM active_chance_effects 
                    WHERE timer_id = ? AND game_id = ?
                ");
                $stmt->execute([$timer['id'], $timer['game_id']]);
                
            } catch (Exception $e) {
                error_log("Error processing expired timer {$timer['id']}: " . $e->getMessage());
            }
        }
        
        return count($expiredTimers);
        
    } catch (Exception $e) {
        error_log("Error checking expired timers: " . $e->getMessage());
        return 0;
    }
}

function cleanupExpiredGames() {
    error_log("Starting cleanup...");
    
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Mark games as completed if they've passed their end date
        $stmt = $pdo->prepare("
            UPDATE games 
            SET status = 'completed' 
            WHERE status = 'active' AND end_date < NOW()
        ");
        $stmt->execute();
        $updatedGames = $stmt->rowCount();
        
        // Clean up inactive timers older than 7 days
        $stmt = $pdo->prepare("
            DELETE FROM timers 
            WHERE is_active = FALSE AND end_time < DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $stmt->execute();
        $deletedTimers = $stmt->rowCount();
        
        error_log("Cleanup completed: {$updatedGames} games marked as completed, {$deletedTimers} old timers deleted");
        echo "Cleanup completed: {$updatedGames} games marked as completed, {$deletedTimers} old timers deleted\n";
        
    } catch (Exception $e) {
        error_log("Error during cleanup: " . $e->getMessage());
        echo "Error during cleanup: " . $e->getMessage() . "\n";
    }
}

echo "Cron job completed at " . date('Y-m-d H:i:s') . "\n";
error_log("Cron job completed at " . date('Y-m-d H:i:s'));
?>