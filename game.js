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

let wheelPrizes = [];
let isWheelSpinning = false;

let actionSound = new Audio('data:audio/mpeg;base64,SUQzBAAAAAABEVRYWFgAAAAtAAADY29tbWVudABCaWdTb3VuZEJhbmsuY29tIC8gTGFTb25vdGhlcXVlLm9yZwBURU5DAAAAHQAAA1N3aXRjaCBQbHVzIMKpIE5DSCBTb2Z0d2FyZQBUSVQyAAAABgAAAzIyMzUAVFNTRQAAAA8AAANMYXZmNTcuODMuMTAwAAAAAAAAAAAAAAD/80DEAAAAA0gAAAAATEFNRTMuMTAwVVVVVVVVVVVVVUxBTUUzLjEwMFVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVf/zQsRbAAADSAAAAABVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVf/zQMSkAAADSAAAAABVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVV');

$(document).ready(function() {
    let hasClicked = false;
    
    $(document).on('click', function(event) {
        if (!hasClicked) {
            hasClicked = true;
            actionSound.src = 'data:audio/mpeg;base64,SUQzBAAAAAABEVRYWFgAAAAtAAADY29tbWVudABCaWdTb3VuZEJhbmsuY29tIC8gTGFTb25vdGhlcXVlLm9yZwBURU5DAAAAHQAAA1N3aXRjaCBQbHVzIMKpIE5DSCBTb2Z0d2FyZQBUSVQyAAAABgAAAzIyMzUAVFNTRQAAAA8AAANMYXZmNTcuODMuMTAwAAAAAAAAAAAAAAD/80DEAAAAA0gAAAAATEFNRTMuMTAwVVVVVVVVVVVVVUxBTUUzLjEwMFVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVf/zQsRbAAADSAAAAABVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVf/zQMSkAAADSAAAAABVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVVV';
            actionSound.play();
            console.log('sounds enabled');
            $(document).off('click');
        }
    });
});

function setSoundEnabled(enabled) {
    localStorage.setItem('couples_quest_sound_enabled', enabled ? 'true' : 'false');
}

function isSoundEnabled() {
    return localStorage.getItem('couples_quest_sound_enabled') !== 'false'; // default true
}

function playSoundIfEnabled(soundFile) {
    if (isSoundEnabled()) {
        console.log('playing sound');
        actionSound.src = soundFile;
        actionSound.play().catch(() => {});
    }
}

function toggleSound() {
    const enabled = !isSoundEnabled();
    setSoundEnabled(enabled);
    updateSoundToggleText();
}

function updateSoundToggleText() {
    const toggleText = document.getElementById('soundToggleText');
    const icon = document.querySelector('.flyout-menu-item.sound i');
    if (isSoundEnabled()) {
        toggleText.textContent = 'Sound: On';
        icon.className = 'fa-solid fa-volume-high';
    } else {
        toggleText.textContent = 'Sound: Off';
        icon.className = 'fa-solid fa-volume-slash';
    }
}

function setOverlayActive(yes) {
    if(yes) {
        $('body').addClass('overlay-active');
    } else {
        $('body').removeClass('overlay-active');
    }
}

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

            console.log('Active modifiers:', data.active_modifiers);

            updateHandBadge();
            updateOpponentHandDisplay();
            updateBlockingStatus(data);
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

function updateHandBadge() {
    const handCount = (cardData.hand_cards.accepted_serve?.length || 0) +
                     (cardData.hand_cards.snap?.length || 0) +
                     (cardData.hand_cards.dare?.length || 0) +
                     (cardData.hand_cards.spicy?.length || 0) +
                     (cardData.hand_cards.chance?.length || 0);
    
    const handMenuItem = document.querySelector('.menu-item[onclick="openHandCards()"]');
    if (handMenuItem) {
        let badge = handMenuItem.querySelector('.hand-badge');
        if (handCount > 0) {
            if (!badge) {
                badge = document.createElement('div');
                badge.className = 'hand-badge';
                handMenuItem.appendChild(badge);
            }
            badge.textContent = handCount;
        } else if (badge) {
            badge.remove();
        }
    }

    // Update app icon badge
    updateAppBadge(handCount);
}

function updateOpponentHandDisplay() {
    if (!document.body.classList.contains('digital')) return;
    
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get_opponent_hand_counts'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const container = document.getElementById('opponent-hand-counts');
            if (container) {
                container.innerHTML = '';
                
                const iconMap = {
                    'accepted_serve': 'fa-circle-arrow-up',
                    'snap': 'fa-camera-retro', 
                    'dare': 'fa-hand-point-right',
                    'spicy': 'fa-pepper-hot',
                    'chance': 'fa-circle-question'
                };
                
                Object.entries(data.counts).forEach(([type, count]) => {
                    if (count > 0 && iconMap[type]) {
                        const badge = document.createElement('div');
                        badge.className = 'opponent-hand-badge';
                        badge.innerHTML = `<i class="fa-solid ${iconMap[type]}"></i> ${count}`;
                        container.appendChild(badge);
                    }
                });
            }
        }
    });
}

