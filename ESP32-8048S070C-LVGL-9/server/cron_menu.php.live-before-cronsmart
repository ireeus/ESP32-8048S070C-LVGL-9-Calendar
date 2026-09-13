<style>
    /* HEADER / NAVBAR */
    .site-header {
        background: #ffffff;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        position: sticky; top: 0; z-index: 1000;
    }
    .header-container {
        max-width: 1200px; margin: 0 auto; padding: 15px 20px;
        display: flex; justify-content: space-between; align-items: center;
        flex-wrap: wrap;
    }
    .logo { font-size: 24px; font-weight: 800; text-transform: uppercase; color: var(--text-main); text-decoration: none; z-index: 1001; position: relative;}
    .logo span { color: var(--primary); }
    
    .header-actions { display: flex; align-items: center; gap: 15px; transition: all 0.3s ease; }
    .contact-info { font-weight: 600; font-size: 14px; color: var(--text-muted); display: none; }
    .contact-info span { color: var(--primary-hover); font-weight: 800; font-size: 16px;}
    
    @media(min-width: 768px) { .contact-info { display: block; } }

    .btn-nav { 
        background: #ffffff; border: 1px solid var(--border-light); color: var(--text-main); 
        padding: 8px 15px; border-radius: 20px; cursor: pointer; font-weight: 700; font-size: 12px; 
        transition: all 0.3s; text-transform: uppercase; text-decoration: none; display: inline-block;
    }
    .btn-nav:hover { background: var(--primary); color: #000; border-color: var(--primary); }

    /* HAMBURGER MENU */
    .hamburger {
        display: none;
        flex-direction: column;
        cursor: pointer;
        gap: 5px;
        z-index: 1001;
        position: relative;
    }
    .hamburger .bar {
        width: 25px;
        height: 3px;
        background-color: var(--text-main);
        border-radius: 3px;
        transition: all 0.3s ease-in-out;
    }

    /* MOBILE MENU STYLES */
    @media(max-width: 768px) {
        .hamburger { display: flex; }
        .header-actions {
            display: none;
            flex-direction: column;
            width: 100%;
            position: absolute;
            top: 100%;
            left: 0;
            background: #ffffff;
            padding: 20px;
            box-shadow: 0 10px 15px rgba(0,0,0,0.1);
            border-top: 1px solid var(--border-light);
        }
        .header-actions.show { display: flex; }
        .btn-nav { width: 100%; text-align: center; padding: 14px; margin-bottom: 5px; }
        
        .hamburger.active .bar:nth-child(1) { transform: translateY(8px) rotate(45deg); }
        .hamburger.active .bar:nth-child(2) { opacity: 0; }
        .hamburger.active .bar:nth-child(3) { transform: translateY(-8px) rotate(-45deg); }
    }
</style>

<header class="site-header">
    <div class="header-container">
        <a href="index.php" class="logo">Cron<span>Tech</span></a>
        
        <!-- Hamburger Icon -->
        <div class="hamburger" onclick="toggleMenu()">
            <div class="bar"></div>
            <div class="bar"></div>
            <div class="bar"></div>
        </div>

        <div class="header-actions" id="nav-menu">
            <div class="contact-info">Contact us: <span>info@crontech.uk</span></div>
            <!-- If on a page without showMyBookings (like about.php), it routes them back to index.php -->
            <button class="btn-nav" id="btn-my-bookings" onclick="if(typeof showMyBookings === 'function') { showMyBookings(); } else { window.location.href='index.php'; } toggleMenu();" style="display:none;">My Bookings</button>
            <a href="offers.php" class="btn-nav" style="text-decoration: none; display: inline-block;" onclick="toggleMenu();">Offers</a>
            <a href="about.php" class="btn-nav" style="text-decoration: none; display: inline-block;" onclick="toggleMenu();">About Us</a>
            <a href="verify.php" class="btn-nav" style="text-decoration: none; display: inline-block;" onclick="toggleMenu();">Verify Member</a>
            <a href="contact.php" class="btn-nav" style="text-decoration: none; display: inline-block;" onclick="toggleMenu();">Contact</a>
        </div>
    </div>
</header>

<script>
    function toggleMenu() {
        const menu = document.getElementById('nav-menu');
        const burger = document.querySelector('.hamburger');
        if(menu) menu.classList.toggle('show');
        if(burger) burger.classList.toggle('active');
    }

    // Globally handle the display of the 'My Bookings' button
    document.addEventListener("DOMContentLoaded", () => {
        let myBookings = JSON.parse(localStorage.getItem('crontech_bookings') || '[]');
        let myQuotes = JSON.parse(localStorage.getItem('crontech_quotes') || '[]').filter(q => !q.hidden);
        
        let topBtn = document.getElementById('btn-my-bookings');
        if (topBtn) {
            topBtn.style.display = (myBookings.length > 0 || myQuotes.length > 0) ? 'inline-block' : 'none';
        }
    });
</script>