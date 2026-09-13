<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>CronTech Electrical | Certified EICR & PAT Testing Services UK</title>
    <meta name="description" content="Professional, fully compliant EICR and PAT testing services for domestic and commercial properties. Transparent pricing and rapid certification by NAPIT / NICEIC engineers.">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { 
            --primary: #ffb703;  
            --primary-hover: #fb8500;   
            --bg-body: #f4f7f9;        
            --bg-card: #ffffff;        
            --text-main: #111827;      
            --text-muted: #4b5563;     
            --border-light: #e5e7eb;    
            --input-bg: #ffffff;       
            --error-red: #ef4444;
            --success-green: #10b981;
        }

        * { box-sizing: border-box; font-family: 'Montserrat', sans-serif; }
        html { scroll-behavior: smooth; }
        body { background-color: var(--bg-body); color: var(--text-main); margin: 0; padding: 0; line-height: 1.6; }

        /* HEADER / NAVBAR */
        .site-header {
            background: #ffffff;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            position: sticky; top: 0; z-index: 100;
        }
        .header-container {
            max-width: 1200px; margin: 0 auto; padding: 15px 20px;
            display: flex; justify-content: space-between; align-items: center;
        }
        .logo { font-size: 24px; font-weight: 800; text-transform: uppercase; color: var(--text-main); text-decoration: none;}
        .logo span { color: var(--primary); }
        
        .header-actions { display: flex; align-items: center; gap: 15px; }
        .contact-info { font-weight: 600; font-size: 14px; color: var(--text-muted); display: none; }
        .contact-info span { color: var(--primary-hover); font-weight: 800; font-size: 16px;}
        
        @media(min-width: 768px) { .contact-info { display: block; } }

        .btn-nav { 
            background: #ffffff; border: 1px solid var(--border-light); color: var(--text-main); 
            padding: 8px 15px; border-radius: 20px; cursor: pointer; font-weight: 700; font-size: 12px; 
            transition: all 0.3s; text-transform: uppercase;
        }
        .btn-nav:hover { background: var(--primary); color: #000; border-color: var(--primary); }

        /* DISCOUNT BANNER */
        .discount-banner {
            width: 100%; background: #fee2e2; color: #b91c1c; text-align: center; padding: 10px; font-weight: 800;
            font-size: 14px; border-bottom: 1px solid #fca5a5; display: none; z-index: 99;
        }
        .discount-banner span.timer { background: #b91c1c; color: #fff; padding: 2px 6px; border-radius: 4px; margin-left: 8px; font-family: monospace; font-size: 15px;}

        /* HERO SECTION */
        .hero-section { position: relative; min-height: 85vh; display: flex; align-items: center; padding: 40px 20px; overflow: hidden; }
        .video-container { position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 0; }
        .video-container video { width: 100%; height: 100%; object-fit: cover; }
        .video-container .overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255, 255, 255, 0.90); backdrop-filter: blur(5px); }

        .hero-container { max-width: 1200px; margin: 0 auto; width: 100%; display: grid; grid-template-columns: 1fr; gap: 40px; align-items: center; z-index: 10; position: relative; }
        @media(min-width: 900px) { .hero-container { grid-template-columns: 1fr 1fr; } }

        .hero-text { text-align: center; }
        @media(min-width: 900px) { .hero-text { text-align: left; } }
        
        .hero-text h1 { font-size: 38px; font-weight: 800; line-height: 1.2; color: var(--text-main); margin: 0 0 20px 0; text-transform: uppercase;}
        .hero-text h1 span { color: var(--primary-hover); }
        .hero-text p.lead { font-size: 16px; font-weight: 500; color: var(--text-muted); margin-bottom: 30px; }
        
        .trust-badge { display: inline-flex; align-items: center; background: #fffbeb; color: #b45309; padding: 8px 15px; border-radius: 20px; font-size: 13px; font-weight: 700; border: 1px solid #fde68a; margin-bottom: 30px;}

        .benefits-list { background: rgba(255,255,255,0.7); border: 1px solid var(--border-light); border-radius: 12px; padding: 20px; display: inline-block; text-align: left; }
        .benefit-item { display: flex; align-items: center; margin-bottom: 12px; font-size: 14px; font-weight: 600; color: var(--text-main); }
        .benefit-item:last-child { margin-bottom: 0; }
        .benefit-icon { display: flex; align-items: center; justify-content: center; width: 24px; height: 24px; background: #ecfdf5; color: #059669; border-radius: 50%; margin-right: 12px; font-size: 12px; }

        /* WIZARD CARD */
        .card-wrapper { width: 100%; max-width: 500px; margin: 0 auto; transition: max-width 0.4s ease; }
        
        .card { 
            background: var(--bg-card); border: 1px solid var(--border-light); border-radius: 16px; 
            box-shadow: 0 15px 35px rgba(0,0,0,0.1); overflow: hidden; position: relative; 
            min-height: 480px; display: flex; flex-direction: column; transition: all 0.3s ease;
        }
        
        .progress-container { background: #f3f4f6; height: 5px; width: 100%; position: absolute; top: 0; left: 0; z-index: 20; }
        .progress-bar { height: 100%; background: var(--primary); width: 0%; transition: width 0.4s ease; border-radius: 0 3px 3px 0; }
        
        .step { padding: 40px 30px; display: none; flex: 1; flex-direction: column; justify-content: center; animation: fadeIn 0.4s; }
        .step.active { display: flex; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        h2.step-title { font-size: 22px; font-weight: 800; color: var(--text-main); margin: 0 0 8px 0; }
        p.subtitle { color: var(--text-muted); text-align: left; margin: 0 0 20px 0; font-size: 13px; font-weight: 500;}
        
        .form-group { margin-bottom: 15px; width: 100%; }
        label.form-label { display: block; font-weight: 700; margin-bottom: 6px; font-size: 12px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
        
        select, input[type="number"], input[type="text"], input[type="email"], input[type="tel"], textarea { 
            width: 100%; padding: 14px; border: 1px solid var(--border-light); border-radius: 8px; 
            font-size: 14px; font-weight: 500; transition: all 0.3s; outline: none; background: var(--input-bg); color: var(--text-main); appearance: none; 
        }
        select:focus, input:focus, textarea:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(255, 183, 3, 0.15); }
        textarea { resize: vertical; min-height: 80px; }
        select { background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22292.4%22%20height%3D%22292.4%22%3E%3Cpath%20fill%3D%22%236b7280%22%20d%3D%22M287%2069.4a17.6%2017.6%200%200%200-13-5.4H18.4c-5%200-9.3%201.8-12.9%205.4A17.6%2017.6%200%200%200%200%2082.2c0%205%201.8%209.3%205.4%2012.9l128%20127.9c3.6%203.6%207.8%205.4%2012.8%205.4s9.2-1.8%2012.8-5.4L287%2095c3.5-3.5%205.4-7.8%205.4-12.8%200-5-1.9-9.2-5.5-12.8z%22%2F%3E%3C%2Fsvg%3E"); background-repeat: no-repeat; background-position: right 14px top 50%; background-size: 12px auto; }

        .btn { width: 100%; padding: 15px; border: none; border-radius: 8px; font-size: 15px; font-weight: 700; cursor: pointer; transition: all 0.3s; text-transform: uppercase; letter-spacing: 1px; display: flex; justify-content: center; align-items: center; }
        .btn-primary { background: var(--primary); color: #111; box-shadow: 0 4px 6px rgba(255, 183, 3, 0.2); }
        .btn-primary:hover { background: var(--primary-hover); transform: translateY(-1px); box-shadow: 0 6px 12px rgba(255, 183, 3, 0.3); }
        .btn-back { background: transparent; color: var(--text-muted); border: 1px solid var(--border-light); margin-top: 10px; }
        .btn-back:hover { background: #f3f4f6; color: var(--text-main); border-color: #d1d5db;}
        
        .price-panel { background: #fffcf0; border: 1px solid #fde08b; border-radius: 8px; padding: 20px; text-align: center; margin-bottom: 20px; }
        .price-panel h3 { margin: 0 0 5px 0; color: #92400e; font-size: 13px; text-transform: uppercase; font-weight: 800; }
        .total-price { font-size: 36px; font-weight: 800; color: var(--text-main); margin: 5px 0 10px 0; }
        .original-price { font-size: 18px; color: #9ca3af; text-decoration: line-through; margin-right: 10px; }
        .deposit-price { font-size: 14px; color: #b45309; font-weight: 700; background: #fef3c7; display: inline-block; padding: 6px 12px; border-radius: 6px; }
        
        .custom-checkbox { display: flex; align-items: flex-start; padding: 15px; background: #f9fafb; border: 1px solid var(--border-light); border-radius: 8px; margin-bottom: 12px; cursor: pointer; transition: all 0.2s; }
        .custom-checkbox:hover { border-color: #cbd5e1; background: #f3f4f6; }
        .custom-checkbox input[type="checkbox"] { 
            appearance: none; -webkit-appearance: none; width: 22px; height: 22px; margin: 0 12px 0 0; padding: 0; 
            cursor: pointer; flex-shrink: 0; border: 1px solid #cbd5e1; border-radius: 4px; background-color: #fff; position: relative; transition: all 0.2s;
        }
        .custom-checkbox input[type="checkbox"]:checked { background-color: var(--primary); border-color: var(--primary); }
        .custom-checkbox input[type="checkbox"]:checked::after { content: ''; position: absolute; left: 7px; top: 2px; width: 5px; height: 10px; border: solid #000; border-width: 0 2px 2px 0; transform: rotate(45deg); }
        .custom-checkbox span { font-size: 12px; color: var(--text-muted); line-height: 1.4; pointer-events: none; font-weight: 600;}
        
        .calendar-wrapper { border: 1px solid var(--border-light); border-radius: 8px; padding: 15px; background: #ffffff; margin-bottom: 15px; }
        .calendar-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .calendar-header button { background: #f3f4f6; border: none; border-radius: 4px; padding: 5px 12px; font-size: 16px; cursor: pointer; color: var(--text-main); font-weight: bold;}
        .calendar-header h3 { margin: 0; font-size: 14px; font-weight: 800; }
        .calendar-days-header { display: grid; grid-template-columns: repeat(7, 1fr); text-align: center; font-weight: 700; font-size: 11px; color: var(--text-muted); margin-bottom: 10px; }
        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
        .calendar-day { padding: 10px 0; text-align: center; border-radius: 4px; font-size: 13px; font-weight: 600; cursor: pointer; background: #fff; border: 1px solid #f3f4f6; color: var(--text-main); }
        .calendar-day:hover:not(.disabled):not(.booked) { background: #fef3c7; border-color: var(--primary); color: #b45309; }
        .calendar-day.disabled { background: transparent; color: #d1d5db; cursor: not-allowed; border-color: transparent;}
        .calendar-day.booked { background: #fee2e2; color: var(--error-red); cursor: not-allowed; text-decoration: line-through; border-color: #fecaca;}
        .calendar-day.selected { background: var(--primary); color: #000; font-weight: 800; border-color: var(--primary); box-shadow: 0 2px 5px rgba(255,183,3,0.3); transform: scale(1.05);}
        
        #paypal-button-container { margin-top: 10px; width: 100%; min-height: 150px; background: #fff; border-radius: 8px; }
        .alert { padding: 20px; border-radius: 10px; text-align: center; }
        .alert-success { background: #ecfdf5; border: 1px solid var(--success-green); color: #065f46; }
        
        /* ==============================================================
           PERFECT SINGLE-LINE BOOKINGS UI
        ============================================================== */
        .booking-row-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 20px;
            background: #fff;
            border: 1px solid var(--border-light);
            border-radius: 8px;
            margin-bottom: 10px;
            gap: 15px;
            transition: all 0.2s ease;
        }
        .booking-row-item:hover { 
            border-color: #cbd5e1; 
            box-shadow: 0 4px 6px rgba(0,0,0,0.02);
            transform: translateY(-1px);
        }
        
        .br-status { 
            flex: 0 0 auto; 
            font-weight: 800; font-size: 11px; padding: 6px 12px; 
            border-radius: 6px; white-space: nowrap; text-transform: uppercase; 
            display: flex; align-items: center; justify-content: center; gap: 6px;
            min-width: 140px;
        }
        
        .br-date { flex: 0 0 auto; font-size: 13px; font-weight: 700; color: var(--text-main); white-space: nowrap;}
        
        .br-address { 
            flex: 1 1 auto; font-size: 13px; font-weight: 500; color: var(--text-muted); 
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 150px; 
        }
        
        .br-details { flex: 0 0 auto; font-size: 13px; font-weight: 800; color: var(--text-main); white-space: nowrap;}
        .br-details span { font-weight: 600; color: var(--text-muted); margin-right: 5px; }
        
        .br-pdf-btn { 
            flex: 0 0 auto;
            background: #fffbeb; color: #b45309; border: 1px solid #fde68a;
            border-radius: 6px; padding: 8px 14px; 
            text-decoration: none; font-weight: 800; font-size: 11px; 
            display: flex; align-items: center; justify-content: center;
            gap: 6px; transition: 0.2s; white-space: nowrap;
            text-transform: uppercase;
        }
        .br-pdf-btn:hover { background: #fde68a; color: #92400e; }

        @media(max-width: 900px) {
            .booking-row-item { flex-wrap: wrap; gap: 10px; }
            .br-status, .br-date, .br-address, .br-details, .br-pdf-btn { flex: 1 1 100%; text-align: left; justify-content: flex-start; }
            .br-address { white-space: normal; }
        }

        .pat-section { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 15px; margin-top: 20px; }

        /* SEO CONTENT SECTIONS */
        .content-section { padding: 80px 20px; max-width: 1200px; margin: 0 auto; }
        .content-section h2 { font-size: 32px; font-weight: 800; text-align: center; margin-bottom: 40px; color: var(--text-main); text-transform: uppercase; }
        
        .grid-2 { display: grid; grid-template-columns: 1fr; gap: 40px; }
        @media(min-width: 768px) { .grid-2 { grid-template-columns: 1fr 1fr; } }
        
        .text-block { background: #fff; padding: 30px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); border: 1px solid var(--border-light); }
        .text-block h3 { font-size: 20px; font-weight: 700; color: var(--primary-hover); margin-top: 0; }
        .text-block p { font-size: 15px; color: var(--text-muted); line-height: 1.7; }
        .text-block ul { padding-left: 20px; color: var(--text-muted); font-size: 15px; line-height: 1.7; }

        /* FOOTER */
        .site-footer { background: #111827; color: #9ca3af; padding: 40px 20px; text-align: center; font-size: 14px; }
        .site-footer span { color: var(--primary); font-weight: bold; }

    </style>
</head>
<body>

<header class="site-header">
    <div class="header-container">
        <a href="#" class="logo">Cron<span>Tech</span></a>
        <div class="header-actions">
            <div class="contact-info">Call us: <span>0800 123 4567</span></div>
            <button class="btn-nav" id="btn-my-bookings" onclick="showMyBookings()" style="display:none;">My Bookings</button>
            <a href="verify.php" class="btn-nav" style="background:#f3f4f6; border-color:#d1d5db; color:#111827; text-decoration:none;">Verify Tester</a>
        </div>
    </div>
</header>

<div id="discount-banner" class="discount-banner">
    🔥 Special Offer! Get <span id="banner-pct">10</span>% OFF if you book in <span class="timer" id="timer-display">--:--</span>
</div>

<section class="hero-section">
    <div class="video-container">
        <video autoplay loop muted playsinline>
            <source src="video.mp4" type="video/mp4">
        </video>
        <div class="overlay"></div>
    </div>

    <div class="hero-container">
        <div class="hero-text">
            <div class="trust-badge" id="trust-badge">⭐ 4.9/5 Trusted by UK Landlords</div>
            <h1>Fast & Reliable <span>EICR & PAT</span> Certificates</h1>
            <p class="lead">Fully compliant electrical safety checks. No hidden fees, clear pricing, and reports delivered within 24 hours to ensure your property is legally safe.</p>
            
            <div class="benefits-list">
                <div class="benefit-item"><div class="benefit-icon">✓</div>Fully Qualified NAPIT / NICEIC Engineers</div>
                <div class="benefit-item"><div class="benefit-icon">✓</div>Transparent, Fixed Pricing Structure</div>
                <div class="benefit-item"><div class="benefit-icon">✓</div>Direct liaison with tenants or agencies</div>
            </div>
        </div>

        <div class="card-wrapper" id="wizard-scroll-target">
            <div class="card" id="main-card">
                <div class="progress-container"><div class="progress-bar" id="progress-bar"></div></div>
                
                <form id="booking-form" onsubmit="return false;">
                    <div class="step active" id="step-2">
                        <h2 class="step-title">Property Details</h2>
                        <p class="subtitle">Select your property size to calculate the base quote.</p>
                        <div class="form-group">
                            <label class="form-label" for="prop_type">Property Type (EICR)</label>
                            <select id="prop_type" name="prop_type" required>
                                <option value="" disabled selected>Loading prices...</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="circuits">Consumer Unit Circuits (Fusebox)</label>
                            <input type="number" id="circuits" name="circuits" value="6" min="1" required>
                        </div>
                        
                        <div class="pat-section">
                            <label class="custom-checkbox" style="margin-bottom: 5px; background: transparent; border: none; padding: 0;">
                                <input type="checkbox" id="pat_included" onchange="togglePatInput()">
                                <span style="font-size: 14px; font-weight: 700; color: #065f46;">Add PAT Testing (Appliance Test)</span>
                            </label>
                            <p style="font-size: 12px; color: #166534; margin-top: 2px; margin-bottom: 15px; margin-left: 34px;">Ensure all plug-in appliances are safe.</p>
                            
                            <div id="pat_items_group" style="display: none; margin-left: 34px;">
                                <label class="form-label" for="pat_items" style="color: #065f46;">Number of Appliances</label>
                                <input type="number" id="pat_items" name="pat_items" value="15" min="1" style="border-color: #86efac;">
                            </div>
                        </div>

                        <button type="button" class="btn btn-primary" style="margin-top:20px;" onclick="if(validateStep('step-2')) nextStep(3)">Continue to Location</button>
                    </div>

                    <div class="step" id="step-3">
                        <h2 class="step-title">Inspection Location</h2>
                        <p class="subtitle">Where is the electrical inspection taking place?</p>
                        <div class="form-group">
                            <label class="form-label" for="address">Full Property Address</label>
                            <textarea id="address" name="address" placeholder="e.g. Flat 4, 123 High Street, London..." required></textarea>
                        </div>
                        <button type="button" class="btn btn-primary" onclick="if(validateStep('step-3')) { loadBookedDates(); nextStep(4); }">Continue to Schedule</button>
                        <button type="button" class="btn btn-back" onclick="prevStep(2)">Back</button>
                    </div>

                    <div class="step" id="step-4">
                        <h2 class="step-title">Schedule & Contact</h2>
                        <div class="form-group">
                            <label class="form-label">Select Inspection Date</label>
                            <div id="date-error" style="color: var(--error-red); font-size: 13px; display: none; margin-bottom: 5px; font-weight:700;">Please select an available date from the calendar.</div>
                            <div class="calendar-wrapper">
                                <div class="calendar-header">
                                    <button type="button" onclick="changeMonth(-1)">&#8249;</button>
                                    <h3 id="month-year">...</h3>
                                    <button type="button" onclick="changeMonth(1)">&#8250;</button>
                                </div>
                                <div class="calendar-days-header">
                                    <span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span>
                                </div>
                                <div class="calendar-grid" id="calendar-grid"></div>
                            </div>
                            <input type="hidden" id="booking_date" name="booking_date">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="client_name">Your Name</label>
                            <input type="text" id="client_name" name="client_name" placeholder="John Doe" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="client_email">Email Address</label>
                            <input type="email" id="client_email" name="client_email" placeholder="john@example.com" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="client_phone">Phone Number</label>
                            <input type="tel" id="client_phone" name="client_phone" placeholder="07..." required>
                        </div>
                        <button type="button" class="btn btn-primary" onclick="proceedToPayment()">View Quote & Proceed</button>
                        <button type="button" class="btn btn-back" onclick="prevStep(3)">Back</button>
                    </div>

                    <div class="step" id="step-5">
                        <h2 class="step-title">Secure Your Booking</h2>
                        <div class="price-panel">
                            <h3>Total Quote</h3>
                            <div class="total-price">
                                <span id="display_original" class="original-price" style="display:none;">£0.00</span>
                                <span id="display_total">£0.00</span>
                            </div>
                            <div class="deposit-price">10% Deposit Required: <span id="display_deposit">£0.00</span></div>
                        </div>
                        <label class="custom-checkbox">
                            <input type="checkbox" id="terms">
                            <span>I agree to the Terms & Conditions. The 10% deposit secures the date.</span>
                        </label>
                        <label class="custom-checkbox">
                            <input type="checkbox" id="gdpr">
                            <span>I consent to the processing of my data for this booking (UK GDPR).</span>
                        </label>
                        <div id="checkbox-error" style="color: var(--error-red); font-size: 13px; font-weight:700; text-align:center; display:none; margin-bottom:15px;">
                            You must tick both boxes above to proceed.
                        </div>
                        
                        <div id="paypal-button-container"></div>
                        <button type="button" class="btn btn-back" id="btn-back-step-5" onclick="prevStep(4)">Back</button>
                    </div>
                    
                    <div class="step" id="step-6">
                        <div class="alert alert-success">
                            <div style="font-size: 50px; margin-bottom: 10px;">✓</div>
                            <h3>Booking Received!</h3>
                            <p style="color: var(--text-muted); font-size: 14px; font-weight:600;">Your deposit was successful. We have emailed you the details.</p>
                        </div>
                        <button type="button" class="btn btn-back" onclick="resetToHome()">Return to Start</button>
                    </div>

                    <div class="step" id="step-my-bookings">
                        <h2 class="step-title">My Bookings</h2>
                        <p class="subtitle">Here are your active and past inspection requests.</p>
                        <div id="bookings-list" style="max-height: 500px; overflow-y: auto; padding-right: 5px; margin-bottom: 15px;"></div>
                        <button type="button" class="btn btn-back" onclick="resetToHome()">Back to Start</button>
                    </div>

                </form>
            </div>
        </div>
    </div>
</section>

<section class="content-section bg-light">
    <h2>Comprehensive Electrical Safety Services</h2>
    <div class="grid-2">
        <div class="text-block">
            <h3>Electrical Installation Condition Report (EICR)</h3>
            <p>An EICR is a comprehensive check of the fixed wiring of an electrical installation. It highlights any safety defects, deterioration, or non-compliances with current UK electrical regulations (BS 7671).</p>
            <ul>
                <li><strong>Landlords:</strong> It is a legal requirement in the UK for landlords to ensure their properties have a valid EICR every 5 years or change of tenancy.</li>
                <li><strong>Homeowners:</strong> Recommended every 10 years to ensure your family's safety from electrical fires and shocks.</li>
                <li><strong>Commercial:</strong> Protect your business, employees, and comply with insurance policies.</li>
            </ul>
        </div>
        <div class="text-block">
            <h3>Portable Appliance Testing (PAT)</h3>
            <p>PAT testing involves the inspection and testing of electrical appliances and equipment to ensure they are safe to use. While not strictly a legal requirement on its own, it is the best way for landlords and employers to meet their legal health and safety obligations.</p>
            <ul>
                <li>Visual inspections to check for damage to cables, plugs, and casing.</li>
                <li>Rigorous instrumental testing for earth continuity, insulation resistance, and lead polarity.</li>
                <li>Detailed inventory report and pass/fail labels applied to every tested appliance.</li>
            </ul>
        </div>
    </div>
</section>

<section class="content-section" style="background:#fff;">
    <h2>Why Choose CronTech?</h2>
    <div class="grid-2">
        <div class="text-block" style="border:none; box-shadow:none;">
            <h3>Certified & Reliable</h3>
            <p>Our engineers are fully qualified, insured, and registered with leading UK electrical bodies (NAPIT / NICEIC). We pride ourselves on punctuality and transparent, fixed pricing with no hidden costs.</p>
            
            <h3 style="margin-top:20px;">Fast Turnaround</h3>
            <p>We understand that letting a property or finalizing a sale can be time-sensitive. That's why we guarantee digital delivery of your PDF EICR or PAT certificate within 24 hours of a successful inspection.</p>
        </div>
        <div class="text-block" style="border:none; box-shadow:none;">
            <h3>What happens if my property fails the EICR?</h3>
            <p>If your installation is found to be "Unsatisfactory", our report will detail exactly what remedial work is required (categorized as C1, C2, or FI). We will provide a no-obligation quote to fix the issues, but you are free to use any registered electrician.</p>
        </div>
    </div>
</section>

<footer class="site-footer">
    <div style="max-width: 1200px; margin: 0 auto;">
        <p>&copy; <?php echo date('Y'); ?> Cron<span>Tech</span> Electrical Services. All rights reserved.</p>
        <p>Providing compliant EICR and PAT testing across the UK.</p>
    </div>
</footer>

<script>
    let currentStep = 2; 
    const totalSteps = 6; 
    let currentTotal = 0;
    let currentDeposit = 0;
    let configData = null;
    let activeDiscountPct = 0;
    let timerInterval = null;

    document.addEventListener("DOMContentLoaded", async () => {
        let myBookings = JSON.parse(localStorage.getItem('crontech_bookings') || '[]');
        if(myBookings.length > 0) document.getElementById('btn-my-bookings').style.display = 'inline-block';

        try {
            const res = await fetch('api.php?action=get_config');
            configData = await res.json();
            
            const sel = document.getElementById('prop_type');
            sel.innerHTML = `
                <option value="" disabled selected>Select property size...</option>
                <option value="studio" data-base="${configData.prices.studio}" data-inc="5">Studio / 1-Bed (£${configData.prices.studio} - up to 5 circuits)</option>
                <option value="2bed" data-base="${configData.prices['2bed']}" data-inc="6">2-Bed Flat / House (£${configData.prices['2bed']} - up to 6 circuits)</option>
                <option value="3bed" data-base="${configData.prices['3bed']}" data-inc="8">3-Bed House (£${configData.prices['3bed']} - up to 8 circuits)</option>
                <option value="4bed" data-base="${configData.prices['4bed']}" data-inc="10">4-Bed House (£${configData.prices['4bed']} - up to 10 circuits)</option>
                <option value="hmo" data-base="${configData.prices.hmo}" data-inc="12">HMO up to 5 rooms (£${configData.prices.hmo} - up to 12 circuits)</option>
            `;
            
            document.getElementById('pat_items').value = configData.prices.pat_base_items;

            let script = document.createElement('script');
            script.src = `https://www.paypal.com/sdk/js?client-id=${configData.client_id}&currency=GBP`;
            script.onload = initPayPal;
            document.head.appendChild(script);

        } catch(e) { console.error("Config load failed", e); }
    });

    function togglePatInput() {
        const isChecked = document.getElementById('pat_included').checked;
        document.getElementById('pat_items_group').style.display = isChecked ? 'block' : 'none';
        if(currentStep === 5) calculateQuote();
    }

    function startUrgencyTimer(quoteKey) {
        let startTime = localStorage.getItem(quoteKey);
        if (!startTime) {
            startTime = Date.now();
            localStorage.setItem(quoteKey, startTime);
        }
        if (timerInterval) clearInterval(timerInterval);
        updateTimerLogic(startTime);
        timerInterval = setInterval(() => { updateTimerLogic(startTime); }, 1000);
    }

    function updateTimerLogic(startTime) {
        let elapsedMins = (Date.now() - parseInt(startTime)) / 60000;
        let t1_mins = configData.discount.t1_mins;
        let t2_mins = configData.discount.t2_mins;
        
        let timeRemainingSecs = 0;

        if (elapsedMins < t1_mins) {
            activeDiscountPct = configData.discount.t1_pct;
            timeRemainingSecs = Math.floor((t1_mins - elapsedMins) * 60);
        } else if (elapsedMins < (t1_mins + t2_mins)) {
            activeDiscountPct = configData.discount.t2_pct;
            timeRemainingSecs = Math.floor(((t1_mins + t2_mins) - elapsedMins) * 60);
        } else {
            activeDiscountPct = 0;
        }

        if (activeDiscountPct > 0) {
            let m = Math.floor(timeRemainingSecs / 60).toString().padStart(2, '0');
            let s = (timeRemainingSecs % 60).toString().padStart(2, '0');
            document.getElementById('discount-banner').style.display = 'block';
            document.getElementById('banner-pct').innerText = activeDiscountPct;
            document.getElementById('timer-display').innerText = `${m}:${s}`;
        } else {
            document.getElementById('discount-banner').style.display = 'none';
            if(timerInterval) clearInterval(timerInterval);
        }
        
        if(currentStep === 5) calculateQuote();
    }

    function scrollToWizard() {
        if(window.innerWidth < 900) {
            document.getElementById('wizard-scroll-target').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    function nextStep(step) {
        document.getElementById('wizard-scroll-target').style.maxWidth = '500px';

        document.querySelectorAll('.step').forEach(el => el.classList.remove('active'));
        currentStep = step;
        document.getElementById(`step-${currentStep}`).classList.add('active');
        
        const percent = ((step - 2) / 3) * 100; 
        document.getElementById('progress-bar').style.width = percent + '%';
        if(step === 6) document.getElementById('progress-bar').style.width = '100%';
        
        scrollToWizard();
    }

    function prevStep(step) {
        document.getElementById('wizard-scroll-target').style.maxWidth = '500px';

        document.querySelectorAll('.step').forEach(el => el.classList.remove('active'));
        currentStep = step;
        document.getElementById(`step-${currentStep}`).classList.add('active');
        
        const percent = ((step - 2) / 3) * 100;
        document.getElementById('progress-bar').style.width = percent + '%';
        
        scrollToWizard();
    }

    function resetToHome() {
        document.getElementById('wizard-scroll-target').style.maxWidth = '500px';

        document.querySelectorAll('.step').forEach(el => el.classList.remove('active'));
        currentStep = 2;
        document.getElementById(`step-2`).classList.add('active');
        document.getElementById('progress-bar').style.width = '0%';
        let myBookings = JSON.parse(localStorage.getItem('crontech_bookings') || '[]');
        document.getElementById('btn-my-bookings').style.display = myBookings.length > 0 ? 'inline-block' : 'none';
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    async function showMyBookings() {
        let myBookings = JSON.parse(localStorage.getItem('crontech_bookings') || '[]');
        if(myBookings.length === 0) return;
        try {
            const res = await fetch('api.php?action=get_my_bookings', {
                method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({bookings: myBookings})
            });
            const data = await res.json();
            if(data.success) {
                const list = document.getElementById('bookings-list');
                list.innerHTML = '';
                if(data.data.length === 0) {
                    list.innerHTML = '<p style="text-align:center; color:var(--text-muted);">No active bookings found.</p>';
                } else {
                    data.data.forEach(b => {
                        let statusColor = '#fb8500'; // Pending (Orange)
                        let statusIcon = '⏳';
                        let badgeBgSafe = '#fffbeb';
                        
                        if(b.status.includes('Accepted')) {
                            statusColor = '#2563eb';
                            statusIcon = '🗓️';
                            badgeBgSafe = '#eff6ff';
                        } else if(b.status.includes('Awaiting')) {
                            statusColor = '#d97706';
                            statusIcon = '💳';
                            badgeBgSafe = '#fef3c7';
                        } else if(b.status.includes('Paid')) {
                            statusColor = '#059669'; // Green
                            statusIcon = '✅';
                            badgeBgSafe = '#d1fae5';
                        } else if(b.status.includes('Rejected')) {
                            statusColor = '#ef4444'; // Red
                            statusIcon = '❌';
                            badgeBgSafe = '#fef2f2';
                        }

                        let statusText = b.status.toUpperCase();

                        list.innerHTML += `
                            <div class="booking-row-item">
                                <div class="br-status" style="color:${statusColor}; background:${badgeBgSafe}; border: 1px solid ${statusColor}40;">
                                    ${statusIcon} ${statusText}
                                </div>
                                <div class="br-date">${b.date}</div>
                                <div class="br-address">${b.address}</div>
                                <div class="br-details"><span>Total:</span> £${b.total}</div>
                                <a href="${b.invoice_link}" target="_blank" class="br-pdf-btn">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                                    DOWNLOAD
                                </a>
                            </div>
                        `;
                    });
                }
                
                // ROZSZERZENIE KARTY DLA IDEALNEGO WIDOKU 1 LINII
                document.getElementById('wizard-scroll-target').style.maxWidth = '1000px';

                document.querySelectorAll('.step').forEach(el => el.classList.remove('active'));
                document.getElementById('step-my-bookings').classList.add('active');
                document.getElementById('progress-bar').style.width = '0%';
                document.getElementById('wizard-scroll-target').scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        } catch(e) { console.error(e); }
    }

    function validateStep(stepId) {
        const step = document.getElementById(stepId);
        const inputs = step.querySelectorAll('input:not([type="hidden"]):not([type="checkbox"]), select, textarea');
        let isValid = true;
        inputs.forEach(input => {
            if (!input.checkValidity()) { input.reportValidity(); isValid = false; }
        });
        return isValid;
    }

    /* Calendar Logic */
    const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
    let currYear = new Date().getFullYear();
    let currMonth = new Date().getMonth();
    let bookedDates = [];

    async function loadBookedDates() {
        try {
            const res = await fetch('api.php?action=get_dates');
            bookedDates = await res.json();
            renderCalendar(currYear, currMonth);
        } catch(e) { renderCalendar(currYear, currMonth); }
    }

    function renderCalendar(year, month) {
        document.getElementById('month-year').innerText = monthNames[month] + " " + year;
        const grid = document.getElementById('calendar-grid');
        grid.innerHTML = '';
        const firstDay = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const today = new Date(); today.setHours(0,0,0,0);
        
        for (let i = 0; i < firstDay; i++) { grid.innerHTML += '<div></div>'; }
        
        for (let d = 1; d <= daysInMonth; d++) {
            const dateStr = year + '-' + String(month+1).padStart(2, '0') + '-' + String(d).padStart(2, '0');
            const dateObj = new Date(year, month, d);
            let btn = document.createElement('div');
            btn.className = 'calendar-day';
            btn.innerText = d;
            
            if (dateObj <= today) {
                btn.classList.add('disabled');
            } else if (bookedDates.includes(dateStr)) {
                btn.classList.add('booked'); btn.title = "Fully Booked";
            } else {
                btn.onclick = function() {
                    document.querySelectorAll('.calendar-day').forEach(el => el.classList.remove('selected'));
                    btn.classList.add('selected');
                    document.getElementById('booking_date').value = dateStr;
                    document.getElementById('date-error').style.display = 'none';
                };
            }
            if (document.getElementById('booking_date').value === dateStr) btn.classList.add('selected');
            grid.appendChild(btn);
        }
    }

    function changeMonth(dir) {
        currMonth += dir;
        if (currMonth > 11) { currMonth = 0; currYear++; }
        if (currMonth < 0) { currMonth = 11; currYear--; }
        renderCalendar(currYear, currMonth);
    }
    
    function proceedToPayment() {
        if(!document.getElementById('booking_date').value) {
            document.getElementById('date-error').style.display = 'block';
            return;
        }
        if(!validateStep('step-4')) return;

        let email = document.getElementById('client_email').value;
        let addr = document.getElementById('address').value;
        let uniqueString = email + addr;
        let quoteKey = 'crontech_offer_' + uniqueString.toLowerCase().replace(/[^a-z0-9]/g, '');

        startUrgencyTimer(quoteKey);
        calculateQuote();
        nextStep(5);
    }

    function calculateQuote() {
        const propSelect = document.getElementById('prop_type');
        const selectedOption = propSelect.options[propSelect.selectedIndex];
        if(!selectedOption.value) return;

        let basePrice = parseFloat(selectedOption.getAttribute('data-base'));
        const incCircuits = parseInt(document.getElementById('circuits').value) || 0;
        const baseCircuits = parseInt(selectedOption.getAttribute('data-inc'));
        
        let originalTotal = basePrice;
        if (incCircuits > baseCircuits) {
            const extraRate = parseFloat(configData.prices.extra);
            originalTotal += (incCircuits - baseCircuits) * extraRate;
        }

        // Add PAT Cost if selected
        const isPatIncluded = document.getElementById('pat_included').checked;
        if (isPatIncluded) {
            const patItems = parseInt(document.getElementById('pat_items').value) || 0;
            const patBasePrice = parseFloat(configData.prices.pat_base);
            const patBaseItems = parseInt(configData.prices.pat_base_items);
            const patExtraPrice = parseFloat(configData.prices.pat_extra);

            let patCost = patBasePrice;
            if (patItems > patBaseItems) {
                patCost += (patItems - patBaseItems) * patExtraPrice;
            }
            originalTotal += patCost;
        }

        if (activeDiscountPct > 0) {
            currentTotal = originalTotal - (originalTotal * (activeDiscountPct / 100));
            document.getElementById('display_original').style.display = 'inline-block';
            document.getElementById('display_original').innerText = '£' + originalTotal.toFixed(2);
        } else {
            currentTotal = originalTotal;
            document.getElementById('display_original').style.display = 'none';
        }

        currentDeposit = parseFloat((currentTotal * 0.10).toFixed(2));

        document.getElementById('display_total').innerText = '£' + currentTotal.toFixed(2);
        document.getElementById('display_deposit').innerText = '£' + currentDeposit.toFixed(2);
    }

    function initPayPal() {
        paypal.Buttons({
            style: { shape: 'rect', color: 'gold', layout: 'vertical', label: 'pay' },
            onClick: function(data, actions) {
                const terms = document.getElementById('terms').checked;
                const gdpr = document.getElementById('gdpr').checked;
                if (!terms || !gdpr) {
                    document.getElementById('checkbox-error').style.display = 'block';
                    return actions.reject();
                }
                document.getElementById('checkbox-error').style.display = 'none';
                return actions.resolve();
            },
            createOrder: function(data, actions) {
                return actions.order.create({
                    purchase_units: [{
                        amount: { value: currentDeposit.toString(), currency_code: 'GBP' },
                        description: `10% Deposit for CronTech Inspection at ${document.getElementById('address').value.substring(0, 50)}`
                    }]
                });
            },
            onApprove: function(data, actions) {
                
                document.querySelector('#step-5 .price-panel').style.display = 'none';
                document.querySelectorAll('#step-5 .custom-checkbox').forEach(el => el.style.display = 'none');
                document.querySelector('#step-5 .step-title').style.display = 'none';
                const backBtn = document.getElementById('btn-back-step-5');
                if(backBtn) backBtn.style.display = 'none';
                document.getElementById('paypal-button-container').style.display = 'none';

                let loadingDiv = document.createElement('div');
                loadingDiv.innerHTML = `
                    <div style="text-align:center; padding:40px 20px;">
                        <div style="font-size:50px; margin-bottom:15px; animation: pulse 1.5s infinite;">⏳</div>
                        <h2 style="color:var(--text-main); margin-bottom:10px;">Processing Booking...</h2>
                        <p style="color:var(--text-muted); font-weight:500;">Please wait while we secure your date and send confirmation emails.</p>
                    </div>
                    <style>@keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.5; } 100% { opacity: 1; } }</style>
                `;
                document.getElementById('step-5').appendChild(loadingDiv);

                return actions.order.capture().then(function(details) {
                    const bookingData = {
                        prop_type: document.getElementById('prop_type').value,
                        circuits: document.getElementById('circuits').value,
                        pat_included: document.getElementById('pat_included').checked,
                        pat_items: document.getElementById('pat_items').value,
                        address: document.getElementById('address').value,
                        date: document.getElementById('booking_date').value,
                        name: document.getElementById('client_name').value,
                        email: document.getElementById('client_email').value,
                        phone: document.getElementById('client_phone').value,
                        total: currentTotal,
                        deposit: currentDeposit,
                        transaction_id: details.id,
                        capture_id: details.purchase_units[0].payments.captures[0].id
                    };

                    fetch('api.php?action=create', {
                        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(bookingData)
                    })
                    .then(res => {
                        if (!res.ok) throw new Error("Server returned status: " + res.status);
                        return res.json();
                    })
                    .then(resData => {
                        if(resData.success) {
                            let myBookings = JSON.parse(localStorage.getItem('crontech_bookings') || '[]');
                            myBookings.push({id: resData.id, secret: resData.secret});
                            localStorage.setItem('crontech_bookings', JSON.stringify(myBookings));
                            document.getElementById('btn-my-bookings').style.display = 'inline-block';
                            
                            document.getElementById('discount-banner').style.display = 'none';
                            clearInterval(timerInterval);

                            document.getElementById('step-5').style.display = 'none';
                            nextStep(6); 
                        } else {
                            alert("Payment successful but there was an error saving the booking. Please contact support.");
                            location.reload();
                        }
                    })
                    .catch(err => {
                        console.error("Booking Save Error: ", err);
                        alert("We captured your payment, but our server took too long to respond. Please contact support to confirm your booking.");
                        location.reload();
                    });
                }).catch(err => {
                    console.error("PayPal Capture Error: ", err);
                    alert("Your payment could not be processed. Please try again.");
                    location.reload();
                });
            }
        }).render('#paypal-button-container');
    }
</script>
</body>
</html>