// Open serve cards overlay
function openServeCards() {
    const grid = document.getElementById('serveCardsGrid');
    populateCardGrid('serveCardsGrid', cardData.serve_cards || [], 'serve');
    // Add count display at top of grid
    let countDisplay = grid.querySelector('.serve-count-display');
    if (!countDisplay) {
        countDisplay = document.createElement('div');
        countDisplay.className = 'serve-count-display';
        grid.appendChild(countDisplay);
    }
    countDisplay.textContent = `${cardData.serve_count || 0} Cards`;
    document.getElementById('serveCardsOverlay').classList.add('active');
    setOverlayActive(true);
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
    setOverlayActive(true);
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

    // Check for active chance effects on this card type
    if (selectedHandCard && selectedHandCard.id === card.id) {
        // Check for active modifiers
        fetch('game.php', {
            method: 'POST', 
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=get_card_modifiers&card_type=${card.card_type}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.modifiers) {
                data.modifiers.forEach(mod => {
                    const modifierBadge = document.createElement('span');
                    modifierBadge.className = 'card-badge modifier';
                    modifierBadge.textContent = mod;
                    // Add to card display
                });
            }
        });
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

    // Add veto penalty badges for snap/dare cards
    if (card.card_type === 'snap' || card.card_type === 'dare') {
        badges.push(`<span class="card-badge penalty">-3</span>`);
    }

    if (card.extra_spicy == 1) {
        badges.push(`<span class="card-badge points">Spicy+</span>`);
    }
    
    // Timer
    if (card.timer) {
        badges.push(`<span class="card-badge timer">${card.timer}min</span>`);
    }

    // Modifier badges (only for hand cards)
    if (context === 'hand' && cardData.active_modifiers) {
        if (cardData.active_modifiers[card.card_type]) {
            badges.push(`<span class="card-badge modifier">${cardData.active_modifiers[card.card_type]}</span>`);
        }
        if (cardData.active_modifiers[card.card_type + '_veto']) {
            badges.push(`<span class="card-badge modifier">${cardData.active_modifiers[card.card_type + '_veto']}</span>`);
        }
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
        setTimeout(()=> {
            playSoundIfEnabled('/card-served.m4r');
        }, 500);
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

function completeChanceCard(playerCardId) {
    if (!selectedHandCard) return;
    
    const cardName = selectedHandCard.card_name;
    const selectedCardElement = document.querySelector('.game-card.selected');
    
    if (selectedCardElement) {
        selectedCardElement.classList.add('discarding');
    }
    
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=complete_chance_card&player_card_id=${playerCardId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            setTimeout(() => {
                hideCardSelectionActions();
                closeCardOverlay('handCardsOverlay');
                loadCardData();
                refreshGameData();
            }, 1000);
        } else {
            clearCardSelection();
            alert('Failed to complete chance card: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        clearCardSelection();
        console.error('Error completing chance card:', error);
        alert('Failed to complete chance card');
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
            if (data.drawn_cards.length > 0 && data.card_details) {
                showCardDrawAnimation(data.card_details);
            } else if (data.drawn_cards.length > 0) {
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

function showCardDrawAnimation(cardData) {
    const overlay = document.getElementById('cardDrawOverlay');
    const deckContainer = document.getElementById('deckContainer');
    const drawnCard = document.getElementById('drawnCard');
    
    // Update deck card spans based on card type
    const deckCards = deckContainer.querySelectorAll('.deck-card span');
    let iconClass, cardTypeText;
    
    switch(cardData.card_type) {
        case 'chance':
            iconClass = 'fa-circle-question';
            cardTypeText = 'Chance';
            break;
        case 'snap':
            iconClass = 'fa-camera-retro';
            cardTypeText = 'Snap';
            break;
        case 'dare':
            iconClass = 'fa-hand-point-right';
            cardTypeText = 'Dare';
            break;
        case 'spicy':
            iconClass = 'fa-pepper-hot';
            cardTypeText = 'Spicy';
            break;
        default:
            iconClass = 'fa-circle-question';
            cardTypeText = 'Chance';
    }
    
    deckCards.forEach(span => {
        span.innerHTML = `<i class="fa-solid ${iconClass}"></i>${cardTypeText}`;
    });
    
    // Set card content
    document.getElementById('drawCardType').innerHTML = getCardType(cardData.card_type);
    document.getElementById('drawCardName').textContent = cardData.card_name;
    document.getElementById('drawCardDescription').textContent = cardData.card_description;
    
    // Add points badge if applicable
    const metaContainer = document.getElementById('drawCardMeta');
    metaContainer.innerHTML = '';
    if (cardData.card_points) {
        const pointsBadge = document.createElement('span');
        pointsBadge.className = 'card-badge points';
        pointsBadge.textContent = `+${cardData.card_points}`;
        metaContainer.appendChild(pointsBadge);
    }
    
    // Reset states
    setOverlayActive(true);
    overlay.classList.add('active');
    deckContainer.classList.remove('shuffling');
    drawnCard.classList.remove('flip-in', 'slide-out');
    
    // Step 1: Show deck scaling up
    setTimeout(() => {
        deckContainer.classList.add('show');
    }, 100);
    
    // Step 2: Start shuffle animation
    setTimeout(() => {
        deckContainer.classList.add('shuffling');
    }, 400);
    
    // Step 3: Hide deck and show card flipping in
    setTimeout(() => {
        deckContainer.classList.remove('show', 'shuffling');
        drawnCard.classList.add('flip-in');
        playSoundIfEnabled('/card-drawn.m4r');
    }, 1400);
    
    // Step 4: Slide card out (show card longer)
    setTimeout(() => {
        drawnCard.classList.add('slide-out');
    }, 6400);

    // For chance cards with immediate effects, trigger after animation
    if (cardData.card_type === 'chance') {
        setTimeout(() => {
            if (cardData.score_add) {
                updateScore(gameData.currentPlayerId, cardData.score_add);
            }
            if (cardData.score_subtract) {
                updateScore(gameData.currentPlayerId, -cardData.score_subtract);
            }
            if (cardData.score_steal) {
                updateScore(gameData.opponentPlayerId, cardData.score_steal);
                updateScore(gameData.currentPlayerId, -cardData.score_steal);
            }
            if (cardData.draw_snap_dare) {
                const drawType = gameData.currentPlayerGender === 'female' ? 'snap' : 'dare';
                manualDrawCard(drawType);
            }
            if (cardData.draw_spicy) {
                manualDrawCard('spicy');
            }
        }, 7200);
    }
    
    // Step 5: Hide overlay
    setTimeout(() => {
        overlay.classList.remove('active');
        drawnCard.classList.remove('flip-in', 'slide-out');
        setOverlayActive(false);
    }, 7200);
}

function updateDrawPopoverCounts() {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get_deck_counts'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const buttons = document.querySelectorAll('.draw-card-btn');
            buttons.forEach(button => {
                const cardType = button.onclick.toString().match(/drawSingleCard\('(\w+)'\)/)?.[1];
                if (cardType && data.counts[cardType]) {
                    let countSpan = button.querySelector('.deck-count');
                    if (!countSpan) {
                        countSpan = document.createElement('div');
                        countSpan.className = 'deck-count';
                        button.appendChild(countSpan);
                    }
                    countSpan.textContent = `${data.counts[cardType]} Cards`;
                }
            });
        }
    });
}

function updateBlockingStatus(cardData) {
    if (cardData.has_blocking) {
        // Show blocking indicator
        let indicator = document.getElementById('blocking-indicator');
        if (!indicator) {
            indicator = document.createElement('div');
            indicator.id = 'blocking-indicator';
            indicator.className = 'blocking-indicator';
            indicator.innerHTML = '<i class="fa-solid fa-exclamation-triangle"></i> A card is blocking Serve card completion';
            document.body.appendChild(indicator);
        }
        indicator.style.display = 'block';
    } else {
        const indicator = document.getElementById('blocking-indicator');
        if (indicator) {
            indicator.style.display = 'none';
        }
    }
}

function openDrawPopover() {
    closeDicePopover();
    const popover = document.getElementById('drawPopover');
    if (popover) {
        if (popover.classList.contains('active')) {
            closeDrawPopover();
            return;
        }
        handleWheelButtonState(false);
        popover.classList.add('active');
        updateDrawPopoverCounts(); // Add this line
        
        setTimeout(() => {
            document.addEventListener('click', closeDrawPopoverOnClickOutside);
        }, 100);
    }
}

function closeDrawPopover() {
    const popover = document.getElementById('drawPopover');
    if (popover) {
        handleWheelButtonState(true);
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
    if (isCardSelected) return;
    
    selectedHandCard = card;
    isCardSelected = true;
    
    const clickedCard = event.target.closest('.game-card');
    if (!clickedCard) return;
    
    // Check for active modifiers and add badge
    if (['snap', 'dare', 'spicy'].includes(card.card_type)) {
        fetch('game.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=get_active_effects`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const modifierEffect = data.effects.find(e => e.effect_type === card.card_type + '_modify');
                if (modifierEffect) {
                    const badge = document.createElement('div');
                    badge.className = 'modifier-badge';
                    badge.textContent = modifierEffect.effect_value === 'double' ? 'Do Twice' : 'Modified';
                    clickedCard.appendChild(badge);
                }
            }
        });
    }
    
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
        actions.innerHTML = '';
        
        if (selectedHandCard.card_type === 'chance') {
            
            // Check if chance card can be auto-completed
            const hasModifiers = selectedHandCard.challenge_modify == 1 || selectedHandCard.snap_modify == 1 || 
                selectedHandCard.dare_modify == 1 || selectedHandCard.spicy_modify == 1 || 
                (selectedHandCard.veto_modify && selectedHandCard.veto_modify !== 'none');
            
            // Special case: dice + timer cards can be manually completed
            const isDiceTimerCard = selectedHandCard.roll_dice == 1 && selectedHandCard.timer;
            
            // Cards with timer but no dice roll are auto-only
            const isTimerOnlyCard = selectedHandCard.timer && !selectedHandCard.roll_dice;
            
            if ((hasModifiers || isTimerOnlyCard) && !isDiceTimerCard) {
                actions.innerHTML = `<button class="btn btn-complete" disabled title="Auto-completes when conditions are met">Complete (Auto)</button>`;
            } else {
                actions.innerHTML = `<button class="btn btn-complete" onclick="completeChanceCard(${selectedHandCard.id})">Complete</button>`;
            }
        } else {
            // Check if this is a win/loss card
            if (selectedHandCard.win_loss == 1 || selectedHandCard.win_loss === true) {
                actions.innerHTML = `
                    <button class="btn btn-complete" onclick="winSelectedCard()">Win</button>
                    <button class="btn btn-veto" onclick="loseSelectedCard()">Loss</button>
                `;
            } else {
                // Regular complete/veto logic
                const hasVetoPenalty = selectedHandCard.veto_subtract || selectedHandCard.veto_steal || 
                                    selectedHandCard.veto_draw_chance || selectedHandCard.veto_draw_snap_dare || 
                                    selectedHandCard.veto_draw_spicy || 
                                    ['snap', 'dare', 'spicy'].includes(selectedHandCard.card_type);
                
                const vetoButton = hasVetoPenalty ? 
                    `<button class="btn btn-veto" onclick="vetoSelectedCard()">Veto</button>` :
                    `<button class="btn btn-veto" disabled>No Veto</button>`;
                
                actions.innerHTML = `
                    <button class="btn btn-complete" onclick="completeSelectedCard()">Complete</button>
                    ${vetoButton}
                `;
            }
        }
        
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
        playSoundIfEnabled('/card-completed.m4r');
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

                setTimeout(() => {
                    // Handle score changes if any
                    if (data.score_changes && data.score_changes.length > 0) {
                        data.score_changes.forEach(change => {
                            updateScore(change.player_id, change.points);
                        });
                    } else if (data.points_awarded) {
                        updateScore(gameData.currentPlayerId, data.points_awarded);
                    }
                }, 1500);
            }, 1100);
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
        playSoundIfEnabled('/card-vetoed.m4r');
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
                setTimeout(() => {
                    // Handle score changes and card draws
                    let animationDelay = 0;

                    if (data.score_changes && data.score_changes.length > 0) {
                        data.score_changes.forEach(change => {
                            setTimeout(() => {
                                updateScore(change.player_id, change.points);
                            }, animationDelay);
                            animationDelay += 2500;
                        });
                    }

                    if (data.drawn_cards && data.drawn_cards.length > 0) {
                        data.drawn_cards.forEach(drawnCard => {
                            setTimeout(() => {
                                showCardDrawAnimation(drawnCard);
                            }, animationDelay);
                            animationDelay += 7500;
                        });
                    }
                    
                    setTimeout(() => {
                        refreshGameData();
                    }, animationDelay || 500);
                }, 1500);
            }, 1100);
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

