// ===========================
// MediClear - JavaScript
// University of St. La Salle
// ===========================

// Wait for DOM to be fully loaded
document.addEventListener('DOMContentLoaded', function() {
    
    // ===========================
    // LOGIN PAGE FUNCTIONALITY
    // ===========================
    
    // Role toggle animation
    const studentRole = document.getElementById('studentRole');
    const clinicRole = document.getElementById('clinicRole');
    const studentLabel = document.getElementById('studentLabel');
    const clinicLabel = document.getElementById('clinicLabel');
    
    if (studentRole && clinicRole) {
        // Add click event listeners
        studentLabel?.addEventListener('click', function() {
            studentRole.checked = true;
            updateToggleSlider();
            updateFormLabels();
            // Ensure other page logic (e.g. register academic fields) updates
            studentRole.dispatchEvent(new Event('change', { bubbles: true }));
        });
        
        clinicLabel?.addEventListener('click', function() {
            clinicRole.checked = true;
            updateToggleSlider();
            updateFormLabels();
            // Ensure other page logic (e.g. register academic fields) updates
            clinicRole.dispatchEvent(new Event('change', { bubbles: true }));
        });
        
        // Update slider position
        function updateToggleSlider() {
            const slider = document.querySelector('.toggle-slider');
            if (studentRole.checked) {
                slider.style.transform = 'translateX(0)';
                if (studentLabel) studentLabel.style.color = '#212121';
                if (clinicLabel) clinicLabel.style.color = 'rgba(255,255,255,0.9)';
            } else if (clinicRole.checked) {
                slider.style.transform = 'translateX(100%)';
                if (studentLabel) studentLabel.style.color = 'rgba(255,255,255,0.9)';
                if (clinicLabel) clinicLabel.style.color = '#212121';
            }
        }
        
        // Update form labels based on role
        function updateFormLabels() {
            const idLabel = document.getElementById('idLabel');
            const idInput = document.getElementById('student_id');
            const isRegister = !!document.getElementById('registerForm');
            
            if (studentRole.checked) {
                idLabel.textContent = isRegister ? 'Student ID *' : 'Student ID';
                idInput.placeholder = 'Enter your ID';
            } else if (clinicRole.checked) {
                idLabel.textContent = isRegister ? 'Staff ID *' : 'Staff ID';
                idInput.placeholder = 'Enter your Staff ID';
            }
        }
        
        // Initialize on page load
        updateToggleSlider();
        updateFormLabels();
    }
    
    // Form validation
    const loginForm = document.getElementById('loginForm');
    if (loginForm) {
        loginForm.addEventListener('submit', function(e) {
            const studentId = document.getElementById('student_id');
            const password = document.getElementById('password');
            
            if (!studentId.value.trim() || !password.value.trim()) {
                e.preventDefault();
                alert('Please fill in all fields');
                return false;
            }
        });
    }

    // ===========================
    // REGISTER PAGE FUNCTIONALITY
    // ===========================
    const registerForm = document.getElementById('registerForm');
    if (registerForm) {
        const emailInput = document.getElementById('email');
        const courseSelect = document.getElementById('course');
        const yearSelect = document.getElementById('year');
        const sectionSelect = document.getElementById('section');
        const yearLevelHidden = document.getElementById('year_level');
        const previewInput = document.getElementById('courseYearSectionPreview');
        const academicFields = document.getElementById('academicFields');

        const fiveYearCourses = (yearSelect?.dataset?.fiveYearCourses || '')
            .split(',')
            .map(s => s.trim())
            .filter(Boolean);
        const selectedYear = yearSelect?.dataset?.selectedYear || '';

        function isStudentRoleSelected() {
            return !!studentRole?.checked;
        }

        function setAcademicEnabled(enabled) {
            if (!academicFields) return;
            academicFields.style.display = enabled ? '' : 'none';

            [courseSelect, yearSelect, sectionSelect].forEach(el => {
                if (!el) return;
                el.disabled = !enabled;
                el.required = enabled;
            });
        }

        function rebuildYearOptions() {
            if (!yearSelect) return;
            const course = courseSelect?.value || '';
            const allow5 = fiveYearCourses.includes(course);
            const maxYear = allow5 ? 5 : 4;

            const current = yearSelect.value || selectedYear || '';
            let options = '<option value="">Select year level</option>';
            for (let y = 1; y <= maxYear; y++) {
                options += `<option value="${y}">${y}</option>`;
            }
            yearSelect.innerHTML = options;

            if (current && Number(current) >= 1 && Number(current) <= maxYear) {
                yearSelect.value = String(current);
            }
        }

        function updateDerivedFields() {
            const c = courseSelect?.value || '';
            const y = yearSelect?.value || '';
            const s = sectionSelect?.value || '';

            const yl = (y && s) ? `${y}${s}` : '';
            if (yearLevelHidden) yearLevelHidden.value = yl;
            if (previewInput) previewInput.value = (c && yl) ? `${c}-${yl}` : '';
        }

        function updateRegisterUI() {
            const enabled = isStudentRoleSelected();
            setAcademicEnabled(enabled);
            if (enabled) {
                rebuildYearOptions();
            }
            updateDerivedFields();
        }

        // Initialize on load (including restoring POSTed values)
        rebuildYearOptions();
        updateRegisterUI();

        // Keep values synced
        courseSelect?.addEventListener('change', function () {
            rebuildYearOptions();
            updateDerivedFields();
        });
        yearSelect?.addEventListener('change', updateDerivedFields);
        sectionSelect?.addEventListener('change', updateDerivedFields);
        studentRole?.addEventListener('change', updateRegisterUI);
        clinicRole?.addEventListener('change', updateRegisterUI);

        registerForm.addEventListener('submit', function (e) {
            const email = (emailInput?.value || '').trim();
            if (!email) {
                e.preventDefault();
                alert('Email is required.');
                return false;
            }
            if (!email.toLowerCase().endsWith('@usls.edu.ph')) {
                e.preventDefault();
                alert('Please use your @usls.edu.ph email address.');
                return false;
            }

            if (isStudentRoleSelected()) {
                updateDerivedFields();
                const course = courseSelect?.value || '';
                const year = yearSelect?.value || '';
                const section = sectionSelect?.value || '';
                if (!course || !year || !section) {
                    e.preventDefault();
                    alert('Please select your course, year level, and section.');
                    return false;
                }
            }
        });
    }
    
    // ===========================
    // SIDEBAR TOGGLE FUNCTIONALITY
    // ===========================
    
    const hamburgerMenu = document.getElementById('hamburgerMenu');
    const sidebar = document.querySelector('.sidebar');
    const mainContent = document.querySelector('.main-content');
    const topHeader = document.querySelector('.top-header');
    
    // Create overlay if it doesn't exist
    let overlay = document.querySelector('.sidebar-overlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.className = 'sidebar-overlay';
        document.body.appendChild(overlay);
    }
    
    function toggleSidebar() {
        if (!sidebar || !mainContent || !topHeader) return;
        
        const isHidden = sidebar.classList.contains('hidden');
        const isMobile = window.innerWidth <= 768;
        
        // Toggle sidebar visibility
        sidebar.classList.toggle('hidden');
        mainContent.classList.toggle('sidebar-hidden');
        topHeader.classList.toggle('sidebar-hidden');
        
        if (hamburgerMenu) {
            hamburgerMenu.classList.toggle('active');
        }
        
        // Handle mobile-specific behavior
        if (isMobile) {
            if (isHidden) {
                // Opening sidebar
                overlay.classList.add('active');
                document.body.classList.add('sidebar-open');
                // Prevent body scroll
                const scrollY = window.scrollY;
                document.body.style.position = 'fixed';
                document.body.style.top = `-${scrollY}px`;
                document.body.style.width = '100%';
            } else {
                // Closing sidebar
                overlay.classList.remove('active');
                document.body.classList.remove('sidebar-open');
                // Restore body scroll
                const scrollY = document.body.style.top;
                document.body.style.position = '';
                document.body.style.top = '';
                document.body.style.width = '';
                if (scrollY) {
                    window.scrollTo(0, parseInt(scrollY || '0') * -1);
                }
            }
        } else {
            // Desktop: always hide overlay
            overlay.classList.remove('active');
            document.body.classList.remove('sidebar-open');
            document.body.style.position = '';
            document.body.style.top = '';
            document.body.style.width = '';
        }
    }
    
    if (hamburgerMenu) {
        hamburgerMenu.addEventListener('click', toggleSidebar);
    }
    
    // Close sidebar when clicking overlay
    if (overlay) {
        overlay.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            if (!sidebar.classList.contains('hidden')) {
                toggleSidebar();
            }
        });
    }
    
    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(e) {
        if (window.innerWidth <= 768 && sidebar && !sidebar.classList.contains('hidden')) {
            const clickedInsideSidebar = sidebar.contains(e.target);
            const clickedHamburger = hamburgerMenu && hamburgerMenu.contains(e.target);
            
            if (!clickedInsideSidebar && !clickedHamburger && e.target !== overlay) {
                toggleSidebar();
            }
        }
    });
    
    // Close sidebar on window resize if desktop
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            if (window.innerWidth > 768) {
                // Desktop: ensure sidebar is visible and overlay is hidden
                if (sidebar) sidebar.classList.remove('hidden');
                if (mainContent) mainContent.classList.remove('sidebar-hidden');
                if (topHeader) topHeader.classList.remove('sidebar-hidden');
                if (overlay) overlay.classList.remove('active');
                if (hamburgerMenu) hamburgerMenu.classList.remove('active');
                document.body.classList.remove('sidebar-open');
                document.body.style.position = '';
                document.body.style.top = '';
                document.body.style.width = '';
            } else {
                // Mobile: ensure sidebar starts hidden
                if (sidebar && !sidebar.classList.contains('hidden')) {
                    // Only close if it was open
                    sidebar.classList.add('hidden');
                    if (mainContent) mainContent.classList.remove('sidebar-hidden');
                    if (topHeader) topHeader.classList.remove('sidebar-hidden');
                    if (overlay) overlay.classList.remove('active');
                    if (hamburgerMenu) hamburgerMenu.classList.remove('active');
                    document.body.classList.remove('sidebar-open');
                    document.body.style.position = '';
                    document.body.style.top = '';
                    document.body.style.width = '';
                }
            }
        }, 100);
    });
    
    // Ensure sidebar starts hidden on mobile on page load
    function initializeSidebar() {
        if (window.innerWidth <= 768 && sidebar) {
            sidebar.classList.add('hidden');
            if (mainContent) mainContent.classList.remove('sidebar-hidden');
            if (topHeader) topHeader.classList.remove('sidebar-hidden');
            if (overlay) overlay.classList.remove('active');
            if (hamburgerMenu) hamburgerMenu.classList.remove('active');
            document.body.classList.remove('sidebar-open');
            document.body.style.position = '';
            document.body.style.top = '';
            document.body.style.width = '';
        }
    }
    
    // Initialize on page load
    initializeSidebar();
    
    // Also initialize after DOM is fully loaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeSidebar);
    } else {
        initializeSidebar();
    }
    
    // Prevent sidebar links from causing issues on mobile
    if (window.innerWidth <= 768) {
        const sidebarLinks = document.querySelectorAll('.sidebar-nav a');
        sidebarLinks.forEach(link => {
            link.addEventListener('click', function() {
                // Close sidebar when a link is clicked on mobile
                setTimeout(() => {
                    if (sidebar && !sidebar.classList.contains('hidden')) {
                        toggleSidebar();
                    }
                }, 100);
            });
        });
    }
    
    // ===========================
    // STUDENT DASHBOARD FUNCTIONALITY
    // ===========================
    
    // Theme toggle (light / dark)
    const themeToggle = document.getElementById('themeToggle');
    const rootEl = document.documentElement;
    const storedTheme = localStorage.getItem('mediclear-theme');

    if (storedTheme === 'dark' || storedTheme === 'light') {
        rootEl.setAttribute('data-theme', storedTheme);
    }

    function toggleTheme() {
        const current = rootEl.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
        const next = current === 'dark' ? 'light' : 'dark';
        rootEl.setAttribute('data-theme', next);
        localStorage.setItem('mediclear-theme', next);
    }

    if (themeToggle) {
        themeToggle.addEventListener('click', toggleTheme);
    }

    // Header panels (notifications, settings, profile)
    function bindPanelToggle(triggerId, panelId) {
        const trigger = document.getElementById(triggerId);
        const panel = document.getElementById(panelId);
        if (!trigger || !panel) return;

        trigger.addEventListener('click', function () {
            const isActive = panel.classList.contains('active');
            document.querySelectorAll('.overlay-panel.active').forEach(p => p.classList.remove('active'));
            if (!isActive) {
                panel.classList.add('active');
            }
        });
    }

    bindPanelToggle('notificationToggle', 'notificationsPanel');
    bindPanelToggle('settingsToggle', 'settingsPanel');
    bindPanelToggle('openProfilePanel', 'profilePanel');
    bindPanelToggle('openProfilePanelHeader', 'profilePanel');

    // Sidebar-nav links that should open panels
    const navNotifications = document.getElementById('navNotifications');
    if (navNotifications) {
        navNotifications.addEventListener('click', function (e) {
            e.preventDefault();
            const panel = document.getElementById('notificationsPanel');
            if (panel) {
                panel.classList.add('active');
            }
        });
    }

    const navProfile = document.getElementById('navProfile');
    if (navProfile) {
        navProfile.addEventListener('click', function (e) {
            e.preventDefault();
            const panel = document.getElementById('profilePanel');
            if (panel) {
                panel.classList.add('active');
            }
        });
    }

    const navSettings = document.getElementById('navSettings');
    if (navSettings) {
        navSettings.addEventListener('click', function (e) {
            e.preventDefault();
            const panel = document.getElementById('settingsPanel');
            if (panel) {
                panel.classList.add('active');
            }
        });
    }

    // Close buttons on overlay panels
    document.querySelectorAll('.overlay-close-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const panelId = this.getAttribute('data-close-panel');
            const panel = panelId ? document.getElementById(panelId) : this.closest('.overlay-panel');
            if (panel) {
                panel.classList.remove('active');
            }
        });
    });

    // Click outside inner content to close panel
    document.querySelectorAll('.overlay-panel').forEach(panel => {
        panel.addEventListener('click', function (e) {
            if (e.target === panel) {
                panel.classList.remove('active');
            }
        });
    });

    // Submit Request Button
    const submitRequestBtn = document.getElementById('submitRequestBtn');
    if (submitRequestBtn) {
        submitRequestBtn.addEventListener('click', function() {
            // This would open a modal in a full implementation
            alert('Form Modal would open here\n\nThis is where students would fill out:\n- Illness type\n- Symptoms\n- Date of illness\n- Additional notes');
        });
    }
    
    // View Certificate Modal (loads certificate.php in iframe)
    const certificateModal = document.getElementById('certificateModal');
    const certificateModalClose = document.getElementById('certificateModalClose');
    const certificateModalIframe = document.getElementById('certificateModalIframe');
    const certificateModalPrint = document.getElementById('certificateModalPrint');
    const certificateModalNewTab = document.getElementById('certificateModalNewTab');
    
    function openCertificateModal(requestId) {
        if (!certificateModal || !certificateModalIframe || !requestId) return;
        const url = 'certificate.php?request_id=' + encodeURIComponent(String(requestId));
        certificateModalIframe.src = url;
        if (certificateModalNewTab) {
            certificateModalNewTab.href = url;
        }
        certificateModal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    
    function closeCertificateModal() {
        if (certificateModal) {
            certificateModal.classList.remove('active');
            document.body.style.overflow = '';
        }
        if (certificateModalIframe) {
            certificateModalIframe.src = 'about:blank';
        }
    }
    
    if (certificateModalClose) {
        certificateModalClose.addEventListener('click', closeCertificateModal);
    }
    
    document.querySelectorAll('.certificate-modal-close-btn').forEach(btn => {
        btn.addEventListener('click', closeCertificateModal);
    });
    
    if (certificateModal) {
        certificateModal.addEventListener('click', function(e) {
            if (e.target === certificateModal) {
                closeCertificateModal();
            }
        });
    }
    
    if (certificateModalPrint) {
        certificateModalPrint.addEventListener('click', function() {
            if (certificateModalIframe && certificateModalIframe.src && certificateModalIframe.src !== 'about:blank') {
                try {
                    certificateModalIframe.contentWindow.print();
                } catch (err) {
                    window.open(certificateModalIframe.src, '_blank');
                }
            }
        });
    }
    
    if (certificateModalNewTab) {
        certificateModalNewTab.addEventListener('click', function(e) {
            if (!this.href || this.href === '#' || this.getAttribute('href') === '#') {
                e.preventDefault();
            }
        });
    }
    
    // View Certificate buttons: open modal with iframe (only .status-approved have certificate)
    const viewCertBtns = document.querySelectorAll('.btn-view-cert.status-approved');
    viewCertBtns.forEach(btn => {
        btn.addEventListener('click', function(e) {
            let requestId = this.getAttribute('data-request-id');
            if (!requestId && this.href) {
                const m = this.href.match(/request_id=(\d+)/);
                requestId = m ? m[1] : null;
            }
            if (!requestId || !certificateModal) return;
            e.preventDefault();
            openCertificateModal(requestId);
        });
    });
    
    // Profile edit form (demo-only front-end update)
    const profileEditForm = document.getElementById('profileEditForm');
    if (profileEditForm) {
        profileEditForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const nameInput = document.getElementById('profileName');
            const emailInput = document.getElementById('profileEmail');
            const passwordInput = document.getElementById('profilePassword');
            const passwordConfirmInput = document.getElementById('profilePasswordConfirm');
            const avatarFileInput = document.getElementById('profileAvatarFile');
            
            if (passwordInput && passwordConfirmInput && passwordInput.value !== passwordConfirmInput.value) {
                alert('Passwords do not match.');
                return;
            }
            
            const newName = nameInput ? nameInput.value.trim() : '';
            
            if (newName) {
                document.querySelectorAll('.profile-panel-name, .summary-name, .user-name').forEach(el => {
                    el.textContent = newName;
                });
                document.querySelectorAll('.profile-panel-avatar span, .summary-avatar-text, .user-avatar-text').forEach(el => {
                    el.textContent = newName.charAt(0).toUpperCase();
                });
            }
            
            if (avatarFileInput && avatarFileInput.files && avatarFileInput.files[0]) {
                const file = avatarFileInput.files[0];
                const reader = new FileReader();
                reader.onload = function(evt) {
                    const dataUrl = evt.target.result;
                    document.querySelectorAll('.profile-panel-avatar, .summary-avatar, .user-avatar').forEach(el => {
                        el.style.backgroundImage = `url(${dataUrl})`;
                        el.style.backgroundSize = 'cover';
                        el.style.backgroundPosition = 'center';
                    });
                };
                reader.readAsDataURL(file);
            }
            
            alert('Profile updated (demo only). In a full system this would save your changes to the server.');
        });
    }
    
    // ===========================
    // CLINIC DASHBOARD: Approve/Reject are handled by clinic_dashboard.php
    // (in-page modal + form submit). No confirm()/alert() here.
    // ===========================
    
    // ===========================
    // UTILITY FUNCTIONS
    // ===========================
    
    // Smooth scroll for any anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });
    
    // Add loading state to buttons on click
    const allButtons = document.querySelectorAll('button[type="submit"]');
    allButtons.forEach(button => {
        button.addEventListener('click', function() {
            // Add visual feedback
            this.style.opacity = '0.7';
            setTimeout(() => {
                this.style.opacity = '1';
            }, 300);
        });
    });
    
    // Auto-hide success messages after 5 seconds
    const successMessages = document.querySelectorAll('.success-message');
    successMessages.forEach(message => {
        setTimeout(() => {
            message.style.transition = 'opacity 0.5s';
            message.style.opacity = '0';
            setTimeout(() => {
                message.remove();
            }, 500);
        }, 5000);
    });
    
    // Console welcome message
    console.log('%c MediClear System ', 'background: #194F2F; color: white; font-size: 16px; padding: 10px;');
    console.log('%c University of St. La Salle - Bacolod ', 'color: #194F2F; font-size: 12px;');
    console.log('Medical Certification System v1.0');
    
});

// ===========================
// ADDITIONAL HELPER FUNCTIONS
// ===========================

// Format date to readable format
function formatDate(dateString) {
    const options = { year: 'numeric', month: 'long', day: 'numeric' };
    return new Date(dateString).toLocaleDateString('en-US', options);
}

// Validate student ID format (example: 2023-0001)
function validateStudentId(id) {
    const pattern = /^\d{4}-\d{4}$/;
    return pattern.test(id);
}

// Show notification (could be used for toast messages)
function showNotification(message, type = 'info') {
    // This is a placeholder for a notification system
    console.log(`[${type.toUpperCase()}] ${message}`);
    
    // In a full implementation, you would create a toast notification element
    // For now, we'll use a simple alert
    if (type === 'error') {
        alert('Error: ' + message);
    }
}

// Export functions for use in other scripts (if needed)
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        formatDate,
        validateStudentId,
        showNotification
    };
}
