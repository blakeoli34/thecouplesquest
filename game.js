// game.js - Complete JavaScript for The Couples Quest game

// Global variables
let gameData = {};
let selectedPlayer = null;
let firebaseMessaging = null;

// Animated Menu System Variables
let menuOpen = false;
let currentAction = null;
let currentTargetPlayerId = null;

// Check for device ID in localStorage as fallback
function checkLocalStorageAuth() {
    const deviceId = localStorage.getItem('couples_quest_device_id');
    if (deviceId && !document.cookie.includes('device_id=')) {
        // Set cookie from localStorage
        document.cookie = `device_id=${deviceId}; max-age=31536000; path=/; secure; samesite=strict`;
        window.location.reload();
    }
}

// Store device ID in localStorage when available
function storeDeviceId() {
    const urlParams = new URLSearchParams(window.location.search);
    const deviceId = urlParams.get('device_id');
    if (deviceId) {
        localStorage.setItem('couples_quest_device_id', deviceId);
    }
}

// Initialize Firebase (but don't request permission automatically)
function initializeFirebase() {
    if (typeof firebase === 'undefined') {
        console.log('Firebase not loaded, skipping initialization');
        return;
    }

    const firebaseConfig = {
        apiKey: "AIzaSyB8H4ClwOR00oxcBENYgi8yiVVMHQAUCSc",
        authDomain: "couples-quest-5b424.firebaseapp.com",
        projectId: "couples-quest-5b424",
        storageBucket: "couples-quest-5b424.firebasestorage.app",
        messagingSenderId: window.fcmSenderId || "551122707531",
        appId: "1:551122707531:web:30309743eea2fe410b19ce"
    };

    try {
        firebase.initializeApp(firebaseConfig);
        firebaseMessaging = firebase.messaging();

        // Handle foreground messages
        firebaseMessaging.onMessage((payload) => {
            console.log('Message received:', payload);
            showNotification(payload);
        });

        console.log('Firebase initialized successfully');
    } catch (error) {
        console.error('Firebase initialization failed:', error);
    }
}

// User-initiated notification enablement
function enableNotifications() {
    const button = document.getElementById('enableNotificationsBtn');
    const status = document.getElementById('notificationStatus');
    
    if (!button || !status) return;
    
    button.disabled = true;
    button.textContent = 'Requesting...';
    status.innerHTML = '';
    
    console.log('User requested to enable notifications');
    
    // Check if notifications are supported
    if (!('Notification' in window)) {
        status.innerHTML = '<span style="color: #ff6b6b;">❌ Notifications not supported in this browser</span>';
        button.textContent = 'Not Supported';
        return;
    }
    
    // Request permission
    Notification.requestPermission().then((permission) => {
        console.log('Permission result:', permission);
        
        if (permission === 'granted') {
            status.innerHTML = '<span style="color: #51cf66;">✅ Notifications enabled!</span>';
            button.textContent = 'Enabled ✓';
            button.style.background = '#51cf66';
            
            // Try to set up Firebase messaging if available
            if (firebaseMessaging) {
                setupFirebaseMessaging();
            } else {
                console.log('Firebase messaging not available, using basic notifications');
            }
            
        } else if (permission === 'denied') {
            status.innerHTML = '<span style="color: #ff6b6b;">❌ Notifications blocked. Please enable in browser settings.</span>';
            button.textContent = 'Blocked';
            button.disabled = false;
            
        } else {
            status.innerHTML = '<span style="color: #ffd43b;">⚠️ Permission dismissed. Click to try again.</span>';
            button.textContent = 'Enable Notifications';
            button.disabled = false;
        }
    }).catch((error) => {
        console.error('Error requesting permission:', error);
        status.innerHTML = '<span style="color: #ff6b6b;">❌ Error requesting permission</span>';
        button.textContent = 'Error';
        button.disabled = false;
    });
}

