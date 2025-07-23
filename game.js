// game.js - Complete JavaScript for The Couples Quest game

// Global variables
let gameData = {};
let selectedPlayer = null;
let firebaseMessaging = null;

// Animated Menu System Variables
let menuOpen = false;
let currentAction = null;
let currentTargetPlayerId = null;

let currentDiceCount = 1;
let isDiceRolling = false;

let selectedTimerId = null;

let cardData = {
    serve_cards: [],
    hand_cards: { snap: [], dare: [], spicy: [] },
    pending_serves: []
};

let selectedCard = null;

let selectedHandCard = null;
let isCardSelected = false;

// Load card data for digital games
function loadCardData() {
    if (!document.body.classList.contains('digital')) return;
    
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get_card_data'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            cardData = data;
            
            // Auto-initialize if no serve cards
            if (!data.serve_cards || data.serve_cards.length === 0) {
                console.log('No serve cards, initializing...');
                initializeCards();
                return;
            }
        }
    })
    .catch(error => {
        console.error('Error loading card data:', error);
    });
}

// Initialize cards if not already done
function initializeCards() {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=initialize_digital_cards'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log('Cards initialized successfully');
            // Reload card data after initialization
            setTimeout(() => loadCardData(), 1000);
        } else {
            console.log('Failed to initialize cards:', data.message);
        }
    })
    .catch(error => {
        console.error('Error initializing cards:', error);
    });
}

// Open serve cards overlay
function openServeCards() {
    populateCardGrid('serveCardsGrid', cardData.serve_cards || [], 'serve');
    document.getElementById('serveCardsOverlay').classList.add('active');
}

// Open hand cards overlay
function openHandCards() {
    const allHandCards = [
        ...(cardData.hand_cards.accepted_serve || []),
        ...(cardData.hand_cards.snap || []),
        ...(cardData.hand_cards.dare || []), 
        ...(cardData.hand_cards.spicy || []),
        ...(cardData.hand_cards.chance || [])
    ];
    populateCardGrid('handCardsGrid', allHandCards, 'hand');
    document.getElementById('handCardsOverlay').classList.add('active');
}

// Populate card grid
function populateCardGrid(gridId, cards, type) {
    const grid = document.getElementById(gridId);
    grid.innerHTML = '';
    
    if (cards.length === 0) {
        grid.innerHTML = '<p style="color: white; text-align: center; width: 100%;">No cards available</p>';
        return;
    }
    
    cards.forEach(card => {
        const cardElement = createCardElement(card, type);
        grid.appendChild(cardElement);
    });
}

// Create card element
function createCardElement(card, type) {
   const div = document.createElement('div');
   div.className = `game-card ${card.card_type || 'serve'}-card`;
   div.dataset.cardId = card.card_id || card.id;
   div.dataset.type = type;
   
   if (type === 'serve') {
       div.onclick = () => selectServeCard(card);
   } else if (type === 'hand') {
       div.onclick = () => selectHandCard(card);
   }
   
   div.innerHTML = `
       <div class="card-header">
           <div class="card-type">${getCardType(card.card_type)}</div>
           <div class="card-name">${card.card_name}</div>
           ${card.quantity > 1 ? `<div class="card-quantity">${card.quantity}x</div>` : ''}
       </div>
       <div class="card-description">${card.card_description}</div>
       <div class="card-meta">
           ${getCardDisplayInfo(card, type)}
       </div>
   `;
   
   return div;
}

function getCardType(type) {
    let iconDisplay = '<i class="fa-solid fa-square"></i>';
    let typeDisplay = 'Unknown Card Type';
    if(type === 'serve' || type === 'accepted_serve') {
        iconDisplay = '<i class="fa-solid fa-circle-arrow-up"></i>';
        typeDisplay = 'Serve';
    }
    if(type === 'chance') {
        iconDisplay = '<i class="fa-solid fa-circle-question"></i>';
        typeDisplay = 'Chance';
    }
    if(type === 'snap') {
        iconDisplay = '<i class="fa-solid fa-camera-retro"></i>';
        typeDisplay = 'Snap';
    }
    if(type === 'dare') {
        iconDisplay = '<i class="fa-solid fa-hand-point-right"></i>';
        typeDisplay = 'Dare';
    }
    if(type === 'spicy') {
        iconDisplay = '<i class="fa-solid fa-pepper-hot"></i>';
        typeDisplay = 'Spicy';
    }
    let cardTypeDisplay = iconDisplay + ' ' + typeDisplay;
    return cardTypeDisplay;
}

