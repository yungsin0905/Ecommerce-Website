<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About Us</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/header.css?v=6.0">
    <link rel="stylesheet" href="css/footer.css?v=6.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

<style>
    /* ===== Design tokens aligned with checkout.php / payment.php ===== */
    :root {
        --main-color: #80b8d2;
        --main-dark: #3c8cb1;
        --font-color: #1B2A3C;
        --secondary-color: #F4F8FC;
        --card-bg-color: #EBF4FC;
        --search-border-color: #C9DCEE;
        --bg-color: #FFFFFF;
        --font2-color: #52708A;
        --transition: 0.45s cubic-bezier(0.25, 0.46, 0.45, 0.94);
    }

    * { box-sizing: border-box; }

    body {
        font-family: 'Inter', sans-serif;
        background-color: var(--bg-color);
        color: var(--font-color);
        margin: 0;
        padding: 0;
        overflow-x: hidden;
    }

    h1, h2, h3 {
        font-family: 'Poppins', sans-serif;
        margin: 0;
    }

    /* ---------- back link ---------- */
    .back-section {
        display: flex;
        align-items: center;
        margin: 30px 0 0 50px;
        position: relative;
        z-index: 5;
    }

    .back-link {
        text-decoration: none;
        color: var(--font2-color);
        font-size: 15px;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: color 0.3s;
    }

    .back-link:hover {
        color: var(--main-dark);
        text-decoration: underline;
    }

    /* ---------- reveal-on-scroll ---------- */
    .reveal {
        opacity: 0;
        transform: translateY(36px);
        transition: opacity 0.8s ease, transform 0.8s ease;
    }

    .reveal.in-view {
        opacity: 1;
        transform: translateY(0);
    }

    .reveal-delay-1 { transition-delay: 0.08s; }
    .reveal-delay-2 { transition-delay: 0.18s; }
    .reveal-delay-3 { transition-delay: 0.28s; }

    @media (prefers-reduced-motion: reduce) {
        .reveal, .reveal.in-view {
            opacity: 1;
            transform: none;
            transition: none;
        }
        .hero-media { transform: none !important; }
        .hero-eyebrow,
        .hero-content h1 {
            opacity: 1 !important;
            transform: none !important;
            animation: none !important;
        }
    }

    /* ---------- hero (banner photo + big title) ---------- */
    .hero {
        position: relative;
        height: 58vh;
        min-height: 400px;
        margin-top: 20px;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .hero-media {
        position: absolute;
        inset: -8% 0 -8% 0;
        background-image: url('image/about_us/photo2.jpg');
        background-size: cover;
        background-position: center;
        will-change: transform;
    }

    .hero-overlay {
        position: absolute;
        inset: 0;
        background: linear-gradient(100deg, rgba(27,42,60,0.72) 10%, rgba(60,140,177,0.35) 100%);
        z-index: 1;
        pointer-events: none;
    }

    .hero-blob {
        position: absolute;
        border-radius: 50%;
        background: rgba(128, 184, 210, 0.35);
        pointer-events: none;
        z-index: 2;
    }

    .hero-blob.b1 { width: 240px; height: 240px; top: -70px; right: 6%; }
    .hero-blob.b2 { width: 150px; height: 150px; bottom: -40px; left: 3%; background: rgba(235,244,252,0.4); }

    .hero-content {
        position: relative;
        z-index: 2;
        color: #FFFFFF;
        text-align: center;
        padding: 0 8%;
    }

    .hero-eyebrow {
        display: block;
        letter-spacing: 3px;
        font-size: 13px;
        font-weight: 600;
        color: #EBF4FC;
        margin: 0 0 14px;
        opacity: 0;
        animation: heroFadeUp 0.9s ease forwards;
        animation-delay: 0.15s;
    }

    .hero-content h1 {
        font-size: clamp(2.4rem, 5vw, 3.6rem);
        font-weight: 700;
        line-height: 1.1;
        margin: 0;
        opacity: 0;
        animation: heroFadeUp 0.9s ease forwards;
        animation-delay: 0.35s;
    }

    @keyframes heroFadeUp {
        from { opacity: 0; transform: translateY(24px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    .scroll-cue {
        position: absolute;
        bottom: 20px;
        left: 50%;
        transform: translateX(-50%);
        z-index: 2;
        color: #EBF4FC;
        font-size: 22px;
        animation: bob 2.2s ease-in-out infinite;
        display: flex;
        justify-content: center;
        align-items: center;
    }

    @keyframes bob {
        0%, 100% { transform: translate(-50%, 0); opacity: 0.6; }
        50% { transform: translate(-50%, 8px); opacity: 1; }
    }

    /* ---------- main container ---------- */
    .main-container {
        max-width: 1100px;
        margin: 0 auto;
        padding: 0 24px 40px;
    }

    /* ---------- who we are ---------- */
    .who-section {
        margin-bottom: 110px;
        margin-top: 40px;
    }

    .eyebrow {
        display: block;
        letter-spacing: 2px;
        font-size: 13px;
        font-weight: 600;
        color: var(--font2-color);
        margin-bottom: 10px;
    }

    .who-heading {
        font-size: clamp(1.8rem, 3.4vw, 2.4rem);
        font-weight: 800;
        margin-bottom: 44px;
    }

    .who-heading .accent {
        color: var(--main-dark);
    }

    .who-grid {
        display: flex;
        gap: 50px;
        align-items: stretch;
    }

    .who-media {
        flex: 0 0 42%;
        position: relative;
    }

    .who-media img {
        width: 100%;
        height: 100%;
        min-height: 360px;
        object-fit: cover;
        border-radius: 24px;
        display: block;
    }

    .who-media::after {
        content: "";
        position: absolute;
        inset: 16px -16px -16px 16px;
        border: 2px solid var(--search-border-color);
        border-radius: 24px;
        z-index: -1;
    }

    .who-features {
        flex: 1;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        gap: 26px;
    }

    .who-feature {
        border-left: 3px solid var(--search-border-color);
        padding-left: 20px;
        transition: border-color 0.3s;
    }

    .who-feature:hover {
        border-color: var(--main-color);
    }

    .who-feature h3 {
        font-size: 1.15rem;
        color: var(--font-color);
        margin-bottom: 8px;
    }

    .who-feature p {
        color: var(--font2-color);
        font-size: 14.5px;
        line-height: 1.75;
        margin: 0;
    }

    /* ---------- what we do ---------- */
    .what-section {
        text-align: center;
        margin-bottom: 40px;
    }

    .what-heading {
        font-size: 1.8rem;
        font-weight: 800;
        margin-bottom: 44px;
    }

    .what-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 24px;
        max-width: 780px;
        margin: 0 auto;
    }

    .what-grid .card-3 {
        grid-column: 1 / span 2;
        max-width: 378px;
        margin: 0 auto;
    }

    .what-card {
        background: var(--secondary-color);
        border: 1px solid var(--search-border-color);
        border-radius: 22px;
        padding: 40px 26px;
        text-align: center;
        transition: var(--transition);
    }

    .what-card:hover {
        transform: translateY(-8px);
        border-color: var(--main-color);
        box-shadow: 0 16px 32px rgba(128, 184, 210, 0.25);
        background: #FFFFFF;
    }

    .what-icon {
        width: 60px;
        height: 60px;
        border-radius: 16px;
        background: var(--card-bg-color);
        color: var(--main-dark);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 26px;
        margin: 0 auto 20px;
    }

    .what-card h3 {
        font-size: 1.1rem;
        color: var(--main-dark);
        margin-bottom: 10px;
    }

    .what-card p {
        color: var(--font2-color);
        font-size: 14px;
        line-height: 1.7;
        margin: 0;
    }

    /* ---------- responsive ---------- */
    @media (max-width: 860px) {
        .who-grid { flex-direction: column; }
        .who-media { flex-basis: auto; width: 100%; }
        .who-media img { min-height: 260px; }
        .what-grid { grid-template-columns: 1fr; }
        .what-grid .card-3 { grid-column: 1; max-width: 100%; }
        .hero-content { padding: 0 6%; }
    }
</style>
</head>
<body>
   <?php include_once 'include/header.php'; ?>


   <!-- Hero banner -->
   <section class="hero">
        <div class="hero-media" id="heroMedia"></div>
        <div class="hero-overlay"></div>
        <div class="hero-blob b1"></div>
        <div class="hero-blob b2"></div>
        <div class="hero-content">
            <p class="hero-eyebrow">About Us</p>
            <h1>Maker Kluang</h1>
        </div>
        <div class="scroll-cue"><i class="bi bi-chevron-down"></i></div>
   </section>


   <div class="main-container">

        <!-- Who We Are -->
        <section class="who-section">
            <span class="eyebrow reveal">Who we are?</span>
            <h2 class="who-heading reveal"><span class="accent">Technology</span> Made <span class="accent">Simple</span></h2>

            <div class="who-grid">
                <div class="who-media reveal">
                    <img src="image/about_us/photo1.jpg" alt="Our team at work">
                </div>

                <div class="who-features">
                    <div class="who-feature reveal reveal-delay-1">
                        <h3>Learn Technology</h3>
                        <p>Making programming, electronics, and digital technology easier to understand.</p>
                    </div>
                    <div class="who-feature reveal reveal-delay-2">
                        <h3>For School</h3>
                        <p>Technology education materials and training content designed for schools.</p>
                    </div>
                    <div class="who-feature reveal reveal-delay-3">
                        <h3>Inspire the Young</h3>
                        <p>Helping the younger generation build skills, explore technology, and innovate.</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- What We Do -->
        <section class="what-section">
            <h2 class="what-heading reveal">What We Do</h2>

            <div class="what-grid">
                <div class="what-card reveal reveal-delay-1">
                    <div class="what-icon"><i class="bi bi-code-slash"></i></div>
                    <h3>Programming Education</h3>
                    <p>Create programming courses and online learning resources for students and the public.</p>
                </div>

                <div class="what-card reveal reveal-delay-2">
                    <div class="what-icon"><i class="bi bi-mortarboard"></i></div>
                    <h3>Workshops &amp; Training</h3>
                    <p>Provide hands-on technology workshops and classes for schools and universities.</p>
                </div>

                <div class="what-card card-3 reveal reveal-delay-3">
                    <div class="what-icon"><i class="bi bi-gear-wide-connected"></i></div>
                    <h3>Technology Solutions</h3>
                    <p>Provide technology consulting and develop mobile apps and web applications for universities and businesses.</p>
                </div>
            </div>
        </section>

   </div>

<script>
    // Reveal sections as they scroll into view
    const revealEls = document.querySelectorAll('.reveal');

    const revealObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('in-view');
                revealObserver.unobserve(entry.target);
            }
        });
    }, { threshold: 0.15, rootMargin: '0px 0px -60px 0px' });

    revealEls.forEach(el => revealObserver.observe(el));

    // Subtle parallax on the hero image
    const heroMedia = document.getElementById('heroMedia');
    let ticking = false;

    window.addEventListener('scroll', () => {
        if (!ticking) {
            requestAnimationFrame(() => {
                const offset = Math.min(window.scrollY * 0.18, 90);
                heroMedia.style.transform = 'translateY(' + offset + 'px)';
                ticking = false;
            });
            ticking = true;
        }
    });
</script>

<?php include_once 'include/footer.php'; ?>
</body>
</html>