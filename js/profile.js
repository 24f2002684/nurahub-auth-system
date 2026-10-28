/**
 * NuraHub Auth & User Profile System
 * Client-Side Profile Dashboard Logic & AJAX Handlers
 */

$(document).ready(function() {
  const $skeleton = $('#loadingSkeleton');
  const $content = $('#profileContent');
  const $alert = $('#profileAlert');
  const $viewCard = $('#viewProfileCard');
  const $editCard = $('#editProfileCard');
  const $editForm = $('#profileEditForm');
  const $saveBtn = $('#saveProfileBtn');
  const $saveSpinner = $('#saveSpinner');
  const $saveBtnText = $('#saveBtnText');
  const $logoutBtn = $('#logoutBtn');
  const $logoutBtnText = $('#logoutBtnText');

  let activeCsrfToken = '';
  let currentUserData = null;

  // ---------------------------------------------------------------------------
  // 1. Notification Display Helper
  // ---------------------------------------------------------------------------
  function showAlert(message, type = 'success') {
    const icon = type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill';
    const alertHtml = `
      <div class="alert alert-${type} alert-dismissible fade show d-flex align-items-center" role="alert">
        <i class="bi ${icon} me-2 fs-5"></i>
        <div class="flex-grow-1">${message}</div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    `;
    $alert.html(alertHtml).removeClass('d-none');
    $('html, body').animate({ scrollTop: $alert.offset().top - 80 }, 200);
  }

  function clearAlert() {
    $alert.html('').addClass('d-none');
  }

  // ---------------------------------------------------------------------------
  // 2. Escape HTML for Safe Rendering (XSS Prevention)
  // ---------------------------------------------------------------------------
  function escapeHtml(string) {
    if (string === null || string === undefined) return '';
    return String(string)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  // ---------------------------------------------------------------------------
  // 3. Fetch and Render Current User Profile
  // ---------------------------------------------------------------------------
  function loadUserProfile() {
    $.ajax({
      url: 'php/profile.php',
      method: 'GET',
      dataType: 'json',
      cache: false,
      success: function(response) {
        if (!response || response.status !== 'success' || !response.data) {
          window.location.replace('login.html');
          return;
        }

        const data = response.data;
        const user = data.user || {};
        const profile = data.profile || {};

        currentUserData = data;
        activeCsrfToken = data.csrf_token || '';
        $('#profileCsrfToken').val(activeCsrfToken);

        // Account Metadata from MySQL
        const username = user.username || 'User';
        const email = user.email || '';
        const userId = user.id ? '#' + user.id : '--';
        const createdAt = user.created_at || 'Unknown';

        $('#navUsername').text(username);
        $('#displayUsername').text('@' + username);
        $('#displayEmail').text(email);
        $('#displayUserId').text(userId);
        $('#displayCreatedAt').text(createdAt);
        $('#avatarLetter').text(username.charAt(0).toUpperCase());

        // Profile Data from MongoDB
        renderProfileView(profile);

        // Populate Edit Form Inputs
        populateEditForm(profile);

        // Transition UI from skeleton to content
        $skeleton.addClass('d-none');
        $content.removeClass('d-none');
      },
      error: function(xhr) {
        // If unauthenticated (401) or forbidden (403), redirect immediately to login
        if (xhr.status === 401 || xhr.status === 403) {
          window.location.replace('login.html');
          return;
        }

        $skeleton.addClass('d-none');
        showAlert('Unable to load profile data. Please try logging in again.', 'danger');
      }
    });
  }

  // Render view card fields
  function renderProfileView(profile) {
    const fullName = profile.full_name ? escapeHtml(profile.full_name) : '<em>Not specified</em>';
    const age = profile.age ? escapeHtml(profile.age) + ' years old' : '<em>Not specified</em>';
    const bio = profile.bio ? escapeHtml(profile.bio) : '<em>No bio provided yet. Click "Edit Profile" to tell us about yourself.</em>';
    const updatedAt = profile.updated_at ? escapeHtml(profile.updated_at) : 'Never';

    $('#viewFullName').html(fullName);
    $('#viewAge').html(age);
    $('#viewBio').html(bio);
    $('#viewUpdatedAt').text(updatedAt);

    // Render Interests Tags
    const $interestsContainer = $('#viewInterestsContainer');
    $interestsContainer.empty();

    if (Array.isArray(profile.interests) && profile.interests.length > 0) {
      $.each(profile.interests, function(index, tag) {
        const cleanTag = escapeHtml(tag);
        $interestsContainer.append(`<span class="profile-tag"><i class="bi bi-tag me-1"></i>${cleanTag}</span>`);
      });
    } else {
      $interestsContainer.html('<em>No interests listed.</em>');
    }
  }

  // Populate edit form fields
  function populateEditForm(profile) {
    $('#fullNameInput').val(profile.full_name || '');
    $('#ageInput').val(profile.age !== null ? profile.age : '');
    $('#bioInput').val(profile.bio || '');

    const interestsStr = Array.isArray(profile.interests) ? profile.interests.join(', ') : '';
    $('#interestsInput').val(interestsStr);

    updateBioCounter();
  }

  // Bio character counter
  function updateBioCounter() {
    const currentLen = $('#bioInput').val().length;
    $('#bioCounter').text(`${currentLen} / 1000`);
    if (currentLen > 950) {
      $('#bioCounter').addClass('text-danger');
    } else {
      $('#bioCounter').removeClass('text-danger');
    }
  }

  $('#bioInput').on('input', updateBioCounter);

  // ---------------------------------------------------------------------------
  // 4. View / Edit Mode Toggling
  // ---------------------------------------------------------------------------
  $('#editProfileToggleBtn').on('click', function() {
    clearAlert();
    $viewCard.addClass('d-none');
    $editCard.removeClass('d-none');
  });

  $('#cancelEditBtn, #cancelEditBtn2').on('click', function() {
    clearAlert();
    // Reset form inputs back to current stored profile
    if (currentUserData && currentUserData.profile) {
      populateEditForm(currentUserData.profile);
    }
    $('.form-control').removeClass('is-invalid');
    $editCard.addClass('d-none');
    $viewCard.removeClass('d-none');
  });

  // ---------------------------------------------------------------------------
  // 5. Profile Edit AJAX Submission
  // ---------------------------------------------------------------------------
  $editForm.on('submit', function(e) {
    e.preventDefault();
    clearAlert();

    // Reset invalid indicators
    $('.form-control').removeClass('is-invalid');
    $('.invalid-feedback').text('').hide();

    const fullName = $.trim($('#fullNameInput').val());
    const rawAge = $.trim($('#ageInput').val());
    const bio = $.trim($('#bioInput').val());
    const rawInterests = $.trim($('#interestsInput').val());

    let isValid = true;

    if (fullName.length > 100) {
      $('#fullNameInput').addClass('is-invalid');
      $('#fullNameFeedback').text('Full name cannot exceed 100 characters.').show();
      isValid = false;
    }

    if (rawAge !== '') {
      const ageNum = parseInt(rawAge, 10);
      if (isNaN(ageNum) || ageNum < 1 || ageNum > 130) {
        $('#ageInput').addClass('is-invalid');
        $('#ageFeedback').text('Age must be a valid whole number between 1 and 130.').show();
        isValid = false;
      }
    }

    if (bio.length > 1000) {
      $('#bioInput').addClass('is-invalid');
      $('#bioFeedback').text('Bio cannot exceed 1000 characters.').show();
      isValid = false;
    }

    if (!isValid) {
      return;
    }

    // Split interests by commas
    const interestsArray = rawInterests.length > 0 
      ? rawInterests.split(',').map(s => $.trim(s)).filter(s => s.length > 0)
      : [];

    const payload = {
      full_name: fullName,
      age: rawAge !== '' ? parseInt(rawAge, 10) : null,
      bio: bio,
      interests: interestsArray,
      csrf_token: activeCsrfToken
    };

    // UI Loading state
    $saveBtn.prop('disabled', true);
    $saveSpinner.removeClass('d-none');
    $saveBtnText.text('Saving Changes...');

    $.ajax({
      url: 'php/profile.php',
      method: 'POST',
      contentType: 'application/json; charset=utf-8',
      data: JSON.stringify(payload),
      dataType: 'json',
      headers: {
        'X-CSRF-Token': activeCsrfToken
      },
      success: function(response) {
        $saveBtn.prop('disabled', false);
        $saveSpinner.addClass('d-none');
        $saveBtnText.text('Save Changes');

        if (response && response.status === 'success' && response.data) {
          const updatedProfile = response.data.profile;

          // Update cached profile
          if (currentUserData) {
            currentUserData.profile = updatedProfile;
          }

          if (response.data.csrf_token) {
            activeCsrfToken = response.data.csrf_token;
            $('#profileCsrfToken').val(activeCsrfToken);
          }

          // Update UI view card
          renderProfileView(updatedProfile);

          // Return to view mode
          $editCard.addClass('d-none');
          $viewCard.removeClass('d-none');

          showAlert('Profile changes saved successfully in MongoDB!', 'success');
        } else {
          showAlert(response.message || 'Unable to update profile.', 'danger');
        }
      },
      error: function(xhr) {
        $saveBtn.prop('disabled', false);
        $saveSpinner.addClass('d-none');
        $saveBtnText.text('Save Changes');

        if (xhr.status === 401) {
          window.location.replace('login.html');
          return;
        }

        let errorMessage = 'Failed to update profile. Please verify your inputs.';
        let responseData = null;

        try {
          responseData = JSON.parse(xhr.responseText);
        } catch (err) {
          // Non-JSON
        }

        if (responseData) {
          if (responseData.message) errorMessage = responseData.message;

          if (responseData.errors) {
            $.each(responseData.errors, function(field, msg) {
              const $field = $('#' + field + 'Input');
              const $feedback = $('#' + field + 'Feedback');
              if ($field.length) {
                $field.addClass('is-invalid');
                $feedback.text(msg).show();
              }
            });
          }
        }

        showAlert(errorMessage, 'danger');
      }
    });
  });

  // ---------------------------------------------------------------------------
  // 6. Secure Logout Handler
  // ---------------------------------------------------------------------------
  $logoutBtn.on('click', function(e) {
    e.preventDefault();

    $logoutBtn.prop('disabled', true);
    $logoutBtnText.text('Logging out...');

    $.ajax({
      url: 'php/logout.php',
      method: 'POST',
      dataType: 'json',
      headers: {
        'X-CSRF-Token': activeCsrfToken
      },
      data: {
        csrf_token: activeCsrfToken
      },
      complete: function() {
        // Regardless of network state, redirect immediately to login
        window.location.replace('login.html');
      }
    });
  });

  // Initialize and load dashboard
  loadUserProfile();
});
