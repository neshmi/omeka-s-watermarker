/**
 * Simple Watermarker module JavaScript - no fancy features
 */
(function() {
    'use strict';

    console.log('Watermarker: Basic JavaScript loaded');

    // Initialize when DOM is loaded
    document.addEventListener('DOMContentLoaded', function() {
        console.log('Watermarker: DOM loaded, initializing');

        // Find the watermark select element
        const watermarkSelect = document.getElementById('o-watermark-set');
        if (!watermarkSelect) {
            console.error('Watermarker: Select element not found');
            return;
        }

        console.log('Watermarker: Found select element', watermarkSelect);

        // Get the resource type and ID from hidden inputs
        const resourceTypeInput = document.querySelector('input[name="resource_type"]');
        const resourceIdInput = document.querySelector('input[name="resource_id"]');

        if (!resourceTypeInput || !resourceIdInput) {
            console.error('Watermarker: Resource type or ID inputs not found');
            return;
        }

        const resourceType = resourceTypeInput.value;
        const resourceId = resourceIdInput.value;

        console.log(`Watermarker: Resource type: ${resourceType}, ID: ${resourceId}`);

        // Add change event handler
        watermarkSelect.addEventListener('change', function() {
            const selectedValue = this.value;
            console.log(`Watermarker: Selected value: ${selectedValue}`);

            saveWatermarkSetting(selectedValue);
        });

        // Add a save button after the select
        const saveButton = document.createElement('button');
        saveButton.type = 'button';
        saveButton.className = 'watermark-save-button button';
        saveButton.textContent = 'Save Watermark Setting';

        // Insert the button after the select
        watermarkSelect.insertAdjacentElement('afterend', saveButton);

        // Add click event to the save button
        saveButton.addEventListener('click', function() {
            const selectedValue = watermarkSelect.value;
            console.log(`Watermarker: Saving value: ${selectedValue}`);

            saveWatermarkSetting(selectedValue);
        });

        // Function to save the watermark setting
        function saveWatermarkSetting(selectedValue) {
            // Find the status element
            const statusElement = document.querySelector('.watermark-status');
            if (statusElement) {
                statusElement.textContent = 'Saving...';
                statusElement.className = 'watermark-status saving';
            }

            // Prepare the data to send
            const data = {
                resource_type: resourceType,
                resource_id: resourceId,
                'o:id': resourceId,
                'o-watermarker:set': selectedValue
            };

            console.log('Watermarker: Sending data:', data);

            // Get CSRF token if available
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
            const headers = {
                'Content-Type': 'application/json'
            };

            if (csrfToken) {
                headers['X-CSRF-Token'] = csrfToken;
                console.log('Watermarker: CSRF Token:', csrfToken);
            }

            // Send the request to the correct endpoint
            fetch('/admin/watermarker-api/setAssignment', {
                method: 'POST',
                headers: headers,
                body: JSON.stringify(data)
            })
            .then(response => {
                console.log('Watermarker: Response status:', response.status);
                console.log('Watermarker: Response headers:', response.headers);
                if (!response.ok) {
                    return response.text().then(text => {
                        console.error('Watermarker: Error response text:', text);
                        throw new Error(`HTTP error! Status: ${response.status}, Text: ${text}`);
                    });
                }
                return response.json();
            })
            .then(data => {
                console.log('Watermarker: Response data:', data);
                if (data.status === 'success') {
                    showMessage('Watermark settings saved successfully', 'success');
                    // Update status element if it exists
                    if (statusElement) {
                        statusElement.textContent = 'Watermark settings saved successfully.';
                        statusElement.className = 'watermark-status success';

                        // Reset after a few seconds
                        setTimeout(() => {
                            statusElement.textContent = '';
                            statusElement.className = 'watermark-status';
                        }, 3000);
                    }
                } else {
                    showMessage(data.message || 'Error saving watermark settings', 'error');
                    // Update status element if it exists
                    if (statusElement) {
                        statusElement.textContent = data.message || 'Error saving watermark settings.';
                        statusElement.className = 'watermark-status error';
                    }
                }
            })
            .catch(error => {
                console.error('Watermarker: Error:', error);
                showMessage('Error saving watermark settings: ' + error.message, 'error');
                // Update status element if it exists
                if (statusElement) {
                    statusElement.textContent = 'Error saving watermark settings: ' + error.message;
                    statusElement.className = 'watermark-status error';
                }
            });
        }

        function showMessage(message, type) {
            // Find the closest field container
            const fieldContainer = watermarkSelect.closest('.field');
            if (!fieldContainer) {
                console.error('Watermarker: Field container not found');
                return;
            }

            // Find the status element or create one if needed
            let statusElement = fieldContainer.querySelector('.watermark-status');
            if (!statusElement) {
                statusElement = document.createElement('div');
                statusElement.className = 'watermark-status';
                watermarkSelect.parentNode.appendChild(statusElement);
            }

            // Update the status message
            statusElement.textContent = message;
            statusElement.className = `watermark-status ${type}`;

            // Remove the message after 3 seconds
            setTimeout(() => {
                statusElement.textContent = '';
                statusElement.className = 'watermark-status';
            }, 3000);
        }
    });
})();