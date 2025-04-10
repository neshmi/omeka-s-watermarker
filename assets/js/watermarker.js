document.addEventListener('DOMContentLoaded', function() {
    // Initialize dropdowns
    const dropdowns = document.querySelectorAll('.watermark-dropdown');

    // Set initial selected option
    dropdowns.forEach(dropdown => {
        const button = dropdown.querySelector('.watermark-dropdown-button');
        const selectedText = button.textContent.trim();

        // Find the option with matching text
        const options = dropdown.querySelectorAll('.watermark-option');
        options.forEach(option => {
            if (option.textContent.trim() === selectedText) {
                option.classList.add('selected');
            }
        });
    });

    // Handle dropdown button clicks
    dropdowns.forEach(dropdown => {
        const button = dropdown.querySelector('.watermark-dropdown-button');
        const content = dropdown.querySelector('.watermark-dropdown-content');

        button.addEventListener('click', function(e) {
            e.stopPropagation();

            // Close other dropdowns
            dropdowns.forEach(otherDropdown => {
                if (otherDropdown !== dropdown) {
                    otherDropdown.classList.remove('active');
                }
            });

            // Toggle current dropdown
            dropdown.classList.toggle('active');
        });

        // Handle option selection
        const options = dropdown.querySelectorAll('.watermark-option');
        options.forEach(option => {
            option.addEventListener('click', function() {
                const value = this.getAttribute('data-value');
                const text = this.textContent;

                // Update button text
                button.textContent = text + ' ▼';

                // Update selected state
                options.forEach(opt => opt.classList.remove('selected'));
                this.classList.add('selected');

                // Close dropdown
                dropdown.classList.remove('active');

                // Dispatch event for form handling
                const event = new CustomEvent('watermarkSelected', {
                    detail: { value: value }
                });
                document.dispatchEvent(event);
            });
        });
    });

    // Close dropdowns when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.watermark-dropdown')) {
            dropdowns.forEach(dropdown => {
                dropdown.classList.remove('active');
            });
        }
    });

    // Handle save button clicks
    const saveButtons = document.querySelectorAll('.watermark-save-button');
    saveButtons.forEach(button => {
        button.addEventListener('click', function() {
            const form = this.closest('.watermark-form');
            if (!form) return;

            const resourceType = form.getAttribute('data-resource-type');
            const resourceId = form.getAttribute('data-resource-id');
            const apiUrl = form.getAttribute('data-api-url');

            // Get the selected option from the dropdown
            const dropdownButton = form.querySelector('.watermark-dropdown-button');
            if (!dropdownButton) {
                alert('Watermark dropdown not found');
                return;
            }

            // Find the option that matches the button text
            const buttonText = dropdownButton.textContent.trim().replace(' ▼', '');
            const options = form.querySelectorAll('.watermark-option');
            let selectedOption = null;

            options.forEach(option => {
                if (option.textContent.trim() === buttonText) {
                    selectedOption = option;
                }
            });

            if (!selectedOption) {
                alert('Please select a watermark option');
                return;
            }

            const watermarkSetId = selectedOption.getAttribute('data-value');

            console.log('Sending watermark assignment request:', {
                resourceType,
                resourceId,
                watermarkSetId,
                apiUrl
            });

            // Map the watermark set ID to the expected format
            let mappedWatermarkSetId = watermarkSetId;
            if (watermarkSetId === 'none') {
                mappedWatermarkSetId = 'None';
            } else if (watermarkSetId === 'default') {
                mappedWatermarkSetId = 'Default';
            }

            // Send AJAX request to save the watermark setting
            fetch(apiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    resource_type: resourceType,
                    resource_id: resourceId,
                    watermark_set_id: mappedWatermarkSetId
                })
            })
            .then(response => {
                console.log('Response status:', response.status);
                console.log('Response headers:', Object.fromEntries(response.headers.entries()));

                if (!response.ok) {
                    return response.text().then(text => {
                        console.error('Error response body:', text);
                        throw new Error('Network response was not ok: ' + text);
                    });
                }
                return response.json();
            })
            .then(data => {
                // Update status message
                const statusElement = form.querySelector('.watermark-status');
                if (statusElement) {
                    if (watermarkSetId === 'none') {
                        statusElement.textContent = 'Watermarking explicitly disabled for this resource.';
                        statusElement.className = 'watermark-status error';
                    } else if (watermarkSetId === 'default') {
                        statusElement.textContent = 'Using default watermark settings.';
                        statusElement.className = 'watermark-status default';
                    } else {
                        statusElement.textContent = 'Using custom watermark set: ' + selectedOption.textContent.trim();
                        statusElement.className = 'watermark-status success';
                    }
                }

                // Show success message
                alert('Watermark setting saved successfully');
            })
            .catch(error => {
                console.error('Error saving watermark setting:', error);
                alert('Error saving watermark setting. Please try again.');
            });
        });
    });

    // Listen for watermark selection events
    document.addEventListener('watermarkSelected', function(e) {
        const form = e.target.closest('.watermark-form');
        if (!form) return;

        const selectedOption = form.querySelector(`.watermark-option[data-value="${e.detail.value}"]`);
        if (selectedOption) {
            // Update button text
            const dropdownButton = form.querySelector('.watermark-dropdown-button');
            if (dropdownButton) {
                dropdownButton.textContent = selectedOption.textContent.trim() + ' ▼';
            }
        }
    });
});