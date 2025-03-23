/**
 * Watermarker edit page JavaScript
 * Handles the watermark form added to edit pages
 */
(function() {
    'use strict';

    // Initialize when DOM is loaded
    document.addEventListener('DOMContentLoaded', function() {
        initializeWatermarkForm();
    });

    /**
     * Initialize the watermark form
     */
    function initializeWatermarkForm() {
        // Find all watermark forms
        var forms = document.querySelectorAll('.watermark-form');
        if (!forms.length) {
            console.log('Watermarker: No watermark forms found');
            return;
        }

        console.log('Watermarker: Found ' + forms.length + ' watermark forms');

        // Set up each form
        forms.forEach(function(form) {
            setupWatermarkForm(form);
        });
    }

    /**
     * Set up a watermark form
     *
     * @param {HTMLElement} form The watermark form element
     */
    function setupWatermarkForm(form) {
        var saveButton = form.querySelector('.watermark-save-button');
        var selectElement = form.querySelector('select');
        var statusElement = form.querySelector('.watermark-status');

        if (!saveButton || !selectElement) {
            console.error('Watermarker: Missing form elements');
            return;
        }

        console.log('Watermarker: Setting up form', {
            resourceType: form.getAttribute('data-resource-type'),
            resourceId: form.getAttribute('data-resource-id'),
            apiUrl: form.getAttribute('data-api-url')
        });

        // Setup save button click handler
        saveButton.addEventListener('click', function(event) {
            event.preventDefault();
            saveWatermarkSettings(form, selectElement, statusElement);
        });
    }

    /**
     * Save watermark settings
     *
     * @param {HTMLElement} form The watermark form element
     * @param {HTMLElement} select The select element
     * @param {HTMLElement} status The status element
     */
    function saveWatermarkSettings(form, select, status) {
        var resourceType = form.getAttribute('data-resource-type');
        var resourceId = form.getAttribute('data-resource-id');
        var selectedValue = select.value;
        var apiUrl = form.getAttribute('data-api-url');

        if (!resourceType || !resourceId) {
            showStatus(status, 'Error: Missing resource information', 'error');
            return;
        }

        if (!apiUrl) {
            showStatus(status, 'Error: Missing API URL', 'error');
            return;
        }

        console.log('Watermarker: Saving setting:', {
            resourceType: resourceType,
            resourceId: resourceId,
            watermarkSetId: selectedValue,
            apiUrl: apiUrl
        });

        // Disable form during submission
        select.disabled = true;
        var saveButton = form.querySelector('.watermark-save-button');
        if (saveButton) {
            saveButton.disabled = true;
        }

        // Show saving message
        showStatus(status, 'Saving...', 'pending');

        // Create form data
        var formData = new FormData();
        formData.append('resource_type', resourceType);
        formData.append('resource_id', resourceId);

        // Handle the different cases
        if (selectedValue === 'none') {
            formData.append('explicitly_no_watermark', '1');
        } else if (selectedValue !== 'default') {
            formData.append('watermark_set_id', selectedValue);
        }
        // If 'default' is selected, we don't send either parameter

        // Send AJAX request
        fetch(apiUrl, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(response) {
            if (!response.ok) {
                throw new Error('Network response was not ok (status: ' + response.status + ')');
            }
            return response.json();
        })
        .then(function(data) {
            if (data.success) {
                showStatus(status, data.message || 'Settings saved successfully', 'success');

                // Update the status text based on the selected option
                setTimeout(function() {
                    if (selectedValue === 'none') {
                        status.textContent = 'Watermarking explicitly disabled for this resource.';
                    } else if (selectedValue === 'default') {
                        status.textContent = 'Using default watermark settings.';
                    } else {
                        status.textContent = 'Using custom watermark set.';
                    }
                }, 5000);
            } else {
                throw new Error(data.error || 'Unknown error');
            }
        })
        .catch(function(error) {
            showStatus(status, 'Error: ' + error.message, 'error');
            console.error('Watermarker: Error saving settings:', error);
        })
        .finally(function() {
            // Re-enable form
            select.disabled = false;
            if (saveButton) {
                saveButton.disabled = false;
            }
        });
    }

    /**
     * Show a status message
     *
     * @param {HTMLElement} statusElement The status element
     * @param {string} message The message to show
     * @param {string} type The type of message (success, error, pending)
     */
    function showStatus(statusElement, message, type) {
        if (!statusElement) {
            return;
        }

        // Remove previous status classes
        statusElement.classList.remove('success', 'error', 'pending');

        // Add the new status class
        if (type) {
            statusElement.classList.add(type);
        }

        // Set the message
        statusElement.textContent = message;

        // For success messages, clear after a delay
        if (type === 'success') {
            setTimeout(function() {
                // Only clear if it's still showing the same success message
                if (statusElement.textContent === message) {
                    statusElement.textContent = '';
                    statusElement.classList.remove('success');
                }
            }, 5000);
        }
    }
})();