function getCardDisplayInfo(card, context = 'serve') {
    let badges = [];
    
    // Points
    if (card.card_points) {
        badges.push(`<span class="card-badge points">+${card.card_points}</span>`);
    }
    
    // Veto penalties (for serve cards)
    if (card.veto_subtract || card.veto_steal || card.veto_draw_chance || card.veto_draw_snap_dare || card.veto_draw_spicy) {
        let penalties = [];
        if (card.veto_subtract) penalties.push(`-${card.veto_subtract}`);
        if (card.veto_steal) penalties.push(`<i class="fa-solid fa-hand-paper"></i> ${card.veto_steal}`);
        if (card.veto_draw_chance) penalties.push(`<i class="fa-solid fa-circle-question"></i> ${card.veto_draw_chance}`);
        
        if (card.veto_draw_snap_dare) {
            let snapDareText = 'snap/dare';
            if (context === 'serve' && gameData.opponentPlayerGender) {
                snapDareText = gameData.opponentPlayerGender === 'female' ? '<i class="fa-solid fa-camera-retro"></i>' : '<i class="fa-solid fa-hand-point-right"></i>';
            } else if (context === 'hand' || context === 'pending') {
                snapDareText = gameData.currentPlayerGender === 'female' ? '<i class="fa-solid fa-camera-retro"></i>' : '<i class="fa-solid fa-hand-point-right"></i>';
            }
            penalties.push(`${snapDareText} ${card.veto_draw_snap_dare}`);
        }
        
        if (card.veto_draw_spicy) penalties.push(`<i class="fa-solid fa-pepper-hot"></i> ${card.veto_draw_spicy}`);
        
        if (penalties.length > 0) {
            badges.push(`<span class="card-badge penalty">${penalties.join('&nbsp;&nbsp;|&nbsp;&nbsp;')}</span>`);
        }
    }
    
    // Timer
    if (card.timer) {
        badges.push(`<span class="card-badge timer">${card.timer}min</span>`);
    }
    
    return badges.join('');
}

// Select serve card
function selectServeCard(card) {
    if (isCardSelected) return; // Prevent multiple selections
    
    selectedCard = card;
    isCardSelected = true;
    
    // Find the clicked card element
    const clickedCard = event.target.closest('.game-card');
    if (!clickedCard) return;
    
    // Add selection state to grid and card
    const grid = document.getElementById('serveCardsGrid');
    grid.classList.add('has-selection');
    clickedCard.classList.add('selected');
    
    // Add selection state to overlay
    const overlay = document.getElementById('serveCardsOverlay');
    overlay.classList.add('has-selection');
    
    // Show action buttons after animation
    setTimeout(() => {
        showServeSelectionActions();
    }, 400);
}

function showServeSelectionActions() {
    const actions = document.getElementById('serveSelectionActions');
    if (actions) {
        actions.classList.add('show');
    }
}

function hideServeSelectionActions() {
    const actions = document.getElementById('serveSelectionActions');
    if (actions) {
        actions.classList.remove('show');
    }
}

function clearServeSelection() {
    if (!isCardSelected) return;
    
    const grid = document.getElementById('serveCardsGrid');
    const overlay = document.getElementById('serveCardsOverlay');
    const selectedCardElement = document.querySelector('.game-card.selected');
    
    if (grid) grid.classList.remove('has-selection');
    if (overlay) overlay.classList.remove('has-selection');
    if (selectedCardElement) selectedCardElement.classList.remove('selected');
    
    hideServeSelectionActions();
    selectedCard = null;
    isCardSelected = false;
}

// Serve selected card
function serveSelectedCard() {
    if (!selectedCard) return;
    
    const opponentId = gameData.opponentPlayerId;
    const cardName = selectedCard.card_name;
    const selectedCardElement = document.querySelector('.game-card.selected');
    
    // Start serving animation
    if (selectedCardElement) {
        selectedCardElement.classList.add('serving');
    }
    
    
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=serve_card&card_id=${selectedCard.card_id || selectedCard.id}&to_player_id=${opponentId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Wait for animation to complete
            setTimeout(() => {
                hideServeSelectionActions();
                closeCardOverlay('serveCardsOverlay');
                loadCardData();
                showInAppNotification('Card Served!', `Served "${cardName}" to opponent`);
            }, 1000);
        } else {
            clearServeSelection();
            alert('Failed to serve card: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        clearServeSelection();
        console.error('Error serving card:', error);
        alert('Failed to serve card');
    });
}