// Win selected card
function winSelectedCard() {
    if (!selectedHandCard) return;
    
    const selectedCardElement = document.querySelector('.game-card.selected');
    if (selectedCardElement) {
        selectedCardElement.classList.add('discarding');
        playSoundIfEnabled('/card-completed.m4r');
    }
    
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=win_hand_card&card_id=${selectedHandCard.card_id || selectedHandCard.id}&player_card_id=${selectedHandCard.id}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            setTimeout(() => {
                hideCardSelectionActions();
                closeCardOverlay('handCardsOverlay');
                loadCardData();
                setTimeout(() => {
                    if (data.score_changes && data.score_changes.length > 0) {
                        data.score_changes.forEach(change => {
                            updateScore(change.player_id, change.points);
                        });
                    }
                }, 1500);
                if (data.drawn_cards && data.drawn_cards.length > 0) {
                    let animationDelay = 1500; // Start after score animations
                    data.drawn_cards.forEach(drawnCard => {
                        setTimeout(() => {
                            showCardDrawAnimation(drawnCard);
                        }, animationDelay);
                        animationDelay += 7500; // 7.5 seconds per card
                    });
                }
            }, 1100);
        } else {
            clearCardSelection();
            alert('Failed to win card: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        clearCardSelection();
        console.error('Error winning card:', error);
        alert('Failed to win card');
    });
}

