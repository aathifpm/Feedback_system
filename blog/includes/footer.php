    <footer class="bg-dark text-white py-5 mt-5">
        <div class="container">
            <div class="row">
                <div class="col-md-4">
                    <h5>Panimalar Engineering College</h5>
                    <p class="text-muted">
                        Sharing knowledge, insights, and campus news through our official blog.
                    </p>
                    <div class="social-links mt-3">
                        <a href="#" class="text-white me-3"><i class="fab fa-facebook-f"></i></a>
                        <a href="#" class="text-white me-3"><i class="fab fa-twitter"></i></a>
                        <a href="#" class="text-white me-3"><i class="fab fa-instagram"></i></a>
                        <a href="#" class="text-white me-3"><i class="fab fa-linkedin-in"></i></a>
                        <a href="#" class="text-white"><i class="fab fa-youtube"></i></a>
                    </div>
                </div>
                <div class="col-md-4">
                    <h5>Quick Links</h5>
                    <ul class="list-unstyled">
                        <li><a href="index.php" class="text-muted">Home</a></li>
                        <li><a href="about.php" class="text-muted">About Us</a></li>
                        <li><a href="contact.php" class="text-muted">Contact</a></li>
                        <li><a href="../index.php" class="text-muted">Main Website</a></li>
                        <li><a href="privacy-policy.php" class="text-muted">Privacy Policy</a></li>
                    </ul>
                </div>
                <div class="col-md-4">
                    <h5>Newsletter</h5>
                    <p class="text-muted">Subscribe to our newsletter for latest updates</p>
                    <form class="mt-3">
                        <div class="input-group mb-3">
                            <input type="email" class="form-control" placeholder="Your Email" aria-label="Email" aria-describedby="subscribe-button">
                            <button class="btn btn-primary" type="button" id="subscribe-button">Subscribe</button>
                        </div>
                    </form>
                </div>
            </div>
            <hr>
            <div class="row">
                <div class="col-md-12 text-center">
                    <p class="mb-0 text-muted">&copy; <?php echo date('Y'); ?> Panimalar Engineering College. All rights reserved.</p>
                </div>
            </div>
        </div>
    </footer>
    
    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <?php if (isset($extra_js)): ?>
    <script>
        <?php echo $extra_js; ?>
    </script>
    <?php endif; ?>
</body>
</html> 