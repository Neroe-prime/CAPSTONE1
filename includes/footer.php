<!-- Bootstrap Core JS Bundle (Ensures dropdowns, modals, and tooltips work system-wide) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Global Dropdown Interaction Fallback -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Target all dropdown toggles inside topbars across all modules
            const dropdownToggles = document.querySelectorAll('.top-navbar .dropdown-toggle');
            
            dropdownToggles.forEach(toggle => {
                toggle.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    // Close all other open dropdowns
                    document.querySelectorAll('.top-navbar .dropdown-menu').forEach(menu => {
                        if (menu !== this.nextElementSibling) {
                            menu.classList.remove('show');
                        }
                    });

                    // Toggle current dropdown menu visibility
                    let menu = this.nextElementSibling;
                    if (menu && menu.classList.contains('dropdown-menu')) {
                        menu.classList.toggle('show');
                    }
                });
            });

            // Close dropdowns when clicking anywhere else on the page
            window.addEventListener('click', function () {
                document.querySelectorAll('.top-navbar .dropdown-menu').forEach(menu => {
                    menu.classList.remove('show');
                });
            });
        });
    </script>