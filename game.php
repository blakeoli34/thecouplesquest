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

$now = new DateTime();
$endDate = new DateTime($player['end_date']);
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
    
    $gameTimeText = 'Game Remaining: ' . implode(', ', $parts);
} else {
    $gameTimeText = 'Game Ended';
}

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

        case 'delete_timer':
            $timerId = intval($_POST['timer_id']);
            $result = deleteTimer($timerId, $player['game_id']);
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
                'history' => $history,
                'gametime' => $gameTimeText
            ]);
            exit;

        case 'check_game_status':
            try {
                $gameId = $player['game_id'];
                $currentPlayers = getGamePlayers($gameId);
                $currentStatus = $player['status']; // or get fresh from DB if needed
                
                echo json_encode([
                    'success' => true,
                    'status' => $currentStatus,
                    'player_count' => count($currentPlayers)
                ]);
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'error' => $e->getMessage()
                ]);
            }
            exit;

        case 'end_game':
            try {
                $pdo = Config::getDatabaseConnection();
                $stmt = $pdo->prepare("UPDATE games SET status = 'completed', end_date = NOW() WHERE id = ?");
                $stmt->execute([$player['game_id']]);
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                error_log("Error ending game: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Failed to end game.']);
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
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
            --animation-spring: cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
    </style>
    <link rel="stylesheet" href="/game.css">
</head>
<body>
    <div class="largeScreen">
        <div class="largeScreenTitle">Please Use a Phone</div>
        <div class="largeScreenMessage">This game was designed for mobile phone use only. (iPhone Recommended)<br>Please use a smaller screen size to see the game UI.</div>
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
            
        <?php elseif ($gameStatus === 'waiting' && count($players) === 2 && !$player['duration_days']): ?>
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
                </div>
            </div>
            
        <?php elseif ($gameStatus === 'completed'): ?>
            <!-- Game ended -->
            <?php 
            $winner = $players[0]['score'] > $players[1]['score'] ? $players[0] : $players[1];
            $loser = $players[0]['score'] > $players[1]['score'] ? $players[1] : $players[0];
            if ($players[0]['score'] === $players[1]['score']) $winner = null;
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
            
            
            ?>
            
            <div class="game-timer">
                <?php echo $gameTimeText; ?>
            </div>
            
            <div class="scoreboard">
                <!-- Opponent Score (Top) -->
                <div class="player-score opponent <?= $opponentPlayer['gender'] ?>">
                    <div class="player-score-animation"></div>
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
                <div class="menu-item" onclick="sendBump()">
                    <div class="menu-item-icon"><i class="fa-solid fa-bullhorn"></i></div>
                    <div class="menu-item-text">Bump</div>
                </div>
                <div class="menu-item" onclick="openTimerModal()">
                    <div class="menu-item-icon"><i class="fa-solid fa-stopwatch"></i></div>
                    <div class="menu-item-text">Timer</div>
                </div>
            </div>

            <div class="bottom-right-menu">
                <i class="fa-solid fa-ellipsis"></i>
                <div class="bottom-right-menu-flyout">
                    <div class="flyout-menu-item red" onclick="endGame()">
                        <div class="flyout-menu-item-icon"><i class="fa-solid fa-ban"></i></div>
                        <div class="flyout-menu-item-text">End Game Now</div>
                    </div>
                    <div class="flyout-menu-item" onclick="hardRefresh()">
                        <div class="flyout-menu-item-icon"><i class="fa-solid fa-arrows-rotate"></i></div>
                        <div class="flyout-menu-item-text">Refresh Game...</div>
                    </div>
                    <div class="flyout-menu-item" onclick="openHistoryModal()">
                        <div class="flyout-menu-item-icon"><i class="fa-solid fa-clock-rotate-left"></i></div>
                        <div class="flyout-menu-item-text">History</div>
                    </div>
                    <div class="flyout-menu-item" onclick="openNotifyModal()">
                        <div class="flyout-menu-item-icon"><i class="fa-solid fa-bell"></i></div>
                        <div class="flyout-menu-item-text">Notifications</div>
                    </div>
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

    <div class="iAN">
        <div class="iAN-title"></div>
        <div class="iAN-body"></div>
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
                    <li>Score updates</li>
                    <li>Timer expiration alerts</li>
                    <li>Bump notifications from your opponent</li>
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

    <div class="modal" id="timerDeleteModal">
        <div class="timer-delete-content">
            <h3>Delete Timer?</h3>
            <p id="timerDeleteDescription"></p>
            <div class="timer-delete-buttons">
                <button class="timer-delete-btn no" onclick="hideTimerDeleteModal()">No</button>
                <button class="timer-delete-btn yes" onclick="deleteSelectedTimer()">Yes</button>
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
    
    <script src="https://www.gstatic.com/firebasejs/9.0.0/firebase-app-compat.js"></script>
    <script src="https://www.gstatic.com/firebasejs/9.0.0/firebase-messaging-compat.js"></script>
    <script src="/game.js"></script>
</body>
</html>