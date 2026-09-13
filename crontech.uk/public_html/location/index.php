<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="public, max-age=3600">
    <title>Location Tracker</title>
    <link rel="manifest" href="/location/manifest.json">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        .pulse {
            animation: pulse 2s infinite;
        }
        .status-message {
            transition: opacity 0.5s ease-in-out;
        }
        .tracking-mode {
            background-color: #000000 !important;
            color: #000000 !important;
        }
        .tracking-mode * {
            color: #000000 !important;
            background-color: #000000 !important;
            border-color: #000000 !important;
        }
        .tracking-mode button {
            background-color: #000000 !important;
            color: #FFFFFF !important;
        }
        .tracking-mode #stopButton {
            background-color: #7F1D1D !important;
            color: #FFFFFF !important;
        }
        .tracking-mode .status-message {
            color: #FFFFFF !important;
        }
    </style>
</head>
<body class="bg-gray-800 min-h-screen">
    <nav class="bg-gradient-to-r from-gray-900 to-gray-800 text-white p-4 sticky top-0 z-20 shadow-lg">
        <a href="index.php" class="hover:underline text-gray-200 font-medium text-sm">
            <button type="button" class="bg-green-800 text-white py-1.5 px-3 rounded-md hover:bg-green-900 hover:shadow-md transition duration-300 transform hover:scale-105 text-sm">Refresh</button>
        </a>  
        <a href="https://crontech.uk/location/view.php" class="hover:underline text-gray-200 font-medium text-sm">
            <button type="button" class="bg-green-800 text-white py-1.5 px-3 rounded-md hover:bg-green-900 hover:shadow-md transition duration-300 transform hover:scale-105 text-sm">View</button>
        </a>
    </nav>

    <div class="flex-grow flex items-center justify-center">
        <div class="bg-gray-900 p-6 rounded-lg shadow-lg border border-gray-600 max-w-md w-full">
            <h1 class="text-2xl font-bold text-center text-gray-200 mb-6">Capture Your Location</h1>
            <div id="loginForm" class="mb-4">
                <div class="mb-4">
                    <label for="username" class="block text-gray-300 text-sm mb-2">Username</label>
                    <input type="text" id="username" class="w-full p-1.5 border border-gray-600 rounded-md bg-gray-700 text-gray-200 focus:ring-2 focus:ring-blue-700 focus:border-blue-700 transition duration-200 text-sm" required>
                </div>
                <div class="mb-4">
                    <label for="password" class="block text-gray-300 text-sm mb-2">Password</label>
                    <input type="password" id="password" class="w-full p-1.5 border border-gray-600 rounded-md bg-gray-700 text-gray-200 focus:ring-2 focus:ring-blue-700 focus:border-blue-700 transition duration-200 text-sm" required>
                </div>
                <button 
                    onclick="generateUserId()" 
                    class="w-full bg-blue-900 text-white py-1.5 px-3 rounded-md hover:bg-blue-950 hover:shadow-md transition duration-300 transform hover:scale-105 mb-2 text-sm"
                    id="loginButton"
                >
                    Login
                </button>
            </div>
            <div id="trackingControls" class="hidden">
                <div class="mb-4">
                    <label for="interval" class="block text-gray-300 text-sm mb-2">Tracking Interval</label>
                    <select id="interval" class="w-full p-1.5 border border-gray-600 rounded-md bg-gray-700 text-gray-200 focus:ring-2 focus:ring-blue-700 focus:border-blue-700 transition duration-200 text-sm">
                        <option value="10000">Every 10 Seconds</option>
                        <option value="30000" selected>Every 30 Seconds</option>
                        <option value="60000">Every 1 Minute</option>
                        <option value="300000" >Every 5 Minutes</option>
                        <option value="600000">Every 10 Minutes</option>
                        <option value="1800000">Every 30 Minutes</option>
                    </select>
                </div>
                <button 
                    onclick="startTracking()" 
                    class="w-full bg-blue-900 text-white py-1.5 px-3 rounded-md hover:bg-blue-950 hover:shadow-md transition duration-300 transform hover:scale-105 pulse mb-2 text-sm"
                    id="trackButton"
                >
                    Start Tracking
                </button>
                <button 
                    onclick="stopTracking()" 
                    class="w-full bg-red-800 text-white py-1.5 px-3 rounded-md hover:bg-red-900 hover:shadow-md transition duration-300 transform hover:scale-105 hidden mb-2 text-sm"
                    id="stopButton"
                >
                    Stop Tracking
                </button>
                <button 
                    onclick="getLocation()" 
                    class="w-full bg-green-800 text-white py-1.5 px-3 rounded-md hover:bg-green-900 hover:shadow-md transition duration-300 transform hover:scale-105 mb-2 text-sm"
                    id="locateNowButton"
                >
                    Locate Now
                </button>
                <button 
                    onclick="pushToServer()" 
                    class="w-full bg-purple-800 text-white py-1.5 px-3 rounded-md hover:bg-purple-900 hover:shadow-md transition duration-300 transform hover:scale-105 mb-2 text-sm"
                    id="pushButton"
                >
                    Push to Server
                </button>
                <button 
                    onclick="downloadAppFile()" 
                    class="w-full bg-teal-800 text-white py-1.5 px-3 rounded-md hover:bg-teal-900 hover:shadow-md transition duration-300 transform hover:scale-105 mb-2 text-sm"
                    id="downloadButton"
                >
                    Download App File
                </button>
                <button 
                    onclick="shareLocation()" 
                    class="w-full bg-yellow-700 text-white py-1.5 px-3 rounded-md hover:bg-yellow-800 hover:shadow-md transition duration-300 transform hover:scale-105 mb-2 text-sm"
                    id="shareButton"
                >
                    Share My Location
                </button>
                <button 
                    onclick="logout()" 
                    class="w-full bg-gray-600 text-white py-1.5 px-3 rounded-md hover:bg-gray-700 hover:shadow-md transition duration-300 transform hover:scale-105 mb-2 text-sm"
                    id="logoutButton"
                >
                    Logout
                </button>
            </div>
            <p id="status" class="mt-4 text-center text-gray-300 status-message"></p>
            <p id="localCount" class="mt-2 text-center text-gray-300 status-message"></p>
        </div>
    </div>

    <script>
        let trackingInterval = null;
        const SERVER_URL = 'https://breezbee.co.uk/location/receive.php';

        // Generate user ID from username and password
        async function generateUserId() {
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value;
            const status = document.getElementById('status');

            if (!username || !password) {
                status.innerText = 'Please enter both username and password.';
                return;
            }

            const input = `${username}:${password}`;
            const encoder = new TextEncoder();
            const data = encoder.encode(input);
            const hashBuffer = await crypto.subtle.digest('SHA-256', data);
            const hashArray = Array.from(new Uint8Array(hashBuffer));
            const userId = hashArray.map(b => b.toString(16).padStart(2, '0')).join('');

            // Set cookie and localStorage
            document.cookie = `userId=${encodeURIComponent(userId)}; path=/; max-age=7776000; SameSite=Strict; Secure`;
            localStorage.setItem('userId', userId);
            console.log('Cookie set:', document.cookie);
            console.log('localStorage set:', localStorage.getItem('userId'));
            document.getElementById('loginForm').classList.add('hidden');
            document.getElementById('trackingControls').classList.remove('hidden');
            status.innerText = 'Logged in successfully! Ready to track locations.';

            updateLocalCount();
            if (navigator.onLine) syncLocations();
        }

        // Retrieve user ID from cookie or localStorage
        function getUserId() {
            const cookies = document.cookie.split(';').reduce((acc, cookie) => {
                const [name, value] = cookie.trim().split('=');
                acc[name] = value ? decodeURIComponent(value) : null;
                return acc;
            }, {});
            console.log('Cookies retrieved:', cookies);
            return cookies.userId || localStorage.getItem('userId') || null;
        }

        // Logout function to clear cookie and localStorage
        function logout() {
            document.cookie = 'userId=; path=/; max-age=0; SameSite=Strict; Secure';
            localStorage.removeItem('userId');
            document.getElementById('trackingControls').classList.add('hidden');
            document.getElementById('loginForm').classList.remove('hidden');
            document.getElementById('status').innerText = 'Logged out. Please log in again.';
            updateLocalCount();
        }

        // Initialize UI based on login status
        function initializeUI() {
            const userId = getUserId();
            const loginForm = document.getElementById('loginForm');
            const trackingControls = document.getElementById('trackingControls');
            const status = document.getElementById('status');

            if (userId) {
                loginForm.classList.add('hidden');
                trackingControls.classList.remove('hidden');
                status.innerText = 'Logged in. Ready to track locations.';
                updateLocalCount();
                if (navigator.onLine) syncLocations();
            } else {
                loginForm.classList.remove('hidden');
                trackingControls.classList.add('hidden');
                status.innerText = 'Please log in to start tracking locations.';
            }
        }

        // Load pending locations from local storage
        function getPendingLocations() {
            const pending = localStorage.getItem('pendingLocations');
            try {
                return pending ? JSON.parse(pending) : [];
            } catch (e) {
                console.error('Error parsing localStorage:', e);
                return [];
            }
        }

        // Save location to local storage
        function saveLocationLocally(latitude, longitude, timestamp, userId) {
            if (isNaN(latitude) || isNaN(longitude)) {
                console.error(`Invalid coordinates: latitude=${latitude}, longitude=${longitude}`);
                document.getElementById('status').innerText = 'Error: Invalid coordinates saved locally.';
                return 0;
            }
            const pendingLocations = getPendingLocations();
            pendingLocations.push({ latitude, longitude, timestamp, userId });
            localStorage.setItem('pendingLocations', JSON.stringify(pendingLocations));
            updateLocalCount();
            return pendingLocations.length;
        }

        // Update local storage count display
        function updateLocalCount() {
            const count = getPendingLocations().length;
            document.getElementById('localCount').innerText = 
                count > 0 ? `Stored ${count} location(s) locally, awaiting sync.` : '';
        }

        // Sync pending locations with server
        function syncLocations() {
            const userId = getUserId();
            if (!userId) {
                document.getElementById('status').innerText = 'Please log in to sync locations.';
                return;
            }

            const pendingLocations = getPendingLocations();
            if (pendingLocations.length === 0) {
                document.getElementById('status').innerText = 'No locations to sync.';
                return;
            }

            if (!navigator.onLine) {
                document.getElementById('status').innerText = 'Offline: Cannot sync. Please try again when online.';
                return;
            }

            const status = document.getElementById('status');
            status.innerText = `Syncing ${pendingLocations.length} location(s)...`;

            Promise.all(pendingLocations.map((location, index) => 
                new Promise(resolve => {
                    setTimeout(() => {
                        fetch(`${SERVER_URL}?latitude=${location.latitude}&longitude=${location.longitude}&userId=${encodeURIComponent(location.userId)}`, {
                            method: 'GET'
                        })
                        .then(response => {
                            if (!response.ok) {
                                console.error(`Sync failed for location (${location.latitude}, ${location.longitude}, ${location.timestamp}, ${location.userId}): HTTP ${response.status}`);
                                return { success: false, location, error: `HTTP ${response.status}` };
                            }
                            return response.text().then(text => {
                                try {
                                    const data = JSON.parse(text);
                                    if (data.status !== 'success') {
                                        console.error(`Sync failed for location (${location.latitude}, ${location.longitude}, ${location.timestamp}, ${location.userId}): ${data.message || 'Server error'}`);
                                        return { success: false, location, error: data.message || 'Server error' };
                                    }
                                    return { success: true, location };
                                } catch (e) {
                                    console.error(`Sync failed for location (${location.latitude}, ${location.longitude}, ${location.timestamp}, ${location.userId}): Invalid JSON response: ${text}`);
                                    return { success: false, location, error: `Invalid JSON response` };
                                }
                            });
                        })
                        .catch(error => {
                            console.error(`Sync failed for location (${location.latitude}, ${location.longitude}, ${location.timestamp}, ${location.userId}): ${error.message}`);
                            return { success: false, location, error: error.message };
                        })
                        .then(result => resolve(result));
                    }, index * 100);
                })
            )).then(results => {
                const successful = results.filter(r => r.success).map(r => r.location);
                const failed = results.filter(r => !r.success);
                if (successful.length > 0) {
                    const remaining = pendingLocations.filter(loc => 
                        !successful.some(s => 
                            s.latitude === loc.latitude && 
                            s.longitude === loc.longitude && 
                            s.timestamp === loc.timestamp && 
                            s.userId === loc.userId
                        )
                    );
                    localStorage.setItem('pendingLocations', JSON.stringify(remaining));
                    status.innerText = `Synced ${successful.length} location(s) at ${new Date().toLocaleTimeString('en-GB')}${failed.length > 0 ? `, ${failed.length} failed (HTTP error or invalid response). Check console for details.` : '.'}`;
                } else {
                    status.innerText = `Failed to sync ${pendingLocations.length} location(s) (HTTP error or invalid response). Check console for details.`;
                }
                if (failed.length > 0) {
                    console.log('Failed locations:', failed);
                }
                updateLocalCount();
            }).catch(error => {
                status.innerText = `Sync error: ${error.message}. Check console for details.`;
                console.error('General sync error:', error);
                updateLocalCount();
            });
        }

        function getLocation() {
            const userId = getUserId();
            if (!userId) {
                document.getElementById('status').innerText = 'Please log in to capture locations.';
                return;
            }

            const status = document.getElementById('status');
            status.innerText = 'Fetching location...';
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    position => sendPosition(position),
                    showError,
                    { enableHighAccuracy: true, timeout: 5000, maximumAge: 0 }
                );
            } else {
                status.innerText = 'Geolocation is not supported by this browser.';
            }
        }

        function sendPosition(position) {
            const userId = getUserId();
            if (!userId) {
                document.getElementById('status').innerText = 'Please log in to save locations.';
                return;
            }

            const { latitude, longitude } = position.coords;
            const timestamp = new Date().toISOString();
            const status = document.getElementById('status');

            if (navigator.onLine) {
                fetch(`${SERVER_URL}?latitude=${latitude}&longitude=${longitude}&userId=${encodeURIComponent(userId)}`, {
                    method: 'GET'
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }
                    return response.text().then(text => {
                        try {
                            const data = JSON.parse(text);
                            if (data.status === 'success') {
                                status.innerText = `Location saved to server at ${new Date().toLocaleTimeString('en-GB')}!`;
                                syncLocations();
                            } else {
                                throw new Error(data.message || 'Server error');
                            }
                        } catch (e) {
                            throw new Error(`Invalid JSON response: ${text}`);
                        }
                    });
                })
                .catch(error => {
                    status.innerText = `Offline or server error: Location saved locally at ${new Date().toLocaleTimeString('en-GB')}.`;
                    console.error(`Failed to save location (${latitude}, ${longitude}, ${timestamp}, ${userId}): ${error.message}`);
                    saveLocationLocally(latitude, longitude, timestamp, userId);
                });
            } else {
                status.innerText = `Offline: Location saved locally at ${new Date().toLocaleTimeString('en-GB')}.`;
                console.log(`Saving location locally: (${latitude}, ${longitude}, ${timestamp}, ${userId})`);
                saveLocationLocally(latitude, longitude, timestamp, userId);
            }
        }

        function showError(error) {
            const messages = {
                1: 'Permission denied. Please allow location access.',
                2: 'Position unavailable. Try again later.',
                3: 'Request timed out.'
            };
            document.getElementById('status').innerText = messages[error.code] || 'Unknown error.';
        }

        let wakeLock = null;

        async function requestWakeLock() {
            try {
                wakeLock = await navigator.wakeLock.request('screen');
                console.log('Wake Lock acquired');
                wakeLock.addEventListener('release', () => {
                    console.log('Wake Lock released');
                });
            } catch (err) {
                console.error('Failed to acquire Wake Lock:', err);
                document.getElementById('status').innerText = `Wake Lock error: ${err.message}`;
            }
        }

        async function releaseWakeLock() {
            if (wakeLock !== null) {
                await wakeLock.release();
                wakeLock = null;
                console.log('Wake Lock released');
            }
        }

        function startTracking() {
            const userId = getUserId();
            if (!userId) {
                document.getElementById('status').innerText = 'Please log in to start tracking.';
                return;
            }

            const interval = parseInt(document.getElementById('interval').value);
            getLocation();
            trackingInterval = setInterval(getLocation, interval);
            document.getElementById('trackButton').classList.add('hidden');
            document.getElementById('stopButton').classList.remove('hidden');
            document.getElementById('status').innerText = `Tracking started! Location will be saved every ${interval / 60000} minute(s).`;
            if (navigator.onLine) syncLocations();
            requestWakeLock();
            document.body.classList.add('tracking-mode');
        }

        function stopTracking() {
            if (trackingInterval) {
                clearInterval(trackingInterval);
                trackingInterval = null;
                document.getElementById('trackButton').classList.remove('hidden');
                document.getElementById('stopButton').classList.add('hidden');
                document.getElementById('status').innerText = 'Tracking stopped.';
                releaseWakeLock();
                document.body.classList.remove('tracking-mode');
            }
        }

        function pushToServer() {
            syncLocations();
        }

        function viewClearLocalData() {
            const userId = getUserId();
            if (!userId) {
                document.getElementById('status').innerText = 'Please log in to view or clear local data.';
                return;
            }

            const pendingLocations = getPendingLocations();
            if (pendingLocations.length === 0) {
                document.getElementById('status').innerText = 'No local data to view or clear.';
                return;
            }

            const confirmClear = confirm(`Found ${pendingLocations.length} location(s) in local storage:\n${JSON.stringify(pendingLocations, null, 2)}\n\nDo you want to clear all local data?`);
            if (confirmClear) {
                localStorage.removeItem('pendingLocations');
                updateLocalCount();
                document.getElementById('status').innerText = 'Local data cleared.';
            }
        }

        function downloadAppFile() {
            const userId = getUserId();
            if (!userId) {
                document.getElementById('status').innerText = 'Please log in to download the app file.';
                return;
            }

            const status = document.getElementById('status');
            status.innerText = 'Preparing download...';

            fetch('https://breezbee.co.uk/location/locate.html')
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }
                    return response.text();
                })
                .then(text => {
                    const blob = new Blob([text], { type: 'text/html' });
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'local.html';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    window.URL.revokeObjectURL(url);
                    status.innerText = 'Download started for local.html.';
                })
                .catch(error => {
                    status.innerText = `Error downloading file: ${error.message}. Try again or check console.`;
                    console.error('Download failed:', error);
                });
        }

        function shareLocation() {
            const userId = getUserId();
            if (!userId) {
                document.getElementById('status').innerText = 'Please log in to share your location.';
                return;
            }

            const shareUrl = `https://breezbee.co.uk/location/view.php?userId=${encodeURIComponent(userId)}`;

            if (navigator.share) {
                navigator.share({
                    title: 'My Live Location',
                    text: 'Check my live location here:',
                    url: shareUrl
                }).then(() => {
                    document.getElementById('status').innerText = 'Location link shared successfully!';
                }).catch(err => {
                    console.error('Share failed:', err);
                    document.getElementById('status').innerText = 'Sharing failed. Try again.';
                });
            } else {
                navigator.clipboard.writeText(shareUrl).then(() => {
                    document.getElementById('status').innerText = 'Link copied to clipboard: ' + shareUrl;
                }).catch(err => {
                    console.error('Clipboard error:', err);
                    document.getElementById('status').innerText = 'Unable to copy link. Please copy manually: ' + shareUrl;
                });
            }
        }

        // Register Service Worker
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/location/sw.js').then(registration => {
                    console.log('Service Worker registered:', registration);
                }).catch(error => {
                    console.error('Service Worker registration failed:', error);
                    document.getElementById('status').innerText = `Service Worker registration failed: ${error.message}`;
                });
            });
        }

        // Sync when back online
        window.addEventListener('online', () => {
            const userId = getUserId();
            if (userId) {
                document.getElementById('status').innerText = 'Back online, checking for locations to sync...';
                syncLocations();
            }
        });

        // Warn about PHP pages offline
        window.addEventListener('offline', () => {
            document.getElementById('status').innerText = 'Offline: Locations will be saved locally. Use "Push to Server" when online.';
        });

        // Initialize UI on DOM content loaded
        document.addEventListener('DOMContentLoaded', () => {
            console.log('DOMContentLoaded fired, initializing UI');
            initializeUI();
        });
    </script>
</body>
</html>