<?php
// Calendar generation (assuming it's defined somewhere, but not in the provided code)
// For the sake of completeness, I'll include a placeholder
$calendar = '<table class="calendar"><thead><tr><th>S</th><th>M</th><th>T</th><th>W</th><th>T</th><th>F</th><th>S</th></tr></thead><tbody><!-- Calendar cells --></tbody></table>';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendar App Layout Preview</title>
    <link href="https://cdn.jsdelivr.net/npm/@fontsource/montserrat@5.0.18/index.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/weather-icons/2.0.9/css/weather-icons.min.css">
    <style>
        @font-face {
            font-family: 'WeatherIcons';
            src: url('https://cdnjs.cloudflare.com/ajax/libs/weather-icons/2.0.9/font/weathericons-regular-webfont.eot');
            src: url('https://cdnjs.cloudflare.com/ajax/libs/weather-icons/2.0.9/font/weathericons-regular-webfont.eot?#iefix') format('embedded-opentype'),
                 url('https://cdnjs.cloudflare.com/ajax/libs/weather-icons/2.0.9/font/weathericons-regular-webfont.woff2') format('woff2'),
                 url('https://cdnjs.cloudflare.com/ajax/libs/weather-icons/2.0.9/font/weathericons-regular-webfont.woff') format('woff'),
                 url('https://cdnjs.cloudflare.com/ajax/libs/weather-icons/2.0.9/font/weathericons-regular-webfont.ttf') format('truetype'),
                 url('https://cdnjs.cloudflare.com/ajax/libs/weather-icons/2.0.9/font/weathericons-regular-webfont.svg#weather_iconsregular') format('svg');
            font-weight: normal;
            font-style: normal;
        }
        :root {
            --bg-gray: 255;
            --text-color: #000000;
            --main-text-color: #636e72; /* Default for light mode */
            --temp-text-color: #2d3436;
        }
        body {
            font-family: 'Montserrat', sans-serif;
            margin: 20px;
            text-align: center;
        }
        .preview-controls {
            margin-bottom: 20px;
        }
        .screen {
            position: relative;
            width: 800px;
            height: 480px;
            margin: auto;
            border: 1px solid #000;
            overflow: hidden;
            background-color: rgb(var(--bg-gray), var(--bg-gray), var(--bg-gray));
            color: var(--text-color);
            font-size: 14px;
        }
        .date-time {
            position: absolute;
            top: 10px;
            left: 50%;
            transform: translateX(-50%);
        }
        .month-label {
            position: absolute;
            top: 30px;
            left: 10px;
            width: 350px;
            text-align: center;
        }
        .calendar-container {
            position: absolute;
            left: 10px;
            top: 50px;
            width: 350px;
            height: 350px;
        }
        table.calendar {
            width: 100%;
            height: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .calendar th, .calendar td {
            border: 1px solid #ddd;
            text-align: center;
            vertical-align: middle;
        }
        .calendar th {
            background-color: rgba(0,0,0,0.1);
            height: 20px; /* Approximate day header height */
        }
        .today {
            border: 2px solid #0000FF !important;
            color: #0000FF;
        }
        .button-bar {
            position: absolute;
            left: 10px;
            top: 410px;
            width: 350px;
            height: 40px;
            display: flex;
            justify-content: space-evenly;
            align-items: center;
        }
        .button-bar button {
            width: 40px;
            height: 40px;
            background-color: #333333;
            color: #FFFFFF;
            border: none;
            border-radius: 5px;
            font-size: 14px;
            cursor: pointer;
        }
        .event-container {
            position: absolute;
            right: 10px;
            top: 50px;
            width: 400px;
            height: 175px;
            background-color: #000000;
            color: #FFFFFF;
            overflow-y: auto;
            padding: 10px;
            box-sizing: border-box;
        }
        .event-item {
            width: 361px;
            height: 65px;
            margin-bottom: 10px;
            padding: 5px;
            border-radius: 10px;
            color: #FFFFFF;
        }
        .event-today {
            background: linear-gradient(to right, #fd79a8, #e84393);
        }
        .event-upcoming-near {
            background: linear-gradient(to right, #a29bfe, #6c5ce7);
        }
        .event-upcoming-far {
            background: linear-gradient(to right, #00b894, #00a085);
        }
        .event-title {
            color: #0000FF;
            font-size: 14px;
        }
        .event-time {
            color: #FFFF00;
            font-size: 14px;
        }
        .event-desc {
            color: #2A2A2A;
            font-size: 14px;
        }
        .weather-container {
            position: absolute;
            right: 10px;
            bottom: 13px;
            width: 400px;
            height: 225px;
            background-color: rgb(var(--bg-gray), var(--bg-gray), var(--bg-gray));
            border-radius: 20px;
            padding: 10px;
            box-sizing: border-box;
            box-shadow: 0 0 40px rgba(0,0,0,0.1);
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }
        #icon-preview {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
        }
        .icon-item {
            margin: 5px;
            text-align: center;
            width: 100px;
        }
        .icon-item i {
            font-size: 50px; /* Default size */
            color: var(--main-text-color);
        }
        .icon-item p {
            font-size: 12px;
            color: var(--main-text-color);
        }
    </style>
    <script src="https://unpkg.com/jszip@3.10.1/dist/jszip.min.js"></script>
    <script>
        const weatherCodes = {
            0: { desc: "Clear sky", icon: "wi-day-sunny", unicode: "\uf00d" },
            1: { desc: "Mainly clear", icon: "wi-day-sunny", unicode: "\uf00d" },
            2: { desc: "Partly cloudy", icon: "wi-day-cloudy", unicode: "\uf002" },
            3: { desc: "Overcast", icon: "wi-cloudy", unicode: "\uf013" },
            45: { desc: "Fog", icon: "wi-fog", unicode: "\uf014" },
            48: { desc: "Depositing rime fog", icon: "wi-fog", unicode: "\uf014" },
            51: { desc: "Light drizzle", icon: "wi-rain-mix", unicode: "\uf017" },
            53: { desc: "Moderate drizzle", icon: "wi-rain-mix", unicode: "\uf017" },
            55: { desc: "Dense drizzle", icon: "wi-rain-mix", unicode: "\uf017" },
            61: { desc: "Slight rain", icon: "wi-rain", unicode: "\uf019" },
            63: { desc: "Moderate rain", icon: "wi-rain", unicode: "\uf019" },
            65: { desc: "Heavy rain", icon: "wi-rain", unicode: "\uf019" },
            71: { desc: "Slight snow fall", icon: "wi-snow", unicode: "\uf01b" },
            73: { desc: "Moderate snow fall", icon: "wi-snow", unicode: "\uf01b" },
            75: { desc: "Heavy snow fall", icon: "wi-snow", unicode: "\uf01b" },
            80: { desc: "Slight rain showers", icon: "wi-showers", unicode: "\uf01a" },
            81: { desc: "Moderate rain showers", icon: "wi-showers", unicode: "\uf01a" },
            82: { desc: "Violent rain showers", icon: "wi-showers", unicode: "\uf01a" },
            95: { desc: "Thunderstorm", icon: "wi-thunderstorm", unicode: "\uf01e" },
            96: { desc: "Thunderstorm with slight hail", icon: "wi-hail", unicode: "\uf015" },
            99: { desc: "Thunderstorm with heavy hail", icon: "wi-hail", unicode: "\uf015" },
            'default': { desc: "Unknown", icon: "wi-cloud", unicode: "\uf041" }
        };

        function updateIconSizes(size) {
            document.querySelectorAll('.icon-item i').forEach(icon => {
                icon.style.fontSize = `${size}px`;
            });
        }

        async function exportIcons() {
            const size = parseInt(document.getElementById('icon-size-slider').value);
            const margin = size * 0.1; // 10% margin
            const effectiveSize = size + 2 * margin;
            const zip = new JSZip();
            for (const [key, info] of Object.entries(weatherCodes)) {
                const desc = info.desc.replace(/ /g, '_');
                const canvas = document.createElement('canvas');
                canvas.width = effectiveSize;
                canvas.height = effectiveSize;
                const ctx = canvas.getContext('2d', { alpha: true });
                // No fill for transparent background
                ctx.font = `${size}px WeatherIcons`;
                ctx.fillStyle = '#000000';
                ctx.textBaseline = 'middle';
                ctx.textAlign = 'center';
                ctx.fillText(info.unicode, effectiveSize / 2, effectiveSize / 2);
                const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
                if (blob) {
                    zip.file(`${desc}.png`, blob);
                }
            }
            const content = await zip.generateAsync({type: 'blob'});
            if (content.size === 0) {
                console.error('Zip file is empty');
                return;
            }
            const url = URL.createObjectURL(content);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'weather_icons_pack.zip';
            a.click();
            URL.revokeObjectURL(url);
        }

        window.onload = () => {
            // Darkness slider
            const darknessSlider = document.getElementById('darkness-slider');
            darknessSlider.addEventListener('input', function() {
                const darkness = parseInt(this.value);
                const gray = 255 - (darkness * 255 / 100);
                document.documentElement.style.setProperty('--bg-gray', gray);
                const textColor = (darkness > 50) ? '#FFFFFF' : '#000000';
                document.documentElement.style.setProperty('--text-color', textColor);
                const mainTextColor = (darkness > 50) ? '#FFFFFF' : '#636e72';
                document.documentElement.style.setProperty('--main-text-color', mainTextColor);
                const tempTextColor = (darkness > 50) ? '#FFFFFF' : '#2d3436';
                document.documentElement.style.setProperty('--temp-text-color', tempTextColor);
                
                // Update icon labels color
                document.querySelectorAll('.icon-item p').forEach(p => {
                    p.style.color = mainTextColor;
                });
                
                // Update icon color
                document.querySelectorAll('.icon-item i').forEach(i => {
                    i.style.color = mainTextColor;
                });
            });

            // Populate icons in weather section using font icons
            const previewDiv = document.getElementById('icon-preview');
            Object.keys(weatherCodes).forEach(key => {
                const info = weatherCodes[key];
                const div = document.createElement('div');
                div.className = 'icon-item';
                const iconElem = document.createElement('i');
                iconElem.className = `wi ${info.icon}`;
                const label = document.createElement('p');
                label.textContent = `${key}: ${info.desc}`;
                div.appendChild(iconElem);
                div.appendChild(label);
                previewDiv.appendChild(div);
            });

            // Icon size slider
            const iconSizeSlider = document.getElementById('icon-size-slider');
            iconSizeSlider.addEventListener('input', e => {
                updateIconSizes(e.target.value);
            });

            // Export PNG ZIP button
            document.getElementById('export-btn').addEventListener('click', exportIcons);
        };
    </script>
</head>
<body>
    <h1>Calendar App Layout Preview</h1>
    <div class="preview-controls">
        <label for="darkness-slider">UI Darkness (0-100): </label>
        <input type="range" id="darkness-slider" min="0" max="100" value="0">
        <label for="icon-size-slider">Icon Size (20-200): </label>
        <input type="range" id="icon-size-slider" min="20" max="200" value="50">
        <button id="export-btn">Download PNG Pack</button>
    </div>
    <div class="screen">
        <div class="date-time">2025-10-05 12:34</div>
        <div class="month-label">October 2025</div>
        <div class="calendar-container">
            <?php echo $calendar; ?>
        </div>
        <div class="button-bar">
            <button>⚙️</button>
            <button>←</button>
            <button>→</button>
        </div>
        <div class="event-container">
            <!-- Sample Today Event -->
            <div class="event-item event-today">
                <div class="event-title">Sample Today Event Title that is quite long...</div>
                <div class="event-time">from 09:00 to 10:00</div>
                <div class="event-desc">Description that is also long...</div>
            </div>
            <!-- Sample Upcoming Near -->
            <div class="event-item event-upcoming-near">
                <div class="event-title">Upcoming Event Near</div>
                <div class="event-time">2025-10-07, from 14:00 to 15:00</div>
                <div class="event-desc">Short desc</div>
            </div>
        </div>
        <div class="weather-container">
            <div id="icon-preview"></div>
        </div>
    </div>
</body>
</html>