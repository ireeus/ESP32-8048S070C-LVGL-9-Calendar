<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CTTA | Official Tester Verification Card</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <style>
        :root { 
            --primary: #ffb703;  
            --primary-hover: #fb8500;   
            --bg-body: #f4f7f9;        
            --bg-card: #ffffff;        
            --text-main: #111827;      
            --text-muted: #4b5563;     
            --border-light: #e5e7eb;    
        }
        body { font-family: 'Montserrat', sans-serif; background: #f4f7f9; margin: 0; padding: 0; color: #1f2937; display: flex; flex-direction: column; min-height: 100vh;}
        
        .hero-banner { background: #111827; padding: 40px 15px; text-align: center; color: #fff; }
        .hero-banner h1 { font-size: 28px; font-weight: 800; margin: 0 0 10px 0; letter-spacing: 1px; }
        @media(min-width: 600px) { .hero-banner h1 { font-size: 32px; } }
        .hero-banner h1 span { color: var(--primary); }
        .hero-banner p { font-size: 14px; color: #d1d5db; margin: 0; }
        @media(min-width: 600px) { .hero-banner p { font-size: 15px; } }
        
        .container { max-width: 800px; margin: -25px auto 40px auto; padding: 0 15px; position: relative; z-index: 10; width: 100%; box-sizing: border-box;}
        .search-box { background: #fff; padding: 25px 20px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); text-align: center; border: 1px solid #e5e7eb;}
        @media(min-width: 600px) { .search-box { padding: 30px; } }
        .search-box input { width: 100%; max-width: 400px; padding: 14px; border: 2px solid #d1d5db; border-radius: 8px; font-size: 15px; outline: none; transition: border 0.3s; margin-bottom: 15px; text-align: center; box-sizing: border-box;}
        .search-box input:focus { border-color: #ffb703; }
        
        .button-group { display:flex; justify-content:center; gap:10px; flex-wrap:wrap; }
        
        .btn-action { background: #ffb703; color: #000; border: none; padding: 14px 24px; font-size: 14px; font-weight: 800; border-radius: 8px; cursor: pointer; transition: 0.2s; text-transform: uppercase; display: inline-flex; align-items: center; justify-content: center; gap: 8px;}
        .btn-action:hover { background: #fb8500; transform: translateY(-1px); }
        .btn-qr { background: #111827; color: #ffffff; border: 1px solid #374151; }
        .btn-qr:hover { background: #1f2937; color: var(--primary); }

        /* Mobile specific adjustments for buttons */
        @media(max-width: 480px) {
            .btn-action { width: 100%; }
        }

        #results { margin-top: 0; }

        /* OFFICIAL PERMISSION CARD STYLING */
        .id-card {
            background: #ffffff;
            border-radius: 16px;
            border: 2px solid #111827;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-bottom: 25px;
            position: relative;
        }

        .id-card-header {
            background: #111827;
            color: #ffffff;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 4px solid var(--primary);
            position: relative;
            z-index: 2;
        }

        /* Stack header elements on very small screens */
        @media(max-width: 480px) {
            .id-card-header { flex-direction: column; gap: 10px; text-align: center; }
        }

        .id-card-header .header-title {
            font-size: 14px;
            font-weight: 800;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }

        .id-card-header .header-title span { color: var(--primary); }

        .hologram-pill {
            background: linear-gradient(135deg, #e0e7ff 0%, #fef3c7 50%, #d1fae5 100%);
            border: 1px solid #cbd5e1;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 800;
            color: #1e293b;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        /* CARD BODY WITH EMBEDDED BLURRED WATERMARK LOGO */
        .id-card-body {
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 20px;
            position: relative;
            z-index: 1;
        }
        @media(min-width: 600px) {
            .id-card-body { padding: 25px; flex-direction: row; }
        }

        /* Watermark Background Element */
        .id-card-body::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 260px;
            height: 260px;
            background-image: url('eYdfx.jpg');
            background-repeat: no-repeat;
            background-position: center;
            background-size: contain;
            opacity: 0.12; 
            filter: blur(2px); 
            pointer-events: none;
            z-index: -1;
        }

        .photo-wrapper { position: relative; flex-shrink: 0; align-self: center; }

        .mc-photo {
            width: 130px; height: 140px; border-radius: 10px; object-fit: cover;
            border: 3px solid #111827; background: #f9fafb; box-shadow: 0 4px 10px rgba(0,0,0,0.08);
        }

        .photo-stamp {
            position: absolute; bottom: -8px; right: -8px; background: #111827; color: var(--primary);
            font-size: 10px; font-weight: 800; padding: 3px 8px; border-radius: 4px; border: 1px solid var(--primary);
        }

        .details-wrapper { flex: 1; display: flex; flex-direction: column; justify-content: space-between; gap: 15px; }
        
        .card-row { display: grid; grid-template-columns: 1fr; gap: 12px; }
        @media(min-width: 500px) { .card-row { grid-template-columns: 1fr 1fr; margin-bottom: 12px; gap: 10px; } }
        
        .card-field { display: flex; flex-direction: column; }
        .card-label { font-size: 10px; font-weight: 800; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; }
        .card-value { font-size: 14px; font-weight: 700; color: #111827; margin-top: 2px; word-break: break-word; }

        .status-badge {
            display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 6px;
            font-weight: 800; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; width: fit-content;
        }

        .badge-verified { background: rgba(209, 250, 229, 0.85); color: #065f46; border: 1px solid #34d399; }
        .badge-expired { background: rgba(254, 243, 199, 0.85); color: #b45309; border: 1px solid #fbbf24; }
        .badge-invalid { background: rgba(254, 226, 226, 0.85); color: #991b1b; border: 1px solid #f87171; }

        .cert-container { background: rgba(249, 250, 251, 0.85); border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 15px; }
        .cert-list { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
        .cert-badge {
            background: #ffffff; color: #1e40af; padding: 4px 10px; border-radius: 4px;
            font-size: 11px; font-weight: 800; border: 1px solid #bfdbfe; box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }
        .cert-badge-redacted {
            background: #fef2f2; color: #991b1b; border: 1px solid #f87171;
            padding: 4px 10px; border-radius: 4px; font-size: 11px; font-weight: 800; box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }

        .permission-notice {
            padding: 15px; font-size: 12px; font-weight: 700; line-height: 1.5; border-top: 1px solid #e5e7eb;
            display: flex; align-items: flex-start; gap: 10px; position: relative; z-index: 2;
        }
        @media(min-width: 600px) { .permission-notice { padding: 15px 20px; align-items: center; gap: 12px; } }

        .notice-verified { background: #f0fdf4; color: #065f46; border-bottom-left-radius: 14px; border-bottom-right-radius: 14px; }
        .notice-expired { background: #fffbeb; color: #b45309; border-bottom-left-radius: 14px; border-bottom-right-radius: 14px; }
        .notice-invalid { background: #fef2f2; color: #991b1b; border-bottom-left-radius: 14px; border-bottom-right-radius: 14px; }

        .no-results { text-align: center; padding: 30px 15px; color: #6b7280; font-weight: 600; background: #fff; border-radius: 12px; border: 1px solid #e5e7eb;}
        
        .modal-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(17, 24, 39, 0.7); z-index: 9999;
            display: flex; justify-content: center; align-items: center;
            backdrop-filter: blur(4px);
            padding: 15px; box-sizing: border-box;
        }
        .modal-box {
            background: #ffffff; width: 100%; max-width: 450px;
            border-radius: 16px; padding: 25px 20px; box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            text-align: center; box-sizing: border-box;
        }

        .site-footer { background: #111827; color: #9ca3af; padding: 30px 15px; text-align: center; font-size: 13px; margin-top: auto;}
        .site-footer span { color: var(--primary); font-weight: bold; }
        .footer-links { margin-top: 15px; display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; }
        .footer-links a { color: #d1d5db; text-decoration: none; transition: color 0.3s;}
        .footer-links a:hover { color: var(--primary); }
    </style>
</head>
<body>

<?php 
if (file_exists('cron_menu.php')) {
    include 'cron_menu.php'; 
}
?>

<div class="hero-banner">
    <h1>CT<span>TA</span></h1>
    <p>CronTech Testers Association - Official Verification Register</p>
</div>

<div class="container" style="flex: 1;">
    <div class="search-box">
        
        <!-- Search controls wrapped in a div to easily hide/show -->
        <div id="search-controls">
            <h2 style="margin-top:0;">Verify a Tester</h2>
            <p style="color:#6b7280; font-size:14px; margin-bottom:20px;">Enter a CTTA ID number or scan the QR code on their ID card.</p>
            
            <input type="text" id="search-input" placeholder="e.g. CTTA-0001">
            <br>
            
            <div class="button-group">
                <button class="btn-action" onclick="searchMember()">Search Register</button>
                <button class="btn-action btn-qr" onclick="startScanner()">📷 Scan QR Code</button>
            </div>
            <p style="color:#6b7280; font-size:14px; margin-top:20px;"><a href="apply.php" style="color:#2563eb; text-decoration:none; font-weight:600;">Become a member</a></p>
        </div>

        <!-- Results div -->
        <div id="results"></div>
    </div>
</div>

<div id="scanner-modal" class="modal-overlay" style="display: none;">
    <div class="modal-box">
        <h3 style="margin-top:0; color:#1f2937;">Scan CTTA Member ID</h3>
        <p style="font-size:12px; color:#6b7280; margin-bottom:15px;">Point your camera at the QR code on the physical ID card.</p>
        
        <div id="reader" style="width: 100%; border-radius: 8px; overflow: hidden; background: #000;"></div>
        
        <button class="btn-action btn-qr" style="margin-top: 20px; width: 100%;" onclick="stopScanner()">Cancel</button>
    </div>
</div>

<footer class="site-footer">
    <div style="max-width: 1200px; margin: 0 auto;">
        <p>&copy; <?php echo date('Y'); ?> Cron<span>Tech</span> Electrical Services. All rights reserved.</p>
        <p>Providing compliant EICR and PAT testing across the UK.</p>
        <div class="footer-links">
            <a href="about.php">About Us</a> <span>|</span>
            <a href="contact.php">Contact</a> <span>|</span>
            <a href="index.php">Book an Inspection</a>
        </div>
    </div>
</footer>

<script>
    let html5QrcodeScanner = null;

    document.addEventListener("DOMContentLoaded", () => {
        const urlParams = new URLSearchParams(window.location.search);
        const queryParam = urlParams.get('query');
        if (queryParam) {
            document.getElementById('search-input').value = queryParam.trim();
            searchMember();
        }
    });

    function closeResult() {
        // Clear the result and input, then show the search controls again
        document.getElementById('results').innerHTML = '';
        document.getElementById('search-input').value = '';
        document.getElementById('search-controls').style.display = 'block';
    }

    function searchMember() {
        const query = document.getElementById('search-input').value.toUpperCase().replace(/[\u0000-\u001F\u007F-\u009F]/g, "").trim();
        const resultsDiv = document.getElementById('results');
        
        if(!query || !query.startsWith('CTTA-')) {
            resultsDiv.innerHTML = '<div class="no-results" style="margin-top: 20px;">Please enter a valid CTTA ID number (e.g., CTTA-0001).</div>';
            return;
        }
        
        // Hide the search controls when processing a valid query
        document.getElementById('search-controls').style.display = 'none';
        resultsDiv.innerHTML = '<div class="no-results" style="margin-top: 15px;">Searching official register...</div>';

        const cacheBuster = new Date().getTime();
        
        fetch(`api_eicr.php?action=verify_member&query=${encodeURIComponent(query)}&_cb=${cacheBuster}`)
        .then(res => res.json())
        .then(data => {
            if(!data.success || data.data.length === 0) {
                // If not found, show error and bring search controls back
                document.getElementById('search-controls').style.display = 'block';
                resultsDiv.innerHTML = '<div class="no-results" style="margin-top: 20px;">No registered tester found matching this ID. Please check and try again.</div>';
                return;
            }
            
            const m = data.data[0]; 
            
            let is_valid = m.is_valid;
            let is_expired = (m.status === 'EXPIRED');
            let is_suspended = (m.status === 'SUSPENDED');

            let badgeClass = 'badge-verified';
            let noticeClass = 'notice-verified';
            let icon = '✅';
            let statementText = '';
            let hologramText = '🛡️ CTTA ID';
            let nameColor = '';

            if (is_valid) {
                badgeClass = 'badge-verified';
                noticeClass = 'notice-verified';
                icon = '✅';
                hologramText = '🛡️ CTTA ID';
                statementText = `<strong>ACTIVE REGISTRATION:</strong> This individual is currently listed on the CTTA register. We have reviewed their submitted qualifications, insurance, and equipment calibration records. Please note: CTTA acts solely as an informational directory, not a statutory licensing body. We advise all clients to perform their own due diligence.`;
            } else if (is_expired) {
                badgeClass = 'badge-expired';
                noticeClass = 'notice-expired';
                icon = '⚠️';
                hologramText = '⚠️ EXPIRED ID CARD';
                nameColor = 'color:#b45309; font-family:monospace;';
                statementText = `<strong>NOTICE - CREDENTIALS EXPIRED:</strong> The documentation associated with CTTA Card ID <strong>${m.id}</strong> has <strong>EXPIRED</strong>. Personal identifying details have been masked. We are currently unable to confirm their active insurance or equipment calibration status. We strongly advise requesting up-to-date compliance documents directly from the individual before proceeding.`;
            } else if (is_suspended) {
                badgeClass = 'badge-invalid';
                noticeClass = 'notice-invalid';
                icon = '⛔';
                hologramText = '⛔ SUSPENDED ID CARD';
                nameColor = 'color:#dc2626; font-family:monospace;';
                statementText = `<strong>CRITICAL NOTICE - ACCOUNT SUSPENDED:</strong> The profile associated with CTTA Card ID <strong>${m.id}</strong> is currently <strong>SUSPENDED</strong>. This account could not be successfully validated or has breached our terms and conditions. Personal details have been masked. We strongly advise exercising caution, as we cannot vouch for the compliance or legality of any certificates issued under this ID.`;
            }
            
            let certsHtml = is_valid 
                ? m.qualifications.split(',').map(c => `<span class="cert-badge">🛡️ ${c.trim()}</span>`).join('')
                : `<span class="cert-badge-redacted">⚠️ CERTIFICATIONS REDACTED</span>`;
            
            let imgSrc = (is_valid && m.photo) 
                ? m.photo 
                : `https://ui-avatars.com/api/?name=*%20*&background=111827&color=ffb703&size=120`;

            resultsDiv.innerHTML = `
                <div style="position: relative; text-align: left; padding-top: 15px;">
                    <!-- Close Button positioned to not clip on mobile viewports -->
                    <button onclick="closeResult()" style="position: absolute; top: 0px; right: 0px; background: #ef4444; color: white; border: none; border-radius: 50%; width: 34px; height: 34px; cursor: pointer; font-weight: bold; z-index: 10; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 6px rgba(0,0,0,0.15);">X</button>
                    
                    <div class="id-card" style="margin-bottom: 0;">
                        <div class="id-card-header">
                            <div class="header-title">Cron<span>Tech</span> Testers Register</div>
                            <div class="hologram-pill">${hologramText}</div>
                        </div>
                        
                        <div class="id-card-body">
                            <div class="photo-wrapper">
                                <img src="${imgSrc}" alt="Photo of ${m.name}" class="mc-photo">
                                <div class="photo-stamp">${m.id}</div>
                            </div>
                            
                            <div class="details-wrapper">
                                <div class="card-row">
                                    <div class="card-field">
                                        <span class="card-label">Licensed Engineer</span>
                                        <span class="card-value" style="letter-spacing:1px; ${nameColor}">${m.name}</span>
                                    </div>
                                    <div class="card-field">
                                        <span class="card-label">Registered Company</span>
                                        <span class="card-value" style="letter-spacing:1px; ${nameColor}">${m.company}</span>
                                    </div>
                                </div>
                                
                                <div class="card-row">
                                    <div class="card-field">
                                        <span class="card-label">Compliance Status</span>
                                        <div class="status-badge ${badgeClass}" style="margin-top:4px;">
                                            ${icon} ${m.status}
                                        </div>
                                    </div>
                                    <div class="card-field">
                                        <span class="card-label">Documents Valid Until</span>
                                        <span class="card-value" style="color: ${is_valid ? '#059669' : '#dc2626'};">${is_valid ? m.valid_until : 'EXPIRED / REVOKED'}</span>
                                    </div>
                                </div>
                                
                                <div class="cert-container">
                                    <span class="card-label">Verified Qualifications & Competencies</span>
                                    <div class="cert-list">
                                        ${certsHtml}
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="permission-notice ${noticeClass}">
                            <div style="font-size: 20px; flex-shrink: 0;">${is_valid ? '📜' : (is_expired ? '⚠️' : '⛔')}</div>
                            <div>${statementText}</div>
                        </div>
                    </div>
                </div>
            `;
        })
        .catch(err => {
            // Restore controls on network error
            document.getElementById('search-controls').style.display = 'block';
            resultsDiv.innerHTML = '<div class="no-results" style="margin-top: 20px;">Network error. Please try again later.</div>';
        });
    }

    function startScanner() {
        document.getElementById('scanner-modal').style.display = 'flex';
        
        html5QrcodeScanner = new Html5Qrcode("reader");
        // Scaled the qrbox size down slightly to fit smaller mobile screens better
        html5QrcodeScanner.start(
            { facingMode: "environment" }, 
            { fps: 10, qrbox: { width: 200, height: 200 } },
            onScanSuccess,
            onScanFailure
        ).catch(err => {
            alert("Unable to access camera: " + err);
            stopScanner();
        });
    }

    function onScanSuccess(decodedText, decodedResult) {
        stopScanner();
        
        let cleanText = decodedText.replace(/[\u0000-\u001F\u007F-\u009F]/g, "").trim();
        let extractedId = cleanText;

        if (cleanText.includes('query=')) {
            let match = cleanText.match(/query=([A-Za-z0-9\-]+)/i);
            if (match && match[1]) {
                extractedId = match[1].trim();
            }
        } else if (cleanText.includes('CTTA-')) {
            let match = cleanText.match(/(CTTA-\d+)/i);
            if (match && match[1]) {
                extractedId = match[1].trim();
            }
        }

        document.getElementById('search-input').value = extractedId;
        searchMember();
    }

    function onScanFailure(error) {}

    function stopScanner() {
        if (html5QrcodeScanner) {
            html5QrcodeScanner.stop().then(() => {
                html5QrcodeScanner.clear();
                document.getElementById('scanner-modal').style.display = 'none';
            }).catch(() => {
                document.getElementById('scanner-modal').style.display = 'none';
            });
        } else {
            document.getElementById('scanner-modal').style.display = 'none';
        }
    }

    document.getElementById("search-input").addEventListener("keypress", function(event) {
        if (event.key === "Enter") {
            event.preventDefault();
            searchMember();
        }
    });
</script>

</body>
</html>