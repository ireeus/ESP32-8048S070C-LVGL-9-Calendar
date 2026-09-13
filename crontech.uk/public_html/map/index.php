<!DOCTYPE html>
<html>
<head>
    <title>Maritime Precision Navigator</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        :root { --danger: #ff4444; --success: #00ffcc; --bg: #121212; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: sans-serif; background: var(--bg); color: white; height: 100dvh; display: flex; flex-direction: column; overflow: hidden; }
        
        #map-screen { display: flex; flex-direction: column; height: 100%; position: relative; }
        #map-container { flex: 1; overflow: hidden; position: relative; background: #000; }
        #map { width: 100%; height: 100%; transition: transform 0.1s linear; }
        
        #crosshair {
            position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
            z-index: 1002; width: 30px; height: 30px; border: 2px solid var(--success);
            border-radius: 50%; pointer-events: none; opacity: 0; transition: opacity 0.3s;
        }

        .panel { background: #1a1a1a; padding: 10px; border-bottom: 2px solid #333; display: flex; flex-direction: column; gap: 8px; z-index: 1000; }
        .search-row { display: flex; gap: 5px; }
        input { flex: 1; min-width: 0; padding: 10px; border-radius: 5px; border: 1px solid #444; background: #222; color: white; font-size: 16px; }
        
        .cal-panel { background: #252525; padding: 12px; border-radius: 5px; border: 1px solid var(--success); margin: 5px 0; }
        .slider-container { display: flex; flex-direction: column; gap: 10px; width: 100%; }
        input[type=range] { 
            width: 100%; height: 15px; border-radius: 5px; background: #444; 
            outline: none; -webkit-appearance: none;
        }
        input[type=range]::-webkit-slider-thumb {
            -webkit-appearance: none; appearance: none;
            width: 25px; height: 25px; border-radius: 50%; 
            background: var(--success); cursor: pointer;
        }

        #nav-screen { height: 100%; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; padding: 20px; }
        #needle-container { width: 260px; height: 260px; border: 3px solid #333; border-radius: 50%; margin: 15px auto; position: relative; background: radial-gradient(circle, #1a1a1a 0%, #000 80%); }
        #needle { position: absolute; width: 40px; height: 220px; top: 20px; left: calc(50% - 20px); transition: transform 0.05s linear; }
        .needle-north { width: 0; height: 0; border-left: 20px solid transparent; border-right: 20px solid transparent; border-bottom: 110px solid var(--danger); }
        .needle-south { width: 0; height: 0; border-left: 20px solid transparent; border-right: 20px solid transparent; border-top: 110px solid #eee; }

        .hidden { display: none !important; }
        button { padding: 10px; font-weight: bold; border-radius: 6px; border: none; cursor: pointer; font-size: 0.9rem; }
        .btn-set { background: #444; color: white; }
        .btn-main { background: var(--success); color: #000; width: 100%; font-size: 1rem; height: 45px; }
        .btn-reset { background: var(--danger); color: white; font-size: 0.8rem; padding: 8px; width: 100%; margin-top: 10px;}
        .btn-sync { background: #222; color: #888; border: 1px solid #444; width: 100%; }
        .btn-sync.active { border-color: var(--success); color: var(--success); background: #1a332d; }
    </style>
</head>
<body>

<div id="map-screen">
    <div class="panel">
        <div class="search-row">
            <input id="search-from" placeholder="From / Last Fix">
            <button class="btn-set" onclick="findPlace('from')">SET</button>
        </div>
        <div class="search-row">
            <input id="search-to" placeholder="To / Destination">
            <button class="btn-set" onclick="findPlace('to')">SET</button>
        </div>

        <button id="btn-sync" class="btn-sync" onclick="toggleSync()">🧭 SYNC MAP TO TERRAIN: OFF</button>
        
        <div id="cal-controls" class="cal-panel hidden">
            <div class="slider-container">
                <div style="display: flex; justify-content: space-between; font-size: 0.8rem; color: var(--success);">
                    <span>ALIGN MAP (SLIDE):</span>
                    <span style="font-weight: bold; font-size: 1.1rem;"><span id="off-val">-85</span>°</span>
                </div>
                <input type="range" id="cal-slider" min="-180" max="180" value="-85" oninput="handleSlider(this.value)">
                <button class="btn-reset" onclick="resetToCustomDefault()">⚠️ RESET TO -85°</button>
            </div>
        </div>

        <button id="btn-lock" class="btn-main" onclick="startNav()" style="opacity: 0.3;" disabled>BEGIN NAVIGATION</button>
    </div>

    <div id="map-container">
        <div id="crosshair"></div>
        <div id="map"></div>
    </div>
</div>

<div id="nav-screen" class="hidden">
    <div class="stats" style="color:#888">Target: <span id="target-brg" style="color:white">--</span>°</div>
    <div id="needle-container">
        <div id="needle">
            <div class="needle-north" id="needle-tip"></div>
            <div class="needle-south"></div>
        </div>
    </div>
    <div class="stats">Heading: <span id="curr-hdg">--</span>°</div>
    <div id="precision-text" style="min-height: 3em; font-weight: bold; margin-top: 10px;">OFF COURSE</div>
    <button onclick="stopNav()" style="background:#444; color:white; width: 100%; margin-top: 20px; padding: 15px;">RETURN TO MAP</button>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    let myLoc = [25.0, -71.0], targetLoc = null, targetBearing = 0, isSyncing = false;
    let destinationName = "Destination", manualOffset = -85, lastRawHeading = 0;

    const map = L.map('map', { zoomControl: false, attributionControl: false }).setView(myLoc, 4);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);

    let fromMarker = L.marker(myLoc, {draggable: true}).addTo(map);
    let toMarker = L.marker([0,0], {opacity: 0}).addTo(map);

    fromMarker.on('dragend', (e) => { myLoc = [e.target.getLatLng().lat, e.target.getLatLng().lng]; updateLogic(); });
    map.on('click', (e) => { targetLoc = [e.latlng.lat, e.latlng.lng]; updateLogic(); fetchDestName(targetLoc[0], targetLoc[1]); });

    function handleSlider(val) {
        manualOffset = parseInt(val);
        document.getElementById('off-val').innerText = manualOffset;
        updateMapRotation();
    }

    function resetToCustomDefault() {
        manualOffset = -85;
        document.getElementById('cal-slider').value = -85;
        document.getElementById('off-val').innerText = "-85";
        updateMapRotation();
    }

    function toggleSync() {
        if (!isSyncing && typeof DeviceOrientationEvent.requestPermission === 'function') {
            DeviceOrientationEvent.requestPermission().then(res => { if(res === 'granted') proceedToggle(); });
        } else { proceedToggle(); }
    }

    function proceedToggle() {
        isSyncing = !isSyncing;
        const btn = document.getElementById('btn-sync');
        btn.innerText = isSyncing ? "🧭 SYNC MAP TO TERRAIN: ON" : "🧭 SYNC MAP TO TERRAIN: OFF";
        btn.classList.toggle('active', isSyncing);
        document.getElementById('cal-controls').classList.toggle('hidden', !isSyncing);
        document.getElementById('crosshair').style.opacity = isSyncing ? 0.8 : 0;
        if (!isSyncing) document.getElementById('map').style.transform = `rotate(0deg)`;
    }

    function updateMapRotation() {
        if (isSyncing) {
            let correctedHeading = (lastRawHeading + manualOffset + 360) % 360;
            document.getElementById('map').style.transform = `rotate(${-correctedHeading}deg)`;
        }
    }

    window.addEventListener('deviceorientation', (event) => {
        lastRawHeading = event.webkitCompassHeading || (360 - event.alpha);
        if (lastRawHeading !== null) {
            let heading = (lastRawHeading + manualOffset + 360) % 360;
            document.getElementById('curr-hdg').innerText = Math.round(heading);
            updateMapRotation();

            let diff = (targetBearing - heading + 360) % 360;
            let displayDiff = diff > 180 ? diff - 360 : diff;
            let absDiff = Math.abs(displayDiff);
            document.getElementById('needle').style.transform = `rotate(${displayDiff}deg)`;
            
            const tip = document.getElementById('needle-tip');
            const txt = document.getElementById('precision-text');
            if (absDiff <= 1) {
                tip.style.borderBottomColor = "#00ffcc";
                txt.innerHTML = `STEADY ON COURSE TO<br><span style="color:white; font-size:1.1rem;">${destinationName.toUpperCase()}</span>`;
                txt.style.color = "#00ffcc";
            } else if (absDiff <= 5) {
                let hue = 120 - (absDiff * 24);
                tip.style.borderBottomColor = `hsl(${hue}, 100%, 50%)`;
                txt.innerText = "CLOSING IN...";
                txt.style.color = `hsl(${hue}, 100%, 50%)`;
            } else {
                tip.style.borderBottomColor = "#ff4444";
                txt.innerText = "OFF COURSE";
                txt.style.color = "#ff4444";
            }
        }
    }, true);

    async function findPlace(type) {
        const query = document.getElementById(`search-${type}`).value;
        if(!query) return;
        const res = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}`);
        const data = await res.json();
        if (data && data.length > 0) {
            const lat = parseFloat(data[0].lat), lon = parseFloat(data[0].lon);
            if (type === 'from') { myLoc = [lat, lon]; fromMarker.setLatLng(myLoc); }
            else { targetLoc = [lat, lon]; destinationName = data[0].display_name.split(',')[0]; }
            map.setView([lat, lon], 8);
            updateLogic();
        }
    }

    function updateLogic() {
        if (targetLoc) {
            toMarker.setLatLng(targetLoc).setOpacity(1)._icon?.classList.add('marker-green');
            const lat1 = myLoc[0] * Math.PI / 180, lon1 = myLoc[1] * Math.PI / 180;
            const lat2 = targetLoc[0] * Math.PI / 180, lon2 = targetLoc[1] * Math.PI / 180;
            const y = Math.sin(lon2 - lon1) * Math.cos(lat2);
            const x = Math.cos(lat1) * Math.sin(lat2) - Math.sin(lat1) * Math.cos(lat2) * Math.cos(lon2 - lon1);
            targetBearing = (Math.atan2(y, x) * 180 / Math.PI + 360) % 360;
            document.getElementById('btn-lock').disabled = false;
            document.getElementById('btn-lock').style.opacity = 1;
            document.getElementById('target-brg').innerText = Math.round(targetBearing);
        }
    }

    async function fetchDestName(lat, lon) {
        try {
            const res = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lon}`);
            const data = await res.json();
            destinationName = data.address.city || data.address.town || data.address.village || data.address.state || "Marked Point";
        } catch(err) { destinationName = "Marked Point"; }
    }

    function startNav() { document.getElementById('map-screen').classList.add('hidden'); document.getElementById('nav-screen').classList.remove('hidden'); }
    function stopNav() { document.getElementById('nav-screen').classList.add('hidden'); document.getElementById('map-screen').classList.remove('hidden'); }
</script>
</body>
</html>

