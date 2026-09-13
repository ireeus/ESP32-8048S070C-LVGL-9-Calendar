<?php
session_start();
$success_message = false;

// Basic form handling logic (you can connect this to your email function later)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_contact'])) {
    $name = htmlspecialchars($_POST['name'] ?? '');
    $email = htmlspecialchars($_POST['email'] ?? '');
    $message = htmlspecialchars($_POST['message'] ?? '');
    
    // Here you would normally use mail() or PHPMailer to send the message
    mail("info@crontech.uk", "New Contact Query from $name", $message, "From: $email");
    
    $success_message = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Contact Us | CronTech Electrical</title>
    <meta name="description" content="Get in touch with CronTech Electrical for any inquiries regarding EICR, PAT testing, or remedial electrical works.">
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
            --input-bg: #ffffff;       
            --success-green: #10b981;
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
        .hero-banner { background: #111827; padding: 60px 20px; text-align: center; color: #fff; }
        .hero-banner h1 { font-size: 36px; font-weight: 800; margin: 0 0 10px 0; text-transform: uppercase; color: var(--primary); }
        .hero-banner p { font-size: 16px; color: #d1d5db; }

        /* CONTENT */
        .content-section { padding: 60px 20px; max-width: 1400px; margin: 0 auto; flex: 1; width: 100%;}
        .grid-2 { display: grid; grid-template-columns: 1fr; gap: 40px; }
        @media(min-width: 768px) { .grid-2 { grid-template-columns: 1fr 1fr; } }
        
        .contact-card { background: var(--bg-card); border: 1px solid var(--border-light); border-radius: 16px; box-shadow: 0 15px 35px rgba(0,0,0,0.05); padding: 40px; }
        
        /* FORM STYLES REUSED FROM INDEX.PHP */
        .form-group { margin-bottom: 20px; width: 100%; }
        label.form-label { display: block; font-weight: 700; margin-bottom: 8px; font-size: 12px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
        input[type="text"], input[type="email"], textarea { width: 100%; padding: 14px; border: 1px solid var(--border-light); border-radius: 8px; font-size: 14px; font-weight: 500; transition: all 0.3s; outline: none; background: var(--input-bg); color: var(--text-main); }
        input:focus, textarea:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(255, 183, 3, 0.15); }
        textarea { resize: vertical; min-height: 120px; }
        .btn-primary { width: 100%; padding: 15px; border: none; border-radius: 8px; font-size: 15px; font-weight: 700; cursor: pointer; transition: all 0.3s; text-transform: uppercase; letter-spacing: 1px; background: var(--primary); color: #111; box-shadow: 0 4px 6px rgba(255, 183, 3, 0.2); }
        .btn-primary:hover { background: var(--primary-hover); transform: translateY(-1px); box-shadow: 0 6px 12px rgba(255, 183, 3, 0.3); }

        .info-block { padding: 20px 0; }
        .info-block h3 { font-size: 20px; font-weight: 800; margin-top: 0; color: var(--text-main); }
        .info-item { display: flex; align-items: flex-start; margin-bottom: 20px; }
        .info-icon { font-size: 24px; margin-right: 15px; color: var(--primary-hover); }
        .info-text p { margin: 0; color: var(--text-muted); font-weight: 500; }
        .info-text strong { color: var(--text-main); display: block; margin-bottom: 4px;}

        .alert-success { background: #ecfdf5; border: 1px solid var(--success-green); color: #065f46; padding: 20px; border-radius: 10px; text-align: center; margin-bottom: 20px;}

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
    <h1>Contact Us</h1>
    <p>Have a question about our services or need to discuss a custom quote? We're here to help.</p>
</div>

<section class="content-section">
    <div class="grid-2">
        <!-- Contact Form -->
        <div class="contact-card">
            <?php if ($success_message): ?>
                <div class="alert-success">
                    <div style="font-size: 40px; margin-bottom: 10px;">✓</div>
                    <h3 style="margin:0 0 5px 0;">Message Sent!</h3>
                    <p style="margin:0; font-size:14px;">Thank you for reaching out. We will get back to you as soon as possible.</p>
                </div>
            <?php else: ?>
                <h2 style="margin-top:0; font-weight:800; font-size:22px; margin-bottom:25px;">Send a Message</h2>
                <form method="POST" action="contact.php">
                    <div class="form-group">
                        <label class="form-label" for="name">Your Name</label>
                        <input type="text" id="name" name="name" required placeholder="John Doe">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="email">Email Address</label>
                        <input type="email" id="email" name="email" required placeholder="john@example.com">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="message">How can we help?</label>
                        <textarea id="message" name="message" required placeholder="Type your message here..."></textarea>
                    </div>
                    <button type="submit" name="submit_contact" class="btn-primary">Send Message</button>
                </form>
            <?php endif; ?>
        </div>

        <!-- Contact Info -->
        <div class="info-block">
            <h3>Get in touch directly</h3>
            <p style="color:var(--text-muted); margin-bottom: 30px;">Prefer to reach out directly? Use the details below to contact our team. We aim to respond to all inquiries within 24 hours.</p>
            
            <div class="info-item">
                <div class="info-icon">✉️</div>
                <div class="info-text">
                    <strong>Email Us</strong>
                    <p><a href="mailto:info@crontech.uk" style="color:var(--text-muted); text-decoration:none;">info@crontech.uk</a></p>
                </div>
            </div>
            
            <div class="info-item">
                <div class="info-icon">📞</div>
                <div class="info-text">
                    <strong>Call Us</strong>
                    <p>Mon - Fri, 9:00am - 5:00pm</p>
                    <p>(Phone number coming soon)</p>
                </div>
            </div>
            
            <div class="info-item">
                <div class="info-icon">📍</div>
                <div class="info-text">
                    <strong>Service Area</strong>
                    <p>We provide EICR and PAT testing services across the Greater Manchester.</p>
                </div>
            </div>
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