import $ from 'jquery';
window.$ = $;

$(document).ready(function() {
    const contentPane = $('#content');

    // Load initial page if we're on an admin route
    const currentPath = window.location.pathname;
    if (currentPath.startsWith('/admin/') && currentPath !== '/admin/dashboard') {
        loadPage(currentPath, false);
    }

    // AJAX link clicks
    $(document).on('click', '.ajax-link', function(e) {
        const url = $(this).attr('href');
        if (!url) return;

        e.preventDefault();
        loadPage(url);
    });

    // AJAX form submission
    $(document).on('submit', '.ajax-form', function(e) {
        e.preventDefault();
        const form = $(this);
        const url = form.attr('action');
        let method = form.attr('method') || 'POST';

        // Check for Laravel's _method field (for DELETE, PUT, PATCH)
        const methodField = form.find('input[name="_method"]');
        if (methodField.length) {
            method = methodField.val();
        }

        // Use form serialization instead of FormData for better compatibility
        const formData = form.serialize();

        $.ajax({
            url: url,
            type: method,
            data: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            success: function(response) {
                contentPane.html(response);
                history.pushState(null, '', url.split('?')[0]);
                updateActiveLink(url.split('?')[0]);
            },
            error: function(xhr) {
                if (xhr.status === 422) {
                    // Validation error - re-render form with errors
                    contentPane.html(xhr.responseText);
                } else {
                    alert('Error: ' + xhr.statusText);
                }
            }
        });
    });

    function loadPage(url, pushState = true) {
        $.ajax({
            url: url,
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            success: function(response) {
                contentPane.html(response);
                if (pushState) {
                    history.pushState(null, '', url);
                }
                updateActiveLink(url);
            },
            error: function(xhr) {
                contentPane.html('<p>Error loading page. ' + xhr.statusText + '</p>');
            }
        });
    }

    function updateActiveLink(url) {
        $('.sidebar-menu a').removeClass('active');
        $('.sidebar-menu a[href*="' + url.split('?')[0] + '"]').first().addClass('active');
    }

    // Handle browser back/forward
    window.addEventListener('popstate', function() {
        const url = window.location.pathname;
        if (url.startsWith('/admin/')) {
            loadPage(url, false);
        }
    });
});
