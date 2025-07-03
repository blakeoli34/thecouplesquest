<?php
// cron.php - Cron jobs for notifications and maintenance
require_once 'config.php';
require_once 'functions.php';

// This file should be run every minute via cron job
// Add to crontab: * * * * * /usr/bin/php /path/to/your/site/cron.php

$action = $argv[1] ?? 'all';

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
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Get all active games with players
        $stmt = $pdo->query("
            SELECT g.id as game_id, g.end_date,
                   p.id, p.first_name, p.gender, p.score, p.fcm_token,
                   opponent.first_name as opponent_name, opponent.score as opponent_score
            FROM games g
            JOIN players p ON g.id = p.game_id
            JOIN players opponent ON g.id = opponent.game_id AND opponent.id != p.id
            WHERE g.status = 'active' AND p.fcm_token IS NOT NULL
        ");
        
        $players = $stmt->fetchAll();
        
        foreach ($players as $player) {
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
            
            $message = "Daily Update: {$scoreStatus} {$daysLeft} days left in your game with {$player['opponent_name']}.";
            
            sendPushNotification(
                $player['fcm_token'],
                'The Couples Quest - Daily Update',
                $message
            );
        }
        
        echo "Daily notifications sent to " . count($players) . " players\n";
        
    } catch (Exception $e) {
        error_log("Error sending daily notifications: " . $e->getMessage());
        echo "Error sending daily notifications\n";
    }
}

function cleanupExpiredGames() {
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
        
        // Clean up old invite codes (older than 30 days and unused)
        $stmt = $pdo->prepare("
            DELETE FROM invite_codes 
            WHERE is_used = FALSE AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute();
        $deletedCodes = $stmt->rowCount();
        
        // Clean up inactive timers older than 7 days
        $stmt = $pdo->prepare("
            DELETE FROM timers 
            WHERE is_active = FALSE AND end_time < DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $stmt->execute();
        $deletedTimers = $stmt->rowCount();
        
        echo "Cleanup completed: {$updatedGames} games marked as completed, {$deletedCodes} old codes deleted, {$deletedTimers} old timers deleted\n";
        
    } catch (Exception $e) {
        error_log("Error during cleanup: " . $e->getMessage());
        echo "Error during cleanup\n";
    }
}

echo "Cron job completed at " . date('Y-m-d H:i:s') . "\n";
?>