<?php
// Connect to SQLite database
$db = new SQLite3('locations.db');

// Handle session close request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['closeSession'])) {
    // Unset cookies server-side
    setcookie('userId', '', time() - 3600, '/', '', false, true);
    setcookie('U_n', '', time() - 3600, '/', '', false, true);
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Handle delete request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    $latitude = $_POST['latitude'];
    $longitude = $_POST['longitude'];
    $timestamp = $_POST['timestamp'];
    $userId = $_POST['userId'];
    
    $stmt = $db->prepare('DELETE FROM coordinates WHERE latitude = :latitude AND longitude = :longitude AND timestamp = :timestamp AND user_id = :userId');
    $stmt->bindValue(':latitude', $latitude, SQLITE3_FLOAT);
    $stmt->bindValue(':longitude', $longitude, SQLITE3_FLOAT);
    $stmt->bindValue(':timestamp', $timestamp, SQLITE3_TEXT);
    $stmt->bindValue(':userId', $userId, SQLITE3_TEXT);
    $stmt->execute();
    
    // Redirect to refresh the page after deletion
    header("Location: " . $_SERVER['PHP_SELF'] . (isset($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}

// Handle date and userId filter, default date to today
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$userId = isset($_GET['userId']) ? filter_input(INPUT_GET, 'userId', FILTER_SANITIZE_SPECIAL_CHARS) : null;

// Check for userId in cookie
$cookieUserId = null;
if (isset($_COOKIE['userId'])) {
    $cookieUserId = filter_var($_COOKIE['userId'], FILTER_SANITIZE_SPECIAL_CHARS);
}

// If userId is provided via GET, set it as a cookie (3 months = 7,776,000 seconds)
if ($userId && !$cookieUserId) {
    setcookie('userId', $userId, time() + 7776000, '/', '', false, true);
    setcookie('U_n', $userId, time() + 7776000, '/', '', false, true); // Set U_n cookie
    $cookieUserId = $userId;
}

// Use cookie userId if available, otherwise use GET userId
$effectiveUserId = $cookieUserId ?: $userId;

// Build query for coordinates table only if userId is present
$coordinates = [];
if ($effectiveUserId) {
    $query = 'SELECT latitude, longitude, timestamp, user_id FROM coordinates';
    $where = [];
    $params = [];

    $where[] = 'user_id = :userId';
    $params[':userId'] = $effectiveUserId;

    if ($date) {
        $where[] = "strftime('%Y-%m-%d', timestamp) = :date";
        $params[':date'] = $date;
    }

    if (!empty($where)) {
        $query .= ' WHERE ' . implode(' AND ', $where);
    }

    $query .= ' ORDER BY timestamp DESC';

    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, $key === ':userId' ? SQLITE3_TEXT : SQLITE3_TEXT);
    }
    $result = $stmt->execute();

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $coordinates[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archived Location History</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        #map { 
            height: calc(100vh - 10rem); 
            width: 100%; 
            border: 1px solid #4B5563; 
            border-radius: 1rem; 
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.2), 0 4px 6px -2px rgba(0, 0, 0, 0.1);
        }
        .custom-pin { 
            filter: drop-shadow(0 0 10px rgba(0, 0, 255, 0.6)); 
        }
        .leaflet-popup-content-wrapper {
            border-radius: 0.5rem;
            background-color: #1F2937;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.2);
        }
        .leaflet-popup-content {
            margin: 1rem;
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        .leaflet-popup-content a, .leaflet-popup-content button {
            display: inline-block;
            margin-top: 0.5rem;
            transition: transform 0.2s ease, background-color 0.2s ease, box-shadow 0.2s ease;
        }
        .leaflet-popup-content a:hover, .leaflet-popup-content button:hover {
            transform: scale(1.05);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }
        .status-message {
            transition: opacity 0.5s ease-in-out;
        }
        .slider-container {
            background-color: #1F2937;
            padding: 1rem;
            border-radius: 0.5rem;
            margin: 1rem auto;
            max-width: 400px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.2);
        }
        #deleteModal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        #deleteModal .modal-content {
            background: #1F2937;
            padding: 1.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.2);
            max-width: 400px;
            width: 90%;
            text-align: center;
        }
    </style>
</head>
<body class="bg-gray-800 min-h-screen">
<?php include('menu.php'); ?>

<?php if (!$effectiveUserId): ?>
    <div class="text-center text-gray-300 my-4 status-message">
        <p>No user ID provided. Please access this page with a valid user ID or ensure cookies are enabled.</p>
    </div>
<?php else: ?>
    <div class="slider-container text-gray-300 text-center">
        <label for="radius">Search Radius (miles): <span id="radius-value">1</span></label>
        <input type="range" id="radius" min="0.1" max="3000" step="0.1" value="1" class="w-full mt-2">
    </div>
    <div id="map"></div>
    <p id="status" class="text-center text-gray-300 my-4 status-message"></p>
    <div class="text-center">
        <form method="post">
            <input type="hidden" name="closeSession" value="1">
            <button id="closeSession" type="submit" class="bg-red-600 text-white py-2 px-4 rounded-md hover:bg-red-700 hover:shadow-md transition duration-300 transform hover:scale-105 text-sm">
                Close Session
            </button>
        </form>
    </div>
