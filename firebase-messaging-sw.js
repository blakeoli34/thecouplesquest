// firebase-messaging-sw.js - Enhanced for iOS PWA reliability

importScripts('https://www.gstatic.com/firebasejs/9.0.0/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/9.0.0/firebase-messaging-compat.js');

firebase.initializeApp({
    apiKey: "AIzaSyB8H4ClwOR00oxcBENYgi8yiVVMHQAUCSc",
    authDomain: "couples-quest-5b424.firebaseapp.com",
    projectId: "couples-quest-5b424",
    storageBucket: "couples-quest-5b424.firebasestorage.app",
    messagingSenderId: "551122707531",
    appId: "1:551122707531:web:30309743eea2fe410b19ce"
});

const messaging = firebase.messaging();

// Enhanced background message handling with retry logic
messaging.onBackgroundMessage(function(payload) {
    console.log('Received background message:', payload);
    
    const notificationTitle = payload.data?.title || payload.notification?.title || 'The Couples Quest';
    const notificationBody = payload.data?.body || payload.notification?.body || 'New notification';
    
    const notificationOptions = {
        body: notificationBody,
        icon: '/icon-192x192.png',
        badge: '/badge-72x72.png',
        tag: 'couples-quest-' + Date.now(), // Unique tag to prevent merging
        requireInteraction: true, // Important for iOS
        silent: false,
        vibrate: [200, 100, 200],
        actions: [
            {
                action: 'open',
                title: 'Open Game',
                icon: '/icon-192x192.png'
            }
        ],
        data: {
            url: 'https://thecouples.quest/game.php',
            timestamp: Date.now()
        }
    };
    
    // Show notification with retry
    return self.registration.showNotification(notificationTitle, notificationOptions)
        .catch(error => {
            console.error('Failed to show notification:', error);
            // Fallback: try again with minimal options
            return self.registration.showNotification(notificationTitle, {
                body: notificationBody,
                tag: 'couples-quest-fallback'
            });
        });
});

// Enhanced notification click handling
self.addEventListener('notificationclick', function(event) {
    console.log('Notification clicked:', event);
    
    event.notification.close();
    
    const targetUrl = event.notification.data?.url || 'https://thecouples.quest/game.php';
    
    event.waitUntil(
        clients.matchAll({ 
            type: 'window', 
            includeUncontrolled: true 
        }).then(clientList => {
            // Try to focus existing window first
            for (const client of clientList) {
                if (client.url.includes('thecouples.quest') && 'focus' in client) {
                    return client.focus();
                }
            }
            
            // Open new window if none found
            if (clients.openWindow) {
                return clients.openWindow(targetUrl);
            }
        }).catch(error => {
            console.error('Error handling notification click:', error);
        })
    );
});

// Keep service worker alive with heartbeat response
self.addEventListener('message', event => {
    if (event.data && event.data.type === 'HEARTBEAT') {
        console.log('Service worker heartbeat received');
        // Respond to keep connection alive
        event.ports[0]?.postMessage({type: 'HEARTBEAT_RESPONSE'});
    }
});

// Prevent service worker from sleeping (iOS optimization)
self.addEventListener('fetch', event => {
    // Only handle same-origin requests
    if (event.request.url.startsWith(self.location.origin)) {
        event.respondWith(fetch(event.request));
    }
});

// Additional iOS PWA optimizations
self.addEventListener('activate', event => {
    console.log('Service worker activated');
    event.waitUntil(
        self.clients.claim().then(() => {
            console.log('Service worker claimed all clients');
        })
    );
});

self.addEventListener('install', event => {
    console.log('Service worker installed');
    self.skipWaiting();
});