<?php
require __DIR__ . '/includes/contact-handler.php';
include __DIR__ . '/includes/header.php';
?>

<main>
    <section class="hero">
        <div class="container hero-grid">
            <div>
                <span class="badge">Community care for every neighbor</span>
                <h1>Healthy communities start with trusted support.</h1>
                <p class="lead">
                    Community Health connects families to preventive care, wellness coaching, food access,
                    and local programs that improve everyday life.
                </p>

                <div class="hero-actions">
                    <a href="contact.php" class="button button-primary">Book a wellness visit</a>
                    <a href="programs.php" class="button button-secondary">Explore programs</a>
                </div>

                <div class="metrics" aria-label="Community health statistics">
                    <div class="metric">
                        <strong>12k+</strong>
                        <span>Residents served</span>
                    </div>
                    <div class="metric">
                        <strong>94%</strong>
                        <span>Care satisfaction</span>
                    </div>
                    <div class="metric">
                        <strong>38</strong>
                        <span>Local partners</span>
                    </div>
                </div>
            </div>

            <div class="hero-visual" aria-label="Community health dashboard">
                <div class="card visual-panel">
                    <h3>Neighborhood wellness snapshot</h3>
                    <div class="stat-list">
                        <div class="stat-item"><span>Preventive care</span><strong>87%</strong></div>
                        <div class="stat-item"><span>Nutrition support</span><strong>64%</strong></div>
                        <div class="stat-item"><span>Medication access</span><strong>71%</strong></div>
                    </div>
                </div>

                <div class="card float-card">
                    <span class="pill">New this month</span>
                    <h3>Mobile checkups</h3>
                    <p>Weekly screenings are now available at three neighborhood hubs.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="section">
        <div class="container">
            <div class="section-header">
                <span class="badge">How we help</span>
                <h2>Support designed around real community needs.</h2>
            </div>

            <div class="grid-3">
                <article class="info-card">
                    <div class="icon-box">🩺</div>
                    <h3>Preventive care</h3>
                    <p>Routine screenings, immunizations, and health coaching to keep families well before issues escalate.</p>
                </article>

                <article class="info-card">
                    <div class="icon-box">🥗</div>
                    <h3>Healthy living</h3>
                    <p>Wellness education and nutrition support that make healthy choices practical, affordable, and local.</p>
                </article>

                <article class="info-card">
                    <div class="icon-box">🤝</div>
                    <h3>Connected care</h3>
                    <p>We coordinate with clinics, schools, and social services so families get help in one place.</p>
                </article>
            </div>
        </div>
    </section>

    <section class="section story">
        <div class="container two-col">
            <div class="story-visual" aria-label="Community health volunteers">
                <div class="story-box">
                    <span>Community-led impact</span>
                    <strong>3,200+</strong>
                    <p>Volunteer hours shared with local families and senior support groups.</p>
                </div>
            </div>

            <div class="story-content">
                <span class="badge">Our mission</span>
                <h2>Making equitable care accessible and personal.</h2>
                <p>We believe healthy neighborhoods are built through trust, education, and practical support. Our programs meet people where they are, helping them navigate care with dignity and confidence.</p>
                <ul class="feature-list">
                    <li>Multilingual outreach and education</li>
                    <li>Evidence-based prevention programs</li>
                    <li>Flexible scheduling for working families</li>
                </ul>
            </div>
        </div>
    </section>

    <section class="section">
        <div class="container">
            <div class="section-header">
                <span class="badge">Featured programs</span>
                <h2>People-first programs that support everyday health.</h2>
            </div>

            <div class="grid-3">
                <article class="program-card">
                    <div class="program-meta"><span>Primary care</span><span>Ongoing</span></div>
                    <h3>Family health navigation</h3>
                    <p>Personalized assistance connecting households to screenings, referrals, and preventive visits.</p>
                </article>

                <article class="program-card">
                    <div class="program-meta"><span>Wellness</span><span>Weekly</span></div>
                    <h3>Healthy habits workshops</h3>
                    <p>Hands-on classes on movement, nutrition, stress reduction, and sleep quality.</p>
                </article>

                <article class="program-card">
                    <div class="program-meta"><span>Outreach</span><span>Community</span></div>
                    <h3>Mobile screening events</h3>
                    <p>Convenient on-site checkups at schools, faith centers, and local parks.</p>
                </article>
            </div>
        </div>
    </section>

    <section class="section">
        <div class="container">
            <div class="section-header">
                <span class="badge">Stories</span>
                <h2>Families feel supported, not overwhelmed.</h2>
            </div>

            <div class="grid-3">
                <blockquote class="testimonial">
                    <div class="quote">“The team helped us find care we could actually afford and understand.”</div>
                    <div class="person">
                        <div class="avatar">L</div>
                        <div>
                            <strong>Leah M.</strong><br>
                            <span>Parent of two</span>
                        </div>
                    </div>
                </blockquote>

                <blockquote class="testimonial">
                    <div class="quote">“Their wellness workshops gave our neighborhood tools we still use every week.”</div>
                    <div class="person">
                        <div class="avatar">D</div>
                        <div>
                            <strong>David R.</strong><br>
                            <span>Community volunteer</span>
                        </div>
                    </div>
                </blockquote>

                <blockquote class="testimonial">
                    <div class="quote">“From screenings to referrals, every step felt respectful and clear.”</div>
                    <div class="person">
                        <div class="avatar">A</div>
                        <div>
                            <strong>Alicia T.</strong><br>
                            <span>Senior program participant</span>
                        </div>
                    </div>
                </blockquote>
            </div>
        </div>
    </section>

    <section class="section">
        <div class="container contact-grid">
            <div class="contact-card">
                <span class="badge">Get involved</span>
                <h2>Help us expand care where it matters most.</h2>
                <p>Whether you want to volunteer, refer a family, or learn more, we’d love to connect.</p>
                <ul class="feature-list">
                    <li>Volunteer with outreach events</li>
                    <li>Support local health education</li>
                    <li>Partner with our wellness programs</li>
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
                        <label for="message">How can we help?</label>
                        <textarea id="message" name="message" placeholder="Tell us a little about your needs or interests..." required></textarea>
                    </div>

                    <button class="button button-primary" type="submit">Send request</button>
                    <div class="form-note <?php echo htmlspecialchars($contactFeedback['type'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" aria-live="polite"><?php echo htmlspecialchars($contactFeedback['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                </form>
            </div>
        </div>
    </section>
</main>

<?php include 'includes/footer.php'; ?>
