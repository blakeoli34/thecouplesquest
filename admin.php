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

// Handle card management actions
if ($_POST && isset($_POST['action'])) {
    switch($_POST['action']) {    
        case 'get_card':
            $id = intval($_POST['id']);
            $card = getCardById($id);
            echo json_encode(['success' => true, 'card' => $card]);
            exit;
            
        case 'save_card':
            $result = saveCard($_POST);
            echo json_encode($result);
            exit;
            
        case 'delete_card':
            $id = intval($_POST['id']);
            $result = deleteCard($id);
            echo json_encode($result);
            exit;

        case 'get_cards':
            $type = $_POST['type'];
            $cards = getCardsByType($type);
            $counts = getCardCounts($type);
            echo json_encode(['success' => true, 'cards' => $cards, 'counts' => $counts]);
            exit;
    }
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

function getCardsByType($type) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("SELECT * FROM cards WHERE card_type = ? ORDER BY card_name ASC");
        $stmt->execute([$type]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Error getting cards: " . $e->getMessage());
        return [];
    }
}

function getCardById($id) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("SELECT * FROM cards WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    } catch (Exception $e) {
        error_log("Error getting card: " . $e->getMessage());
        return null;
    }
}

function getCardCounts($type) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        if ($type === 'serve') {
            $stmt = $pdo->prepare("
                SELECT 
                    SUM(CASE WHEN serve_to_her = 1 THEN quantity ELSE 0 END) as her_count,
                    SUM(CASE WHEN serve_to_him = 1 THEN quantity ELSE 0 END) as him_count
                FROM cards WHERE card_type = ?
            ");
        } elseif (in_array($type, ['chance', 'spicy'])) {
            $stmt = $pdo->prepare("
                SELECT 
                    SUM(CASE WHEN for_her = 1 THEN quantity ELSE 0 END) as her_count,
                    SUM(CASE WHEN for_him = 1 THEN quantity ELSE 0 END) as him_count
                FROM cards WHERE card_type = ?
            ");
        } else {
            // snap, dare cards don't have gender
            $stmt = $pdo->prepare("SELECT SUM(quantity) as total FROM cards WHERE card_type = ?");
            $stmt->execute([$type]);
            $total = $stmt->fetchColumn();
            return ['total' => $total ?: 0];
        }
        
        $stmt->execute([$type]);
        return $stmt->fetch();
    } catch (Exception $e) {
        return ['her_count' => 0, 'him_count' => 0];
    }
}

