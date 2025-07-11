<?php
// index.php - Main entry point
require_once 'config.php';
require_once 'functions.php';
require_once 'auth.php';

// Check if user is already authenticated
$player = getAuthenticatedPlayer();
if ($player) {
    // User is registered, redirect to game
    header('Location: game.php');
    exit;
}

// Handle form submission
if ($_POST) {
    $inviteCode = strtoupper(trim($_POST['invite_code'] ?? ''));
    $gender = $_POST['gender'] ?? '';
    $firstName = trim($_POST['first_name'] ?? '');
    
    if (empty($inviteCode) || empty($gender) || empty($firstName)) {
        $error = "Please fill in all fields.";
    } else {
        $result = registerPlayer($inviteCode, $gender, $firstName);
        if ($result['success']) {
            // Set device ID cookie and redirect to download
            setcookie('device_id', $result['device_id'], time() + (365 * 24 * 60 * 60), '/', '', true, true);
            header('Location: download.php');
            exit;
        } else {
            $error = $result['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://use.typekit.net/oqm2ymj.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <title>The Couple's Quest</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'museo-sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, <?= Config::COLOR_BLUE ?> 0%, <?= Config::COLOR_PINK ?> 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
        }

        .animated-background {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
        }

        .icon-item {
            position: absolute;
            font-size: 2rem;
            opacity: 0;
            color: rgba(255, 255, 255, 0.5);
            animation: float 15s linear infinite;
        }

        @keyframes float {
            0% {
                transform: translateY(120vh) rotate(0deg);
                opacity: 1;
            }
            100% {
                opacity: 1;
                transform: translateY(-120vh) rotate(360deg);
            }
        }
        
        .container {
            background: rgba(255,255,255,0.3);
            backdrop-filter: blur(8px);
            border-radius: 20px;
            padding: 20px 40px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 400px;
            text-align: center;
        }

        .gameLogo {
            display: block;
            width: 90%;
            max-width: 110px;
            margin: 0 auto 24px;
        }
        
        h1 {
            color: #111;
            margin-bottom: 30px;
            font-size: 2em;
            letter-spacing: -0.02em;
        }
        
        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }
        
        label {
            display: block;
            margin-bottom: 5px;
            color: #555;
            font-weight: 500;
        }
        
        input[type="text"] {
            background: transparent;
            color: #111;
            width: 100%;
            padding: 12px;
            border: 2px solid #555;
            border-radius: 10px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        
        input[type="text"]:focus {
            outline: none;
            border-color: #111;
        }
        
        .gender-options {
            display: flex;
            gap: 10px;
            margin-top: 5px;
        }
        
        .gender-option {
            flex: 1;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 10px;
            cursor: pointer;
            text-align: center;
            transition: all 0.3s;
        }
        
        .gender-option.male {
            border-color: <?= Config::COLOR_BLUE ?>;
            color: <?= Config::COLOR_BLUE ?>;
        }
        
        .gender-option.female {
            border-color: <?= Config::COLOR_PINK ?>;
            color: <?= Config::COLOR_PINK ?>;
        }
        
        .gender-option.selected.male {
            background: <?= Config::COLOR_BLUE ?>;
            color: white;
        }
        
        .gender-option.selected.female {
            background: <?= Config::COLOR_PINK ?>;
            color: white;
        }
        
        .submit-btn {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, <?= Config::COLOR_PINK ?>, <?= Config::COLOR_BLUE ?>);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
        }
        
        .submit-btn:hover {
            transform: translateY(-2px);
        }
        
        .error {
            background: #ffebee;
            color: #c62828;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .admin-link {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        
        .admin-link a {
            color: #666;
            text-decoration: none;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="animated-background" id="background"></div>
    <div class="container">
        <img class="gameLogo" src="logo.svg">
        <h1>The Couple's Quest</h1>
        
        <?php if (isset($error)): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label for="invite_code">Invite Code</label>
                <input type="text" id="invite_code" name="invite_code" required 
                       maxlength="6" style="text-transform: uppercase;">
            </div>
            
            <div class="form-group">
                <label>Gender</label>
                <input type="hidden" id="gender" name="gender" required>
                <div class="gender-options">
                    <div class="gender-option male" data-gender="male">Male</div>
                    <div class="gender-option female" data-gender="female">Female</div>
                </div>
            </div>
            
            <div class="form-group">
                <label for="first_name">First Name</label>
                <input type="text" id="first_name" name="first_name" required maxlength="100">
            </div>
            
            <button type="submit" class="submit-btn">Join Game</button>
        </form>
        
        <div class="admin-link">
            <a href="admin.php">Admin</a>
        </div>
    </div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script>
        $(document).ready(function() {
            const icons = ['fa-mars', 'fa-venus', 'fa-circle-arrow-up', 'fa-circle-question', 'fa-camera-retro', 'fa-hand-point-right', 'fa-pepper-hot'];
            const $bg = $('#background');
            
            // Generate random icons
            for (let i = 0; i < 100; i++) {
                const randomIcon = icons[Math.floor(Math.random() * icons.length)];
                const $icon = $('<i>').addClass('fas ' + randomIcon + ' icon-item');
                
                $icon.css({
                    left: Math.random() * 100 + '%',
                    animationDelay: Math.random() * 15 + 's'
                });
                
                $bg.append($icon);
            }
        });
    </script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const genderOptions = document.querySelectorAll('.gender-option');
            const genderInput = document.getElementById('gender');
            
            genderOptions.forEach(option => {
                option.addEventListener('click', function() {
                    genderOptions.forEach(opt => opt.classList.remove('selected'));
                    this.classList.add('selected');
                    genderInput.value = this.dataset.gender;
                });
            });
            
            // Auto-uppercase invite code
            document.getElementById('invite_code').addEventListener('input', function() {
                this.value = this.value.toUpperCase();
            });
        });
    </script>
</body>
</html>