// Manual card draw function
function manualDrawCard(cardType) {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=manual_draw&card_type=${cardType}&quantity=1`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            loadCardData();
            if (data.drawn_cards.length > 0) {
                showInAppNotification('Card Drawn!', `Drew: ${data.drawn_cards.join(', ')}`);
            } else {
                showInAppNotification('No Cards', `No ${cardType} cards available`);
            }
        } else {
            alert('Failed to draw card: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error drawing card:', error);
        alert('Failed to draw card');
    });
}

function openDrawPopover() {
    const popover = document.getElementById('drawPopover');
    if (popover) {
        // Check if popover is already active and close it
        if (popover.classList.contains('active')) {
            closeDrawPopover();
            return;
        }
        
        popover.classList.add('active');
        
        // Add click outside to close
        setTimeout(() => {
            document.addEventListener('click', closeDrawPopoverOnClickOutside);
        }, 100);
    }
}

function closeDrawPopover() {
    const popover = document.getElementById('drawPopover');
    if (popover) {
        popover.classList.remove('active');
        document.removeEventListener('click', closeDrawPopoverOnClickOutside);
    }
}

function closeDrawPopoverOnClickOutside(event) {
    const popover = document.getElementById('drawPopover');
    if (popover && !popover.contains(event.target)) {
        closeDrawPopover();
    }
}

function drawSingleCard(cardType) {
    closeDrawPopover();
    manualDrawCard(cardType);
}

// Select hand card
function selectHandCard(card) {
    if (isCardSelected) return; // Prevent multiple selections
    
    selectedHandCard = card;
    isCardSelected = true;
    
    // Find the clicked card element
    const clickedCard = event.target.closest('.game-card');
    if (!clickedCard) return;
    
    // Add selection state to grid and card
    const grid = document.getElementById('handCardsGrid');
    grid.classList.add('has-selection');
    clickedCard.classList.add('selected');
    
    // Add selection state to overlay
    const overlay = document.getElementById('handCardsOverlay');
    overlay.classList.add('has-selection');
    
    // Show action buttons after animation
    setTimeout(() => {
        showCardSelectionActions();
    }, 400);
}

// Show action buttons
function showCardSelectionActions() {
    const actions = document.getElementById('cardSelectionActions');
    if (actions) {
        actions.classList.add('show');
    }
}

// Hide action buttons
function hideCardSelectionActions() {
    const actions = document.getElementById('cardSelectionActions');
    if (actions) {
        actions.classList.remove('show');
    }
}

// Clear card selection
function clearCardSelection() {
    if (!isCardSelected) return;
    
    const grid = document.getElementById('handCardsGrid');
    const overlay = document.getElementById('handCardsOverlay');
    const selectedCard = document.querySelector('.game-card.selected');
    
    if (grid) grid.classList.remove('has-selection');
    if (overlay) overlay.classList.remove('has-selection');
    if (selectedCard) selectedCard.classList.remove('selected');
    
    hideCardSelectionActions();
    selectedHandCard = null;
    isCardSelected = false;
}

// Complete selected card
function completeSelectedCard() {
    if (!selectedHandCard) return;
    
    const cardName = selectedHandCard.card_name;
    const selectedCardElement = document.querySelector('.game-card.selected');
    
    // Start discard animation
    if (selectedCardElement) {
        selectedCardElement.classList.add('discarding');
    }
    
    
    // Make API call
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=complete_hand_card&card_id=${selectedHandCard.card_id || selectedHandCard.id}&player_card_id=${selectedHandCard.id}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Wait for animation to complete
            setTimeout(() => {
                hideCardSelectionActions();
                closeCardOverlay('handCardsOverlay');
                loadCardData();
                refreshGameData();
                
                if (data.points_awarded) {
                    showInAppNotification('Card Completed!', `Gained ${data.points_awarded} points from "${cardName}"`);
                } else {
                    showInAppNotification('Card Completed!', `Completed "${cardName}"`);
                }
            }, 1000);
        } else {
            clearCardSelection();
            alert('Failed to complete card: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        clearCardSelection();
        console.error('Error completing card:', error);
        alert('Failed to complete card');
    });
}

// Veto selected card
function vetoSelectedCard() {
    if (!selectedHandCard) return;
    
    const cardName = selectedHandCard.card_name;
    const selectedCardElement = document.querySelector('.game-card.selected');
    
    // Start discard animation
    if (selectedCardElement) {
        selectedCardElement.classList.add('discarding');
    }
    
    // Make API call
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=veto_hand_card&card_id=${selectedHandCard.card_id || selectedHandCard.id}&player_card_id=${selectedHandCard.id}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Wait for animation to complete
            setTimeout(() => {
                hideCardSelectionActions();
                closeCardOverlay('handCardsOverlay');
                loadCardData();
                refreshGameData();
                
                if (data.penalties && data.penalties.length > 0) {
                    showInAppNotification('Card Vetoed!', data.penalties.join(', '));
                } else {
                    showInAppNotification('Card Vetoed!', `Vetoed "${cardName}"`);
                }
            }, 1000);
        } else {
            clearCardSelection();
            alert('Failed to veto card: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        clearCardSelection();
        console.error('Error vetoing card:', error);
        alert('Failed to veto card');
    });
}

function closeCardOverlay(overlayId) {
    if (overlayId === 'serveCardsOverlay') {
        clearServeSelection();
    } else {
        clearCardSelection();
    }
    document.getElementById(overlayId).classList.remove('active');
    selectedCard = null;
}

function handleOverlayClick(event, overlayId) {
    if (event.target.classList.contains('card-overlay')) {
        if (isCardSelected) {
            if (overlayId === 'serveCardsOverlay') {
                clearServeSelection();
            } else {
                clearCardSelection();
            }
        } else {
            closeCardOverlay(overlayId);
        }
    }
}
$('.bottom-right-menu').on('click', function() {
    $(this).toggleClass('open');
});

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

// Notification enablement from modal
function enableNotificationsFromModal() {
    const button = document.getElementById('enableNotificationsModalBtn');
    const status = document.getElementById('notificationModalStatus');
    const statusText = document.getElementById('notificationModalStatusText');
    const testButton = document.getElementById('testNotificationBtn');
    
    if (!button || !status || !statusText) return;
    
    button.disabled = true;
    button.textContent = 'Requesting...';
    statusText.textContent = 'Requesting permission...';
    status.className = 'notification-status disabled';
    
    console.log('User requested to enable notifications from modal');
    
    // Check if notifications are supported
    if (!('Notification' in window)) {
        statusText.textContent = '❌ Notifications not supported in this browser';
        status.className = 'notification-status blocked';
        button.textContent = 'Not Supported';
        return;
    }
    
    // Request permission
    Notification.requestPermission().then((permission) => {
        console.log('Permission result:', permission);
        
        if (permission === 'granted') {
            statusText.textContent = '✅ Notifications are enabled!';
            status.className = 'notification-status enabled';
            button.textContent = 'Enabled ✓';
            button.style.background = '#51cf66';
            button.disabled = true;
            
            // Show test button
            if (testButton) {
                testButton.style.display = 'block';
            }
            
            // Try to set up Firebase messaging if available
            if (firebaseMessaging) {
                setupFirebaseMessaging();
            } else {
                console.log('Firebase messaging not available, using basic notifications');
            }
            
        } else if (permission === 'denied') {
            statusText.textContent = '❌ Notifications blocked. Please enable in browser settings and refresh the page.';
            status.className = 'notification-status blocked';
            button.textContent = 'Blocked';
            button.disabled = false;
            
        } else {
            statusText.textContent = '⚠️ Permission dismissed. Click to try again.';
            status.className = 'notification-status disabled';
            button.textContent = 'Enable Notifications';
            button.disabled = false;
        }
    }).catch((error) => {
        console.error('Error requesting permission:', error);
        statusText.textContent = '❌ Error requesting permission';
        status.className = 'notification-status blocked';
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
        const statusElements = [
            document.getElementById('notificationStatus'),
            document.getElementById('notificationModalStatusText')
        ];
        statusElements.forEach(element => {
            if (element) {
                element.innerHTML += '<br><span style="color: #ffd43b;">⚠️ Firebase push notifications need setup</span>';
            }
        });
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
                    const statusElements = [
                        document.getElementById('notificationStatus'),
                        document.getElementById('notificationModalStatusText')
                    ];
                    statusElements.forEach(element => {
                        if (element) {
                            if (element.id === 'notificationModalStatusText') {
                                element.textContent = '✅ Notifications are enabled! 🔥 Global notifications ready!';
                            } else {
                                element.innerHTML += '<br><span style="color: #51cf66;">🔥 Global notifications ready!</span>';
                            }
                        }
                    });
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

// Check notification status for modal
function checkNotificationStatusForModal() {
    const status = document.getElementById('notificationModalStatus');
    const statusText = document.getElementById('notificationModalStatusText');
    const button = document.getElementById('enableNotificationsModalBtn');
    const testButton = document.getElementById('testNotificationBtn');
    
    if (!status || !statusText || !button) return;
    
    if (!('Notification' in window)) {
        statusText.textContent = '❌ Notifications not supported in this browser';
        status.className = 'notification-status blocked';
        button.textContent = 'Not Supported';
        button.disabled = true;
        return;
    }
    
    if (Notification.permission === 'granted') {
        statusText.textContent = '✅ Notifications are enabled!';
        status.className = 'notification-status enabled';
        button.textContent = 'Enabled ✓';
        button.style.background = '#51cf66';
        button.disabled = true;
        
        // Show test button
        if (testButton) {
            testButton.style.display = 'block';
        }
        
        // Set up Firebase if available
        if (firebaseMessaging) {
            setupFirebaseMessaging();
        }
        
    } else if (Notification.permission === 'denied') {
        statusText.textContent = '❌ Notifications are blocked. Please enable in browser settings and refresh the page.';
        status.className = 'notification-status blocked';
        button.textContent = 'Blocked';
        button.disabled = false;
        
    } else {
        statusText.textContent = 'Click below to enable notifications for this game.';
        status.className = 'notification-status disabled';
        button.textContent = 'Enable Notifications';
        button.disabled = false;
    }
}

// Show notification in foreground
function showNotification(payload) {
    const title = payload.data.title || 'The Couples Quest';
    const body = payload.data.body || 'New notification';
    
    // Show browser notification if page is visible
    if (document.visibilityState === 'visible') {
        // Optional: Show in-app notification instead
        showInAppNotification(title, body);
    }
}

// Show in-app notification
function showInAppNotification(title, body) {
    // Create a simple in-app notification
    let $notification = $('.iAN'),
    $title = $notification.find('.iAN-title'),
    $body = $notification.find('.iAN-body'),
    alertSound = new Audio('/ian.m4r');

    $title.text(title);
    $body.text(body);
    $notification.addClass('show');
    alertSound.play();

    
    // Remove after 10 seconds
    setTimeout(() => {
        $notification.removeClass('show');
        $title.empty();
        $body.empty();
    }, 10000);
}

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

    $('.player-name').addClass('hide');
    
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

    $('.player-name').removeClass('hide');
    
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

// Open notification modal
function openNotifyModal() {
    const modal = document.getElementById('notifyModal');
    if (modal) {
        modal.classList.add('active');
        // Check notification status when modal opens
        setTimeout(checkNotificationStatusForModal, 100);
    }
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

function openEndGameModal() {
    const modal = document.getElementById('endGameModal');
    if(modal) {
        modal.classList.add('active');
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('active');
    }
}

// Score update function with animation
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
            // Find the score element
            const players = gameData.players || [];
            let targetGender = '';
            
            // Get updated data to find which player was modified
            fetch('game.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_game_data'
            })
            .then(response => response.json())
            .then(gameDataUpdate => {
                if (gameDataUpdate.players) {
                    const updatedPlayer = gameDataUpdate.players.find(p => p.id == playerId);
                    if (updatedPlayer) {
                        animateScoreChange(updatedPlayer.gender, updatedPlayer.score, points);
                    }
                    
                    // Update all scores
                    gameDataUpdate.players.forEach(player => {
                        const scoreElement = document.querySelector(`.player-score.${player.gender} .player-score-value`);
                        if (scoreElement) {
                            scoreElement.textContent = player.score;
                        }
                    });
                    
                    // Update timers
                    if (gameDataUpdate.timers) {
                        updateTimerDisplay(gameDataUpdate.timers);
                    }
                    if (gameDataUpdate.gametime) {
                        $('.game-timer').text(gameDataUpdate.gametime);
                    }
                }
            });
        } else {
            alert('Failed to update score. Please try again.');
        }
    })
    .catch(error => {
        console.error('Error updating score:', error);
        alert('Failed to update score. Please try again.');
    });
}

// New function to animate score changes
function animateScoreChange(playerGender, newScore, pointsChanged) {
    const scoringPlayer = document.querySelector(`.player-score.${playerGender}`),
    scoreElement = scoringPlayer.querySelector('.player-score-value'),
    animateElement = scoringPlayer.querySelector('.player-score-animation');
    if (!scoreElement) return;
    
    // Add counting class for scale effect
    scoreElement.classList.add('counting');
    setTimeout(() => {
        scoreElement.classList.remove('counting');
    }, 1700);
    
    
    
    if (pointsChanged > 0) {
        animateElement.textContent = `+${pointsChanged}`;
    } else {
        animateElement.textContent = `${pointsChanged}`;
    }

    animateElement.classList.add('animate');
    
    // Animate the score counting
    const oldScore = newScore - pointsChanged;
    animateCounter(scoreElement, oldScore, newScore, 2000);
    
    // Remove animation element
    setTimeout(() => {
        if (animateElement) {
            animateElement.textContent = '';
            animateElement.classList.remove('animate');
        }
    }, 1800);
}

// Counter animation function
function animateCounter(element, start, end, duration) {
    const startTime = performance.now();
    const difference = end - start;
    
    function updateCounter(currentTime) {
        const elapsed = currentTime - startTime;
        const progress = Math.min(elapsed / duration, 1);
        
        // Easing function for smooth animation
        const easeOutQuart = 1 - Math.pow(1 - progress, 4);
        const current = Math.round(start + (difference * easeOutQuart));
        
        element.textContent = current;
        
        if (progress < 1) {
            requestAnimationFrame(updateCounter);
        } else {
            element.textContent = end; // Ensure final value is exact
        }
    }
    
    requestAnimationFrame(updateCounter);
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

function showTimerDeleteModal(timerId, description) {
    selectedTimerId = timerId;
    
    // Create modal if it doesn't exist
    let modal = document.getElementById('timerDeleteModal');
    
    document.getElementById('timerDeleteDescription').textContent = `"${description}"`;
    modal.classList.add('active');
}

function hideTimerDeleteModal() {
    const modal = document.getElementById('timerDeleteModal');
    if (modal) {
        modal.classList.remove('active');
    }
    selectedTimerId = null;
}

function deleteSelectedTimer() {
    if (!selectedTimerId) return;
    
    fetch('game.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=delete_timer&timer_id=${selectedTimerId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            refreshGameData();
        } else {
            alert('Failed to delete timer.');
        }
        hideTimerDeleteModal();
    })
    .catch(error => {
        console.error('Error deleting timer:', error);
        alert('Failed to delete timer.');
        hideTimerDeleteModal();
    });
}

// Bump notification function
function sendBump() {
    let $bubble = $('.bump-send-display');
    $bubble.text('Sending Bump...').addClass('show');
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
            $bubble.text(data.message);
        } else {
            $bubble.text('Failed to send bump');
        }
    })
    .catch(error => {
        console.error('Error sending bump:', error);
        $bubble.text('Bump Failed');
    });
    setTimeout(function() {
        $bubble.text('').removeClass('show');
    }, 5000);
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
            
            const time = new Date(item.timestamp + 'Z');
            const options = {
                month: 'long',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
                hour12: true
            };
            const formatter = new Intl.DateTimeFormat('en-US', options);
            const formattedDate = formatter.format(time);
            const change = item.points_changed < 0 ? Math.abs(item.points_changed) : item.points_changed;
            var modifiedWord = 'added',
            modifiedWordNext = 'to';
            if(item.points_changed < 0) {
                modifiedWord = 'subtracted',
                modifiedWordNext = 'from';
            }
            
            div.innerHTML = `
                <div class="history-time">${formattedDate}</div>
                <div class="history-change">
                    ${item.modified_by_name} ${modifiedWord} ${change} points ${modifiedWordNext} ${item.player_name}'s score
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

// Refresh game data function (modified to not interfere with animations)
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
        // Check if game has expired and auto-end it
        if (data.game_expired && gameData.gameStatus === 'active') {
            fetch('game.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=end_game'
            })
            .then(response => response.json())
            .then(endData => {
                if (endData.success) {
                    location.reload();
                }
            });
            return; // Don't process other updates
        }
        // Only update scores if they're not currently animating
        if (data.players) {
            data.players.forEach(player => {
                const scoreElement = document.querySelector(`.player-score.${player.gender} .player-score-value`);
                if (scoreElement && !scoreElement.classList.contains('counting')) {
                    scoreElement.textContent = player.score;
                }
            });
        }

        if(data.gametime === 'Game Ended') {
            location.reload();
        }
        
        // Update timers
        if (data.timers) {
            updateTimerDisplay(data.timers);
        }
        if (data.gametime) {
            $('.game-timer').text(data.gametime);
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
        div.onclick = () => showTimerDeleteModal(timer.id, timer.description);
        
        // Treat database time as UTC
        const endTime = new Date(timer.end_time + 'Z');
        const now = new Date();
        const diff = endTime - now;
        
        if (diff > 0) {
            const hours = Math.floor(diff / (1000 * 60 * 60));
            const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));

            const title = '<span class="timer-title">' + timer.description + '</span>';
            
            div.innerHTML = title.concat(hours > 0 ? `${hours}h ${minutes}m` : `${minutes}m`);
            
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

