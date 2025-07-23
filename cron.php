<?php
// cron.php - Fixed version with proper logging and error handling
require_once 'config.php';
require_once 'functions.php';

// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', '/path/to/your/logs/cron_errors.log'); // Update this path

$action = $argv[1] ?? 'all';

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
        
        // Get all active games with players - Fixed query
        $stmt = $pdo->query("
            SELECT g.id as game_id, g.end_date,
                   p.id, p.first_name, p.gender, p.score, p.fcm_token,
                   opponent.first_name as opponent_name, opponent.score as opponent_score
            FROM games g
            JOIN players p ON g.id = p.game_id
            JOIN players opponent ON g.id = opponent.game_id AND opponent.id != p.id
            WHERE g.status = 'active' AND p.fcm_token IS NOT NULL AND p.fcm_token != ''
        ");
        
        $players = $stmt->fetchAll();
        error_log("Found " . count($players) . " players with FCM tokens for daily notifications");

        // Get game mode
        $stmt = $pdo->prepare("SELECT game_mode FROM games WHERE id = ?");
        $stmt->execute([$player['game_id']]);
        $gameData = ['game_mode' => $stmt->fetchColumn()];
        
        $notificationsSent = 0;
        
        foreach ($players as $player) {
            try {
                $endDate = new DateTime($player['end_date']);
                $now = new DateTime();
                $daysLeft = $now < $endDate ? $endDate->diff($now)->days : 0;
                
                $scoreStatus = '';
                if ($player['score'] > $player['opponent_score']) {
                    $scoreDiff = $player['score'] - $player['opponent_score'];
                    $scoreStatus = "You're winning by {$scoreDiff} points! 🎉";
                } elseif ($player['score'] < $player['opponent_score']) {
                    $scoreDiff = $player['opponent_score'] - $player['score'];
                    $scoreStatus = "You're behind by {$scoreDiff} points. Time to catch up! 💪";
                } else {
                    $scoreStatus = "It's a tie! Who will take the lead? 🤝";
                }
                
                // Get card count for digital games
                $cardCountText = '';
                if ($gameData['game_mode'] === 'digital') {
                    $stmt = $pdo->prepare("SELECT SUM(quantity) as hand_count FROM player_cards WHERE game_id = ? AND player_id = ? AND card_type != 'serve'");
                    $stmt->execute([$player['game_id'], $player['id']]);
                    $handCount = $stmt->fetchColumn() ?: 0;
                    
                    if ($handCount > 0) {
                        $cardCountText = " You have {$handCount} cards in your hand.";
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

function checkExpiredTimers() {
    error_log("Checking expired timers...");
    
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Get expired timers - Fixed to use UTC_TIMESTAMP()
        $stmt = $pdo->prepare("
            SELECT t.*, p.fcm_token, p.first_name 
            FROM timers t
            JOIN players p ON t.player_id = p.id
            WHERE t.is_active = TRUE AND t.end_time <= UTC_TIMESTAMP()
        ");
        $stmt->execute();
        $expiredTimers = $stmt->fetchAll();
        
        error_log("Found " . count($expiredTimers) . " expired timers");
        
        $notificationsSent = 0;
        
        foreach ($expiredTimers as $timer) {
            try {
                // Send notification if FCM token exists
                if ($timer['fcm_token'] && !empty($timer['fcm_token'])) {
                    $result = sendPushNotification(
                        $timer['fcm_token'],
                        'Timer Expired ⏰',
                        $timer['description']
                    );
                    
                    if ($result) {
                        $notificationsSent++;
                        error_log("Timer notification sent for timer {$timer['id']} to {$timer['first_name']}");
                    } else {
                        error_log("Failed to send timer notification for timer {$timer['id']} to {$timer['first_name']}");
                    }
                } else {
                    error_log("No FCM token for timer {$timer['id']} player {$timer['first_name']}");
                }
                
                // Mark timer as inactive
                $stmt = $pdo->prepare("UPDATE timers SET is_active = FALSE WHERE id = ?");
                $stmt->execute([$timer['id']]);
                
            } catch (Exception $e) {
                error_log("Error processing expired timer {$timer['id']}: " . $e->getMessage());
            }
        }
        
        error_log("Timer check completed: {$notificationsSent} notifications sent, " . count($expiredTimers) . " timers deactivated");
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