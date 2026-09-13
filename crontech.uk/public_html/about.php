<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>About Us | CronTech Electrical</title>
    <meta name="description" content="Learn about CronTech Electrical. We are certified NAPIT / NICEIC engineers providing fast, reliable EICR and PAT testing across the UK.">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="app.css">
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
        * { box-sizing: border-box; font-family: 'Montserrat', sans-serif; }
        html { scroll-behavior: smooth; }
        body { background-color: var(--bg-body); color: var(--text-main); margin: 0; padding: 0; line-height: 1.6; display: flex; flex-direction: column; min-height: 100vh;}
        
        /* HEADER */
        .site-header { background: #ffffff; box-shadow: 0 2px 10px rgba(0,0,0,0.05); position: sticky; top: 0; z-index: 100; }
        .header-container { max-width: 1400px; margin: 0 auto; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; }
        .logo { font-size: 24px; font-weight: 800; text-transform: uppercase; color: var(--text-main); text-decoration: none;}
        .logo span { color: var(--primary); }
        .header-actions { display: flex; align-items: center; gap: 15px; }
        .btn-nav { background: #ffffff; border: 1px solid var(--border-light); color: var(--text-main); padding: 8px 15px; border-radius: 20px; cursor: pointer; font-weight: 700; font-size: 12px; transition: all 0.3s; text-transform: uppercase; text-decoration: none;}
        .btn-nav:hover { background: var(--primary); color: #000; border-color: var(--primary); }

        /* HERO */
        .hero-banner { background: #111827; padding: 80px 20px; text-align: center; color: #fff; }
        .hero-banner h1 { font-size: 42px; font-weight: 800; margin: 0 0 15px 0; text-transform: uppercase; color: var(--primary); }
        .hero-banner p { font-size: 18px; color: #d1d5db; max-width: 600px; margin: 0 auto; }

        /* CONTENT */
        .content-section { padding: 60px 20px; max-width: 1400px; margin: 0 auto; flex: 1; }
        .grid-2 { display: grid; grid-template-columns: 1fr; gap: 40px; margin-top: 40px;}
        @media(min-width: 768px) { .grid-2 { grid-template-columns: 1fr 1fr; } }
        .text-block { background: #fff; padding: 40px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); border: 1px solid var(--border-light); }
        .text-block h3 { font-size: 22px; font-weight: 800; color: var(--text-main); margin-top: 0; }
        .text-block p { font-size: 15px; color: var(--text-muted); line-height: 1.8; margin-bottom: 20px;}
        
        .feature-list { list-style: none; padding: 0; margin: 0; }
        .feature-list li { padding: 10px 0; border-bottom: 1px solid var(--border-light); color: var(--text-muted); font-weight: 600; display: flex; align-items: center;}
        .feature-list li::before { content: '✓'; color: #10b981; font-weight: bold; margin-right: 10px; font-size: 18px;}

        /* FOOTER */
        .site-footer { background: #111827; color: #9ca3af; padding: 40px 20px; text-align: center; font-size: 14px; margin-top: auto;}
        .site-footer span { color: var(--primary); font-weight: bold; }
        .footer-links { margin-top: 15px; }
        .footer-links a { color: #d1d5db; text-decoration: none; margin: 0 10px; transition: color 0.3s;}
        .footer-links a:hover { color: var(--primary); }
    </style>
</head>
<body>

<?php include 'cron_menu.php'; ?>


<div class="hero-banner">
    <h1>About Us</h1>
    <p>Delivering uncompromised electrical safety, transparent pricing, and industry-leading turnaround times.</p>
</div>

<section class="content-section">
    <div class="text-block" style="margin-bottom: 40px; text-align: center;">
        <h3>Our Mission</h3>
        <p>At CronTech, our mission is to simplify the often complex world of electrical compliance. We believe that ensuring the safety of a property shouldn't involve hidden fees, unpredictable arrival times, or days of waiting for paperwork. We leverage modern booking systems and deploy highly skilled engineers to provide landlords, estate agents, and homeowners with absolute peace of mind.</p>
    </div>

    <div class="grid-2">
        <div class="text-block">
            <h3>Fully Certified Engineers</h3>
            <p>Electrical safety is not something to compromise on. All of our engineers are fully qualified, fully insured, and registered with the UK's leading electrical regulatory bodies, including NAPIT and NICEIC.</p>
            <p>We strictly adhere to the latest BS 7671 wiring regulations to ensure every EICR and PAT test is conducted to the highest possible standard.</p>
        </div>
        
        <div class="text-block">
            <h3>Why Landlords Trust Us</h3>
            <ul class="feature-list">
                <li>Fixed, transparent pricing with zero hidden fees.</li>
                <li>Digital certificates delivered within 24 hours.</li>
                <li>Direct liaison with tenants to arrange access.</li>
                <li>Clear, itemized quotes for any required remedial works.</li>
                <li>Secure online payments and invoice management.</li>
            </ul>
        </div>
    </div>
</section>

<footer class="site-footer">
    <div style="max-width: 1400px; margin: 0 auto;">
        <p>&copy; <?php echo date('Y'); ?> Cron<span>Tech</span> Electrical Services. All rights reserved.</p>
        <p>Providing compliant EICR and PAT testing across the UK.</p>
        <div class="footer-links">
            <a href="about.php">About Us</a> | 
            <a href="contact.php">Contact</a> | 
            <a href="index.php">Book an Inspection</a>
        </div>
    </div>
</footer>

</body>
</html>