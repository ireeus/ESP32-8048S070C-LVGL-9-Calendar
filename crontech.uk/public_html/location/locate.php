<?php
// Initialize SQLite database
$db = new SQLite3('locations.db');

// Create table if it doesn't exist
$db->exec('CREATE TABLE IF NOT EXISTS coordinates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    latitude REAL NOT NULL,
    longitude REAL NOT NULL,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
)');

// Handle POST request for GPS coordinates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $latitude = filter_input(INPUT_POST, 'latitude', FILTER_VALIDATE_FLOAT);
    $longitude = filter_input(INPUT_POST, 'longitude', FILTER_VALIDATE_FLOAT);

    if ($latitude !== false && $longitude !== false) {
        $stmt = $db->prepare('INSERT INTO coordinates (latitude, longitude) VALUES (:lat, :lon)');
        $stmt->bindValue(':lat', $latitude, SQLITE3_FLOAT);
        $stmt->bindValue(':lon', $longitude, SQLITE3_FLOAT);
        $stmt->execute();
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid coordinates']);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Locate Device</title>
    <link rel="manifest" href="/manifest.json">
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
    </style>
</head>
<body class="bg-gradient-to-br from-blue-100 to-purple-100 min-h-screen flex items-center justify-center">
    
	<div class="bg-white p-8 rounded-2xl shadow-xl max-w-md w-full"> <?php include('menu.php');?>
        <h1 class="text-3xl font-bold text-center text-gray-800 mb-6">Capture Your Location</h1>
        <button 
            onclick="startTracking()" 
            class="w-full bg-blue-600 text-white py-3 rounded-lg hover:bg-blue-700 transition duration-300 pulse"
            id="trackButton"
        >
            Capture Your Location
        </button>
        <button 
            onclick="stopTracking()" 
            class="w-full bg-red-600 text-white py-3 rounded-lg hover:bg-red-700 transition duration-300 hidden"
            id="stopButton"
        >
            Stop Tracking
        </button>
        <p id="status" class="mt-4 text-center text-gray-600"></p>
        <a href="view.php" class="block mt-4 text-center text-blue-600 hover:underline">View Location History</a>
        <a href="archive_view.php" class="block mt-2 text-center text-blue-600 hover:underline">View Archived Locations</a>
    </div>

    <script>
        let trackingInterval = null;

        // Load pending locations from local storage
        function getPendingLocations() {
            const pending = localStorage.getItem('pendingLocations');
            return pending ? JSON.parse(pending) : [];
        }

        // Save location to local storage
        function saveLocationLocally(latitude, longitude, timestamp) {
            const pendingLocations = getPendingLocations();
            pendingLocations.push({ latitude, longitude, timestamp });
            localStorage.setItem('pendingLocations', JSON.stringify(pendingLocations));
            return pendingLocations.length;
        }

        // Sync pending locations with server
        function syncLocations() {
            const pendingLocations = getPendingLocations();
            if (pendingLocations.length === 0) return;

            const status = document.getElementById('status');
            status.innerText = `Syncing ${pendingLocations.length} location(s)...`;

            Promise.all(pendingLocations.map(location => 
                fetch('locate.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `latitude=${location.latitude}&longitude=${location.longitude}`
                })
                .then(response => response.json())
                .then(data => ({ success: data.status === 'success', location }))
                .catch(() => ({ success: false, location }))
            )).then(results => {
                const successful = results.filter(r => r.success).map(r => r.location);
                if (successful.length > 0) {
                    const remaining = pendingLocations.filter(loc => 
                        !successful.some(s => 
                            s.latitude === loc.latitude && 
                            s.longitude === loc.longitude && 
                            s.timestamp === loc.timestamp
                        )
                    );
                    localStorage.setItem('pendingLocations', JSON.stringify(remaining));
                    status.innerText = `Synced ${successful.length} location(s) at ${new Date().toLocaleTimeString()}!`;
                }
            }).catch(error => {
                status.innerText = `Sync error: ${error.message}`;
            });
        }

        function getLocation() {
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
            const { latitude, longitude } = position.coords;
            const timestamp = new Date().toISOString();
            const status = document.getElementById('status');

            if (navigator.onLine) {
                fetch('locate.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `latitude=${latitude}&longitude=${longitude}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        status.innerText = `Location saved at ${new Date().toLocaleTimeString()}!`;
                        syncLocations(); // Try syncing any pending locations
                    } else {
                        status.innerText = `Error: ${data.message}`;
                    }
                })
                .catch(error => {
                    status.innerText = `Offline: Location saved locally.`;
                    saveLocationLocally(latitude, longitude, timestamp);
                });
            } else {
                status.innerText = `Offline: Location saved locally.`;
                saveLocationLocally(latitude, longitude, timestamp);
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

        function startTracking() {
            getLocation(); // Get initial location
            trackingInterval = setInterval(getLocation, 300000); // Every 5 minutes
            document.getElementById('trackButton').classList.add('hidden');
            document.getElementById('stopButton').classList.remove('hidden');
            document.getElementById('status').innerText = 'Tracking started! Location will be saved every 5 minutes.';
            syncLocations(); // Attempt to sync any pending locations
        }

        function stopTracking() {
            if (trackingInterval) {
                clearInterval(trackingInterval);
                trackingInterval = null;
                document.getElementById('trackButton').classList.remove('hidden');
                document.getElementById('stopButton').classList.add('hidden');
                document.getElementById('status').innerText = 'Tracking stopped.';
            }
        }

        // Check for pending locations on page load and sync if online
        window.addEventListener('load', () => {
            if (navigator.onLine) {
                syncLocations();
            }
        });

        // Sync when back online
        window.addEventListener('online', syncLocations);

        // Register Service Worker
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/sw.js').then(registration => {
                    console.log('Service Worker registered:', registration);
                }).catch(error => {
                    console.error('Service Worker registration failed:', error);
                });
            });
        }
    </script>
</body>
</html>