// Setup Firebase messaging after permission granted
function setupFirebaseMessaging() {
    if (!firebaseMessaging) {
        console.log('Firebase messaging not available');
        return;
    }
    
    const vapidKey = 'BAhDDY44EUfm9YKOElboy-2fb_6lzVhW4_TLMr4Ctiw6oA_ROcKZ09i5pKMQx3s7SoWgjuPbW-eGI7gFst6qjag';
    
    if (vapidKey === 'your-actual-vapid-key-here') {
        console.log('⚠️ VAPID key not configured. Please set your actual VAPID key.');
        document.getElementById('notificationStatus').innerHTML += 
            '<br><span style="color: #ffd43b;">⚠️ Firebase push notifications need setup</span>';
        return;
    }
    
    firebaseMessaging.getToken({ vapidKey: vapidKey }).then((currentToken) => {
        if (currentToken) {
            console.log('FCM Token received:', currentToken);
            
            // Send token to server
            fetch('game.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=update_fcm_token&fcm_token=' + encodeURIComponent(currentToken)
            })
            .then(response => response.json())
            .then(data => {
                console.log('Token update result:', data);
                if (data.success) {
                    document.getElementById('notificationStatus').innerHTML += 
                        '<br><span style="color: #51cf66;">🔥 Firebase notifications ready!</span>';
                }
            })
            .catch(error => {
                console.error('Error updating token:', error);
            });
        } else {
            console.log('No FCM token available');
        }
    }).catch((err) => {
        console.log('Error getting FCM token:', err);
        console.log('This is likely due to missing or invalid VAPID key');
    });
}

// Check notification status on page load
function checkNotificationStatus() {
    const button = document.getElementById('enableNotificationsBtn');
    const status = document.getElementById('notificationStatus');
    
    if (!button || !status) return;
    
    if (!('Notification' in window)) {
        button.textContent = 'Not Supported';
        button.disabled = true;
        status.innerHTML = '<span style="color: #ff6b6b;">❌ Notifications not supported</span>';
        return;
    }
    
    if (Notification.permission === 'granted') {
        button.textContent = 'Enabled ✓';
        button.style.background = '#51cf66';
        status.innerHTML = '<span style="color: #51cf66;">✅ Notifications are enabled</span>';
        
        // Set up Firebase if available
        if (firebaseMessaging) {
            setupFirebaseMessaging();
        }
        
    } else if (Notification.permission === 'denied') {
        button.textContent = 'Blocked';
        status.innerHTML = '<span style="color: #ff6b6b;">❌ Notifications blocked in browser settings</span>';
        
    } else {
        button.textContent = 'Enable Notifications';
        status.innerHTML = '<span style="color: #868e96;">Click to enable notifications</span>';
    }
}

// Show notification in foreground
function showNotification(payload) {
    const title = payload.notification?.title || 'The Couples Quest';
    const body = payload.notification?.body || 'New notification';
    
    // Show browser notification if page is visible
    if (document.visibilityState === 'visible') {
        // Optional: Show in-app notification instead
        showInAppNotification(title, body);
    }
}

