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

$players = getGamePlayers($player['game_id']);
$gameStatus = $player['status'];

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'set_duration':
            $duration = intval($_POST['duration']);
            $result = setGameDuration($player['game_id'], $duration);
            echo json_encode($result);
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
            $minutes = intval($_POST['minutes']);
            $result = createTimer($player['game_id'], $player['id'], $description, $minutes);
            echo json_encode($result);
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
            $updatedPlayers = getGamePlayers($player['game_id']);
            $timers = getActiveTimers($player['game_id']);
            $history = getScoreHistory($player['game_id']);
            
            echo json_encode([
                'players' => $updatedPlayers,
                'timers' => $timers,
                'history' => $history
            ]);
            exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>The Couple's Quest</title>
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="<?= Config::COLOR_BLUE ?>">
    <link rel="stylesheet" href="https://use.typekit.net/oqm2ymj.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/icon-180x180.png">
    <meta name="apple-mobile-web-app-title" content="TCQ">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'museo-sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
            overflow-x: hidden;
        }
        
        .container {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        .game-timer {
            background: #111;
            color: white;
            text-align: center;
            padding: 12px;
            font-weight: 500;
            font-size: 12px;
        }
        
        .scoreboard {
            flex: 1;
            display: flex;
            flex-direction: column;
            position: relative;
        }
        
        .player-score {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            position: relative;
            padding: 40px 20px;
            color: white;
            font-weight: bold;
        }
        
        .player-score.male {
            background: <?= Config::COLOR_BLUE ?>;
        }
        
        .player-score.female {
            background: <?= Config::COLOR_PINK ?>;
        }
        
        .player-name {
            font-size: 24px;
            margin-bottom: 12px;
        }
        
        .player-score-value {
            font-size: 64px;
            font-weight: 300;
        }
        
        .player-timers {
            position: absolute;
            top: 20px;
            right: 20px;
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .timer-badge {
            background: rgba(255,255,255,0.2);
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 12px;
            cursor: pointer;
            backdrop-filter: blur(10px);
        }

        .board-separator {
            position: absolute;
            top: 50%;
            left: 0;
            width: 100%;
            height: 5px;
            z-index: 99;
            background: white;
            transform: translateY(-50%);
        }
        
        /* Menu System */
        .menu-system {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 1000;
        }
        
        .menu-button {
            width: 80px;
            height: 80px;
            background: white;
            border: none;
            border-radius: 50%;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: #111;
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            z-index: 101;
        }
        
        .menu-button:hover {
            transform: scale(1.1);
        }
        
        .menu-button.active {
            background: #111;
            color: white;
            transform: rotate(45deg);
        }
        
        /* Action Buttons (Add, Subtract, Steal) */
        .action-buttons {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }
        
        .action-button {
            position: absolute;
            width: 70px;
            height: 70px;
            background: rgba(255,255,255,0.7);
            border: none;
            border-radius: 50%;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            cursor: pointer;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 600;
            color: #333;
            opacity: 0;
            transform: translate(-50%, -50%) scale(0);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            pointer-events: none;
        }
        
        .action-button.show {
            opacity: 1;
            pointer-events: all;
        }
        
        .action-button:hover {
            transform: scale(1.1);
        }
        
        .action-button i {
            font-size: 18px;
            margin-bottom: 2px;
        }
        
        /* Arc positioning for top player */
        .action-button.top1 { 
            top: -100px; 
            left: -120px;
            transform: translate(-50%, -50%) scale(0);
        }
        .action-button.top1.show { 
            transform: translate(-50%, -50%) scale(1);
        }
        .action-button.top2 { 
            top: -140px; 
            left: 50%;
            transform: translate(-50%, -50%) scale(0);
        }
        .action-button.top2.show { 
            transform: translate(-50%, -50%) scale(1);
        }
        .action-button.top3 { 
            top: -100px; 
            left: 120px;
            transform: translate(-50%, -50%) scale(0);
        }
        .action-button.top3.show { 
            transform: translate(-50%, -50%) scale(1);
        }
        
        /* Arc positioning for bottom player */
        .action-button.bottom1 { 
            top: 80px; 
            left: -120px;
            transform: translate(-50%, -50%) scale(0);
        }
        .action-button.bottom1.show { 
            transform: translate(-50%, -50%) scale(1);
        }
        .action-button.bottom2 { 
            top: 120px; 
            left: 50%;
            transform: translate(-50%, -50%) scale(0);
        }
        .action-button.bottom2.show { 
            transform: translate(-50%, -50%) scale(1);
        }
        .action-button.bottom3 { 
            top: 80px; 
            left: 120px;
            transform: translate(-50%, -50%) scale(0);
        }
        .action-button.bottom3.show { 
            transform: translate(-50%, -50%) scale(1);
        }
        
        
        /* Point Buttons */
        .point-buttons {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 102; /* Higher than menu button */
        }
        
        .point-button {
            position: absolute;
            width: 50px;
            height: 50px;
            background: white;
            border: 2px solid #333;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            font-weight: 700;
            color: #333;
            opacity: 0;
            transform: translate(-50%, -50%) scale(0);
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            pointer-events: none;
            z-index: 102;
        }
        
        .point-button.show {
            opacity: 1;
            transform: translate(-50%, -50%) scale(1);
            pointer-events: all;
        }
        
        .point-button:hover {
            transform: translate(-50%, -50%) scale(1.2);
            background: #333;
            color: white;
        }
        
        /* Point button positions - spread wider */
        .point-button.p1 { left: -120px; }
        .point-button.p2 { left: -60px; }
        .point-button.p3 { left: 0px; }
        .point-button.p4 { left: 60px; }
        .point-button.p5 { left: 120px; }
        
        /* Overlay */
        .menu-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.3);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
            z-index: 99;
        }
        
        .menu-overlay.active {
            opacity: 1;
            pointer-events: all;
        }
        
        .bottom-menu {
            background: white;
            padding: 20px;
            display: flex;
            justify-content: center;
            gap: 40px;
            box-shadow: 0 -2px 20px rgba(0,0,0,0.1);
        }
        
        .menu-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 5px;
            cursor: pointer;
            border-radius: 10px;
            transition: background-color 0.2s;
        }
        
        .menu-item:hover {
            background: #f0f0f0;
        }
        
        .menu-item-icon {
            width: 50px;
            height: 50px;
            font-size: 28px;
            background: #eee;
            border-radius: 8px;
            padding: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #666;
        }
        
        .menu-item-text {
            font-size: 14px;
            color: #666;
        }
        
        /* Modals - keeping timer and history modals */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: 20px;
            padding: 30px;
            width: 100%;
            max-width: 400px;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .modal-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
        }
        
        .form-group input, .form-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 10px;
            font-size: 16px;
        }
        
        .btn {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, <?= Config::COLOR_PINK ?>, <?= Config::COLOR_BLUE ?>);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            margin-bottom: 10px;
        }
        
        .btn-secondary {
            background: #6b7280;
        }
        
        .history-item {
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            margin-bottom: 10px;
        }
        
        .history-time {
            font-size: 12px;
            color: #666;
        }
        
        .history-change {
            font-weight: 600;
            margin-top: 5px;
        }
        
        .waiting-screen {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px;
            text-align: center;
        }
        
        .waiting-screen h2 {
            margin-bottom: 20px;
            color: #333;
        }
        
        .waiting-screen p {
            color: #666;
            margin-bottom: 30px;
        }
        
        .duration-options {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .duration-btn {
            padding: 20px;
            border: 2px solid #ddd;
            background: white;
            border-radius: 15px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .duration-btn:hover {
            border-color: <?= Config::COLOR_BLUE ?>;
            color: <?= Config::COLOR_BLUE ?>;
        }
        
        .game-ended {
            text-align: center;
            padding: 40px;
        }
        
        .winner {
            font-size: 32px;
            font-weight: 900;
            margin-bottom: 20px;
        }
        
        .winner.male {
            color: <?= Config::COLOR_BLUE ?>;
        }
        
        .winner.female {
            color: <?= Config::COLOR_PINK ?>;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($gameStatus === 'waiting' && count($players) < 2): ?>
            <!-- Waiting for other player -->
            <div class="waiting-screen">
                <h2>Waiting for Opponent</h2>
                <p>Share your invite code with your opponent to start the game!</p>
                <p><strong>Invite Code: <?= htmlspecialchars($player['invite_code']) ?></strong></p>
                <div style="margin-top: 30px; padding: 20px; background: rgba(255,255,255,0.1); border-radius: 15px;">
                    <h3 style="margin-bottom: 15px;">🔔 Enable Notifications</h3>
                    <p style="margin-bottom: 15px; font-size: 14px;">Get notified when your partner bumps you or when timers expire!</p>
                    <button id="enableNotificationsBtn" class="btn" onclick="enableNotifications()">
                        Enable Notifications
                    </button>
                    <div id="notificationStatus" style="margin-top: 10px; font-size: 14px;"></div>
                </div>
            </div>
            
        <?php elseif ($gameStatus === 'waiting' && count($players) === 2 && !$player['duration_days']): ?>
            <!-- Set game duration -->
            <div class="waiting-screen">
                <div style="margin-bottom: 30px; padding: 20px; background: rgba(255,255,255,0.1); border-radius: 15px;">
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
                    <div class="duration-btn" data-days="90">3 Months</div>
                    <div class="duration-btn" data-days="180">6 Months</div>
                    <div class="duration-btn" data-days="365">1 Year</div>
                </div>
            </div>
            
        <?php elseif ($gameStatus === 'completed'): ?>
            <!-- Game ended -->
            <?php 
            $winner = $players[0]['score'] > $players[1]['score'] ? $players[0] : $players[1];
            if ($players[0]['score'] === $players[1]['score']) $winner = null;
            ?>
            <div class="game-ended">
                <?php if ($winner): ?>
                    <div class="winner <?= $winner['gender'] ?>">
                        🎉 <?= htmlspecialchars($winner['first_name']) ?> Wins! 🎉
                    </div>
                    <p>Final Score: <?= $winner['score'] ?> points</p>
                <?php else: ?>
                    <div class="winner">
                        🤝 It's a Tie! 🤝
                    </div>
                    <p>Final Score: <?= $players[0]['score'] ?> points each</p>
                <?php endif; ?>
            </div>
            
        <?php else: ?>
            <!-- Active game -->
            <?php
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
            
            $now = new DateTime();
            $endDate = new DateTime($player['end_date']);
            $timeRemaining = $now < $endDate ? $endDate->diff($now) : null;
            ?>
            
            <div class="game-timer">
                <?php if ($timeRemaining): ?>
                    Time Remaining: <?= $timeRemaining->format('%a days, %h hours, %i minutes') ?>
                <?php else: ?>
                    Game Ended
                <?php endif; ?>
            </div>
            
            <div class="scoreboard">
                <!-- Opponent Score (Top) -->
                <div class="player-score opponent <?= $opponentPlayer['gender'] ?>">
                    <div class="player-timers" id="opponent-timers"></div>
                    <div class="player-name"><?= htmlspecialchars($opponentPlayer['first_name']) ?></div>
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
                            <span>Add</span>
                        </button>
                        <button class="action-button subtract top2" data-action="subtract" data-player="<?= $opponentPlayer['id'] ?>">
                            <i class="fa-solid fa-minus"></i>
                            <span>Subtract</span>
                        </button>
                        <button class="action-button steal top3" data-action="steal" data-player="<?= $opponentPlayer['id'] ?>">
                            <i class="fa-solid fa-hand-holding"></i>
                            <span>Steal</span>
                        </button>
                    </div>
                    
                    <!-- Action buttons for bottom player -->
                    <div class="action-buttons">
                        <button class="action-button add bottom1" data-action="add" data-player="<?= $currentPlayer['id'] ?>">
                            <i class="fa-solid fa-plus"></i>
                            <span>Add</span>
                        </button>
                        <button class="action-button subtract bottom2" data-action="subtract" data-player="<?= $currentPlayer['id'] ?>">
                            <i class="fa-solid fa-minus"></i>
                            <span>Subtract</span>
                        </button>
                        <button class="action-button steal bottom3" data-action="steal" data-player="<?= $currentPlayer['id'] ?>">
                            <i class="fa-solid fa-hand-holding"></i>
                            <span>Steal</span>
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
                <div class="player-score <?= $currentPlayer['gender'] ?>">
                    <div class="player-timers" id="current-timers"></div>
                    <div class="player-name"><?= htmlspecialchars($currentPlayer['first_name']) ?></div>
                    <div class="player-score-value"><?= $currentPlayer['score'] ?></div>
                </div>
            </div>
            
            <!-- Bottom Menu -->
            <div class="bottom-menu">
                <div class="menu-item" onclick="sendBump()">
                    <div class="menu-item-icon"><i class="fa-solid fa-bullhorn"></i></div>
                    <div class="menu-item-text">Bump</div>
                </div>
                <div class="menu-item" onclick="openTimerModal()">
                    <div class="menu-item-icon"><i class="fa-solid fa-stopwatch"></i></div>
                    <div class="menu-item-text">Timers</div>
                </div>
                <div class="menu-item" onclick="openHistoryModal()">
                    <div class="menu-item-icon"><i class="fa-solid fa-clock-rotate-left"></i></div>
                    <div class="menu-item-text">History</div>
                </div>
            </div>
            
            <!-- Pass game data to JavaScript -->
            <script>
                window.gameDataFromPHP = {
                    currentPlayerId: <?= $currentPlayer['id'] ?>,
                    opponentPlayerId: <?= $opponentPlayer['id'] ?>,
                    gameStatus: '<?= $gameStatus ?>'
                };
            </script>
        <?php endif; ?>
    </div>
    
    <!-- Timer Modal -->
    <div class="modal" id="timerModal">
        <div class="modal-content">
            <div class="modal-title">Set Timer</div>
            
            <div class="form-group">
                <label>Description</label>
                <input type="text" id="timerDescription" placeholder="What is this timer for?">
            </div>
            
            <div class="form-group">
                <label>Duration</label>
                <select id="timerDuration">
                    <option value="10">10 minutes</option>
                    <option value="15">15 minutes</option>
                    <option value="30">30 minutes</option>
                    <option value="60">1 hour</option>
                    <option value="120">2 hours</option>
                    <option value="240">4 hours</option>
                    <option value="480">8 hours</option>
                    <option value="720">12 hours</option>
                    <option value="1440">24 hours</option>
                </select>
            </div>
            
            <button class="btn" onclick="createTimer()">Create Timer</button>
            <button class="btn btn-secondary" onclick="closeModal('timerModal')">Cancel</button>
        </div>
    </div>
    
    <!-- History Modal -->
    <div class="modal" id="historyModal">
        <div class="modal-content">
            <div class="modal-title">Score History (24h)</div>
            <div id="historyContent"></div>
            <button class="btn btn-secondary" onclick="closeModal('historyModal')">Close</button>
        </div>
    </div>
    
    <script src="https://www.gstatic.com/firebasejs/9.0.0/firebase-app-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/9.0.0/firebase-messaging-compat.js"></script>
    
    <script src="/game.js"></script>
</body>
</html>