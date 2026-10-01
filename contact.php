<?php
require __DIR__ . '/includes/contact-handler.php';
include __DIR__ . '/includes/header.php';
?>

<main>
    <section class="page-hero">
        <div class="container section-header">
            <span class="badge">Contact</span>
            <h1>Let’s build healthier neighborhoods together.</h1>
            <p>Reach out to learn about services, volunteer opportunities, or partner programs in your area.</p>
        </div>
    </section>

    <section class="section">
        <div class="container contact-grid">
            <div class="contact-card">
                <h2>Contact information</h2>
                <ul class="feature-list">
                    <li>186 Oak Avenue, Suite 210</li>
                    <li>(415) 555-0148</li>
                    <li>hello@communityhealth.org</li>
                    <li>Mon–Fri, 8:30 AM–6:00 PM</li>
                </ul>
            </div>

            <div class="contact-card">
                <form id="contact-form" data-form="contact" method="post">
                    <input type="hidden" name="contact_form" value="1">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['contact_csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="form-grid">
                        <div class="form-row">
                            <label for="name">Full name</label>
                            <input id="name" name="name" type="text" placeholder="Your name" required>
                        </div>
                        <div class="form-row">
                            <label for="email">Email</label>
                            <input id="email" name="email" type="email" placeholder="name@email.com" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <label for="message">Message</label>
                        <textarea id="message" name="message" placeholder="Tell us how we can support you..." required></textarea>
                    </div>

                    <button class="button button-primary" type="submit">Submit inquiry</button>
                    <div class="form-note <?php echo htmlspecialchars($contactFeedback['type'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" aria-live="polite"><?php echo htmlspecialchars($contactFeedback['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                </form>
            </div>
        </div>
    </section>
</main>

<?php include 'includes/footer.php'; ?>