// Hard refresh function
function hardRefresh() {
    window.location.reload(true);
}

// End game function
function endGame() {
    fetch('game.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=end_game'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Failed to end game: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error ending game:', error);
        alert('Failed to end game. Please try again.');
    });
}

function readyForNewGame() {
    const button = document.getElementById('newGameBtn');
    button.disabled = true;
    button.textContent = 'Getting Ready...';
    
    fetch('game.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=ready_for_new_game'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (data.redirect) {
                // Both players ready, redirect to new game
                window.location.reload();
            } else {
                // This player is ready, wait for opponent
                button.textContent = 'Ready ✓';
                button.style.background = '#51cf66';
                
                // Start polling for opponent
                startNewGamePolling();
            }
        } else {
            button.disabled = false;
            button.textContent = 'Start New Game';
            alert('Failed to ready for new game: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error readying for new game:', error);
        button.disabled = false;
        button.textContent = 'Start New Game';
        alert('Failed to ready for new game.');
    });
}

function startNewGamePolling() {
    const pollInterval = setInterval(() => {
        fetch('game.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=get_new_game_status'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.game_reset) {
                clearInterval(pollInterval);
                window.location.reload();
            }
        })
        .catch(error => {
            console.error('Error polling new game status:', error);
        });
    }, 5000);
}

