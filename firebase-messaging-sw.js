// firebase-messaging-sw.js - Service worker for Firebase Cloud Messaging
// This file should be placed in the root directory of your website

importScripts('https://www.gstatic.com/firebasejs/9.0.0/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/9.0.0/firebase-messaging-compat.js');

// Initialize Firebase
firebase.initializeApp({
    apiKey: "AIzaSyB8H4ClwOR00oxcBENYgi8yiVVMHQAUCSc",
    authDomain: "couples-quest-5b424.firebaseapp.com",
    projectId: "couples-quest-5b424",
    storageBucket: "couples-quest-5b424.firebasestorage.app",
    messagingSenderId: "551122707531",
    appId: "1:551122707531:web:30309743eea2fe410b19ce"
});

// Retrieve an instance of Firebase Messaging so that it can handle background messages
const messaging = firebase.messaging();

// Handle background messages
messaging.onBackgroundMessage(function(payload) {
    console.log('Received background message:', payload);
    
    const notificationTitle = payload.notification.title;
    const notificationOptions = {
        body: payload.notification.body,
        icon: '/icon-192x192.png', // Add your app icon
        badge: '/badge-72x72.png',  // Add your badge icon
        tag: 'couples-quest',
        requireInteraction: false,
        actions: [
            {
                action: 'open',
                title: 'Open Game'
            }
        ]
    };
    
    self.registration.showNotification(notificationTitle, notificationOptions);
});

// Handle notification clicks
self.addEventListener('notificationclick', function(event) {
    console.log('Notification click received.');
    
    event.notification.close();
    
    if (event.action === 'open' || !event.action) {
        // Open the app when notification is clicked
        event.waitUntil(
            clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clientList => {
                // Check if a window is already open
                for (const client of clientList) {
                    if (client.url.includes('thecouples.quest') && 'focus' in client) {
                        return client.focus();
                    }
                }
                // If no window is open, open a new one
                if (clients.openWindow) {
                    return clients.openWindow('https://thecouples.quest/game.php');
                }
            })
        );
    }
});