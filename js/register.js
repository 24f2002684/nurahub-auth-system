/**
 * NuraHub Auth & User Profile System
 * Client-Side Registration Logic & AJAX Form Submission
 */

$(document).ready(function() {
  const $form = $('#registerForm');
  const $submitBtn = $('#submitBtn');
  const $btnSpinner = $('#btnSpinner');
  const $btnText = $('#btnText');
  const $alertContainer = $('#alertContainer');
  const $csrfTokenInput = $('#csrfToken');

  let activeCsrfToken = '';

  // ---------------------------------------------------------------------------
  // 1. Fetch Fresh CSRF Token and Verify Auth Status
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
            // Already logged in; redirect to profile
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

  // ---------------------------------------------------------------------------
  // 4. Live Password Strength Meter
  // ---------------------------------------------------------------------------
  $('#password').on('input', function() {
    const val = $(this).val();
    let score = 0;
    if (val.length >= 8) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[a-z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;

    const $bar = $('#passwordMeterBar');
    const $label = $('#passwordStrengthLabel');

    if (val.length === 0) {
      $bar.css({ width: '0%', backgroundColor: 'transparent' });
      $label.text('Strength: None').removeClass().addClass('text-muted');
    } else if (score <= 2) {
      $bar.css({ width: '33%', backgroundColor: '#ef4444' });
      $label.text('Strength: Weak').removeClass().addClass('text-danger fw-semibold');
    } else if (score <= 4) {
      $bar.css({ width: '66%', backgroundColor: '#f59e0b' });
      $label.text('Strength: Moderate').removeClass().addClass('text-warning fw-semibold');
    } else {
      $bar.css({ width: '100%', backgroundColor: '#10b981' });
      $label.text('Strength: Strong').removeClass().addClass('text-success fw-semibold');
    }

    // Live confirm password check if filled
    const confirmVal = $('#confirmPassword').val();
    if (confirmVal.length > 0) {
      validatePasswordMatch();
    }
  });

  $('#confirmPassword').on('input', validatePasswordMatch);

  function validatePasswordMatch() {
    const pass = $('#password').val();
    const confirmPass = $('#confirmPassword').val();
    const $confirmInput = $('#confirmPassword');
    const $feedback = $('#confirmPasswordFeedback');

    if (confirmPass.length > 0 && pass !== confirmPass) {
      $confirmInput.addClass('is-invalid');
      $feedback.text('Passwords do not match.').show();
      return false;
    } else {
      $confirmInput.removeClass('is-invalid');
      $feedback.text('').hide();
      return true;
    }
  }

  // Clear validation state on input focus
  $('input').on('focus', function() {
    $(this).removeClass('is-invalid');
  });

  // ---------------------------------------------------------------------------
  // 5. Client-Side Form Validation
  // ---------------------------------------------------------------------------
  function validateClientForm() {
    let isValid = true;

    // Reset previous invalid states
    $('.form-control').removeClass('is-invalid');
    $('.invalid-feedback').text('').hide();

    // Username validation
    const username = $.trim($('#username').val());
    if (username.length === 0) {
      $('#username').addClass('is-invalid');
      $('#usernameFeedback').text('Please enter a username.').show();
      isValid = false;
    } else if (username.length < 3 || username.length > 30) {
      $('#username').addClass('is-invalid');
      $('#usernameFeedback').text('Username must be between 3 and 30 characters.').show();
      isValid = false;
    } else if (!/^[a-zA-Z0-9_]+$/.test(username)) {
      $('#username').addClass('is-invalid');
      $('#usernameFeedback').text('Username can only contain letters, numbers, and underscores.').show();
      isValid = false;
    }

    // Email validation
    const email = $.trim($('#email').val());
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (email.length === 0) {
      $('#email').addClass('is-invalid');
      $('#emailFeedback').text('Please enter an email address.').show();
      isValid = false;
    } else if (!emailRegex.test(email)) {
      $('#email').addClass('is-invalid');
      $('#emailFeedback').text('Please enter a valid email address.').show();
      isValid = false;
    }

    // Password validation
    const password = $('#password').val();
    if (password.length === 0) {
      $('#password').addClass('is-invalid');
      $('#passwordFeedback').text('Please enter a password.').show();
      isValid = false;
    } else if (password.length < 8) {
      $('#password').addClass('is-invalid');
      $('#passwordFeedback').text('Password must be at least 8 characters long.').show();
      isValid = false;
    } else if (!/[A-Z]/.test(password) || !/[a-z]/.test(password) || !/[0-9]/.test(password)) {
      $('#password').addClass('is-invalid');
      $('#passwordFeedback').text('Password must include uppercase, lowercase, and numeric characters.').show();
      isValid = false;
    }

    // Confirm password validation
    const confirmPassword = $('#confirmPassword').val();
    if (confirmPassword.length === 0) {
      $('#confirmPassword').addClass('is-invalid');
      $('#confirmPasswordFeedback').text('Please confirm your password.').show();
      isValid = false;
    } else if (password !== confirmPassword) {
      $('#confirmPassword').addClass('is-invalid');
      $('#confirmPasswordFeedback').text('Passwords do not match.').show();
      isValid = false;
    }

    return isValid;
  }

  // ---------------------------------------------------------------------------
  // 6. Asynchronous AJAX Form Submission
  // ---------------------------------------------------------------------------
  $form.on('submit', function(e) {
    e.preventDefault();
    clearAlert();

    if (!validateClientForm()) {
      return;
    }

    // Prepare payload
    const formData = {
      username: $.trim($('#username').val()),
      email: $.trim($('#email').val()),
      password: $('#password').val(),
      confirm_password: $('#confirmPassword').val(),
      csrf_token: activeCsrfToken || $csrfTokenInput.val()
    };

    // UI Loading State
    $submitBtn.prop('disabled', true);
    $btnSpinner.removeClass('d-none');
    $btnText.text('Creating Account...');

    $.ajax({
      url: 'php/register.php',
      method: 'POST',
      contentType: 'application/json; charset=utf-8',
      data: JSON.stringify(formData),
      dataType: 'json',
      headers: {
        'X-CSRF-Token': activeCsrfToken
      },
      success: function(response) {
        $submitBtn.prop('disabled', false);
        $btnSpinner.addClass('d-none');
        $btnText.text('Create Account');

        if (response && response.status === 'success') {
          // Reset form fields
          $form[0].reset();
          $('#passwordMeterBar').css('width', '0%');
          $('#passwordStrengthLabel').text('Strength: None').removeClass().addClass('text-muted');

          // Show success message with navigation to login
          const successMsg = `
            <strong>Registration successful!</strong> ${response.message}
            <div class="mt-2">
              <a href="login.html" class="btn btn-sm btn-success text-white">Go to Login</a>
            </div>
          `;
          showAlert(successMsg, 'success');
        } else {
          showAlert(response.message || 'An unexpected error occurred.', 'danger');
        }
      },
      error: function(xhr) {
        $submitBtn.prop('disabled', false);
        $btnSpinner.addClass('d-none');
        $btnText.text('Create Account');

        let errorMessage = 'An error occurred during registration. Please try again.';
        let responseData = null;

        try {
          responseData = JSON.parse(xhr.responseText);
        } catch (err) {
          // Non-JSON response
        }

        if (responseData) {
          if (responseData.message) {
            errorMessage = responseData.message;
          }

          // Handle field-specific validation errors from backend
          if (responseData.errors && typeof responseData.errors === 'object') {
            $.each(responseData.errors, function(field, msg) {
              const $field = $('#' + field);
              const $feedback = $('#' + field + 'Feedback');
              if ($field.length) {
                $field.addClass('is-invalid');
                $feedback.text(msg).show();
              }
            });
          }
        }

        showAlert(errorMessage, 'danger');

        // If CSRF error occurred, refresh token
        if (xhr.status === 403) {
          initializeSession();
        }
      }
    });
  });
});