function openDiceOverlay() {
    const overlay = document.getElementById('diceOverlay');
    if (overlay) {
        overlay.classList.add('active');
        
        // Set dice color based on current player's gender
        if (typeof gameData !== 'undefined' && gameData.currentPlayerGender) {
            setDiceColor(gameData.currentPlayerGender);
        }
        
        // Initialize dice to face 1 position
        initializeDicePosition();
    }
}

function initializeDicePosition() {
    const die1 = document.getElementById('die1');
    const die2 = document.getElementById('die2');
    
    if (die1) {
        setDieRotation(die1, 1); // Start at face 1
    }
    if (die2) {
        setDieRotation(die2, 1); // Start at face 1
    }
}

function closeDiceOverlay() {
    const overlay = document.getElementById('diceOverlay');
    if (overlay) {
        overlay.classList.remove('active');
    }
}

function setDiceCount(count) {
    currentDiceCount = count;
    
    // Update button states
    document.querySelectorAll('.dice-count-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    event.target.classList.add('active');
    
    // Update dice container
    const container = document.getElementById('diceContainer');
    if (count === 2) {
        container.classList.add('two-dice');
    } else {
        container.classList.remove('two-dice');
    }
    
    // Clear previous result
    const resultDiv = document.getElementById('diceResult');
    if (resultDiv) {
        resultDiv.classList.remove('show');
    }
}

function rollDice() {
    if (isDiceRolling) return;
    
    isDiceRolling = true;
    const rollButton = document.getElementById('rollButton');
    
    if (rollButton) {
        rollButton.disabled = true;
        rollButton.textContent = 'Rolling...';
    }
    
    // Generate random values
    const die1Value = Math.floor(Math.random() * 6) + 1;
    const die2Value = currentDiceCount === 2 ? Math.floor(Math.random() * 6) + 1 : 0;
    
    const die1 = document.getElementById('die1');
    const die2 = document.getElementById('die2');
    
    if (die1) {
        const extraSpins1 = Math.floor(Math.random() * 4) + 1; // extra spins
        const finalRotation1 = getDieRotationForValue(die1Value);
        die1.style.transform = `rotateX(${finalRotation1.x + (extraSpins1 * 360)}deg) rotateY(${finalRotation1.y + (extraSpins1 * 360)}deg)`;
    }
    
    if (currentDiceCount === 2 && die2) {
        const extraSpins2 = Math.floor(Math.random() * 4) + 3;
        const finalRotation2 = getDieRotationForValue(die2Value);
        die2.style.transform = `rotateX(${finalRotation2.x + (extraSpins2 * 360)}deg) rotateY(${finalRotation2.y + (extraSpins2 * 360)}deg)`;
    }
        
    // Show result
    setTimeout(() => {
        if (rollButton) {
            rollButton.disabled = false;
            rollButton.textContent = 'Roll Again';
        }
        isDiceRolling = false;
    }, 300);
}

function getDieRotationForValue(value) {
    const rotations = {
        1: { x: 0, y: 0 },       // front
        2: { x: -90, y: 0 },     // top
        3: { x: 0, y: 90 },      // right
        4: { x: 0, y: -90 },     // left
        5: { x: 90, y: 0 },      // bottom
        6: { x: 0, y: 180 }      // back
    };
    return rotations[value];
}

function setDieRotation(die, value) {
    const rotation = getDieRotationForValue(value);
    die.style.transform = `rotateX(${rotation.x}deg) rotateY(${rotation.y}deg)`;
}

function setDiceColor(gender) {
    document.querySelectorAll('.die').forEach(die => {
        die.className = `die ${gender}`;
        if (die.id === 'die2') {
            die.classList.add('two');
        }
    });
}

// Mode selection handler
function setupModeButtons() {
    document.querySelectorAll('.mode-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const mode = this.dataset.mode;
            
            fetch('game.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=set_game_mode&mode=' + mode
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Add body class for digital mode
                    if (mode === 'digital') {
                        document.body.classList.add('digital');
                    }
                    location.reload();
                } else {
                    alert('Failed to set game mode. Please try again.');
                }
            })
            .catch(error => {
                console.error('Error setting mode:', error);
                alert('Failed to set game mode. Please try again.');
            });
        });
    });
}