// Lose selected card
function loseSelectedCard() {
    if (!selectedHandCard) return;
    
    const selectedCardElement = document.querySelector('.game-card.selected');
    if (selectedCardElement) {
        selectedCardElement.classList.add('discarding');
        playSoundIfEnabled('/card-vetoed.m4r');
    }
    
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=lose_hand_card&card_id=${selectedHandCard.card_id || selectedHandCard.id}&player_card_id=${selectedHandCard.id}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            setTimeout(() => {
                hideCardSelectionActions();
                closeCardOverlay('handCardsOverlay');
                loadCardData();
                setTimeout(() => {
                    if (data.score_changes && data.score_changes.length > 0) {
                        data.score_changes.forEach(change => {
                            updateScore(change.player_id, change.points);
                        });
                    }
                }, 1500);
                if (data.drawn_cards && data.drawn_cards.length > 0) {
                    let animationDelay = 1500; // Start after score animations
                    data.drawn_cards.forEach(drawnCard => {
                        setTimeout(() => {
                            showCardDrawAnimation(drawnCard);
                        }, animationDelay);
                        animationDelay += 7500; // 7.5 seconds per card
                    });
                }
            }, 1100);
        } else {
            clearCardSelection();
            alert('Failed to lose card: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        clearCardSelection();
        console.error('Error losing card:', error);
        alert('Failed to lose card');
    });
}

function closeCardOverlay(overlayId) {
    if (overlayId === 'serveCardsOverlay') {
        clearServeSelection();
    } else {
        clearCardSelection();
    }
    document.getElementById(overlayId).classList.remove('active');
    setOverlayActive(false);
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

function displayActiveEffects() {
    if (!document.body.classList.contains('digital')) return;
    
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get_active_effects'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.effects.length > 0) {
            let effectsHtml = '<div class="active-effects"><h4>Active Effects:</h4>';
            data.effects.forEach(effect => {
                effectsHtml += `<div class="effect-item">${effect.description}</div>`;
            });
            effectsHtml += '</div>';
            
            // Add to hand cards grid
            const handGrid = document.getElementById('handCardsGrid');
            if (handGrid) {
                let effectsDiv = handGrid.querySelector('.active-effects');
                if (!effectsDiv) {
                    handGrid.insertAdjacentHTML('afterbegin', effectsHtml);
                }
            }
        }
    });
}

$('.bottom-right-menu').on('click', function() {
    if($(this).hasClass('open')) {
        $(this).removeClass('open');
        handleWheelButtonState(true);
        $('.blocking-indicator').removeClass('move');
    } else {
        $(this).addClass('open');
        handleWheelButtonState(false);
        $('.blocking-indicator').addClass('move');
    }
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
    console.log('Initializing Firebase...');
    
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

        // Enhanced foreground message handling
        firebaseMessaging.onMessage((payload) => {
            console.log('Firebase message received in foreground:', payload);
            
            // Try multiple payload structures
            let title = payload.notification?.title || payload.data?.title || 'The Couples Quest';
            let body = payload.notification?.body || payload.data?.body || 'New notification';
            
            console.log('Parsed notification data:', { title, body });
            
            // Always show in-app notification for foreground messages
            showInAppNotification(title, body);
        });

        // onBackgroundMessage can only be used in service worker
        // Background messages are handled in firebase-messaging-sw.js

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

// Enhanced Firebase messaging with better reliability for iOS PWA

// Add token refresh monitoring
function setupFirebaseMessaging() {
    if (!firebaseMessaging) return;
    
    const vapidKey = 'BAhDDY44EUfm9YKOElboy-2fb_6lzVhW4_TLMr4Ctiw6oA_ROcKZ09i5pKMQx3s7SoWgjuPbW-eGI7gFst6qjag';
    
    // Get initial token
    firebaseMessaging.getToken({ vapidKey }).then((currentToken) => {
        if (currentToken) {
            console.log('FCM Token received:', currentToken);
            updateTokenOnServer(currentToken);
            
            // Store token locally for comparison
            localStorage.setItem('fcm_token', currentToken);
        }
    }).catch((err) => {
        console.log('Error getting FCM token:', err);
    });

    // Monitor token refresh
    firebaseMessaging.onTokenRefresh(() => {
        console.log('FCM Token refreshed');
        firebaseMessaging.getToken({ vapidKey }).then((refreshedToken) => {
            console.log('New token:', refreshedToken);
            updateTokenOnServer(refreshedToken);
            localStorage.setItem('fcm_token', refreshedToken);
        }).catch((err) => {
            console.log('Unable to retrieve refreshed token:', err);
        });
    });

    // Enhanced foreground message handling
    firebaseMessaging.onMessage((payload) => {
        console.log('Message received in foreground:', payload);
        
        // Multiple fallback approaches for payload parsing
        let title = payload.notification?.title || 
                   payload.data?.title || 
                   'The Couples Quest';
        let body = payload.notification?.body || 
                  payload.data?.body || 
                  'New notification';
        
        // Only show in-app notification for foreground (Firebase handles the rest)
        showInAppNotification(title, body);
    });
}

function updateTokenOnServer(token) {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=update_fcm_token&fcm_token=' + encodeURIComponent(token)
    })
    .then(response => response.json())
    .then(data => {
        console.log('Token update result:', data);
    })
    .catch(error => {
        console.error('Error updating token:', error);
        // Retry after delay
        setTimeout(() => updateTokenOnServer(token), 5000);
    });
}

