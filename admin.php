<?php
// admin.php - Admin interface
require_once 'config.php';
require_once 'functions.php';

session_start();

// Handle login
if ($_POST && isset($_POST['login'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (authenticateAdmin($username, $password)) {
        $_SESSION['admin_logged_in'] = true;
        header('Location: admin.php');
        exit;
    } else {
        $loginError = 'Invalid credentials';
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

// Check if logged in
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    showLoginForm($loginError ?? null);
    exit;
}

// Handle generate invite code
if ($_POST && isset($_POST['generate_code'])) {
    $code = generateNewInviteCode();
    if(!$code) {
        $code = 'error';
    }
    header('Location: admin.php?code=' . $code);
}

if(isset($_GET['code'])) {
    if($_GET['code'] !== 'error') {
        $message = 'Generated invite code: <strong>' . $_GET['code'] . '</strong>';
    } else {
        $message = 'Failed to generate code';
    }
}

// Handle delete invite code
if ($_POST && isset($_POST['delete_code'])) {
    $codeId = intval($_POST['code_id']);
    $result = deleteInviteCode($codeId);
    if($result) {
        header('Location: admin.php?deletecode=1');
    } else {
        header('Location: admin.php?deletecode=0');
    }
}

if(isset($_GET['deletecode'])) {
    if($_GET['deletecode'] === '1') {
        $message = 'Invite code deleted successfully';
    } else {
        $message = 'Failed to delete invite code';
    }
}

// Handle delete game
if ($_POST && isset($_POST['delete_game'])) {
    $gameId = intval($_POST['game_id']);
    $result = deleteGame($gameId);
    if($result) {
        header('Location: admin.php?deletegame=1');
    } else {
        header('Location: admin.php?deletegame=0');
    }
}

if(isset($_GET['deletegame'])) {
    if($_GET['deletegame'] === '1') {
        $message = 'Game deleted successfully';
    } else {
        $message = 'Failed to delete game';
    }
}

// Get statistics
$stats = getGameStatistics();

function authenticateAdmin($username, $password) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("SELECT password_hash FROM admin_users WHERE username = ?");
        $stmt->execute([$username]);
        $hash = $stmt->fetchColumn();
        
        return $hash && password_verify($password, $hash);
    } catch (Exception $e) {
        error_log("Admin auth error: " . $e->getMessage());
        return false;
    }
}

function generateNewInviteCode() {
    try {
        $pdo = Config::getDatabaseConnection();
        $code = Config::generateInviteCode();
        
        $stmt = $pdo->prepare("INSERT INTO invite_codes (code) VALUES (?)");
        $stmt->execute([$code]);
        
        return $code;
    } catch (Exception $e) {
        error_log("Error generating invite code: " . $e->getMessage());
        return false;
    }
}

function deleteInviteCode($codeId) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Check if code is unused
        $stmt = $pdo->prepare("SELECT is_used FROM invite_codes WHERE id = ?");
        $stmt->execute([$codeId]);
        $code = $stmt->fetch();
        
        if (!$code || $code['is_used']) {
            return false; // Can't delete used codes
        }
        
        $stmt = $pdo->prepare("DELETE FROM invite_codes WHERE id = ? AND is_used = FALSE");
        $stmt->execute([$codeId]);
        
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        error_log("Error deleting invite code: " . $e->getMessage());
        return false;
    }
}

function deleteGame($gameId) {
    try {
        $pdo = Config::getDatabaseConnection();
        $pdo->beginTransaction();
        
        // Delete related records first (due to foreign key constraints)
        $stmt = $pdo->prepare("DELETE FROM score_history WHERE game_id = ?");
        $stmt->execute([$gameId]);
        
        $stmt = $pdo->prepare("DELETE FROM timers WHERE game_id = ?");
        $stmt->execute([$gameId]);
        
        $stmt = $pdo->prepare("DELETE FROM players WHERE game_id = ?");
        $stmt->execute([$gameId]);
        
        // Get invite code to mark as unused
        $stmt = $pdo->prepare("SELECT invite_code FROM games WHERE id = ?");
        $stmt->execute([$gameId]);
        $inviteCode = $stmt->fetchColumn();
        
        // Delete the game
        $stmt = $pdo->prepare("DELETE FROM games WHERE id = ?");
        $stmt->execute([$gameId]);
        
        // Mark invite code as unused if game is deleted
        if ($inviteCode) {
            $stmt = $pdo->prepare("UPDATE invite_codes SET is_used = FALSE WHERE code = ?");
            $stmt->execute([$inviteCode]);
        }
        
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error deleting game: " . $e->getMessage());
        return false;
    }
}

