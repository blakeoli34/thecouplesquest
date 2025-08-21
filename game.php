<?php
// game.php - Main game interface
require_once 'config.php';
require_once 'functions.php';

$deviceId = $_GET['device_id'] ?? $_COOKIE['device_id'] ?? null;

// If device ID is in URL parameter, set the cookie for future visits
if (isset($_GET['device_id']) && $_GET['device_id']) {
    $deviceId = $_GET['device_id'];
    setcookie('device_id', $deviceId, time() + (365 * 24 * 60 * 60), '/', '', true, true);
    
    // Redirect to clean URL without parameter
    header('Location: game.php');
    exit;
}

if (!$deviceId) {
    header('Location: index.php');
    exit;
}

$player = getPlayerByDeviceId($deviceId);
if (!$player) {
    header('Location: index.php');
    exit;
}

// Get fresh game data to check current mode
$pdo = Config::getDatabaseConnection();
$stmt = $pdo->prepare("SELECT * FROM games WHERE id = ?");
$stmt->execute([$player['game_id']]);
$gameData = $stmt->fetch();

$players = getGamePlayers($player['game_id']);
$currentPlayer = null;
$opponentPlayer = null;

foreach ($players as $p) {
    if ($p['device_id'] === $deviceId) {
        $currentPlayer = $p;
    } else {
        $opponentPlayer = $p;
    }
}
$gameStatus = $gameData['status']; // Use fresh game data
$gameMode = $gameData['game_mode']; // Get current mode

$timezone = new DateTimeZone('America/Indiana/Indianapolis');
$now = new DateTime('now', $timezone);
$endDate = new DateTime($player['end_date'], $timezone);
$timeRemaining = $now < $endDate ? $endDate->diff($now) : null;