// Show in-app notification
function showInAppNotification(title, body) {
    // Create a simple in-app notification
    const notification = document.createElement('div');
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: #333;
        color: white;
        padding: 15px 20px;
        border-radius: 10px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        z-index: 10000;
        max-width: 300px;
        animation: slideIn 0.3s ease;
    `;
    
    notification.innerHTML = `
        <div style="font-weight: bold; margin-bottom: 5px;">${title}</div>
        <div>${body}</div>
    `;
    
    document.body.appendChild(notification);
    
    // Remove after 5 seconds
    setTimeout(() => {
        notification.remove();
    }, 5000);
}

// Add CSS animation for notifications
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
`;
document.head.appendChild(style);

// ===========================================
// ANIMATED MENU SYSTEM FUNCTIONS
// ===========================================

// Setup animated menu system
function setupAnimatedMenu() {
    const menuButton = document.getElementById('menuButton');
    const menuOverlay = document.getElementById('menuOverlay');
    const actionButtons = document.querySelectorAll('.action-button');
    const pointButtons = document.querySelectorAll('.point-button');
    
    if (!menuButton) return; // Menu not available on this page
    
    // Toggle main menu
    menuButton.addEventListener('click', toggleMenu);
    menuOverlay.addEventListener('click', closeMenu);
    
    // Action button handlers
    actionButtons.forEach(button => {
        button.addEventListener('click', (e) => {
            e.stopPropagation();
            currentAction = button.dataset.action;
            currentTargetPlayerId = button.dataset.player;
            showPointButtons();
        });
    });
    
    // Point button handlers
    pointButtons.forEach(button => {
        button.addEventListener('click', (e) => {
            e.stopPropagation();
            const points = parseInt(button.dataset.points);
            executeScoreAction(currentAction, currentTargetPlayerId, points);
            closeMenu();
        });
    });
    
    // Prevent menu from closing when clicking on buttons
    actionButtons.forEach(button => {
        button.addEventListener('click', (e) => {
            e.stopPropagation();
        });
    });
    
    pointButtons.forEach(button => {
        button.addEventListener('click', (e) => {
            e.stopPropagation();
        });
    });
}

function toggleMenu() {
    if (menuOpen) {
        closeMenu();
    } else {
        openMenu();
    }
}

function openMenu() {
    menuOpen = true;
    const menuButton = document.getElementById('menuButton');
    const menuOverlay = document.getElementById('menuOverlay');
    const actionButtons = document.querySelectorAll('.action-button');
    
    menuButton.classList.add('active');
    menuOverlay.classList.add('active');
    
    // Show action buttons with staggered animation
    actionButtons.forEach((button, index) => {
        setTimeout(() => {
            button.classList.add('show');
        }, index * 50);
    });
}

function closeMenu() {
    menuOpen = false;
    const menuButton = document.getElementById('menuButton');
    const menuOverlay = document.getElementById('menuOverlay');
    const actionButtons = document.querySelectorAll('.action-button');
    const pointButtons = document.querySelectorAll('.point-button');
    
    menuButton.classList.remove('active');
    menuOverlay.classList.remove('active');
    
    // Hide all buttons
    actionButtons.forEach(button => {
        button.classList.remove('show');
    });
    pointButtons.forEach(button => {
        button.classList.remove('show');
    });
    
    // Reset state
    currentAction = null;
    currentTargetPlayerId = null;
}

function showPointButtons() {
    const actionButtons = document.querySelectorAll('.action-button');
    const pointButtons = document.querySelectorAll('.point-button');
    
    // Hide action buttons
    actionButtons.forEach(button => {
        button.classList.remove('show');
    });
    
    // Show point buttons with staggered animation
    pointButtons.forEach((button, index) => {
        setTimeout(() => {
            button.classList.add('show');
        }, index * 50);
    });
}

function executeScoreAction(action, targetPlayerId, points) {
    console.log('Executing score action:', action, targetPlayerId, points);
    
    let actualPoints = points;
    let sourcePlayerId = null;
    
    // Calculate points based on action type
    switch(action) {
        case 'add':
            actualPoints = points;
            break;
        case 'subtract':
            actualPoints = -points;
            break;
        case 'steal':
            // For steal, we subtract from opposite player and add to current player
            // First add to current player
            updateScore(targetPlayerId, points);
            // Then subtract from opposite player
            const currentPlayerId = gameData.currentPlayerId;
            const opponentPlayerId = gameData.opponentPlayerId;
            const stealToPlayerId = (targetPlayerId == currentPlayerId) ? opponentPlayerId : currentPlayerId;
            updateScore(stealToPlayerId, -points);
            return; // Early return for steal since we handle it specially
    }
    
    // For add/subtract, just update the target player
    updateScore(targetPlayerId, actualPoints);
}

// ===========================================
// EXISTING FUNCTIONS (Updated)
// ===========================================

// Duration selection handler
function setupDurationButtons() {
    document.querySelectorAll('.duration-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const days = this.dataset.days;
            
            fetch('game.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=set_duration&duration=' + days
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Failed to set game duration. Please try again.');
                }
            })
            .catch(error => {
                console.error('Error setting duration:', error);
                alert('Failed to set game duration. Please try again.');
            });
        });
    });
}

// Modal functions
function openTimerModal() {
    const modal = document.getElementById('timerModal');
    if (modal) {
        modal.classList.add('active');
    }
}

function openHistoryModal() {
    loadHistory();
    const modal = document.getElementById('historyModal');
    if (modal) {
        modal.classList.add('active');
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('active');
    }
}

// Score update function (updated to work with new system)
function updateScore(playerId, points) {
    fetch('game.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=update_score&player_id=${playerId}&points=${points}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            refreshGameData();
        } else {
            alert('Failed to update score. Please try again.');
        }
    })
    .catch(error => {
        console.error('Error updating score:', error);
        alert('Failed to update score. Please try again.');
    });
}

// Timer creation function
function createTimer() {
    const description = document.getElementById('timerDescription');
    const minutes = document.getElementById('timerDuration');
    
    if (!description || !minutes) {
        alert('Timer form elements not found');
        return;
    }
    
    if (!description.value.trim()) {
        alert('Please enter a description');
        return;
    }
    
    fetch('game.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=create_timer&description=${encodeURIComponent(description.value)}&minutes=${minutes.value}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            description.value = '';
            refreshGameData();
            closeModal('timerModal');
        } else {
            alert('Failed to create timer. Please try again.');
        }
    })
    .catch(error => {
        console.error('Error creating timer:', error);
        alert('Failed to create timer. Please try again.');
    });
}

// Bump notification function
function sendBump() {
    fetch('game.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=send_bump'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
        } else {
            alert('Failed to send bump: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error sending bump:', error);
        alert('Failed to send bump. Please try again.');
    });
}