function getGameStatistics() {
    try {
        $pdo = Config::getDatabaseConnection();
        
        // Total games
        $stmt = $pdo->query("SELECT COUNT(*) FROM games");
        $totalGames = $stmt->fetchColumn();
        
        // Active games
        $stmt = $pdo->query("SELECT COUNT(*) FROM games WHERE status = 'active'");
        $activeGames = $stmt->fetchColumn();
        
        // Completed games
        $stmt = $pdo->query("SELECT COUNT(*) FROM games WHERE status = 'completed'");
        $completedGames = $stmt->fetchColumn();
        
        // Waiting games
        $stmt = $pdo->query("SELECT COUNT(*) FROM games WHERE status = 'waiting'");
        $waitingGames = $stmt->fetchColumn();
        
        // Total players
        $stmt = $pdo->query("SELECT COUNT(*) FROM players");
        $totalPlayers = $stmt->fetchColumn();
        
        // Unused invite codes
        $stmt = $pdo->query("SELECT COUNT(*) FROM invite_codes WHERE is_used = FALSE");
        $unusedCodes = $stmt->fetchColumn();
        
        // Recent games with player details including device IDs
        $stmt = $pdo->query("
            SELECT g.*, 
                   COUNT(p.id) as player_count,
                   GROUP_CONCAT(
                       CONCAT(
                           p.first_name, ' (', p.gender, ') - Device: ', 
                           SUBSTRING(p.device_id, 1, 8), '...'
                       ) 
                       ORDER BY p.joined_at 
                       SEPARATOR ' | '
                   ) as players_with_devices
            FROM games g
            LEFT JOIN players p ON g.id = p.game_id
            GROUP BY g.id
            ORDER BY g.created_at DESC
            LIMIT 20
        ");
        $recentGames = $stmt->fetchAll();

        // Unused invite codes
        $stmt = $pdo->query("
            SELECT * FROM invite_codes 
            WHERE is_used = FALSE 
            ORDER BY created_at DESC
        ");
        $unusedCodes = $stmt->fetchAll();
        
        // Active players with last activity
        $stmt = $pdo->query("
            SELECT p.*, g.invite_code, g.status as game_status,
                   SUBSTRING(p.device_id, 1, 12) as short_device_id
            FROM players p
            JOIN games g ON p.game_id = g.id
            WHERE g.status IN ('active', 'waiting')
            ORDER BY p.joined_at DESC
        ");
        $activePlayers = $stmt->fetchAll();
        
        return [
            'totalGames' => $totalGames,
            'activeGames' => $activeGames,
            'completedGames' => $completedGames,
            'waitingGames' => $waitingGames,
            'totalPlayers' => $totalPlayers,
            'unusedCodes' => $unusedCodes,
            'recentGames' => $recentGames,
            'activePlayers' => $activePlayers
        ];
    } catch (Exception $e) {
        error_log("Error getting statistics: " . $e->getMessage());
        return [];
    }
}

function showLoginForm($error = null) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - The Couples Quest</title>
    <link rel="stylesheet" href="https://use.typekit.net/oqm2ymj.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'museo-sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, <?= Config::COLOR_PINK ?> 0%, <?= Config::COLOR_BLUE ?> 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-container {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 400px;
        }
        
        h1 {
            text-align: center;
            margin-bottom: 30px;
            color: #333;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 5px;
            color: #555;
            font-weight: 500;
        }
        
        input {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 10px;
            font-size: 16px;
        }
        
        button {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, <?= Config::COLOR_PINK ?>, <?= Config::COLOR_BLUE ?>);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
        }
        
        .error {
            background: #ffebee;
            color: #c62828;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h1>Admin Login</h1>
        
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required>
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <button type="submit" name="login">Login</button>
        </form>
    </div>
</body>
</html>
<?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - The Couples Quest</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
        }
        
        .header {
            background: white;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header h1 {
            color: #333;
        }
        
        .logout-btn {
            padding: 10px 20px;
            background: #dc3545;
            color: white;
            text-decoration: none;
            border-radius: 5px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px 20px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .stat-number {
            font-size: 32px;
            font-weight: 900;
            color: <?= Config::COLOR_BLUE ?>;
            margin-bottom: 10px;
        }
        
        .stat-label {
            color: #666;
            font-weight: 500;
        }
        
        .section {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .section h2 {
            margin-bottom: 20px;
            color: #333;
        }
        
        .generate-form {
            display: flex;
            gap: 15px;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .btn {
            padding: 12px 24px;
            background: linear-gradient(135deg, <?= Config::COLOR_PINK ?>, <?= Config::COLOR_BLUE ?>);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
        }
        
        .message {
            padding: 15px;
            background: #d4edda;
            color: #155724;
            border-radius: 8px;
            margin-bottom: 20px;
            position: fixed;
            left: -100%;
            opacity: 0;
            animation: notify 6s ease;
        }

        @keyframes notify {
            0%, 100% {
                left: -100%;
                opacity: 0;
            }
            16%, 84% {
                left: 20px;
                opacity: 1;
            }
        }
        
        .games-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .games-table th,
        .games-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        .games-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
        }
        
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-active {
            background: #d4edda;
            color: #155724;
        }
        
        .status-waiting {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-completed {
            background: #d1ecf1;
            color: #0c5460;
        }
        .actions {
            display: flex;
            gap: 10px;
        }
        
        .btn-small {
            padding: 5px 10px;
            font-size: 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
        
        .btn-warning {
            background: #ffc107;
            color: #212529;
        }
        
        .btn-warning:hover {
            background: #e0a800;
        }
        
        .code-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 10px;
        }
        
        .code-details {
            flex: 1;
        }
        
        .code-value {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            font-size: 18px;
            color: #333;
        }
        
        .code-meta {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        
        .player-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 10px;
        }
        
        .player-details {
            flex: 1;
        }
        
        .player-name {
            font-weight: bold;
            font-size: 16px;
            color: #333;
        }
        
        .player-meta {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        
        .device-id {
            font-family: 'Courier New', monospace;
            background: #e9ecef;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 11px;
        }
        
        .confirm-dialog {
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
        }
        
        .confirm-dialog.active {
            display: flex;
        }
        
        .confirm-content {
            background: white;
            padding: 30px;
            border-radius: 15px;
            text-align: center;
            max-width: 400px;
            margin: 20px;
        }
        
        .confirm-buttons {
            display: flex;
            gap: 15px;
            margin-top: 20px;
            justify-content: center;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Admin Dashboard</h1>
        <a href="?logout=1" class="logout-btn">Logout</a>
    </div>
    
    <div class="container">
        <?php if (isset($message)): ?>
            <div class="message"><?= $message ?></div>
        <?php endif; ?>
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?= $stats['totalGames'] ?></div>
                <div class="stat-label">Total Games</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['activeGames'] ?></div>
                <div class="stat-label">Active Games</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['waitingGames'] ?></div>
                <div class="stat-label">Waiting Games</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['completedGames'] ?></div>
                <div class="stat-label">Completed Games</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['totalPlayers'] ?></div>
                <div class="stat-label">Total Players</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= count($stats['unusedCodes']) ?></div>
                <div class="stat-label">Unused Codes</div>
            </div>
        </div>
        
        <!-- Generate Invite Code -->
        <div class="section">
            <h2>Generate Invite Code</h2>
            <form method="POST" class="generate-form">
                <button type="submit" name="generate_code" class="btn">Generate New Invite Code</button>
            </form>
        </div>

        <!-- Active Players -->
        <div class="section">
            <h2>Active Players (<?= count($stats['activePlayers']) ?>)</h2>
            
            <?php if (empty($stats['activePlayers'])): ?>
                <p style="color: #666; text-align: center; padding: 20px;">No active players</p>
            <?php else: ?>
                <?php foreach ($stats['activePlayers'] as $player): ?>
                    <div class="player-item">
                        <div class="player-details">
                            <div class="player-name">
                                <?= htmlspecialchars($player['first_name']) ?> 
                                (<?= ucfirst($player['gender']) ?>) - Score: <?= $player['score'] ?>
                            </div>
                            <div class="player-meta">
                                Game: <strong><?= htmlspecialchars($player['invite_code']) ?></strong> 
                                (<?= ucfirst($player['game_status']) ?>) | 
                                Device: <span class="device-id"><?= htmlspecialchars($player['device_id']) ?></span> |
                                Joined: <?= date('M j, Y g:i A', strtotime($player['joined_at'])) ?>
                                <?php if ($player['fcm_token']): ?>
                                    | <span style="color: #28a745;">📱 Notifications enabled</span>
                                <?php else: ?>
                                    | <span style="color: #dc3545;">🔕 No notifications</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Unused Invite Codes -->
        <div class="section">
            <h2>Unused Invite Codes (<?= count($stats['unusedCodes']) ?>)</h2>
            
            <?php if (empty($stats['unusedCodes'])): ?>
                <p style="color: #666; text-align: center; padding: 20px;">No unused invite codes</p>
            <?php else: ?>
                <?php foreach ($stats['unusedCodes'] as $code): ?>
                    <div class="code-item">
                        <div class="code-details">
                            <div class="code-value"><?= htmlspecialchars($code['code']) ?></div>
                            <div class="code-meta">
                                Created: <?= date('M j, Y g:i A', strtotime($code['created_at'])) ?>
                                <?php if ($code['expires_at']): ?>
                                    | Expires: <?= date('M j, Y g:i A', strtotime($code['expires_at'])) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="actions">
                            <button class="btn-small btn-danger" onclick="confirmDeleteCode(<?= $code['id'] ?>, '<?= htmlspecialchars($code['code']) ?>')">
                                Delete
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- Recent Games -->
        <div class="section">
            <h2>Recent Games (<?= count($stats['recentGames']) ?>)</h2>
            
            <table class="games-table">
                <thead>
                    <tr>
                        <th>Invite Code</th>
                        <th>Status</th>
                        <th>Players & Devices</th>
                        <th>Duration</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats['recentGames'] as $game): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($game['invite_code']) ?></strong></td>
                            <td>
                                <span class="status-badge status-<?= $game['status'] ?>">
                                    <?= ucfirst($game['status']) ?>
                                </span>
                            </td>
                            <td>
                                <?= $game['players_with_devices'] ? htmlspecialchars($game['players_with_devices']) : 'None' ?>
                                (<?= $game['player_count'] ?>/2)
                            </td>
                            <td>
                                <?= $game['duration_days'] ? $game['duration_days'] . ' days' : 'Not set' ?>
                            </td>
                            <td><?= date('M j, Y g:i A', strtotime($game['created_at'])) ?></td>
                            <td>
                                <div class="actions">
                                    <button class="btn-small btn-danger" onclick="confirmDeleteGame(<?= $game['id'] ?>, '<?= htmlspecialchars($game['invite_code']) ?>')">
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Confirmation Dialogs -->
    <div class="confirm-dialog" id="confirmDeleteCode">
        <div class="confirm-content">
            <h3>Delete Invite Code</h3>
            <p>Are you sure you want to delete invite code <strong id="deleteCodeValue"></strong>?</p>
            <p style="color: #666; font-size: 14px;">This action cannot be undone.</p>
            <div class="confirm-buttons">
                <button class="btn btn-secondary" onclick="closeConfirmDialog()">Cancel</button>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="code_id" id="deleteCodeId">
                    <button type="submit" name="delete_code" class="btn" style="background: #dc3545;">Delete Code</button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="confirm-dialog" id="confirmDeleteGame">
        <div class="confirm-content">
            <h3>Delete Game</h3>
            <p>Are you sure you want to delete the game with invite code <strong id="deleteGameValue"></strong>?</p>
            <p style="color: #666; font-size: 14px;">This will delete all players, scores, timers, and history. This action cannot be undone.</p>
            <div class="confirm-buttons">
                <button class="btn btn-secondary" onclick="closeConfirmDialog()">Cancel</button>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="game_id" id="deleteGameId">
                    <button type="submit" name="delete_game" class="btn" style="background: #dc3545;">Delete Game</button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        function confirmDeleteCode(codeId, codeValue) {
            document.getElementById('deleteCodeId').value = codeId;
            document.getElementById('deleteCodeValue').textContent = codeValue;
            document.getElementById('confirmDeleteCode').classList.add('active');
        }
        
        function confirmDeleteGame(gameId, gameCode) {
            document.getElementById('deleteGameId').value = gameId;
            document.getElementById('deleteGameValue').textContent = gameCode;
            document.getElementById('confirmDeleteGame').classList.add('active');
        }
        
        function closeConfirmDialog() {
            document.querySelectorAll('.confirm-dialog').forEach(dialog => {
                dialog.classList.remove('active');
            });
        }
        
        // Close dialog when clicking outside
        document.querySelectorAll('.confirm-dialog').forEach(dialog => {
            dialog.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeConfirmDialog();
                }
            });
        });
    </script>
</body>
</html>