function initializeDigitalCards() {
    if (document.body.classList.contains('digital')) {
        loadCardData();
        
        // Refresh card data periodically
        setInterval(() => {
            loadCardData();
        }, 15000); // Every 15 seconds
    }
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
    setupModeButtons();
    setupModalHandlers();
    setupAnimatedMenu(); // New animated menu system
    initializeDigitalCards();

    // Check if we're waiting on an opponent
    if (document.querySelector('.waiting-screen.no-opponent')) {
        console.log('Starting opponent check polling...');
        
        function checkForOpponent() {
            fetch('game.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=check_game_status'
            })
            .then(response => response.json())
            .then(data => {
                console.log('Game status check:', data);
                
                // If game status changed or opponent joined, reload
                if (data.success && (data.status !== 'waiting' || data.player_count >= 2)) {
                    console.log('Game status changed or opponent joined, reloading...');
                    window.location.reload();
                }
            })
            .catch(error => {
                console.error('Error checking game status:', error);
            });
        }
        
        // Check every 10 seconds
        const statusInterval = setInterval(checkForOpponent, 5000);
        
        // Clear interval when page unloads
        window.addEventListener('beforeunload', () => {
            clearInterval(statusInterval);
        });
    }

    if (document.querySelector('.waiting-screen.mode-selection')) {
        console.log('Starting mode selection polling...');
        
        function checkForModeSelection() {
            fetch('game.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=check_game_status'
            })
            .then(response => response.json())
            .then(data => {
                console.log('Game status check:', data);
                
                // If mode has been selected, reload
                if (data.success && data.game_mode) {
                    console.log('Game mode selected, reloading...');
                    window.location.reload();
                }
            })
            .catch(error => {
                console.error('Error checking game status:', error);
            });
        }
        
        // Check every 5 seconds
        const modeInterval = setInterval(checkForModeSelection, 5000);
        
        // Clear interval when page unloads
        window.addEventListener('beforeunload', () => {
            clearInterval(modeInterval);
        });
    }

    // Check if game duration has been chosen
    if (document.querySelector('.waiting-screen.duration')) {
        console.log('Starting game status polling...');
        
        function checkForStatusChange() {
            fetch('game.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=check_game_status'
            })
            .then(response => response.json())
            .then(data => {
                console.log('Game status check:', data);
                
                // If game status changed or opponent joined, reload
                if (data.success && (data.status !== 'waiting')) {
                    console.log('Game status changed, reloading...');
                    window.location.reload();
                }
            })
            .catch(error => {
                console.error('Error checking game status:', error);
            });
        }
        
        // Check every 10 seconds
        const statusInterval = setInterval(checkForStatusChange, 5000);
        
        // Clear interval when page unloads
        window.addEventListener('beforeunload', () => {
            clearInterval(statusInterval);
        });
    }
    
    // Start periodic refresh for active games
    if (gameData.gameStatus === 'active') {
        refreshGameData();
        setInterval(refreshGameData, 10000); // Refresh every 10 seconds
    }
});