// Test notification function
function testNotification() {
    console.log('Testing notification...');
    
    fetch('game.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=test_notification'
    })
    .then(response => response.json())
    .then(data => {
        console.log('Test notification response:', data);
        if (data.success) {
            alert('Test notification sent! Check your device.');
        } else {
            alert('Failed to send test notification: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error sending test notification:', error);
        alert('Failed to send test notification.');
    });
}

// Load history function
function loadHistory() {
    fetch('game.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=get_game_data'
    })
    .then(response => response.json())
    .then(data => {
        const historyContent = document.getElementById('historyContent');
        if (!historyContent) return;
        
        historyContent.innerHTML = '';
        
        if (!data.history || data.history.length === 0) {
            historyContent.innerHTML = '<p style="text-align: center; color: #666;">No score changes in the last 24 hours</p>';
            return;
        }
        
        data.history.forEach(item => {
            const div = document.createElement('div');
            div.className = 'history-item';
            
            const time = new Date(item.timestamp).toLocaleString();
            const change = item.points_changed > 0 ? `+${item.points_changed}` : item.points_changed;
            
            div.innerHTML = `
                <div class="history-time">${time}</div>
                <div class="history-change">
                    ${item.modified_by_name} gave ${change} points to ${item.player_name}
                </div>
            `;
            
            historyContent.appendChild(div);
        });
    })
    .catch(error => {
        console.error('Error loading history:', error);
        const historyContent = document.getElementById('historyContent');
        if (historyContent) {
            historyContent.innerHTML = '<p style="text-align: center; color: #666;">Failed to load history</p>';
        }
    });
}

// Refresh game data function
function refreshGameData() {
    fetch('game.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=get_game_data'
    })
    .then(response => response.json())
    .then(data => {
        // Update scores
        if (data.players) {
            data.players.forEach(player => {
                const scoreElement = document.querySelector(`.player-score.${player.gender} .player-score-value`);
                if (scoreElement) {
                    scoreElement.textContent = player.score;
                }
            });
        }
        
        // Update timers
        if (data.timers) {
            updateTimerDisplay(data.timers);
        }
    })
    .catch(error => {
        console.error('Error refreshing game data:', error);
    });
}

function updateTimerDisplay(timers) {
    const currentTimers = document.getElementById('current-timers');
    const opponentTimers = document.getElementById('opponent-timers');
    
    if (!currentTimers || !opponentTimers) return;
    
    currentTimers.innerHTML = '';
    opponentTimers.innerHTML = '';
    
    timers.forEach(timer => {
        const div = document.createElement('div');
        div.className = 'timer-badge';
        div.title = timer.description;
        
        // Treat database time as UTC
        const endTime = new Date(timer.end_time + 'Z');
        const now = new Date();
        const diff = endTime - now;
        
        if (diff > 0) {
            const hours = Math.floor(diff / (1000 * 60 * 60));
            const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
            
            div.textContent = hours > 0 ? `${hours}h ${minutes}m` : `${minutes}m`;
            
            if (timer.player_id == gameData.currentPlayerId) {
                currentTimers.appendChild(div);
            } else {
                opponentTimers.appendChild(div);
            }
        }
    });
}

// Setup modal close handlers
function setupModalHandlers() {
    // Close modals when clicking outside
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.remove('active');
            }
        });
    });
}

// Initialize everything when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    console.log('Game page loaded');
    
    // Store device ID and check auth
    storeDeviceId();
    checkLocalStorageAuth();
    
    // Get game data from the page (set by PHP)
    if (typeof window.gameDataFromPHP !== 'undefined') {
        gameData = window.gameDataFromPHP;
        console.log('Game data:', gameData);
    }
    
    // Initialize Firebase (but don't request permission automatically)
    initializeFirebase();
    
    // Check notification status if button exists
    setTimeout(checkNotificationStatus, 500);
    
    // Setup event handlers
    setupDurationButtons();
    setupModalHandlers();
    setupAnimatedMenu(); // New animated menu system
    
    // Start periodic refresh for active games
    if (gameData.gameStatus === 'active') {
        refreshGameData();
        setInterval(refreshGameData, 10000); // Refresh every 10 seconds
    }
});

// Make functions globally available
window.openTimerModal = openTimerModal;
window.openHistoryModal = openHistoryModal;
window.closeModal = closeModal;
window.createTimer = createTimer;
window.sendBump = sendBump;
window.testNotification = testNotification;
window.enableNotifications = enableNotifications;