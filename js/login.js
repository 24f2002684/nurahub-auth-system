/**
 * NuraHub Auth & User Profile System
 * Client-Side Login Logic & AJAX Form Submission
 */

$(document).ready(function() {
  const $form = $('#loginForm');
  const $submitBtn = $('#submitBtn');
  const $btnSpinner = $('#btnSpinner');
  const $btnText = $('#btnText');
  const $alertContainer = $('#alertContainer');
  const $csrfTokenInput = $('#csrfToken');

  let activeCsrfToken = '';

  // ---------------------------------------------------------------------------
  // 1. Check Current Authentication Status & Fetch CSRF Token
  // ---------------------------------------------------------------------------
  function initializeSession() {
    $.ajax({
      url: 'php/auth_check.php',
      method: 'GET',
      dataType: 'json',
      cache: false,
      success: function(response) {
        if (response && response.data) {
          if (response.data.authenticated) {
            // User is already logged in; redirect immediately to profile
            window.location.replace('profile.html');
            return;
          }
          if (response.data.csrf_token) {
            activeCsrfToken = response.data.csrf_token;
            $csrfTokenInput.val(activeCsrfToken);
          }
        }
      },
      error: function() {
        showAlert('Unable to establish secure session connection with server.', 'danger');
      }
    });
  }

  initializeSession();

  // ---------------------------------------------------------------------------
  // 2. Alert Box Display Helper
  // ---------------------------------------------------------------------------
  function showAlert(message, type = 'danger') {
    const icon = type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill';
    const alertHtml = `
      <div class="alert alert-${type} alert-dismissible fade show d-flex align-items-center" role="alert">
        <i class="bi ${icon} me-2 fs-5"></i>
        <div class="flex-grow-1">${message}</div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    `;
    $alertContainer.html(alertHtml).removeClass('d-none');
    $('html, body').animate({ scrollTop: $alertContainer.offset().top - 80 }, 200);
  }

  function clearAlert() {
    $alertContainer.html('').addClass('d-none');
  }

  // ---------------------------------------------------------------------------
  // 3. Password Visibility Toggle
  // ---------------------------------------------------------------------------
  $('.toggle-password').on('click', function() {
    const targetSelector = $(this).data('target');
    const $target = $(targetSelector);
    const $icon = $(this).find('i');

    if ($target.attr('type') === 'password') {
      $target.attr('type', 'text');
      $icon.removeClass('bi-eye').addClass('bi-eye-slash');
    } else {
      $target.attr('type', 'password');
      $icon.removeClass('bi-eye-slash').addClass('bi-eye');
    }
  });

  // Clear invalid indicators on input focus
  $('input').on('focus', function() {
    $(this).removeClass('is-invalid');
  });

  // ---------------------------------------------------------------------------
  // 4. Asynchronous AJAX Login Submission
  // ---------------------------------------------------------------------------
  $form.on('submit', function(e) {
    e.preventDefault();
    clearAlert();

    const email = $.trim($('#email').val());
    const password = $('#password').val();

    let isValid = true;
    if (email.length === 0) {
      $('#email').addClass('is-invalid');
      $('#emailFeedback').text('Please enter your email address.').show();
      isValid = false;
    }

    if (password.length === 0) {
      $('#password').addClass('is-invalid');
      $('#passwordFeedback').text('Please enter your password.').show();
      isValid = false;
    }

    if (!isValid) {
      return;
    }

    const payload = {
      email: email,
      password: password,
      csrf_token: activeCsrfToken || $csrfTokenInput.val()
    };

    // UI Loading State
    $submitBtn.prop('disabled', true);
    $btnSpinner.removeClass('d-none');
    $btnText.text('Signing In...');

    $.ajax({
      url: 'php/login.php',
      method: 'POST',
      contentType: 'application/json; charset=utf-8',
      data: JSON.stringify(payload),
      dataType: 'json',
      headers: {
        'X-CSRF-Token': activeCsrfToken
      },
      success: function(response) {
        if (response && response.status === 'success') {
          showAlert('Login successful! Redirecting to your dashboard...', 'success');
          // Short delay for visual feedback before redirect
          setTimeout(function() {
            window.location.replace(response.data && response.data.redirect ? response.data.redirect : 'profile.html');
          }, 600);
        } else {
          $submitBtn.prop('disabled', false);
          $btnSpinner.addClass('d-none');
          $btnText.text('Sign In');
          showAlert(response.message || 'Authentication failed.', 'danger');
        }
      },
      error: function(xhr) {
        $submitBtn.prop('disabled', false);
        $btnSpinner.addClass('d-none');
        $btnText.text('Sign In');

        let errorMessage = 'Invalid email or password.';
        let responseData = null;

        try {
          responseData = JSON.parse(xhr.responseText);
        } catch (err) {
          // Non-JSON response
        }

        if (responseData && responseData.message) {
          errorMessage = responseData.message;
        }

        showAlert(errorMessage, 'danger');

        // Always re-fetch session CSRF token if request was rejected or unauthorized
        initializeSession();
      }
    });
  });
});
