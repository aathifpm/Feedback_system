<?php
// footer.php - Reusable footer component
?>
<style>
    .footer {
        background: var(--bg-color);
        padding: 3rem 2rem;
        text-align: center;
        box-shadow: var(--shadow);
        margin-top: 4rem;
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
    }

    .footer-content {
        max-width: 1200px;
        margin: 0 auto;
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 2rem;
        width: 100%;
        padding: 0 1rem;
        box-sizing: border-box;
    }

    .footer-section h3 {
        color: var(--text-color);
        margin-bottom: 1rem;
    }

    .footer-section p {
        color: #666;
        line-height: 1.6;
    }

    .social-links {
        display: flex;
        justify-content: center;
        gap: 1rem;
        margin-top: 1rem;
    }

    .social-links a {
        color: var(--primary-color);
        font-size: 1.5rem;
        transition: all 0.3s ease;
    }

    .social-links a:hover {
        color: var(--accent-color);
        transform: translateY(-3px);
    }

    .footer-section a {
        color: var(--primary-color);
        text-decoration: none;
        transition: all 0.3s ease;
    }

    .footer-section a:hover {
        color: var(--accent-color);
        text-decoration: underline;
    }

    .copyright {
        margin-top: 2rem;
        color: #666;
        font-size: 0.9rem;
    }

    @media (max-width: 768px) {
        .footer-content {
            grid-template-columns: 1fr;
        }
        
        .footer {
            padding: 2rem 1rem;
        }
        
        .footer-section {
            padding: 0 1rem;
        }
    }
    
    @media (max-width: 480px) {
        .footer {
            padding: 1.5rem 0.5rem;
        }
        
        .footer-content {
            padding: 0 0.5rem;
        }
        
        .footer-section {
            padding: 0 0.5rem;
        }
    }
</style>

<div class="footer">
    <div class="footer-content">
        <div class="footer-section">
            <h3>About Us</h3>
            <p>Panimalar Engineering College is committed to excellence in education, research, and innovation.</p>
        </div>
        <div class="footer-section">
            <h3>Contact</h3>
            <p>Bangalore Trunk Road, Varadharajapuram, Poonamallee, Chennai – 600 123.</p>
            <p><strong>Phone:</strong> <a href="tel:04426490404">044 -26490404 / 0505 / 0717</a></p>
            <p><strong>Email:</strong> <a href="https://mail.google.com/mail/?view=cm&fs=1&to=info@panimalar.ac.in&su=Contact%20from%20Panimalar%20Website&body=Hello%2C%0A%0AI%20would%20like%20to%20contact%20Panimalar%20Engineering%20College.%0A%0A" target="_blank">info@panimalar.ac.in</a></p>
        </div>
        <div class="footer-section">
            <h3>Follow Us</h3>
            <div class="social-links">
                <a href="https://www.facebook.com/panimalarengineeringcollegeofficial" target="_blank"><i class="fab fa-facebook"></i></a>
                <a href="https://www.instagram.com/panimalarengineeringcollege/" target="_blank"><i class="fab fa-instagram"></i></a>
                <a href="https://www.linkedin.com/school/panimalar-engineering-college" target="_blank"><i class="fab fa-linkedin"></i></a>
                <a href="https://www.youtube.com/channel/UCHmR5GOkXG54CoLgJyADIXA" target="_blank"><i class="fab fa-youtube"></i></a>
            </div>
        </div>
        <div class="footer-section">
            <h3>Website Developer</h3>
            <p>Designed and developed by <strong>Aathif Jameel PM</strong> (AI&DS Department)</p>
            <p><strong>Email:</strong> <a href="https://mail.google.com/mail/?view=cm&fs=1&to=aathifpm123@gmail.com&su=Contact%20from%20Panimalar%20Website&body=Hello%2C%0A%0AI%20would%20like%20to%20talk%20to%20you%20regarding...%20.%0A%0A" target="_blank">aathifpm123@gmail.com</a></p>
            <div class="social-links">
                <a href="https://www.linkedin.com/in/aathifpm/" target="_blank"><i class="fab fa-linkedin"></i></a>
                <a href="https://www.github.com/aathifpm" target="_blank"><i class="fab fa-github"></i></a>
                <a href="https://aathifpm.xyz" target="_blank"><i class="fas fa-globe"></i></a>
            </div>
        </div>
    </div>
    <p class="copyright">&copy; <?php echo date('Y'); ?> Panimalar Engineering College. All rights reserved.</p>
</div>

<script>
    // Add smooth scrolling for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            document.querySelector(this.getAttribute('href')).scrollIntoView({
                behavior: 'smooth'
            });
        });
    });

    // Add animation to feature cards on scroll
    const observerOptions = {
        threshold: 0.1
    };

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, observerOptions);

    document.querySelectorAll('.feature-card').forEach(card => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        card.style.transition = 'all 0.5s ease';
        observer.observe(card);
    });
</script> 