function saveCard($data) {
    try {
        $pdo = Config::getDatabaseConnection();
        
        $id = !empty($data['id']) ? intval($data['id']) : null;
        $cardType = $data['card_type'];
        $cardName = trim($data['card_name']);
        $cardDescription = trim($data['card_description']);
        
        if (empty($cardName) || empty($cardDescription)) {
            return ['success' => false, 'message' => 'Card name and description are required'];
        }
        
        if ($id) {
            // Update existing card
            $sql = "UPDATE cards SET card_name = ?, card_description = ?, quantity = ?";
            $params = [$cardName, $cardDescription, intval($data['quantity']) ?: 1];
            
            // Add type-specific fields
            if ($cardType === 'serve') {
                $sql .= ", card_points = ?, serve_to_her = ?, serve_to_him = ?, veto_subtract = ?, veto_steal = ?, veto_draw_chance = ?, veto_draw_snap_dare = ?, veto_draw_spicy = ?";
                $params = array_merge($params, [
                    !empty($data['card_points']) ? intval($data['card_points']) : null,
                    intval($data['serve_to_her']),
                    intval($data['serve_to_him']),
                    !empty($data['veto_subtract']) ? intval($data['veto_subtract']) : null,
                    !empty($data['veto_steal']) ? intval($data['veto_steal']) : null,
                    !empty($data['veto_draw_chance']) ? intval($data['veto_draw_chance']) : null,
                    !empty($data['veto_draw_snap_dare']) ? intval($data['veto_draw_snap_dare']) : null,
                    !empty($data['veto_draw_spicy']) ? intval($data['veto_draw_spicy']) : null
                ]);
            } elseif ($cardType === 'chance') {
                $sql .= ", for_her = ?, for_him = ?, before_next_challenge = ?, challenge_modify = ?, opponent_challenge_modify = ?, draw_snap_dare = ?, draw_spicy = ?, score_modify = ?, timer = ?, veto_modify = ?, snap_modify = ?, dare_modify = ?, spicy_modify = ?, score_add = ?, score_subtract = ?, score_steal = ?, repeat_count = ?, roll_dice = ?, dice_condition = ?, dice_threshold = ?, double_it = ?";
                $params = array_merge($params, [
                    intval($data['for_her']),
                    intval($data['for_him']),
                    intval($data['before_next_challenge']),
                    intval($data['challenge_modify']),
                    intval($data['opponent_challenge_modify']),
                    intval($data['draw_snap_dare']),
                    intval($data['draw_spicy']),
                    $data['score_modify'] ?: 'none',
                    !empty($data['timer']) ? intval($data['timer']) : null,
                    $data['veto_modify'] ?: 'none',
                    intval($data['snap_modify']),
                    intval($data['dare_modify']),
                    intval($data['spicy_modify']),
                    !empty($data['score_add']) ? intval($data['score_add']) : null,
                    !empty($data['score_subtract']) ? intval($data['score_subtract']) : null,
                    !empty($data['score_steal']) ? intval($data['score_steal']) : null,
                    !empty($data['repeat_count']) ? intval($data['repeat_count']) : null,
                    intval($data['roll_dice']),
                    !empty($data['dice_condition']) ? $data['dice_condition'] : null,
                    !empty($data['dice_threshold']) ? intval($data['dice_threshold']) : null,
                    intval($data['double_it'])
                ]);
            } elseif ($cardType === 'spicy') {
                $sql .= ", for_her = ?, for_him = ?, extra_spicy = ?";
                $params = array_merge($params, [
                    intval($data['for_her']),
                    intval($data['for_him']),
                    intval($data['extra_spicy'])
                ]);
            }
            
            $sql .= " WHERE id = ?";
            $params[] = $id;
            
        } else {
            // Insert new card
            if ($cardType === 'serve') {
                $sql = "INSERT INTO cards (card_type, card_name, card_description, quantity, card_points, serve_to_her, serve_to_him, veto_subtract, veto_steal, veto_draw_chance, veto_draw_snap_dare, veto_draw_spicy) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $params = [
                    $cardType, $cardName, $cardDescription, intval($data['quantity']) ?: 1,
                    !empty($data['card_points']) ? intval($data['card_points']) : null,
                    intval($data['serve_to_her']),
                    intval($data['serve_to_him']),
                    !empty($data['veto_subtract']) ? intval($data['veto_subtract']) : null,
                    !empty($data['veto_steal']) ? intval($data['veto_steal']) : null,
                    !empty($data['veto_draw_chance']) ? intval($data['veto_draw_chance']) : null,
                    !empty($data['veto_draw_snap_dare']) ? intval($data['veto_draw_snap_dare']) : null,
                    !empty($data['veto_draw_spicy']) ? intval($data['veto_draw_spicy']) : null
                ];
            } elseif ($cardType === 'chance') {
                $sql = "INSERT INTO cards (card_type, card_name, card_description, quantity, for_her, for_him, before_next_challenge, challenge_modify, opponent_challenge_modify, draw_snap_dare, draw_spicy, score_modify, timer, veto_modify, snap_modify, dare_modify, spicy_modify, score_add, score_subtract, score_steal, repeat_count, roll_dice, dice_condition, dice_threshold, double_it) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $params = [
                    $cardType, $cardName, $cardDescription, intval($data['quantity']) ?: 1,
                    intval($data['for_her']),
                    intval($data['for_him']),
                    intval($data['before_next_challenge']),
                    intval($data['challenge_modify']),
                    intval($data['opponent_challenge_modify']),
                    intval($data['draw_snap_dare']),
                    intval($data['draw_spicy']),
                    $data['score_modify'] ?: 'none',
                    !empty($data['timer']) ? intval($data['timer']) : null,
                    $data['veto_modify'] ?: 'none',
                    intval($data['snap_modify']),
                    intval($data['dare_modify']),
                    intval($data['spicy_modify']),
                    !empty($data['score_add']) ? intval($data['score_add']) : null,
                    !empty($data['score_subtract']) ? intval($data['score_subtract']) : null,
                    !empty($data['score_steal']) ? intval($data['score_steal']) : null,
                    !empty($data['repeat_count']) ? intval($data['repeat_count']) : null,
                    intval($data['roll_dice']),
                    !empty($data['dice_condition']) ? $data['dice_condition'] : null,
                    !empty($data['dice_threshold']) ? intval($data['dice_threshold']) : null,
                    intval($data['double_it'])
                ];
            } else {
                // snap, dare, or spicy (simple cards)
                $sql = "INSERT INTO cards (card_type, card_name, card_description, quantity, for_her, for_him, extra_spicy) VALUES (?, ?, ?, ?, ?, ?, ?)";
                $params = [
                    $cardType, $cardName, $cardDescription, intval($data['quantity']) ?: 1,
                    ($cardType === 'spicy') ? intval($data['for_her']) : 0,
                    ($cardType === 'spicy') ? intval($data['for_him']) : 0,
                    ($cardType === 'spicy') ? intval($data['extra_spicy']) : 0
                ];
            }
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        return ['success' => true];
        
    } catch (Exception $e) {
        error_log("Error saving card: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to save card'];
    }
}