$gameTimeText = '';
if ($timeRemaining) {
    $parts = [];
    
    if ($timeRemaining->days > 0) {
        $parts[] = $timeRemaining->days . ' day' . ($timeRemaining->days > 1 ? 's' : '');
    }
    
    if ($timeRemaining->h > 0) {
        $parts[] = $timeRemaining->h . ' hour' . ($timeRemaining->h > 1 ? 's' : '');
    }
    
    if ($timeRemaining->i > 0) {
        $parts[] = $timeRemaining->i . ' minute' . ($timeRemaining->i > 1 ? 's' : '');
    }
    
    $gameTimeText = 'Game ends in ' . implode(', ', $parts);
} else {
    $gameTimeText = 'Game Ended';
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    // Get fresh game mode for AJAX calls
    $pdo = Config::getDatabaseConnection();
    $stmt = $pdo->prepare("SELECT game_mode FROM games WHERE id = ?");
    $stmt->execute([$player['game_id']]);
    $gameMode = $stmt->fetchColumn();
    
    switch ($_POST['action']) {
        case 'reset_decks':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            try {
                $pdo = Config::getDatabaseConnection();
                $pdo->beginTransaction();
                
                $gameId = $player['game_id'];
                
                // Delete all related records
                $stmt = $pdo->prepare("DELETE FROM game_decks WHERE game_id = ?");
                $stmt->execute([$gameId]);
                
                $stmt = $pdo->prepare("DELETE FROM player_cards WHERE game_id = ?");
                $stmt->execute([$gameId]);
                
                $stmt = $pdo->prepare("DELETE FROM timers WHERE game_id = ?");
                $stmt->execute([$gameId]);
                
                $stmt = $pdo->prepare("DELETE FROM active_chance_effects WHERE game_id = ?");
                $stmt->execute([$gameId]);
                
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Decks reset successfully']);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Error resetting decks: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Failed to reset decks']);
            }
            exit;

        case 'debug_effects':
            $effects = getActiveChanceEffects($player['game_id']);
            $timers = getActiveTimers($player['game_id']);
            
            // Check for orphaned effects
            $stmt = $pdo->prepare("
                SELECT ace.*, t.id as timer_exists, t.end_time, t.is_active
                FROM active_chance_effects ace
                LEFT JOIN timers t ON ace.timer_id = t.id
                WHERE ace.game_id = ?
            ");
            $stmt->execute([$player['game_id']]);
            $effectsWithTimers = $stmt->fetchAll();
            
            echo json_encode([
                'effects' => $effects,
                'timers' => $timers,
                'effects_with_timers' => $effectsWithTimers,
                'current_time' => date('Y-m-d H:i:s')
            ]);
            exit;

        case 'set_duration':
            if (isset($_POST['custom_date'])) {
                // Handle custom date
                $customDate = $_POST['custom_date'];
                $timezone = new DateTimeZone('America/Indiana/Indianapolis');
                $now = new DateTime('now', $timezone);
                $customDateTime = new DateTime($customDate . ' 23:59:59', $timezone);
                
                // Validate date is at least 1 week from now and max 1 year
                $minDate = clone $now;
                $minDate->add(new DateInterval('P7D'));
                $maxDate = clone $now;
                $maxDate->add(new DateInterval('P1Y'));
                
                if ($customDateTime < $minDate || $customDateTime > $maxDate) {
                    echo json_encode(['success' => false, 'message' => 'Date must be between 1 week and 1 year from now']);
                    exit;
                }
                
                // Calculate duration in days from now to selected date
                $diffDays = $now->diff($customDateTime)->days + 1;
                
                // Manually set the game dates
                try {
                    $pdo = Config::getDatabaseConnection();
                    $stmt = $pdo->prepare("
                        UPDATE games 
                        SET duration_days = ?, start_date = ?, end_date = ?, status = 'active', custom_end_date = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $diffDays,
                        $now->format('Y-m-d H:i:s'),
                        $customDateTime->format('Y-m-d H:i:s'),
                        $customDate,
                        $player['game_id']
                    ]);
                    $result = ['success' => true];
                } catch (Exception $e) {
                    error_log("Error setting custom duration: " . $e->getMessage());
                    $result = ['success' => false, 'message' => 'Failed to set custom duration'];
                }
            } else {
                // Handle preset duration
                $duration = intval($_POST['duration']);
                $result = setGameDuration($player['game_id'], $duration);
            }
            
            // Initialize digital cards if this is a digital game
            if ($result['success'] && $gameMode === 'digital') {
                $initResult = initializeDigitalGame($player['game_id']);
                if (!$initResult['success']) {
                    $result['warning'] = 'Game started but failed to initialize cards';
                }
            }
            
            echo json_encode($result);
            exit;

        case 'set_game_mode':
            $mode = $_POST['mode'];
            if (!in_array($mode, ['hybrid', 'digital'])) {
                echo json_encode(['success' => false, 'message' => 'Invalid game mode']);
                exit;
            }
            
            try {
                $pdo = Config::getDatabaseConnection();
                $stmt = $pdo->prepare("UPDATE games SET game_mode = ? WHERE id = ?");
                $stmt->execute([$mode, $player['game_id']]);
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                error_log("Error setting game mode: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Failed to set game mode']);
            }
            exit;

        case 'debug_timers':
            try {
                error_log("Debug timers called for game_id: " . $player['game_id']);
                $pdo = Config::getDatabaseConnection();
                $stmt = $pdo->prepare("SELECT *, NOW() as server_now FROM timers WHERE game_id = ? ORDER BY id DESC LIMIT 5");
                $stmt->execute([$player['game_id']]);
                $result = $stmt->fetchAll();
                error_log("Timer results: " . print_r($result, true));
                echo json_encode($result);
            } catch (Exception $e) {
                error_log("Debug timer error: " . $e->getMessage());
                echo json_encode(['error' => $e->getMessage()]);
            }
            exit;
            
        case 'update_score':
            $playerId = intval($_POST['player_id']);
            $points = intval($_POST['points']);
            $result = updateScore($player['game_id'], $playerId, $points, $player['id']);
            echo json_encode($result);
            exit;
            
        case 'create_timer':
            $description = trim($_POST['description']);
            $minutes = floatval($_POST['minutes']);
            $result = createTimer($player['game_id'], $player['id'], $description, $minutes);
            echo json_encode($result);
            exit;

        case 'delete_timer':
            $timerId = intval($_POST['timer_id']);
            $result = deleteTimer($timerId, $player['game_id']);
            echo json_encode($result);
            exit;

        case 'timer_expired':
            $timerId = intval($_POST['timer_id']);
            $description = $_POST['description'];
            
            // Send push notification
            if ($player['fcm_token']) {
                $result = sendPushNotification(
                    $player['fcm_token'],
                    'Timer Expired ⏰',
                    $description
                );
            }
            
            echo json_encode(['success' => true]);
            exit;
            
        case 'send_bump':
            $result = sendBumpNotification($player['game_id'], $player['id']);
            echo json_encode($result);
            exit;

        case 'test_notification':
            $result = sendTestNotification($deviceId);
            echo json_encode($result);
            exit;
            
        case 'update_fcm_token':
            $token = $_POST['fcm_token'];
            $result = updateFcmToken($deviceId, $token);
            echo json_encode(['success' => $result]);
            exit;
            
        case 'get_game_data':

            processExpiredTimers($player['game_id']);
            clearExpiredChanceEffects($player['game_id']);
            
            $updatedPlayers = getGamePlayers($player['game_id']);
            $timers = getActiveTimers($player['game_id']);
            $history = getScoreHistory($player['game_id']);
            $updatedPlayers = getGamePlayers($player['game_id']);
            $timers = getActiveTimers($player['game_id']);
            $history = getScoreHistory($player['game_id']);

            // Check if game has expired
            $now = new DateTime();
            $endDate = new DateTime($player['end_date']);
            $gameExpired = ($now >= $endDate && $player['status'] === 'active');
            
            echo json_encode([
                'players' => $updatedPlayers,
                'timers' => $timers,
                'history' => $history,
                'gametime' => $gameTimeText,
                'game_expired' => $gameExpired
            ]);
            exit;

        case 'check_game_status':
            try {
                $pdo = Config::getDatabaseConnection();
                $gameId = $player['game_id'];
                
                // Get updated game info including mode
                $stmt = $pdo->prepare("SELECT status, game_mode FROM games WHERE id = ?");
                $stmt->execute([$gameId]);
                $gameInfo = $stmt->fetch();
                
                $currentPlayers = getGamePlayers($gameId);
                
                echo json_encode([
                    'success' => true,
                    'status' => $gameInfo['status'],
                    'game_mode' => $gameInfo['game_mode'],
                    'player_count' => count($currentPlayers)
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'error' => $e->getMessage()
                ]);
            }
            exit;

        case 'get_card_data':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $serveCards = getPlayerCards($player['game_id'], $player['id'], 'serve');
            
            // Initialize hand cards structure
            $handCards = [
                'accepted_serve' => [],
                'snap' => [],
                'dare' => [],
                'spicy' => [],
                'chance' => []
            ];
            
            // Get all non-serve cards in hand and organize by type
            $allHandCards = getPlayerCards($player['game_id'], $player['id']);
            
            foreach ($allHandCards as $card) {
                if ($card['card_type'] !== 'serve') {
                    $handCards[$card['card_type']][] = $card;
                }
            }

            // Get serve card count
            $stmt = $pdo->prepare("SELECT SUM(quantity) as serve_count FROM player_cards WHERE game_id = ? AND player_id = ? AND card_type = 'serve'");
            $stmt->execute([$player['game_id'], $player['id']]);
            $serveCount = $stmt->fetchColumn() ?: 0;

            $hasBlocking = hasBlockingChanceCard($player['game_id'], $player['id']);
            $blockingCards = $hasBlocking ? getBlockingChanceCardNames($player['game_id'], $player['id']) : [];

            $activeModifiers = [];
            $effects = getActiveChanceEffects($player['game_id'], null, $player['id']);
            foreach ($effects as $effect) {
                $stmt = $pdo->prepare("SELECT card_name FROM cards WHERE id = ?");
                $stmt->execute([$effect['chance_card_id']]);
                $cardName = $stmt->fetchColumn();
                
                switch ($effect['effect_type']) {
                    case 'challenge_modify':
                        // Only show if this player is the target
                        if (!$effect['target_player_id'] || $effect['target_player_id'] == $player['id']) {
                            $activeModifiers['accepted_serve'] = $cardName;
                        }
                        break;
                    case 'snap_modify':
                        // Show if this player is the target OR if they own it and no target (self-modifier)
                        if ($effect['target_player_id'] == $player['id'] || 
                            ($effect['player_id'] == $player['id'] && !$effect['target_player_id'])) {
                            $activeModifiers['snap'] = $cardName;
                        }
                        break;
                    case 'dare_modify':
                        // Show if this player is the target OR if they own it and no target (self-modifier)
                        if ($effect['target_player_id'] == $player['id'] || 
                            ($effect['player_id'] == $player['id'] && !$effect['target_player_id'])) {
                            $activeModifiers['dare'] = $cardName;
                        }
                        break;
                    case 'spicy_modify':
                        // These only affect the player who drew the card
                        if ($effect['player_id'] == $player['id']) {
                            $activeModifiers['spicy'] = $cardName;
                        }
                        break;
                    case 'veto_modify':
                        if (strpos($effect['effect_value'], 'opponent_double') !== false) {
                            // Show on opponent's cards only
                            if ($effect['target_player_id'] == $player['id']) {
                                $activeModifiers['accepted_serve_veto'] = $cardName;
                                $activeModifiers['snap_veto'] = $cardName;
                                $activeModifiers['dare_veto'] = $cardName;
                            }
                        } elseif(strpos($effect['effect_value'], 'opponent_reward') !== false) {
                            // Do not show opponent reward as veto modifier badge
                        } else {
                            // Show on current player's cards
                            if ($effect['player_id'] == $player['id']) {
                                $activeModifiers['accepted_serve_veto'] = $cardName;
                                $activeModifiers['snap_veto'] = $cardName;
                                $activeModifiers['dare_veto'] = $cardName;
                            }
                        }
                        break;
                }
            }
            
            echo json_encode([
                'success' => true,
                'serve_cards' => $serveCards,
                'hand_cards' => $handCards,
                'serve_count' => $serveCount,
                'has_blocking' => $hasBlocking,
                'blocking_cards' => $blockingCards,
                'active_modifiers' => $activeModifiers
            ]);
            exit;

        case 'get_opponent_hand_cards':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $opponentId = null;
            foreach ($players as $p) {
                if ($p['device_id'] !== $deviceId) {
                    $opponentId = $p['id'];
                    break;
                }
            }
            
            if (!$opponentId) {
                echo json_encode(['success' => false, 'message' => 'Opponent not found']);
                exit;
            }
            
            // Get all non-serve cards in opponent's hand
            $allHandCards = getPlayerCards($player['game_id'], $opponentId);
            $handCards = [];
            
            foreach ($allHandCards as $card) {
                if ($card['card_type'] !== 'serve') {
                    $handCards[] = $card;
                }
            }
            
            echo json_encode([
                'success' => true,
                'hand_cards' => $handCards
            ]);
            exit;

        case 'serve_card':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $cardId = intval($_POST['card_id']);
            $toPlayerId = intval($_POST['to_player_id']);
            $filledDescription = $_POST['filled_description'] ?? null;
            
            $result = serveCard($player['game_id'], $player['id'], $toPlayerId, $cardId, $filledDescription);
            echo json_encode($result);
            exit;

        case 'complete_hand_card':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $cardId = intval($_POST['card_id']);
            $playerCardId = intval($_POST['player_card_id']);
            $result = completeHandCard($player['game_id'], $player['id'], $cardId, $playerCardId);
            echo json_encode($result);
            exit;

        case 'veto_hand_card':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $cardId = intval($_POST['card_id']);
            $playerCardId = intval($_POST['player_card_id']);
            $result = vetoHandCard($player['game_id'], $player['id'], $cardId, $playerCardId);
            echo json_encode($result);
            exit;

        case 'win_hand_card':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $cardId = intval($_POST['card_id']);
            $playerCardId = intval($_POST['player_card_id']);
            $result = processWinLossCard($player['game_id'], $player['id'], $cardId, $playerCardId, true);
            echo json_encode($result);
            exit;

        case 'lose_hand_card':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $cardId = intval($_POST['card_id']);
            $playerCardId = intval($_POST['player_card_id']);
            $result = processWinLossCard($player['game_id'], $player['id'], $cardId, $playerCardId, false);
            echo json_encode($result);
            exit;

        case 'complete_chance_card':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $playerCardId = intval($_POST['player_card_id']);
            
            try {
                $pdo = Config::getDatabaseConnection();
                $pdo->beginTransaction();
                
                // Get the chance card details
                $stmt = $pdo->prepare("
                    SELECT pc.*, c.* 
                    FROM player_cards pc 
                    JOIN cards c ON pc.card_id = c.id 
                    WHERE pc.id = ? AND pc.player_id = ? AND pc.game_id = ?
                ");
                $stmt->execute([$playerCardId, $player['id'], $player['game_id']]);
                $chanceCard = $stmt->fetch();
                
                if (!$chanceCard) {
                    throw new Exception("Chance card not found");
                }
                
                // Delete any active timers for this card
                if ($chanceCard['timer']) {
                    $stmt = $pdo->prepare("DELETE FROM timers WHERE game_id = ? AND player_id = ? AND description = ?");
                    $stmt->execute([$player['game_id'], $player['id'], $chanceCard['card_name']]);
                }
                
                // Remove any active effects for this card
                $stmt = $pdo->prepare("DELETE FROM active_chance_effects WHERE game_id = ? AND player_id = ? AND chance_card_id = ?");
                $stmt->execute([$player['game_id'], $player['id'], $chanceCard['card_id']]);
                
                // Remove card from hand
                if ($chanceCard['quantity'] > 1) {
                    $stmt = $pdo->prepare("UPDATE player_cards SET quantity = quantity - 1 WHERE id = ?");
                    $stmt->execute([$playerCardId]);
                } else {
                    $stmt = $pdo->prepare("DELETE FROM player_cards WHERE id = ?");
                    $stmt->execute([$playerCardId]);
                }
                
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => "Completed {$chanceCard['card_name']}"]);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Error completing chance card: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
            exit;

        case 'get_active_effects':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false]);
                exit;
            }
            
            $effects = getActiveChanceEffects($player['game_id'], null, $player['id']);
            $descriptions = [];
            
            foreach ($effects as $effect) {
                $desc = '';
                switch ($effect['effect_type']) {
                    case 'before_next_challenge':
                        $desc = 'Must complete before next challenge';
                        break;
                    case 'challenge_modify':
                        $desc = "Next challenge: {$effect['effect_value']}";
                        break;
                    case 'veto_modify':
                        $desc = "Next veto: {$effect['effect_value']}";
                        break;
                    case 'timer_effect':
                        $desc = 'Timer-based effect active';
                        break;
                    case 'recurring_timer':
                        $desc = 'Losing 1 point every 5 minutes';
                        break;
                }
                
                if ($desc) {
                    $descriptions[] = ['description' => $desc];
                }
            }
            
            echo json_encode(['success' => true, 'effects' => $descriptions]);
            exit;

        case 'get_card_modifiers':
            $cardType = $_POST['card_type'];
            $modifiers = [];
            
            $effects = getActiveChanceEffects($player['game_id'], null, $player['id']);
            foreach ($effects as $effect) {
                if (($cardType === 'accepted_serve' && $effect['effect_type'] === 'challenge_modify') ||
                    ($cardType === 'snap' && $effect['snap_modify']) ||
                    ($cardType === 'dare' && $effect['dare_modify']) ||
                    ($cardType === 'spicy' && $effect['spicy_modify'])) {
                    $modifiers[] = $effect['effect_value'] ?: 'Modified';
                }
            }
            
            echo json_encode(['modifiers' => $modifiers]);
            exit;

        case 'manual_draw':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $cardType = $_POST['card_type'];
            $quantity = intval($_POST['quantity']) ?: 1;
            $source = $_POST['source'] ?? 'manual';
            
            if (!in_array($cardType, ['chance', 'snap', 'dare', 'spicy'])) {
                echo json_encode(['success' => false, 'message' => 'Invalid card type']);
                exit;
            }
            
            $drawResult = drawCards($player['game_id'], $player['id'], $cardType, $quantity, $source);
            $drawnCards = $drawResult['card_names'];
            $cardDetails = !empty($drawResult['card_details']) ? $drawResult['card_details'][0] : null;
            
            echo json_encode([
                'success' => true, 
                'drawn_cards' => $drawnCards,
                'card_details' => $cardDetails,
                'immediate_effects' => $cardDetails['card_type'] === 'chance' ? $cardDetails : null
            ]);
            exit;

        case 'initialize_digital_cards':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $result = initializeDigitalGame($player['game_id']);
            echo json_encode($result);
            exit;

        case 'get_deck_counts':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false]);
                exit;
            }
            
            $playerGender = $player['gender'];
            $genderCondition = ($playerGender === 'male') ? 'for_him = 1' : 'for_her = 1';
            
            $stmt = $pdo->prepare("
                SELECT c.card_type, SUM(gd.remaining_quantity) as remaining_count
                FROM game_decks gd
                JOIN cards c ON gd.card_id = c.id  
                WHERE gd.game_id = ? AND gd.player_id = ?
                AND c.card_type IN ('chance', 'snap', 'dare', 'spicy')
                AND (
                    (c.card_type IN ('snap', 'dare')) OR
                    (c.card_type IN ('chance', 'spicy') AND c.{$genderCondition})
                )
                GROUP BY c.card_type
            ");
            $stmt->execute([$player['game_id'], $player['id']]);
            $counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            echo json_encode(['success' => true, 'counts' => $counts]);
            exit;

        case 'get_opponent_hand_counts':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false]);
                exit;
            }
            
            $opponentId = null;
            foreach ($players as $p) {
                if ($p['device_id'] !== $deviceId) {
                    $opponentId = $p['id'];
                    break;
                }
            }
            
            if ($opponentId) {
                $stmt = $pdo->prepare("
                    SELECT pc.card_type, SUM(pc.quantity) as total_count
                    FROM player_cards pc 
                    WHERE pc.game_id = ? AND pc.player_id = ? AND pc.card_type != 'serve'
                    GROUP BY pc.card_type
                ");
                $stmt->execute([$player['game_id'], $opponentId]);
                $counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                echo json_encode(['success' => true, 'counts' => $counts]);
            } else {
                echo json_encode(['success' => false]);
            }
            exit;

        case 'can_spin_wheel':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false]);
                exit;
            }
            
            $canSpin = canPlayerSpinWheel($player['id']);
            echo json_encode(['success' => true, 'can_spin' => $canSpin]);
            exit;

        case 'get_wheel_data':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false]);
                exit;
            }
            
            // Get player gender for formatting
            $playerGender = $currentPlayer['gender'];
            
            $prizes = getDailyWheelPrizes();
            if (empty($prizes)) {
                echo json_encode(['success' => false, 'message' => 'Not enough prizes configured']);
                exit;
            }
            
            // Format prizes for this player's gender
            $formattedPrizes = array_map(function($prize) use ($playerGender) {
                return formatPrizeForPlayer($prize, $playerGender);
            }, $prizes);
            
            echo json_encode(['success' => true, 'prizes' => $formattedPrizes]);
            exit;

        case 'spin_wheel':
            if ($gameMode !== 'digital') {
                echo json_encode(['success' => false, 'message' => 'Not a digital game']);
                exit;
            }
            
            $result = spinWheel($player['game_id'], $player['id']);
            echo json_encode($result);
            exit;

        case 'get_rules':
            try {
                $stmt = $pdo->query("SELECT content FROM game_rules ORDER BY id LIMIT 1");
                $rules = $stmt->fetch();
                echo json_encode([
                    'success' => true,
                    'content' => $rules ? $rules['content'] : '<p>No rules available yet.</p>'
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to load rules'
                ]);
            }
            exit;

        case 'cleanup_effects':
            clearExpiredChanceEffects($player['game_id']);
            echo json_encode(['success' => true]);
            exit;

        case 'end_game':
            try {
                $pdo = Config::getDatabaseConnection();
                
                // Get final scores before ending the game
                $players = getGamePlayers($player['game_id']);
                
                // Determine winner
                $winner = null;
                $loser = null;
                $isTie = false;
                
                if (count($players) === 2) {
                    if ($players[0]['score'] > $players[1]['score']) {
                        $winner = $players[0];
                        $loser = $players[1];
                    } elseif ($players[1]['score'] > $players[0]['score']) {
                        $winner = $players[1];
                        $loser = $players[0];
                    } else {
                        $isTie = true;
                    }
                }
                
                // End the game
                $stmt = $pdo->prepare("UPDATE games SET status = 'completed', end_date = NOW() WHERE id = ?");
                $stmt->execute([$player['game_id']]);
                
                // Send notifications to both players
                foreach ($players as $p) {
                    if ($p['fcm_token']) {
                        if ($isTie) {
                            $title = "Game Over - It's a Tie!";
                            $body = "Final score: " . $players[0]['score'] . " points each. Great game!";
                        } else {
                            if ($p['id'] === $winner['id']) {
                                $title = "🎉 You Won!";
                                $body = "Final score: " . $winner['score'] . "-" . $loser['score'] . ". Congratulations!";
                            } else {
                                $title = "Game Over";
                                $body = $winner['first_name'] . " won " . $winner['score'] . "-" . $loser['score'] . ". Better luck next time!";
                            }
                        }
                        
                        sendPushNotification($p['fcm_token'], $title, $body);
                    }
                }
                
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                error_log("Error ending game: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Failed to end game.']);
            }
            exit;

        case 'ready_for_new_game':
            $result = markPlayerReadyForNewGame($player['game_id'], $player['id']);
            if ($result['success'] && $result['both_ready']) {
                $resetResult = resetGameForNewRound($player['game_id']);
                if ($resetResult['success']) {
                    echo json_encode(['success' => true, 'redirect' => true]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to reset game']);
                }
            } else {
                echo json_encode($result);
            }
            exit;

        case 'get_new_game_status':
            // Check if game has been reset (status = 'waiting')
            try {
                $pdo = Config::getDatabaseConnection();
                $stmt = $pdo->prepare("SELECT status FROM games WHERE id = ?");
                $stmt->execute([$player['game_id']]);
                $gameStatus = $stmt->fetchColumn();
                
                echo json_encode([
                    'success' => true, 
                    'game_reset' => ($gameStatus === 'waiting')
                ]);
            } catch (Exception $e) {
                echo json_encode(['success' => false]);
            }
            exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>The Couple's Quest</title>
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="<?= Config::COLOR_BLUE ?>">
    <link rel="stylesheet" href="https://use.typekit.net/oqm2ymj.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/aquawolf04/font-awesome-pro@5cd1511/css/all.css">
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/icon-180x180.png">
    <meta name="apple-mobile-web-app-title" content="TCQ">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.3/dist/confetti.browser.min.js"></script>
    <style>
        :root {
            --color-blue: <?= Config::COLOR_BLUE ?>;
            --color-pink: <?= Config::COLOR_PINK ?>;
            --color-blue-dark: <?= Config::COLOR_BLUE_DARK ?>;
            --color-pink-dark: <?= Config::COLOR_PINK_DARK ?>;
            --color-blue-mid: <?= Config::COLOR_BLUE_MID ?>;
            --color-pink-mid: <?= Config::COLOR_PINK_MID ?>;
            --color-blue-light: <?= Config::COLOR_BLUE_LIGHT ?>;
            --color-pink-light: <?= Config::COLOR_PINK_LIGHT ?>;
            --animation-spring: cubic-bezier(0.2, 0.8, 0.3, 1.1);
        }
    </style>
    <link rel="stylesheet" href="/game.css">
</head>
<body class="<?php if($player['gender'] === 'male') { echo 'male'; } else { echo 'female'; } ?>">
    <div class="largeScreen">
        <div class="largeScreenTitle">Please Use a Phone</div>
        <div class="largeScreenMessage">This game was designed for mobile phone use only. (iPhone Recommended)<br>Please use a smaller screen size to see the game UI.</div>
        <div class="largeScreenTitle phone">Please Rotate Your Phone</div>
        <div class="largeScreenMessage phone">This game was designed for portrait orientation only.<br>Please rotate back to portrait mode to see the game UI.</div>
    </div>
    <div class="container">
        <?php if ($gameStatus === 'waiting' && count($players) < 2): ?>
            <!-- Waiting for other player -->
            <div class="waiting-screen no-opponent">
                <h2>Waiting for Opponent</h2>
                <p>Share your invite code with your opponent to start the game!</p>
                <p><strong>Invite Code: <?= htmlspecialchars($player['invite_code']) ?></strong></p>
                <div class="notify-bubble" style="margin-top: 30px; padding: 20px; border-radius: 15px;">
                    <h3 style="margin-bottom: 15px;">🔔 Enable Notifications</h3>
                    <p style="margin-bottom: 15px; font-size: 14px;">Get notified when your partner bumps you or when timers expire!</p>
                    <button id="enableNotificationsBtn" class="btn" onclick="enableNotifications()">
                        Enable Notifications
                    </button>
                    <div id="notificationStatus" style="margin-top: 10px; font-size: 14px;"></div>
                </div>
            </div>
            
       <?php elseif ($gameStatus === 'waiting' && count($players) === 2 && (!$gameMode || $gameMode === '')): ?>
            <!-- Set game mode -->
            <div class="waiting-screen mode-selection">
                <h2>Choose Game Mode</h2>
                <p>How would you like to play?</p>
                <div class="mode-options">
                    <div class="mode-btn" data-mode="hybrid">
                        <div class="mode-icon">🃏📱</div>
                        <div class="mode-title">Hybrid</div>
                        <div class="mode-description">Physical cards + app for scoring</div>
                    </div>
                    <div class="mode-btn" data-mode="digital">
                        <div class="mode-icon">📱✨</div>
                        <div class="mode-title">Digital</div>
                        <div class="mode-description">Play entirely within the app</div>
                    </div>
                </div>
            </div>
            
        <?php elseif ($gameStatus === 'waiting' && count($players) === 2 && $gameMode && !$gameData['duration_days']): ?>
            <!-- Set game duration -->
            <div class="waiting-screen duration">
                <div class="notify-bubble" style="margin-bottom: 30px; padding: 20px; border-radius: 15px;">
                    <h3 style="margin-bottom: 15px;">🔔 Enable Notifications</h3>
                    <p style="margin-bottom: 15px; font-size: 14px;">Get notified when your partner bumps you or when timers expire!</p>
                    <button id="enableNotificationsBtn" class="btn" onclick="enableNotifications()">
                        Enable Notifications
                    </button>
                    <div id="notificationStatus" style="margin-top: 10px; font-size: 14px;"></div>
                </div>
                <h2>Set Game Duration</h2>
                <p>How long should this game last?</p>
                <div class="duration-options">
                    <div class="duration-btn" data-days="7">1 Week</div>
                    <div class="duration-btn" data-days="14">2 Weeks</div>
                    <div class="duration-btn" data-days="30">1 Month</div>
                    <div class="duration-btn recommended" data-days="90">3 Months</div>
                    <div class="duration-btn" data-days="180">6 Months</div>
                    <div class="duration-btn" data-days="365">1 Year</div>
                    <div class="duration-btn custom-date-btn" onclick="showCustomDatePicker()">
                        <div style="font-size: 18px; margin-bottom: 5px;">📅</div>
                        Custom Date
                    </div>
                </div>

                <div class="custom-date-picker" id="customDatePicker" style="display: none;">
                    <div class="form-group">
                        <label for="customEndDate">Choose End Date:</label>
                        <input type="date" id="customEndDate" min="" max="">
                    </div>
                    <div style="display: flex; gap: 15px;">
                        <button class="btn btn-secondary" onclick="hideCustomDatePicker()" style="flex: 1;">Cancel</button>
                        <button class="btn" onclick="setCustomDuration()" style="flex: 1;">Set Duration</button>
                    </div>
                </div>
            </div>
            
        <?php elseif ($gameStatus === 'completed'): ?>
            <!-- Game ended -->
            <?php 
            $winner = $players[0]['score'] > $players[1]['score'] ? $players[0] : $players[1];
            $loser = $players[0]['score'] > $players[1]['score'] ? $players[1] : $players[0];
            if ($players[0]['score'] === $players[1]['score']) $winner = null;
            
            // Add these missing variables:
            $readyStatus = getNewGameReadyStatus($player['game_id']);
            $currentPlayerReady = false;
            $opponentPlayerReady = false;
            
            foreach ($readyStatus as $status) {
                if ($status['first_name'] === $currentPlayer['first_name']) {
                    $currentPlayerReady = $status['ready_for_new_game'];
                } else {
                    $opponentPlayerReady = $status['ready_for_new_game'];
                }
            }
            ?>
            <div class="game-ended">
                <div class="confetti"></div>
                <?php if ($winner): ?>
                    <div class="winner <?= $winner['gender'] ?>">
                        🎉 <?= htmlspecialchars($winner['first_name']) ?> Wins! 🎉
                    </div>
                    <p>Final Score: <?= $winner['score'] ?>-<?= $loser['score'] ?></p>
                <?php else: ?>
                    <div class="winner">
                        🤝 It's a Tie! 🤝
                    </div>
                    <p>Final Score: <?= $players[0]['score'] ?> points each</p>
                <?php endif; ?>

                <div style="margin-top: 40px;">
                    <?php if ($currentPlayerReady && $opponentPlayerReady): ?>
                        <p style="color: #51cf66; margin-bottom: 20px;">Both players ready! Creating new game...</p>
                    <?php elseif ($currentPlayerReady): ?>
                        <p style="color: #ffd43b; margin-bottom: 20px;">Waiting for opponent to be ready...</p>
                    <?php elseif ($opponentPlayerReady): ?>
                        <p style="color: #ffd43b; margin-bottom: 20px;">Your opponent is ready for a new game!</p>
                    <?php endif; ?>
                    
                    <button id="newGameBtn" class="btn" onclick="readyForNewGame()" 
                            <?= $currentPlayerReady ? 'disabled style="background: #51cf66;"' : '' ?>>
                        <?= $currentPlayerReady ? 'Ready ✓' : 'Start New Game' ?>
                    </button>
                </div>
            </div>
            
        <?php else: ?>
            <!-- Active game -->
            <?php
            if ($gameMode === 'digital') {
                echo '<script>document.body.classList.add("digital");</script>';
            }
            
            $currentPlayer = null;
            $opponentPlayer = null;
            
            foreach ($players as $p) {
                if ($p['device_id'] === $deviceId) {
                    $currentPlayer = $p;
                } else {
                    $opponentPlayer = $p;
                }
            }
            
            if (!$currentPlayer || !$opponentPlayer) {
                echo '<div style="color: red; padding: 20px;">Error: Could not identify players correctly. Please contact support.</div>';
                exit;
            }
            
            
            ?>
            
            <div class="game-timer">
                <?php echo $gameTimeText; ?>
            </div>
            
            <div class="scoreboard">
                <!-- Opponent Score (Top) -->
                <div class="player-score opponent <?= $opponentPlayer['gender'] ?>">
                    <div class="player-score-animation"></div>
                    <div class="opponent-hand-counts" id="opponent-hand-counts" onclick="openOpponentHandPopover()">
                        <div class="opponent-hand-popover"></div>
                    </div>
                    <div class="player-timers" id="opponent-timers"></div>
                    <div class="player-name<?= strlen($opponentPlayer['first_name']) > 5 ? ' long' : '' ?>"><?= htmlspecialchars($opponentPlayer['first_name']) ?></div>
                    <div class="player-score-value"><?= $opponentPlayer['score'] ?></div>
                </div>
                
                <!-- Animated Menu System -->
                <div class="menu-overlay" id="menuOverlay"></div>
                <div class="menu-system">
                    <button class="menu-button" id="menuButton">
                        <i class="fa-solid fa-plus-minus"></i>
                    </button>
                    
                    <!-- Action buttons for top player -->
                    <div class="action-buttons">
                        <button class="action-button add top1" data-action="add" data-player="<?= $opponentPlayer['id'] ?>">
                            <i class="fa-solid fa-plus"></i>
                        </button>
                        <button class="action-button subtract top2" data-action="subtract" data-player="<?= $opponentPlayer['id'] ?>">
                            <i class="fa-solid fa-minus"></i>
                        </button>
                        <button class="action-button steal top3" data-action="steal" data-player="<?= $opponentPlayer['id'] ?>">
                            <i class="fa-solid fa-hand"></i>
                        </button>
                    </div>
                    
                    <!-- Action buttons for bottom player -->
                    <div class="action-buttons">
                        <button class="action-button add bottom1" data-action="add" data-player="<?= $currentPlayer['id'] ?>">
                            <i class="fa-solid fa-plus"></i>
                        </button>
                        <button class="action-button subtract bottom2" data-action="subtract" data-player="<?= $currentPlayer['id'] ?>">
                            <i class="fa-solid fa-minus"></i>
                        </button>
                        <button class="action-button steal bottom3" data-action="steal" data-player="<?= $currentPlayer['id'] ?>">
                            <i class="fa-solid fa-hand"></i>
                        </button>
                    </div>
                    
                    <!-- Point buttons -->
                    <div class="point-buttons" id="pointButtons">
                        <button class="point-button p1" data-points="1">1</button>
                        <button class="point-button p2" data-points="2">2</button>
                        <button class="point-button p3" data-points="3">3</button>
                        <button class="point-button p4" data-points="4">4</button>
                        <button class="point-button p5" data-points="5">5</button>
                    </div>
                </div>
                
                <div class="board-separator"></div>
                
                <!-- Current Player Score (Bottom) -->
                <div class="player-score bottom <?= $currentPlayer['gender'] ?>">
                    <div class="player-score-animation"></div>
                    <div class="player-timers" id="current-timers"></div>
                    <div class="player-name<?= strlen($currentPlayer['first_name']) > 5 ? ' long' : '' ?>"><?= htmlspecialchars($currentPlayer['first_name']) ?></div>
                    <div class="player-score-value"><?= $currentPlayer['score'] ?></div>
                </div>
            </div>
            
            <!-- Bottom Menu -->
            <div class="bottom-menu">
                <div class="bump-send-display"></div>
                <div class="menu-item digital-menu-item" onclick="openServeCards()">
                    <div class="menu-item-icon"><i class="fa-solid fa-circle-arrow-up"></i></div>
                    <div class="menu-item-text">Serve</div>
                </div>
                <div class="menu-item digital-menu-item" onclick="openHandCards()">
                    <div class="menu-item-icon"><i class="fa-solid fa-hand-paper"></i></div>
                    <div class="menu-item-text">Hand</div>
                </div>
                <div class="menu-item digital-menu-item" onclick="event.stopPropagation(); openDrawPopover()">
                    <div class="menu-item-icon"><i class="fa-solid fa-cards-blank"></i></div>
                    <div class="menu-item-text">Draw</div>
                </div>
                <div class="menu-item digital-menu-item" onclick="openDicePopover()">
                    <div class="menu-item-icon"><i class="fa-solid fa-dice"></i></div>
                    <div class="menu-item-text">Roll</div>
                </div>
                <div class="menu-item hybrid-menu-item" onclick="sendBump()">
                    <div class="menu-item-icon"><i class="fa-solid fa-bullhorn"></i></div>
                    <div class="menu-item-text">Bump</div>
                </div>
                <div class="menu-item hybrid-menu-item" onclick="openTimerModal()">
                    <div class="menu-item-icon"><i class="fa-solid fa-stopwatch"></i></div>
                    <div class="menu-item-text">Timer</div>
                </div>
            </div>

            <div class="bottom-right-menu">
                <i class="fa-solid fa-ellipsis"></i>
                <div class="bottom-right-menu-flyout">
                    <div class="flyout-menu-item red" onclick="openEndGameModal()">
                        <div class="flyout-menu-item-icon"><i class="fa-solid fa-ban"></i></div>
                        <div class="flyout-menu-item-text">End Game Now</div>
                    </div>
                    <div class="flyout-menu-item" onclick="hardRefresh()">
                        <div class="flyout-menu-item-icon"><i class="fa-solid fa-arrows-rotate"></i></div>
                        <div class="flyout-menu-item-text">Refresh Game...</div>
                    </div>
                    <div class="flyout-menu-item" onclick="resetDecks()" style="display: none;">
                        <div class="flyout-menu-item-icon"><i class="fa-solid fa-arrows-rotate"></i></div>
                        <div class="flyout-menu-item-text">Reset Decks...</div>
                    </div>
                    <div class="flyout-menu-item" onclick="openRulesOverlay()">
                        <div class="flyout-menu-item-icon"><i class="fa-solid fa-book"></i></div>
                        <div class="flyout-menu-item-text">Game Rules</div>
                    </div>
                    <div class="flyout-menu-item hybrid-menu-item" onclick="openDicePopover()">
                        <div class="flyout-menu-item-icon"><i class="fa-solid fa-dice"></i></div>
                        <div class="flyout-menu-item-text">Roll Dice</div>
                    </div>
                    <div class="flyout-menu-item digital-menu-item" onclick="sendBump()">
                        <div class="flyout-menu-item-icon"><i class="fa-solid fa-bullhorn"></i></div>
                        <div class="flyout-menu-item-text">Bump</div>
                    </div>
                    <div class="flyout-menu-item digital-menu-item" onclick="openTimerModal()">
                        <div class="flyout-menu-item-icon"><i class="fa-solid fa-stopwatch"></i></div>
                        <div class="flyout-menu-item-text">Timer</div>
                    </div>
                    <div class="flyout-menu-item" onclick="openHistoryModal()">
                        <div class="flyout-menu-item-icon"><i class="fa-solid fa-clock-rotate-left"></i></div>
                        <div class="flyout-menu-item-text">History</div>
                    </div>
                    <div class="flyout-menu-item" onclick="openNotifyModal()">
                        <div class="flyout-menu-item-icon"><i class="fa-solid fa-bell"></i></div>
                        <div class="flyout-menu-item-text">Notifications</div>
                    </div>
                    <div class="flyout-menu-item" onclick="toggleTheme()">
                        <div class="flyout-menu-item-icon"><i class="fa-solid fa-palette"></i></div>
                        <div class="flyout-menu-item-text" id="themeToggleText">Theme: Color</div>
                    </div>
                    <div class="flyout-menu-item sound" onclick="toggleSound()">
                        <div class="flyout-menu-item-icon"><i class="fa-solid fa-volume-high"></i></div>
                        <div class="flyout-menu-item-text" id="soundToggleText">Sound: On</div>
                    </div>
                </div>
            </div>

            <div class="draw-popover" id="drawPopover">
                <button class="draw-card-btn" onclick="event.stopPropagation(); drawSingleCard('chance')" title="Draw Chance Card">
                    <i class="fa-solid fa-circle-question"></i> Chance
                </button>
                <?php if ($currentPlayer['gender'] === 'female'): ?>
                <button class="draw-card-btn" onclick="event.stopPropagation(); drawSingleCard('snap')" title="Draw Snap Card">
                    <i class="fa-solid fa-camera-retro"></i> Snap
                </button>
                <?php else: ?>
                <button class="draw-card-btn" onclick="event.stopPropagation(); drawSingleCard('dare')" title="Draw Dare Card">
                    <i class="fa-solid fa-hand-point-right"></i> Dare
                </button>
                <?php endif; ?>
                <button class="draw-card-btn" onclick="event.stopPropagation(); drawSingleCard('spicy')" title="Draw Spicy Card">
                    <i class="fa-solid fa-pepper-hot"></i> Spicy
                </button>
            </div>
            
            <!-- Pass game data to JavaScript -->
            <script>
                window.gameDataFromPHP = {
                    currentPlayerId: <?= $currentPlayer['id'] ?>,
                    opponentPlayerId: <?= $opponentPlayer['id'] ?>,
                    gameStatus: '<?= $gameStatus ?>',
                    currentPlayerGender: '<?= $currentPlayer['gender'] ?>',
                    opponentPlayerGender: '<?= $opponentPlayer['gender'] ?>',
                    opponentPlayerName: '<?= htmlspecialchars($opponentPlayer['first_name']) ?>'
                };
            </script>
        <?php endif; ?>
    </div>

    <div class="iAN">
        <div class="iAN-title"></div>
        <div class="iAN-body"></div>
    </div>

    <!-- Serve Cards Overlay -->
    <div class="card-overlay" id="serveCardsOverlay" onclick="handleOverlayClick(event, 'serveCardsOverlay')">
        <button class="card-overlay-close" onclick="closeCardOverlay('serveCardsOverlay')">
            <i class="fa-solid fa-xmark"></i>
        </button>
        <div class="card-grid" id="serveCardsGrid">
            <!-- Serve cards will be populated here -->
        </div>
        
        <!-- Serve Selection Actions -->
        <div class="card-selection-actions" id="serveSelectionActions">
            <button class="btn" onclick="serveSelectedCard()">
                Serve to <?= htmlspecialchars($opponentPlayer['first_name']) ?>
            </button>
        </div>
    </div>

    <!-- Hand Cards Overlay -->
    <div class="card-overlay" id="handCardsOverlay" onclick="handleOverlayClick(event, 'handCardsOverlay')">
        <button class="card-overlay-close" onclick="closeCardOverlay('handCardsOverlay')">
            <i class="fa-solid fa-xmark"></i>
        </button>
        <div class="card-grid" id="handCardsGrid">
            <!-- Hand cards will be populated here -->
        </div>
        
        <!-- Card Selection Actions -->
        <div class="card-selection-actions" id="cardSelectionActions">
            <button class="btn btn-complete" onclick="completeSelectedCard()">Complete</button>
            <button class="btn btn-veto" onclick="vetoSelectedCard()">Veto</button>
        </div>
    </div>

    <!-- Notify Modal -->
    <div class="modal" id="notifyModal">
        <div class="modal-content">
            <div class="modal-title">🔔 Notification Settings</div>
            
            <div id="notificationModalStatus" class="notification-status disabled">
                <span id="notificationModalStatusText">Checking notification status...</span>
            </div>
            
            <div class="notification-info">
                <h4>What you'll receive:</h4>
                <ul>
                    <li class="digital-only">Served Cards</li>
                    <li class="digital-only">Opponent Veto & Completion Actions</li>
                    <li class="digital-only">Game Modifications</li>
                    <li>Score Updates</li>
                    <li>Timer Expiration Alerts</li>
                    <li>Bump Notifications from Your Opponent</li>
                </ul>
            </div>
            
            <button id="enableNotificationsModalBtn" class="btn" onclick="enableNotificationsFromModal()">
                Enable Notifications
            </button>
            
            <button id="testNotificationBtn" class="btn btn-test" onclick="testNotification()" style="display: none;">
                Send Test Notification
            </button>
            
            <button class="btn btn-secondary" onclick="closeModal('notifyModal')">Close</button>
        </div>
    </div>
    
    <!-- Timer Modal -->
    <div class="modal" id="timerModal">
        <div class="modal-content">
            <div class="modal-title">Create Timer</div>
            
            <div class="form-group">
                <label>Description</label>
                <input type="text" id="timerDescription" placeholder="What is this timer for?">
            </div>
            
            <div class="form-group">
                <label>Duration</label>
                <select id="timerDuration">
                    <option value="0.5">30 seconds</option>
                    <option value="1">1 minute</option>
                    <option value="5">5 minutes</option>
                    <option value="10">10 minutes</option>
                    <option value="15">15 minutes</option>
                    <option value="30">30 minutes</option>
                    <option value="60">1 hour</option>
                    <option value="120">2 hours</option>
                    <option value="240">4 hours</option>
                    <option value="480">8 hours</option>
                    <option value="720">12 hours</option>
                    <option value="1440">24 hours</option>
                    <option value="10080">7 days</option>
                </select>
            </div>
            
            <button class="btn" onclick="createTimer()">Create Timer</button>
            <button class="btn btn-secondary" onclick="closeModal('timerModal')">Cancel</button>
        </div>
    </div>

    <!-- Delete Timer Modal -->
    <div class="modal" id="timerDeleteModal">
        <div class="modal-content">
            <div class="modal-title">Delete Timer?</div>
            <div class="modal-subtitle" id="timerDeleteDescription"></div>
            <div class="modal-buttons">
                <button class="btn dark no" onclick="hideTimerDeleteModal()">No</button>
                <button class="btn red yes" onclick="deleteSelectedTimer()">Yes</button>
            </div>
        </div>
    </div>

    <!-- End Game Modal -->
    <div class="modal" id="endGameModal">
        <div class="modal-content">
            <div class="modal-title">Are you sure you want to end this game now?</div>
            <div class="modal-subtitle">This action cannot be undone.</div>
            <div class="modal-buttons">
                <button class="btn dark" onclick="closeModal('endGameModal')">No</button>
                <button class="btn red" onclick="endGame()">Yes</button>
            </div>
        </div>
    </div>
    
    <!-- History Modal -->
    <div class="modal" id="historyModal">
        <div class="modal-content">
            <div class="modal-title">Score History (24h)</div>
            <div id="historyContent"></div>
            <button class="btn btn-secondary" onclick="closeModal('historyModal')" style="margin-top: 12px;">Close</button>
        </div>
    </div>

    <!-- Dice Overlay -->
    <div class="dice-popover" id="dicePopover">
        <div id="dicePopoverContainer"></div>
    </div>

    <!-- Hidden template for dice HTML -->
    <div id="diceTemplate" style="display: none;">
        <div class="dice-container" id="diceContainer">
                <div class="die male" id="die1">
                <div class="die-face front face-1">
                    <div class="die-dot"></div>
                </div>
                <div class="die-face back face-6">
                    <div class="die-dot"></div>
                    <div class="die-dot"></div>
                    <div class="die-dot"></div>
                    <div class="die-dot"></div>
                    <div class="die-dot"></div>
                    <div class="die-dot"></div>
                </div>
                <div class="die-face right face-3">
                    <div class="die-dot"></div>
                    <div class="die-dot"></div>
                    <div class="die-dot"></div>
                </div>
                <div class="die-face left face-4">
                    <div class="die-dot"></div>
                    <div class="die-dot"></div>
                    <div class="die-dot"></div>
                    <div class="die-dot"></div>
                </div>
                <div class="die-face top face-2">
                    <div class="die-dot"></div>
                    <div class="die-dot"></div>
                </div>
                <div class="die-face bottom face-5">
                    <div class="die-dot"></div>
                    <div class="die-dot"></div>
                    <div class="die-dot"></div>
                    <div class="die-dot"></div>
                    <div class="die-dot"></div>
                </div>
            </div>
            <div class="die male two" id="die2">
                <div class="die-face front face-1">
                    <div class="die-dot"></div>
                </div>
                <div class="die-face back face-6">
                    <div class="die-dot"></div>
                    <div class="die-dot"></div>
                    <div class="die-dot"></div>
                    <div class="die-dot"></div>
                    <div class="die-dot"></div>
                    <div class="die-dot"></div>
                </div>
                <div class="die-face right face-3">
                    <div class="die-dot"></div>
                    <div class="die-dot"></div>
                    <div class="die-dot"></div>
                </div>
                <div class="die-face left face-4">
                    <div class="die-dot"></div>
                    <div class="die-dot"></div>
                    <div class="die-dot"></div>
                    <div class="die-dot"></div>
                </div>
                <div class="die-face top face-2">
                    <div class="die-dot"></div>
                    <div class="die-dot"></div>
                </div>
                <div class="die-face bottom face-5">
                    <div class="die-dot"></div>
                    <div class="die-dot"></div>
                    <div class="die-dot"></div>
                    <div class="die-dot"></div>
                    <div class="die-dot"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Card Draw Animation Overlay -->
    <div class="card-draw-overlay" id="cardDrawOverlay">
        <div class="deck-container" id="deckContainer">
            <div class="deck-card">The<br>Couple's<br>Quest<span><i class="fa-solid fa-circle-arrow-up"></i>Serve</span></div>
            <div class="deck-card">The<br>Couple's<br>Quest<span><i class="fa-solid fa-circle-arrow-up"></i>Serve</span></div>
            <div class="deck-card">The<br>Couple's<br>Quest<span><i class="fa-solid fa-circle-arrow-up"></i>Serve</span></div>
            <div class="deck-card">The<br>Couple's<br>Quest<span><i class="fa-solid fa-circle-arrow-up"></i>Serve</span></div>
            <div class="deck-card">The<br>Couple's<br>Quest<span><i class="fa-solid fa-circle-arrow-up"></i>Serve</span></div>
        </div>
        
        <div class="drawn-card" id="drawnCard">
            <div class="card-type" id="drawCardType">Chance</div>
            <div class="card-name" id="drawCardName">Card Name</div>
            <div class="card-description" id="drawCardDescription">
                Card description text
            </div>
            <div class="card-meta" id="drawCardMeta">
                <!-- Points badge will be added here if applicable -->
            </div>
        </div>
    </div>

    <!-- Wheel Button (only shows when available) -->
    <div class="wheel-button" id="wheelButton" onclick="openWheelOverlay()">
        <i class="fa-solid fa-arrows-spin"></i>
    </div>

    <!-- Wheel Overlay -->
    <div class="wheel-overlay" id="wheelOverlay" onclick="handleWheelOverlayClick(event)">
        <div class="wheel-container">
            <div class="wheel-result" id="wheelResult">Spin the Daily Wheel</div>
            <div class="wheel" id="wheel">
                <div class="wheel-background">
                    <div class="wheel-text wheel-text-1" id="wheelText1"></div>
                    <div class="wheel-text wheel-text-2" id="wheelText2"></div>
                    <div class="wheel-text wheel-text-3" id="wheelText3"></div>
                    <div class="wheel-text wheel-text-4" id="wheelText4"></div>
                    <div class="wheel-text wheel-text-5" id="wheelText5"></div>
                    <div class="wheel-text wheel-text-6" id="wheelText6"></div>
                </div>
                <div class="wheel-pointer"></div>
                <div class="wheel-center" onclick="event.stopPropagation(); spinWheelAction()">SPIN</div>
            </div>
        </div>
    </div>

    <!-- Rules Overlay -->
    <div class="card-overlay" id="rulesOverlay" onclick="handleRulesOverlayClick(event)">
        <button class="card-overlay-close" onclick="closeRulesOverlay()">
            <i class="fa-solid fa-xmark"></i>
        </button>
        <div class="rules-content" id="rulesContent">
            <!-- Rules will be loaded here -->
        </div>
    </div>
    
    <script src="https://www.gstatic.com/firebasejs/9.0.0/firebase-app-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/9.0.0/firebase-messaging-compat.js"></script>
    <script src="/game.js"></script>
</body>
</html>