// Confetti at game end
window.addEventListener('load', function() {
    const confettiDiv = document.querySelector('.confetti');
    
    // Exit if confetti div doesn't exist
    if (!confettiDiv) return;
    
    console.log('found confetti div. creating canvas element.');
    // Create canvas for confetti
    const canvas = document.createElement('canvas');
    
    // Append canvas to confetti div
    confettiDiv.appendChild(canvas);
    
    console.log('initializing confetti');
    // Initialize confetti with canvas
    const myConfetti = confetti.create(canvas, {
        resize: true,
        useWorker: true
    });
    
    // Confetti colors
    const colors = ['#fff', '#FD9BC7', '#4BC0D9'];
    
    // Function to launch confetti from bottom
    function launchConfetti() {
        myConfetti({
            particleCount: 30,
            startVelocity: 60,
            angle: 90,
            spread: 45,
            origin: { x: Math.random(), y: 1 },
            colors: colors,
            gravity: 0.9,
            scalar: 0.8,
            drift: 0
        });
    }
    console.log('starting confetti');
    // Start confetti animation
    setInterval(launchConfetti, 2000);

});

// Make functions globally available
window.openServeCards = openServeCards;
window.clearServeSelection = clearServeSelection;
window.showServeSelectionActions = showServeSelectionActions;
window.hideServeSelectionActions = hideServeSelectionActions;
window.openHandCards = openHandCards;
window.manualDrawCard = manualDrawCard;
window.openDrawPopover = openDrawPopover;
window.closeDrawPopover = closeDrawPopover;
window.drawSingleCard = drawSingleCard;
window.selectHandCard = selectHandCard;
window.completeSelectedCard = completeSelectedCard;
window.vetoSelectedCard = vetoSelectedCard;
window.clearCardSelection = clearCardSelection;
window.handleOverlayClick = handleOverlayClick;
window.closeCardOverlay = closeCardOverlay;
window.openNotifyModal = openNotifyModal;
window.openTimerModal = openTimerModal;
window.openHistoryModal = openHistoryModal;
window.closeModal = closeModal;
window.createTimer = createTimer;
window.sendBump = sendBump;
window.testNotification = testNotification;
window.enableNotifications = enableNotifications;
window.enableNotificationsFromModal = enableNotificationsFromModal;
window.hardRefresh = hardRefresh;
window.openEndGameModal = openEndGameModal;
window.endGame = endGame;
window.showTimerDeleteModal = showTimerDeleteModal;
window.hideTimerDeleteModal = hideTimerDeleteModal;
window.deleteSelectedTimer = deleteSelectedTimer;
window.readyForNewGame = readyForNewGame;
window.openDiceOverlay = openDiceOverlay;
window.closeDiceOverlay = closeDiceOverlay;
window.setDiceCount = setDiceCount;
window.rollDice = rollDice;