// Periodic token validation (check every 30 minutes)
setInterval(() => {
    if (firebaseMessaging) {
        firebaseMessaging.getToken().then((currentToken) => {
            const storedToken = localStorage.getItem('fcm_token');
            if (currentToken && currentToken !== storedToken) {
                console.log('Token changed, updating server');
                updateTokenOnServer(currentToken);
                localStorage.setItem('fcm_token', currentToken);
            }
        });
    }
}, 30 * 60 * 1000);

// Keep service worker alive with periodic heartbeat
if ('serviceWorker' in navigator) {
    setInterval(() => {
        navigator.serviceWorker.ready.then(registration => {
            if (registration.active) {
                registration.active.postMessage({type: 'HEARTBEAT'});
            }
        });
    }, 30000); // Every 30 seconds
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

// App badge functionality
let badgeSupported = false;

function checkBadgeSupport() {
    badgeSupported = 'setAppBadge' in navigator;
    console.log('Badge support:', badgeSupported);
}

function updateAppBadge(count) {
    if (!badgeSupported || !document.body.classList.contains('digital')) return;
    
    try {
        if (count > 0) {
            navigator.setAppBadge(count);
        } else {
            navigator.clearAppBadge();
        }
    } catch (error) {
        console.log('Badge update failed:', error);
    }
}

function clearAppBadge() {
    if (badgeSupported) {
        try {
            navigator.clearAppBadge();
        } catch (error) {
            console.log('Badge clear failed:', error);
        }
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
    console.log('Showing notification:', title, body); // Debug log
    
    const $notification = $('.iAN');
    const $title = $notification.find('.iAN-title');
    const $body = $notification.find('.iAN-body');
    
    // Bail if elements don't exist
    if ($notification.length === 0) {
        console.error('Notification elements not found');
        return;
    }
    
    // Clear any existing content/classes
    $notification.removeClass('show');
    $title.text(title);
    $body.text(body);
    loadCardData(); //Refresh cards in case a card was served
    
    // Force reflow then show
    setTimeout(() => {
        $notification.addClass('show');
    }, 10);
    
    playSoundIfEnabled('/tritone.m4r');
    
    // Remove after 5 seconds
    setTimeout(() => {
        $notification.removeClass('show');
    }, 5000);

    setTimeout(() => {
        $title.empty();
        $body.empty();
    }, 5500);

    // Refresh card data to update badge
    if (document.body.classList.contains('digital')) {
        setTimeout(() => loadCardData(), 1000);
    }
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
    setOverlayActive(true);
    
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
    setOverlayActive(false);
    
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
    document.querySelectorAll('.duration-btn:not(.custom-date-btn)').forEach(btn => {
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
        setOverlayActive(true);
        // Check notification status when modal opens
        setTimeout(checkNotificationStatusForModal, 100);
    }
}

// Modal functions
function openTimerModal() {
    const modal = document.getElementById('timerModal');
    if (modal) {
        modal.classList.add('active');
        setOverlayActive(true);
    }
}

function openHistoryModal() {
    loadHistory();
    const modal = document.getElementById('historyModal');
    if (modal) {
        modal.classList.add('active');
        setOverlayActive(true);
    }
}

function openEndGameModal() {
    const modal = document.getElementById('endGameModal');
    if(modal) {
        modal.classList.add('active');
        setOverlayActive(true);
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('active');
        setOverlayActive(false);
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
            // Get fresh data immediately after score update
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
                    
                    // Update gameData to prevent duplicate animations
                    gameData.players = gameDataUpdate.players;
                    
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
    playSoundIfEnabled('/score-change.m4r');
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
    setOverlayActive(true);
}

function hideTimerDeleteModal() {
    const modal = document.getElementById('timerDeleteModal');
    if (modal) {
        modal.classList.remove('active');
        setOverlayActive(false);
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
            // Remove timer from UI immediately instead of waiting for refresh
            timerManager.removeTimer(selectedTimerId);
            // Still refresh game data for other potential updates
            setTimeout(() => refreshGameData(), 500);
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

// Timer management object
const timerManager = {
    intervals: new Map(),
    
    startTimer(timerId, endTime) {
        this.stopTimer(timerId); // Clear any existing interval
        
        const interval = setInterval(() => {
            const now = new Date();
            const end = new Date(endTime + 'Z');
            const diff = end - now;
            
            if (diff <= 0) {
                this.expireTimer(timerId);
                return;
            }
            
            this.updateTimerDisplay(timerId, diff);
        }, 1000);
        
        this.intervals.set(timerId, interval);
    },
    
    stopTimer(timerId) {
        if (this.intervals.has(timerId)) {
            clearInterval(this.intervals.get(timerId));
            this.intervals.delete(timerId);
        }
    },
    
    updateTimerDisplay(timerId, diff) {
        const badge = document.querySelector(`[data-timer-id="${timerId}"]`);
        if (!badge) return;
        
        const timeString = this.formatTime(diff);
        const descSpan = badge.querySelector('.timer-title');
        const timeSpan = badge.querySelector('.timer-countdown');
        
        if (timeSpan) {
            timeSpan.textContent = timeString;
        }
        
        // Pulse in last 10 seconds
        if (diff <= 10000) {
            badge.classList.add('timer-expiring');
        } else {
            badge.classList.remove('timer-expiring');
        }
    },
    
    expireTimer(timerId) {
        this.stopTimer(timerId);
        const badge = document.querySelector(`[data-timer-id="${timerId}"]`);
        if (badge) {
            const description = badge.querySelector('.timer-title').textContent;
            
            badge.querySelector('.timer-countdown').textContent = '0:00';
            setTimeout(() => {
                badge.remove();
                refreshGameData();
            }, 1000);
        }
    },
    
    formatTime(milliseconds) {
        const totalSeconds = Math.ceil(milliseconds / 1000);
        const hours = Math.floor(totalSeconds / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        const seconds = totalSeconds % 60;
        
        if (hours > 0) {
            return `${hours}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
        } else {
            return `${minutes}:${seconds.toString().padStart(2, '0')}`;
        }
    }
};

timerManager.removeTimer = function(timerId) {
    // Stop the interval
    if (this.intervals.has(timerId)) {
        clearInterval(this.intervals.get(timerId));
        this.intervals.delete(timerId);
    }
    
    // Remove timer badge from UI immediately
    const badge = document.querySelector(`[data-timer-id="${timerId}"]`);
    if (badge) {
        badge.remove();
    }
};

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
            playSoundIfEnabled('/bumped.m4r');
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
            
            const time = new Date(item.timestamp);
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
            pointWord = 'points',
            modifiedWordNext = 'to';
            if(item.points_changed < 0) {
                modifiedWord = 'subtracted',
                modifiedWordNext = 'from';
            }

            if(change === 1) {
                pointWord = 'point';
            }
            
            div.innerHTML = `
                <div class="history-time">${formattedDate}</div>
                <div class="history-change">
                    ${item.modified_by_name} ${modifiedWord} ${change} ${pointWord} ${modifiedWordNext} ${item.player_name}'s score
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
    const oldPlayers = gameData.players ? [...gameData.players] : null; // Store old data
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
        
        // Check for score changes and animate them
        if (data.players && oldPlayers) {
            data.players.forEach(player => {
                const oldPlayer = oldPlayers.find(p => p.id === player.id);
                const scoreElement = document.querySelector(`.player-score.${player.gender} .player-score-value`);
                
                if (oldPlayer && player.score !== oldPlayer.score && scoreElement && !scoreElement.classList.contains('counting')) {
                    const pointsChanged = player.score - oldPlayer.score;
                    animateScoreChange(player.gender, player.score, pointsChanged);
                } else if (scoreElement && !scoreElement.classList.contains('counting')) {
                    scoreElement.textContent = player.score;
                }
            });
        }

        // Update stored game data
        gameData.players = data.players;

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
    
    // Get existing timer IDs to avoid recreating them
    const existingTimerIds = new Set();
    document.querySelectorAll('[data-timer-id]').forEach(badge => {
        existingTimerIds.add(badge.dataset.timerId);
    });
    
    // Get timer IDs from server response
    const serverTimerIds = new Set(timers.map(timer => timer.id.toString()));
    
    // Remove timers that no longer exist on server
    existingTimerIds.forEach(timerId => {
        if (!serverTimerIds.has(timerId)) {
            timerManager.removeTimer(timerId);
        }
    });
    
    // Add new timers
    timers.forEach(timer => {
        // Skip if timer already exists and is running
        if (existingTimerIds.has(timer.id.toString())) {
            return;
        }
        
        const div = document.createElement('div');
        div.className = 'timer-badge';
        div.dataset.timerId = timer.id;
        div.title = timer.description;
        div.onclick = () => showTimerDeleteModal(timer.id, timer.description);
        
        const endTime = new Date(timer.end_time + 'Z');
        const now = new Date();
        const diff = endTime - now;
        
        if (diff > 0) {
            const title = '<span class="timer-title">' + timer.description + '</span>';
            const countdown = '<span class="timer-countdown">' + timerManager.formatTime(diff) + '</span>';
            
            div.innerHTML = title + countdown;
            
            if (timer.player_id == gameData.currentPlayerId) {
                currentTimers.appendChild(div);
            } else {
                opponentTimers.appendChild(div);
            }
            
            timerManager.startTimer(timer.id, timer.end_time);
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
                setOverlayActive(false);
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

function openDicePopover() {
    const popover = document.getElementById('dicePopover');
    if (popover) {
        if (popover.classList.contains('active')) {
            closeDicePopover();
            return;
        }
        handleWheelButtonState(false);
        showDiceChoiceButtons();
        popover.classList.add('active');
        
        setTimeout(() => {
            document.addEventListener('click', closeDicePopoverOnClickOutside);
        }, 100);
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

function closeDicePopover() {
    const popover = document.getElementById('dicePopover');
    if (popover) {
        handleWheelButtonState(true);
        popover.classList.remove('active');
        document.removeEventListener('click', closeDicePopoverOnClickOutside);
    }
}

function closeDicePopoverOnClickOutside(event) {
    const popover = document.getElementById('dicePopover');
    if (popover && !popover.contains(event.target)) {
        closeDicePopover();
    }
}

function showDiceChoiceButtons() {
    const container = document.getElementById('dicePopoverContainer');
    container.innerHTML = `
        <div class="dice-choice-buttons">
            <button class="dice-choice-btn" onclick="event.stopPropagation(); rollDiceChoice(1)">1 Die</button>
            <button class="dice-choice-btn" onclick="event.stopPropagation(); rollDiceChoice(2)">2 Dice</button>
            <button class="dice-choice-btn" onclick="event.stopPropagation(); rollDiceChoice('sexy')">Sexy</button>
        </div>
    `;
}

function rollDiceChoice(count) {
    setDiceCount(count);
    const container = document.getElementById('dicePopoverContainer');
    
    // Move the dice HTML from game.php into here
    container.innerHTML = document.getElementById('diceTemplate').innerHTML;
    
    // Add click handlers for re-rolling
    const die1 = container.querySelector('#die1');
    const die2 = container.querySelector('#die2');
    
    if (die1) {
        die1.onclick = (e) => {
            e.stopPropagation();
            playSoundIfEnabled('/dice-roll.m4r');
            setTimeout(() => rollDice(), 500);
        };
    }
    
    if (die2) {
        die2.onclick = (e) => {
            e.stopPropagation();
            playSoundIfEnabled('/dice-roll.m4r');
            setTimeout(() => rollDice(), 500);
        };
    }
    
    // Set up dice based on type
    if (count === 'sexy') {
        setupSexyDice();
    } else {
        // Update dice color and auto-roll for regular dice
        if (gameData.currentPlayerGender) {
            setDiceColor(gameData.currentPlayerGender);
        }
        initializeDicePosition();
    }
    
    playSoundIfEnabled('/dice-roll.m4r');
    setTimeout(() => rollDice(), 500);
}

function setDiceCount(count) {
    currentDiceCount = count;
    
    // Update button states
    document.querySelectorAll('.dice-count-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    if (event && event.target) {
        event.target.classList.add('active');
    }
    
    // Update dice container
    const container = document.getElementById('diceContainer');
    if (count === 2 || count === 'sexy') {
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
    
    // Generate random values based on dice type
    let die1Value, die2Value;
    
    if (currentDiceCount === 'sexy') {
        die1Value = Math.floor(Math.random() * 6) + 1;
        die2Value = Math.floor(Math.random() * 6) + 1;
    } else {
        die1Value = Math.floor(Math.random() * 6) + 1;
        die2Value = currentDiceCount === 2 ? Math.floor(Math.random() * 6) + 1 : 0;
    }
    
    const die1 = document.getElementById('die1');
    const die2 = document.getElementById('die2');
    
    if (die1) {
        const extraSpins1 = Math.floor(Math.random() * 4) + 1;
        const finalRotation1 = getDieRotationForValue(die1Value);
        die1.style.transform = `rotateX(${finalRotation1.x + (extraSpins1 * 360)}deg) rotateY(${finalRotation1.y + (extraSpins1 * 360)}deg)`;
    }
    
    if ((currentDiceCount === 2 || currentDiceCount === 'sexy') && die2) {
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

function setupSexyDice() {
    currentDiceCount = 'sexy';
    
    // Update dice container to show both dice
    const container = document.getElementById('diceContainer');
    container.classList.add('two-dice');
    
    // Set dice color
    if (gameData.currentPlayerGender) {
        setDiceColor(gameData.currentPlayerGender);
    }
    
    // Set up sex dice faces
    setupSexyDiceFaces();
    
    // Initialize to random positions
    initializeSexyDicePosition();
}

function setupSexyDiceFaces() {
    const die1 = document.getElementById('die1');
    const die2 = document.getElementById('die2');
    
    if (!die1 || !die2) return;
    
    // Die 1 - Action dice (same for both genders)
    const actions = ['Rub', 'Pinch', 'Kiss', 'Lick', 'Suck', 'Do Whatever You Want to'];
    
    // Die 2 - Body parts (different based on gender)
    const bodyParts = gameData.currentPlayerGender === 'female' 
        ? ['His Booty', 'His Neck', 'His Nipples', 'Your Choice', 'His Penis', 'His Balls']
        : ['Her Booty', 'Her Neck', 'Her Boobs', 'Her Nipples', 'Your Choice', 'Her Vagina'];
    
    // Update die 1 faces
    const die1Faces = die1.querySelectorAll('.die-face');
    die1Faces.forEach((face, index) => {
        face.classList.add('sexy');
        face.innerHTML = `<div class="die-text">${actions[index]}</div>`;
    });
    
    // Update die 2 faces  
    const die2Faces = die2.querySelectorAll('.die-face');
    die2Faces.forEach((face, index) => {
        face.classList.add('sexy');
        face.innerHTML = `<div class="die-text">${bodyParts[index]}</div>`;
    });
}

function initializeSexyDicePosition() {
    const die1 = document.getElementById('die1');
    const die2 = document.getElementById('die2');
    
    if (die1) {
        setDieRotation(die1, Math.floor(Math.random() * 6) + 1);
    }
    if (die2) {
        setDieRotation(die2, Math.floor(Math.random() * 6) + 1);
    }
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
        setTimeout(() => loadCardData(), 500);
        
        // Refresh card data periodically
        setInterval(() => {
            loadCardData();
        }, 10000); // Every 10 seconds
    }
}

function resetDecks() {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=reset_decks'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showInAppNotification('Decks Reset', data.message);
            loadCardData();
        } else {
            alert('Failed to reset decks: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error resetting decks:', error);
        alert('Failed to reset decks');
    });
}

function showCustomDatePicker() {
    const picker = document.getElementById('customDatePicker');
    const input = document.getElementById('customEndDate');
    const notifyBubble = document.querySelector('.notify-bubble');
    
    // Hide notify bubble to save space
    if (notifyBubble) {
        notifyBubble.style.display = 'none';
    }
    
    // Set min date to 1 week from now
    const minDate = new Date();
    minDate.setDate(minDate.getDate() + 7);
    
    // Set max date to 1 year from now
    const maxDate = new Date();
    maxDate.setFullYear(maxDate.getFullYear() + 1);
    
    input.min = minDate.toISOString().split('T')[0];
    input.max = maxDate.toISOString().split('T')[0];
    
    picker.style.display = 'block';
}

function hideCustomDatePicker() {
    const picker = document.getElementById('customDatePicker');
    const notifyBubble = document.querySelector('.notify-bubble');
    
    // Show notify bubble again
    if (notifyBubble) {
        notifyBubble.style.display = 'block';
    }
    
    picker.style.display = 'none';
    document.getElementById('customEndDate').value = '';
}

function setCustomDuration() {
    const dateInput = document.getElementById('customEndDate');
    const selectedDate = dateInput.value;
    
    if (!selectedDate) {
        alert('Please select a date');
        return;
    }
    
    fetch('game.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=set_duration&custom_date=' + selectedDate
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Failed to set custom duration: ' + (data.message || 'Please try again.'));
        }
    })
    .catch(error => {
        console.error('Error setting custom duration:', error);
        alert('Failed to set custom duration. Please try again.');
    });
}

function openRulesOverlay() {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get_rules'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById('rulesContent').innerHTML = data.content;
            document.getElementById('rulesOverlay').classList.add('active');
            setOverlayActive(true);
        } else {
            alert('Failed to load rules');
        }
    })
    .catch(error => {
        console.error('Error loading rules:', error);
        alert('Failed to load rules');
    });
}

function closeRulesOverlay() {
    document.getElementById('rulesOverlay').classList.remove('active');
    setOverlayActive(false);
}

function handleRulesOverlayClick(event) {
    if (event.target.classList.contains('card-overlay')) {
        closeRulesOverlay();
    }
}

function toggleTheme() {
    const body = document.body;
    const toggleText = document.getElementById('themeToggleText');
    
    if (body.classList.contains('gradient-theme')) {
        // Switch to Color theme
        body.classList.remove('gradient-theme');
        toggleText.textContent = 'Theme: Color';
        localStorage.setItem('couples_quest_theme', 'color');
    } else {
        // Switch to Gradient theme
        body.classList.add('gradient-theme');
        toggleText.textContent = 'Theme: Gradient';
        localStorage.setItem('couples_quest_theme', 'gradient');
    }
}

function loadThemePreference() {
    const savedTheme = localStorage.getItem('couples_quest_theme');
    const toggleText = document.getElementById('themeToggleText');
    
    if (savedTheme === 'gradient') {
        document.body.classList.add('gradient-theme');
        if (toggleText) toggleText.textContent = 'Theme: Gradient';
    } else {
        // Default to color theme
        if (toggleText) toggleText.textContent = 'Theme: Color';
    }
}

function checkWheelAvailability() {
    if (!document.body.classList.contains('digital')) return;
    
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=can_spin_wheel'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.can_spin) {
            document.getElementById('wheelButton').classList.add('available');
        } else {
            document.getElementById('wheelButton').classList.remove('available');
        }
    });
}

function handleWheelButtonState(bool) {
    var $wheelButton = $('.wheel-button');
    if($wheelButton.hasClass('available')) {
        if(bool === true) {
            $wheelButton.removeClass('hide');
        } else {
            $wheelButton.addClass('hide');
        }
    }
}

function openWheelOverlay() {
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get_wheel_data'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            wheelPrizes = data.prizes;
            populateWheel(data.prizes);
            document.getElementById('wheelOverlay').classList.add('active');
            setOverlayActive(true);
        }
    });
}

function spinWheelAction() {
    if (isWheelSpinning) return;
    
    isWheelSpinning = true;
    
    fetch('game.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=spin_wheel'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const wheelBackground = document.querySelector('.wheel-background');
            const result = document.getElementById('wheelResult');
            
            // Calculate rotation to land on winning segment
            const targetSegment = data.winning_index;
            const segmentAngle = (targetSegment * 60) + 30;
            
            // Do 5 full rotations (1800deg) plus the angle to reach the target
            const finalRotation = 1800 + (360 - segmentAngle);
            
            wheelBackground.style.transform = `rotate(${finalRotation}deg)`;
            
            playSoundIfEnabled('/dice-roll.m4r');
            
            // Show result after spin animation
            setTimeout(() => {
                if (result) {
                    result.textContent = data.winning_prize.display_text;
                    result.classList.add('show');
                }
                
                // Hide wheel button since it's been used
                document.getElementById('wheelButton').style.display = 'none';
                
                // Auto-close after showing result
                setTimeout(() => {
                    closeWheelOverlay();
                    
                    // Execute prize after overlay closes
                    setTimeout(() => {
                        executePrize(data.winning_prize);
                    }, 500);
                }, 3000);
                
            }, 3000);
        } else {
            alert('Failed to spin wheel: ' + (data.message || 'Unknown error'));
            isWheelSpinning = false;
        }
    })
    .catch(error => {
        console.error('Error spinning wheel:', error);
        alert('Failed to spin wheel');
        isWheelSpinning = false;
    });
}

function executePrize(prize) {
    switch (prize.prize_type) {
        case 'points':
            updateScore(gameData.currentPlayerId, prize.prize_value);
            break;
        case 'draw_chance':
            manualDrawCard('chance');
            break;
        case 'draw_snap_dare':
            // Use existing logic - draw based on current player's gender
            const drawType = gameData.currentPlayerGender === 'female' ? 'snap' : 'dare';
            manualDrawCard(drawType);
            break;
        case 'draw_spicy':
            manualDrawCard('spicy');
            break;
    }
    
    isWheelSpinning = false;
}

function handleWheelOverlayClick(event) {
    if (event.target.classList.contains('wheel-overlay')) {
        closeWheelOverlay();
    }
}

function closeWheelOverlay() {
    document.getElementById('wheelOverlay').classList.remove('active');
    setOverlayActive(false);
    isWheelSpinning = false;
}

function populateWheel(prizes) {
    for (let i = 1; i <= 6; i++) {
        const textElement = document.getElementById(`wheelText${i}`);
        const prize = prizes[i - 1];
        if (textElement) {
            textElement.textContent = prize.display_text;
        }
    }
    
    // Reset wheel state
    const wheelBackground = document.querySelector('.wheel-background');
    if (wheelBackground) {
        wheelBackground.style.transform = 'rotate(0deg)';
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

    checkBadgeSupport();
    
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

    setTimeout(() => {
        $('.game-timer').addClass('visible');
    }, 1000);

    // Clear all timer intervals on page unload
    window.addEventListener('beforeunload', () => {
        timerManager.intervals.forEach((interval, timerId) => {
            timerManager.stopTimer(timerId);
        });
    });
    
    // Start periodic refresh for active games
    if (gameData.gameStatus === 'active') {
        refreshGameData();
        loadThemePreference();
        updateSoundToggleText();
        setInterval(refreshGameData, 5000); // Refresh every 5 seconds
    }

    // Clear badge when app becomes visible
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden && document.body.classList.contains('digital')) {
            // Reload card data to get fresh count
            setTimeout(() => {
                loadCardData();
            }, 500);
        }
    });

    // Check wheel availability for digital games
    if (document.body.classList.contains('digital')) {
        setTimeout(() => checkWheelAvailability(), 1000);
        
        // Check periodically for wheel availability
        setInterval(() => {
            checkWheelAvailability();
        }, 60000); // Every minute
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
window.winSelectedCard = winSelectedCard;
window.loseSelectedCard = loseSelectedCard;
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
window.openDicePopover = openDicePopover;
window.closeDicePopover = closeDicePopover;
window.setDiceCount = setDiceCount;
window.rollDice = rollDice;
window.completeChanceCard = completeChanceCard;
window.displayActiveEffects = displayActiveEffects;
window.resetDecks = resetDecks;
window.showCustomDatePicker = showCustomDatePicker;
window.hideCustomDatePicker = hideCustomDatePicker;
window.setCustomDuration = setCustomDuration;
window.openRulesOverlay = openRulesOverlay;
window.closeRulesOverlay = closeRulesOverlay;
window.handleRulesOverlayClick = handleRulesOverlayClick;
window.toggleTheme = toggleTheme;
window.toggleSound = toggleSound;
window.openWheelOverlay = openWheelOverlay;
window.spinWheelAction = spinWheelAction;
window.closeWheelOverlay = closeWheelOverlay;
window.handleWheelOverlayClick = handleWheelOverlayClick;