<?php endif; ?>

<div id="deleteModal">
    <div class="modal-content">
        <h2 class="text-lg font-semibold text-gray-100 mb-4">Confirm Deletion</h2>
        <p class="text-gray-300 mb-6">Are you sure you want to delete this location?</p>
        <div class="flex justify-center gap-4">
            <button id="confirmDelete" class="bg-red-600 text-white py-2 px-4 rounded-md hover:bg-red-700">Yes, Delete</button>
            <button id="cancelDelete" class="bg-gray-600 text-white py-2 px-4 rounded-md hover:bg-gray-700">Cancel</button>
        </div>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    // Refresh the page every 30 seconds
    setInterval(function() {
        location.reload();
    }, 60000);

    function getUserId() {
        const cookies = document.cookie.split('; ').reduce((acc, cookie) => {
            const [name, value] = cookie.trim().split('=');
            acc[name] = value ? decodeURIComponent(value) : null;
            return acc;
        }, {});
        return cookies.userId || null;
    }

    function closeSession() {
        document.cookie = 'userId=; path=/; max-age=0; SameSite=Strict';
        document.cookie = 'U_n=; path=/; max-age=0; SameSite=Strict';
        window.location.href = '<?php echo $_SERVER['PHP_SELF']; ?>';
    }

    function updateFormWithUserId() {
        const userId = getUserId();
        if (userId) {
            const form = document.getElementById('filterForm');
            if (form) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'userId';
                input.value = userId;
                form.appendChild(input);
            }
        }
    }

    <?php if ($effectiveUserId): ?>
    const coords = <?php echo json_encode($coordinates); ?>;
    let currentForm = null;

    const map = L.map('map').setView(
        coords.length > 0 ? [coords[0].latitude, coords[0].longitude] : [0, 0],
        13
    );

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    }).addTo(map);

    let markerLayer = L.layerGroup().addTo(map);
    let circleLayer = L.layerGroup().addTo(map);

    function getColorForDate(dateStr) {
        const colors = [
            '#1E40AF', '#B91C1C', '#15803D', '#7E22CE', '#B45309',
            '#BE123C', '#047857', '#6B7280', '#7C3AED', '#B45309'
        ];
        let hash = 0;
        for (let i = 0; i < dateStr.length; i++) {
            hash = dateStr.charCodeAt(i) + ((hash << 5) - hash);
        }
        return colors[Math.abs(hash) % colors.length];
    }

    function calculateDistance(lat1, lon1, lat2, lon2) {
        const R = 3958.8;
        const φ1 = lat1 * Math.PI / 180;
        const φ2 = lat2 * Math.PI / 180;
        const Δφ = (lat2 - lat1) * Math.PI / 180;
        const Δλ = (lon2 - lon1) * Math.PI / 180;

        const a = Math.sin(Δφ / 2) * Math.sin(Δφ / 2) +
                  Math.cos(φ1) * Math.cos(φ2) *
                  Math.sin(Δλ / 2) * Math.sin(Δλ / 2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        return R * c;
    }

    function showDeleteModal(form) {
        currentForm = form;
        document.getElementById('deleteModal').style.display = 'flex';
    }

    function hideDeleteModal() {
        document.getElementById('deleteModal').style.display = 'none';
        currentForm = null;
    }

    document.getElementById('confirmDelete').addEventListener('click', function() {
        if (currentForm) {
            currentForm.submit();
        }
        hideDeleteModal();
    });

    document.getElementById('cancelDelete').addEventListener('click', hideDeleteModal);

    function displayMarkers(filteredCoords, isRadiusSearch = false) {
        markerLayer.clearLayers();
        const latlngs = [];

        const coordsByDate = {};
        filteredCoords.forEach(coord => {
            const date = coord.timestamp.split(' ')[0];
            if (!coordsByDate[date]) {
                coordsByDate[date] = [];
            }
            coordsByDate[date].push(coord);
        });

        Object.keys(coordsByDate).forEach((date, dateIndex) => {
            const coords = coordsByDate[date];
            const color = getColorForDate(date);

            coords.forEach((coord, index) => {
                const isLatest = filteredCoords[0] === coord;
                const navLink = `https://www.google.com/maps/dir/?api=1&destination=${coord.latitude},${coord.longitude}`;
                const streetViewLink = `https://www.google.com/maps/@?api=1&map_action=pano&viewpoint=${coord.latitude},${coord.longitude}`;
                let marker;

                if (isLatest) {
                    marker = L.marker([coord.latitude, coord.longitude], {
                        icon: L.divIcon({
                            className: 'custom-pin',
                            html: `<img src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.7.1/images/marker-icon.png">`,
                            iconSize: [48, 48],
                            iconAnchor: [11, 40]
                        })
                    }).addTo(markerLayer);
                    marker.bindPopup(`
                        <div class="p-1.5">
                            <b class="text-base font-semibold text-gray-100">Latest Location</b><br>
                            <span class="text-sm text-gray-400">Time: ${coord.timestamp}</span><br>
                            <a href="${navLink}" target="_blank" class="text-yellow-500 hover:underline font-medium">Navigate to this location</a><br>
                            <a href="${streetViewLink}" target="_blank" class="bg-yellow-600 text-white py-1 px-2 rounded-md hover:bg-yellow-700 hover:shadow-md transition duration-300 transform hover:scale-105 text-sm inline-block mr-2">Street View</a>
                            <form method="post" class="mt-1.5 inline-block">
                                <input type="hidden" name="latitude" value="${coord.latitude}">
                                <input type="hidden" name="longitude" value="${coord.longitude}">
                                <input type="hidden" name="timestamp" value="${coord.timestamp}">
                                <input type="hidden" name="userId" value="${coord.user_id}">
                                <input type="hidden" name="delete" value="1">
                                <?php if (isset($_COOKIE['U_n'])): ?>
                                    <a href="#" onclick="showDeleteModal(this.closest('form'))" class="text-red-500 hover:underline text-sm">Delete</a>
                                <?php endif; ?>
                            </form>
                        </div>
                    `);
                } else {
                    marker = L.circleMarker([coord.latitude, coord.longitude], {
                        radius: 10,
                        fillColor: color,
                        color: color,
                        weight: 1,
                        opacity: 1,
                        fillOpacity: 0.8
                    }).addTo(markerLayer);
                    marker.bindPopup(`
                        <div class="p-1.5">
                            <span class="text-sm text-gray-400">Time: ${coord.timestamp}</span><br>
                            <a href="${navLink}" target="_blank" class="text-yellow-500 hover:underline font-medium">Navigate to this location</a><br>
                            <a href="${streetViewLink}" target="_blank" class="bg-yellow-600 text-white py-1 px-2 rounded-md hover:bg-yellow-700 hover:shadow-md transition duration-300 transform hover:scale-105 text-sm inline-block mr-2">Street View</a>
                            <form method="post" class="mt-1.5 inline-block">
                                <input type="hidden" name="latitude" value="${coord.latitude}">
                                <input type="hidden" name="longitude" value="${coord.longitude}">
                                <input type="hidden" name="timestamp" value="${coord.timestamp}">
                                <input type="hidden" name="userId" value="${coord.user_id}">
                                <input type="hidden" name="delete" value="1">
                                <?php if (isset($_COOKIE['U_n'])): ?>
                                    <a href="#" onclick="showDeleteModal(this.closest('form'))" class="text-red-500 hover:underline text-sm">Delete</a>
                                <?php endif; ?>
                            </form>
                        </div>
                    `);
                }
                latlngs.push([coord.latitude, coord.longitude]);
            });

            if (latlngs.length > 1) {
                L.polyline(latlngs, { color: color, weight: 4, opacity: 0.7 }).addTo(markerLayer);
            }
        });

        if (!isRadiusSearch && latlngs.length > 0) {
            map.fitBounds(latlngs, { padding: [50, 50] });
        }
    }

    displayMarkers(coords);

    map.on('click', function(e) {
        circleLayer.clearLayers();
        const radius = parseFloat(document.getElementById('radius').value);
        const clickedLat = e.latlng.lat;
        const clickedLng = e.latlng.lng;

        L.circle([clickedLat, clickedLng], {
            radius: radius * 1609.34,
            color: '#3B82F6',
            fillColor: '#3B82F6',
            fillOpacity: 0.2
        }).addTo(circleLayer);

        const filteredCoords = coords.filter(coord => {
            const distance = calculateDistance(
                clickedLat, clickedLng,
                coord.latitude, coord.longitude
            );
            return distance <= radius;
        });

        document.getElementById('status').textContent = 
            filteredCoords.length > 0 
                ? `Found ${filteredCoords.length} locations within ${radius} mile radius`
                : `No locations found within ${radius} mile radius`;

        displayMarkers(filteredCoords, true);
    });

    document.getElementById('radius').addEventListener('input', function() {
        document.getElementById('radius-value').textContent = this.value;
    });

    function changeDate(offset) {
        const dateInput = document.getElementById('date');
        const currentDate = new Date(dateInput.value || '<?php echo date('Y-m-d'); ?>');
        currentDate.setDate(currentDate.getDate() + offset);
        const newDate = currentDate.toISOString().split('T')[0];
        dateInput.value = newDate;
        updateFormWithUserId();
        document.getElementById('filterForm').submit();
    }
    <?php endif; ?>

    window.addEventListener('load', function() {
        const userId = getUserId();
        if (userId && !window.location.search.includes('userId=') && !<?php echo json_encode(!$effectiveUserId); ?>) {
            const url = new URL(window.location);
            url.searchParams.set('userId', userId);
            window.history.replaceState(null, '', url.toString());
            window.location.reload();
        }
    });
</script>
</body>
</html>