function deleteCard($id) {
    try {
        $pdo = Config::getDatabaseConnection();
        $stmt = $pdo->prepare("DELETE FROM cards WHERE id = ?");
        $stmt->execute([$id]);
        
        return ['success' => true];
        
    } catch (Exception $e) {
        error_log("Error deleting card: " . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to delete card'];
    }
}

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
        
        .card-tabs {
            display: flex;
            border-bottom: 2px solid #eee;
            margin-bottom: 20px;
        }
        
        .card-tab {
            padding: 12px 24px;
            background: none;
            border: none;
            color: #666;
            font-weight: 600;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: all 0.3s ease;
        }
        
        .card-tab.active {
            color: #333;
            border-bottom-color: <?= Config::COLOR_BLUE ?>;
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .card-item {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            border-left: 4px solid <?= Config::COLOR_BLUE ?>;
        }
        
        .card-item h4 {
            margin-bottom: 10px;
            color: #333;
        }
        
        .card-item p {
            color: #666;
            margin-bottom: 15px;
            line-height: 1.4;
        }
        
        .card-meta {
            display: flex;
            gap: 15px;
            font-size: 12px;
            color: #888;
            margin-bottom: 10px;
        }
        
        .card-actions {
            display: flex;
            gap: 10px;
        }
        
        .modal-form {
            max-height: 60vh;
            overflow-y: auto;
        }
        
        .form-row {
            display: flex;
            gap: 15px;
        }
        
        .form-row .form-group {
            flex: 1;
        }
        
        .checkbox-group {
            display: flex;
            gap: 20px;
            margin-top: 10px;
        }
        
        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .checkbox-item input[type="checkbox"] {
            width: auto;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
        }
        
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 10px;
            font-size: 16px;
            -webkit-appearance: none;
            appearance: none;
            background: transparent;
            color: darkgray;
            outline: none;
            font-family: inherit;
        }
        
        .btn-secondary, .btn.dark {
            background: #6b7280;
        }

        .mode-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .mode-hybrid {
            background: #e3f2fd;
            color: #1565c0;
        }

        .mode-digital {
            background: #f3e5f5;
            color: #7b1fa2;
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
                        <th>Mode</th>
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
                                <span class="mode-badge mode-<?= $game['game_mode'] ?>">
                                    <?= $game['game_mode'] === 'digital' ? '📱 Digital' : '🃏 Hybrid' ?>
                                </span>
                            </td>
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

        <!-- Playing Cards Management -->
        <div class="section">
            <h2>Playing Cards Management</h2>
            
            <!-- Card Type Tabs -->
            <div class="card-tabs">
                <button class="card-tab active" onclick="showCardType('serve')">Serve Cards</button>
                <button class="card-tab" onclick="showCardType('chance')">Chance Cards</button>
                <button class="card-tab" onclick="showCardType('snap')">Snap Cards</button>
                <button class="card-tab" onclick="showCardType('dare')">Dare Cards</button>
                <button class="card-tab" onclick="showCardType('spicy')">Spicy Cards</button>
            </div>
            
            <div class="card-type-content" id="serve-cards">
                <div class="card-header">
                    <h3>Serve Cards</h3>
                    <button class="btn" onclick="openCardModal('serve')">Add New Serve Card</button>
                </div>
                <div class="cards-list" id="serve-cards-list">
                    <!-- Cards will be loaded here -->
                </div>
            </div>
            
            <div class="card-type-content" id="chance-cards" style="display: none;">
                <div class="card-header">
                    <h3>Chance Cards</h3>
                    <button class="btn" onclick="openCardModal('chance')">Add New Chance Card</button>
                </div>
                <div class="cards-list" id="chance-cards-list">
                    <!-- Cards will be loaded here -->
                </div>
            </div>
            
            <div class="card-type-content" id="snap-cards" style="display: none;">
                <div class="card-header">
                    <h3>Snap Cards</h3>
                    <button class="btn" onclick="openCardModal('snap')">Add New Snap Card</button>
                </div>
                <div class="cards-list" id="snap-cards-list">
                    <!-- Cards will be loaded here -->
                </div>
            </div>
            
            <div class="card-type-content" id="dare-cards" style="display: none;">
                <div class="card-header">
                    <h3>Dare Cards</h3>
                    <button class="btn" onclick="openCardModal('dare')">Add New Dare Card</button>
                </div>
                <div class="cards-list" id="dare-cards-list">
                    <!-- Cards will be loaded here -->
                </div>
            </div>
            
            <div class="card-type-content" id="spicy-cards" style="display: none;">
                <div class="card-header">
                    <h3>Spicy Cards</h3>
                    <button class="btn" onclick="openCardModal('spicy')">Add New Spicy Card</button>
                </div>
                <div class="cards-list" id="spicy-cards-list">
                    <!-- Cards will be loaded here -->
                </div>
            </div>
        </div>
    </div>

    <!-- Card Management Modal -->
    <div class="confirm-dialog" id="cardModal">
        <div class="confirm-content" style="max-width: 600px;">
            <h3 id="cardModalTitle">Add Card</h3>
            <form id="cardForm" class="modal-form">
                <input type="hidden" id="cardId">
                <input type="hidden" id="cardType">
                
                <div class="form-group">
                    <label for="cardName">Card Name</label>
                    <input type="text" id="cardName" required>
                </div>
                
                <div class="form-group">
                    <label for="cardDescription">Card Description</label>
                    <textarea id="cardDescription" rows="3" required></textarea>
                </div>

                <div class="form-group">
                    <label for="cardQuantity">Quantity</label>
                    <input type="number" id="cardQuantity" min="1" value="1" required>
                </div>
                
                <!-- Serve Card Fields -->
                <div id="serveFields" style="display: none;">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="cardPoints">Points (1-5)</label>
                            <input type="number" id="cardPoints" min="1" max="5">
                        </div>
                    </div>
                    
                    <div class="checkbox-group">
                        <div class="checkbox-item">
                            <input type="checkbox" id="serveToHer">
                            <label for="serveToHer">Serve to Her</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="serveToHim">
                            <label for="serveToHim">Serve to Him</label>
                        </div>
                    </div>
                    
                    <h4 style="margin-top: 20px;">Veto Options</h4>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="vetoSubtract">Veto Subtract</label>
                            <input type="number" id="vetoSubtract" min="0">
                        </div>
                        <div class="form-group">
                            <label for="vetoSteal">Veto Steal</label>
                            <input type="number" id="vetoSteal" min="0">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="vetoDrawChance">Veto Draw Chance</label>
                            <input type="number" id="vetoDrawChance" min="0">
                        </div>
                        <div class="form-group">
                            <label for="vetoDrawSnapDare">Veto Draw Snap/Dare</label>
                            <input type="number" id="vetoDrawSnapDare" min="0">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="vetoDrawSpicy">Veto Draw Spicy</label>
                        <input type="number" id="vetoDrawSpicy" min="0">
                    </div>
                </div>
                
                <!-- Chance Card Fields -->
                <div id="chanceFields" style="display: none;">
                    <div class="checkbox-group">
                        <div class="checkbox-item">
                            <input type="checkbox" id="forHer">
                            <label for="forHer">For Her</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="forHim">
                            <label for="forHim">For Him</label>
                        </div>
                    </div>
                    
                    <div class="checkbox-group">
                        <div class="checkbox-item">
                            <input type="checkbox" id="beforeNextChallenge">
                            <label for="beforeNextChallenge">Before Next Challenge</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="challengeModify">
                            <label for="challengeModify">Challenge Modify</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="opponentChallengeModify">
                            <label for="opponentChallengeModify">Opponent Challenge Modify</label>
                        </div>
                    </div>
                    
                    <div class="checkbox-group">
                        <div class="checkbox-item">
                            <input type="checkbox" id="drawSnapDareChance">
                            <label for="drawSnapDareChance">Draw Snap/Dare</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="drawSpicyChance">
                            <label for="drawSpicyChance">Draw Spicy</label>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="scoreModify">Score Modify</label>
                            <select id="scoreModify">
                                <option value="none">None</option>
                                <option value="half">Half</option>
                                <option value="opponent_double">Opponent Double</option>
                                <option value="zero">Zero</option>
                                <option value="opponent_extra_point">Opponent Extra Point</option>
                                <option value="challenge_reward_opponent">Challenge Reward Opponent</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="timer">Timer (minutes)</label>
                            <input type="number" id="timer" min="0">
                        </div>
                        <div class="form-group">
                            <label for="timerCompletionType">Timer Completion</label>
                            <select id="timerCompletionType">
                                <option value="timer_expires">Timer Expires</option>
                                <option value="first_trigger">First Trigger</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="vetoModify">Veto Modify</label>
                        <select id="vetoModify">
                            <option value="none">None</option>
                            <option value="double">Double</option>
                            <option value="skip">Skip</option>
                            <option value="opponent_double">Opponent Double</option>
                            <option value="opponent_reward">Opponent Reward</option>
                        </select>
                    </div>
                    
                    <div class="checkbox-group">
                        <div class="checkbox-item">
                            <input type="checkbox" id="snapModify">
                            <label for="snapModify">Snap Modify</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="dareModify">
                            <label for="dareModify">Dare Modify</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="spicyModify">
                            <label for="spicyModify">Spicy Modify</label>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="scoreAdd">Score Add</label>
                            <input type="number" id="scoreAdd">
                        </div>
                        <div class="form-group">
                            <label for="scoreSubtract">Score Subtract</label>
                            <input type="number" id="scoreSubtract">
                        </div>
                        <div class="form-group">
                            <label for="scoreSteal">Score Steal</label>
                            <input type="number" id="scoreSteal">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="repeatCount">Repeat</label>
                            <input type="number" id="repeatCount" min="0">
                        </div>
                        <div class="checkbox-item" style="align-self: end; padding-bottom: 12px;">
                            <input type="checkbox" id="rollDice">
                            <label for="rollDice">Roll Dice</label>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="diceCondition">Dice Condition</label>
                            <select id="diceCondition">
                                <option value="">None</option>
                                <option value="Even">Even</option>
                                <option value="Odd">Odd</option>
                                <option value="Same">Same</option>
                                <option value="Doubles">Doubles</option>
                                <option value="Above">Above</option>
                                <option value="Below">Below</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="diceThreshold">Dice Threshold</label>
                            <input type="number" id="diceThreshold" min="2" max="12">
                        </div>
                        <div class="checkbox-item" style="align-self: end; padding-bottom: 12px;">
                            <input type="checkbox" id="doubleIt">
                            <label for="doubleIt">Double It</label>
                        </div>
                    </div>
                </div>
                
                <!-- Spicy Card Fields -->
                <div id="spicyFields" style="display: none;">
                    <div class="checkbox-group">
                        <div class="checkbox-item">
                            <input type="checkbox" id="forHerSpicy">
                            <label for="forHerSpicy">For Her</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="forHimSpicy">
                            <label for="forHimSpicy">For Him</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="extraSpicy">
                            <label for="extraSpicy">Extra Spicy</label>
                        </div>
                    </div>
                </div>
                
            </form>
            
            <div class="confirm-buttons">
                <button class="btn btn-secondary" onclick="closeCardModal()">Cancel</button>
                <button class="btn" onclick="saveCard()">Save Card</button>
            </div>
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
        let currentCardType = 'serve';

        function showCardType(type) {
            currentCardType = type;
            
            // Update tab states
            document.querySelectorAll('.card-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            event.target.classList.add('active');
            
            // Show/hide content
            document.querySelectorAll('.card-type-content').forEach(content => {
                content.style.display = 'none';
            });
            document.getElementById(type + '-cards').style.display = 'block';
            
            // Load cards for this type
            loadCards(type);
        }

        function loadCards(type) {
            fetch('admin.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=get_cards&type=' + type
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayCards(type, data.cards);
                    updateCardCounts(type, data.counts);
                }
            });
        }

        function updateCardCounts(type, counts) {
            const header = document.querySelector(`#${type}-cards .card-header h3`);
            let countText = '';
            
            if (counts.total !== undefined) {
                countText = ` (${counts.total} cards)`;
            } else {
                const herCount = counts.her_count || 0;
                const himCount = counts.him_count || 0;
                countText = ` (Her: ${herCount}, Him: ${himCount})`;
            }
            
            const baseTitle = type.charAt(0).toUpperCase() + type.slice(1) + ' Cards';
            header.textContent = baseTitle + countText;
        }

        function displayCards(type, cards) {
            const container = document.getElementById(type + '-cards-list');
            const header = document.querySelector(`#${type}-cards .card-header h3`);
            container.innerHTML = '';
            
            if (cards.length === 0) {
                container.innerHTML = '<p style="text-align: center; color: #666; padding: 20px;">No cards yet</p>';
                return;
            }
            
            cards.forEach(card => {
                const cardElement = document.createElement('div');
                cardElement.className = 'card-item';
                cardElement.innerHTML = `
                    <h4>${card.card_name}</h4>
                    <p>${card.card_description}</p>
                    <div class="card-meta">
                        ${getCardMeta(card)}
                    </div>
                    <div class="card-actions">
                        <button class="btn-small btn-warning" onclick="editCard(${card.id})">Edit</button>
                        <button class="btn-small btn-danger" onclick="confirmDeleteCard(${card.id}, '${card.card_name}')">Delete</button>
                    </div>
                `;
                container.appendChild(cardElement);
            });
        }

        function getCardMeta(card) {
            let meta = [];
            
            if (card.card_type === 'serve') {
                if (card.card_points) meta.push(`${card.card_points} points`);
                if (card.serve_to_her && card.serve_to_him) meta.push('Both genders');
                else if (card.serve_to_her) meta.push('For her');
                else if (card.serve_to_him) meta.push('For him');
            } else if (card.card_type === 'chance') {
                if (card.for_her && card.for_him) meta.push('Both genders');
                else if (card.for_her) meta.push('For her');
                else if (card.for_him) meta.push('For him');
                if (card.timer) meta.push(`${card.timer}min timer`);
            } else if (card.card_type === 'spicy') {
                if (card.for_her && card.for_him) meta.push('Both genders');
                else if (card.for_her) meta.push('For her');
                else if (card.for_him) meta.push('For him');
                if (card.extra_spicy) meta.push('Extra spicy');
            }

            if (card.quantity && card.quantity > 1) meta.push(`${card.quantity}x`);
            
            return meta.join(' • ');
        }

        function openCardModal(type, cardId = null) {
            currentCardType = type;
            const modal = document.getElementById('cardModal');
            const title = document.getElementById('cardModalTitle');
            
            // Reset form
            document.getElementById('cardForm').reset();
            document.getElementById('cardId').value = cardId || '';
            document.getElementById('cardType').value = type;
            
            // Show/hide field groups
            document.getElementById('serveFields').style.display = type === 'serve' ? 'block' : 'none';
            document.getElementById('chanceFields').style.display = type === 'chance' ? 'block' : 'none';
            document.getElementById('spicyFields').style.display = type === 'spicy' ? 'block' : 'none';
            
            title.textContent = cardId ? 'Edit ' + type.charAt(0).toUpperCase() + type.slice(1) + ' Card' : 'Add ' + type.charAt(0).toUpperCase() + type.slice(1) + ' Card';
            
            if (cardId) {
                loadCardData(cardId);
            }
            
            modal.classList.add('active');
        }

        function loadCardData(cardId) {
            fetch('admin.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=get_card&id=' + cardId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    populateForm(data.card);
                }
            });
        }

        function populateForm(card) {
            document.getElementById('cardName').value = card.card_name;
            document.getElementById('cardDescription').value = card.card_description;
            if (card.quantity) document.getElementById('cardQuantity').value = card.quantity;
            
            if (card.card_type === 'serve') {
                if (card.card_points) document.getElementById('cardPoints').value = card.card_points;
                document.getElementById('serveToHer').checked = card.serve_to_her;
                document.getElementById('serveToHim').checked = card.serve_to_him;
                if (card.veto_subtract) document.getElementById('vetoSubtract').value = card.veto_subtract;
                if (card.veto_steal) document.getElementById('vetoSteal').value = card.veto_steal;
                if (card.veto_draw_chance) document.getElementById('vetoDrawChance').value = card.veto_draw_chance;
                if (card.veto_draw_snap_dare) document.getElementById('vetoDrawSnapDare').value = card.veto_draw_snap_dare;
                if (card.veto_draw_spicy) document.getElementById('vetoDrawSpicy').value = card.veto_draw_spicy;
            } else if (card.card_type === 'chance') {
                document.getElementById('forHer').checked = card.for_her;
                document.getElementById('forHim').checked = card.for_him;
                document.getElementById('beforeNextChallenge').checked = card.before_next_challenge;
                document.getElementById('challengeModify').checked = card.challenge_modify;
                document.getElementById('opponentChallengeModify').checked = card.opponent_challenge_modify;
                document.getElementById('drawSnapDareChance').checked = card.draw_snap_dare;
                document.getElementById('drawSpicyChance').checked = card.draw_spicy;
                document.getElementById('scoreModify').value = card.score_modify;
                if (card.timer) document.getElementById('timer').value = card.timer;
                document.getElementById('vetoModify').value = card.veto_modify;
                document.getElementById('snapModify').checked = card.snap_modify;
                document.getElementById('dareModify').checked = card.dare_modify;
                document.getElementById('spicyModify').checked = card.spicy_modify;
                if (card.score_add) document.getElementById('scoreAdd').value = card.score_add;
                if (card.score_subtract) document.getElementById('scoreSubtract').value = card.score_subtract;
                if (card.score_steal) document.getElementById('scoreSteal').value = card.score_steal;
                if (card.repeat_count) document.getElementById('repeatCount').value = card.repeat_count;
                document.getElementById('rollDice').checked = card.roll_dice;
                if (card.dice_condition) document.getElementById('diceCondition').value = card.dice_condition;
                if (card.dice_threshold) document.getElementById('diceThreshold').value = card.dice_threshold;
                document.getElementById('doubleIt').checked = card.double_it;
            } else if (card.card_type === 'spicy') {
                document.getElementById('forHerSpicy').checked = card.for_her;
                document.getElementById('forHimSpicy').checked = card.for_him;
                document.getElementById('extraSpicy').checked = card.extra_spicy;
            }
        }

        function closeCardModal() {
            document.getElementById('cardModal').classList.remove('active');
        }

        function saveCard() {
            const formData = new FormData();
            formData.append('action', 'save_card');
            
            // Basic fields
            formData.append('id', document.getElementById('cardId').value);
            formData.append('card_type', document.getElementById('cardType').value);
            formData.append('card_name', document.getElementById('cardName').value);
            formData.append('card_description', document.getElementById('cardDescription').value);
            formData.append('quantity', document.getElementById('cardQuantity').value || '1');
            
            const cardType = document.getElementById('cardType').value;
            
            if (cardType === 'serve') {
                formData.append('card_points', document.getElementById('cardPoints').value || '');
                formData.append('serve_to_her', document.getElementById('serveToHer').checked ? '1' : '0');
                formData.append('serve_to_him', document.getElementById('serveToHim').checked ? '1' : '0');
                formData.append('veto_subtract', document.getElementById('vetoSubtract').value || '');
                formData.append('veto_steal', document.getElementById('vetoSteal').value || '');
                formData.append('veto_draw_chance', document.getElementById('vetoDrawChance').value || '');
                formData.append('veto_draw_snap_dare', document.getElementById('vetoDrawSnapDare').value || '');
                formData.append('veto_draw_spicy', document.getElementById('vetoDrawSpicy').value || '');
            } else if (cardType === 'chance') {
                formData.append('for_her', document.getElementById('forHer').checked ? '1' : '0');
                formData.append('for_him', document.getElementById('forHim').checked ? '1' : '0');
                formData.append('before_next_challenge', document.getElementById('beforeNextChallenge').checked ? '1' : '0');
                formData.append('challenge_modify', document.getElementById('challengeModify').checked ? '1' : '0');
                formData.append('opponent_challenge_modify', document.getElementById('opponentChallengeModify').checked ? '1' : '0');
                formData.append('draw_snap_dare', document.getElementById('drawSnapDareChance').checked ? '1' : '0');
                formData.append('draw_spicy', document.getElementById('drawSpicyChance').checked ? '1' : '0');
                formData.append('score_modify', document.getElementById('scoreModify').value);
                formData.append('timer', document.getElementById('timer').value || '');
                formData.append('timer_completion_type', document.getElementById('timerCompletionType').value);
                formData.append('veto_modify', document.getElementById('vetoModify').value);
                formData.append('snap_modify', document.getElementById('snapModify').checked ? '1' : '0');
                formData.append('dare_modify', document.getElementById('dareModify').checked ? '1' : '0');
                formData.append('spicy_modify', document.getElementById('spicyModify').checked ? '1' : '0');
                formData.append('score_add', document.getElementById('scoreAdd').value || '');
                formData.append('score_subtract', document.getElementById('scoreSubtract').value || '');
                formData.append('score_steal', document.getElementById('scoreSteal').value || '');
                formData.append('repeat_count', document.getElementById('repeatCount').value || '');
                formData.append('roll_dice', document.getElementById('rollDice').checked ? '1' : '0');
                formData.append('dice_condition', document.getElementById('diceCondition').value || '');
                formData.append('dice_threshold', document.getElementById('diceThreshold').value || '');
                formData.append('double_it', document.getElementById('doubleIt').checked ? '1' : '0');
            } else if (cardType === 'spicy') {
                formData.append('for_her', document.getElementById('forHerSpicy').checked ? '1' : '0');
                formData.append('for_him', document.getElementById('forHimSpicy').checked ? '1' : '0');
                formData.append('extra_spicy', document.getElementById('extraSpicy').checked ? '1' : '0');
            }
            
            fetch('admin.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    closeCardModal();
                    loadCards(currentCardType);
                } else {
                    alert('Error saving card: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error saving card');
            });
        }

        function editCard(cardId) {
            openCardModal(currentCardType, cardId);
        }

        function confirmDeleteCard(cardId, cardName) {
            if (confirm(`Are you sure you want to delete the card "${cardName}"?`)) {
                deleteCard(cardId);
            }
        }

        function deleteCard(cardId) {
            fetch('admin.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=delete_card&id=' + cardId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loadCards(currentCardType);
                } else {
                    alert('Error deleting card: ' + (data.message || 'Unknown error'));
                }
            });
        }

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

        // Load initial cards when page loads
        document.addEventListener('DOMContentLoaded', function() {
            loadCards('serve');
        });
    </script>
</body>
</html>