/**
 * Watermarker module JavaScript
 */
(function() {
    'use strict';

    // Add a flag to track initialization
    var isInitialized = false;

    // Initialize when DOM is loaded
    document.addEventListener('DOMContentLoaded', function() {
        initializeWatermarker();
    });

    // Also try on window load as a backup
    window.addEventListener('load', function() {
        initializeWatermarker();
    });

    /**
     * Initialize watermarker functionality
     */
    function initializeWatermarker() {
        if (isInitialized) {
            return;
        }

        isInitialized = true;
        console.log('Watermarker: Initializing');

        // Handle position selector if present
        var positionSelector = document.querySelector('.watermark-position-selector');
        if (positionSelector) {
            initPositionSelector(positionSelector);
        }

        // Preview watermark if on edit or add page
        var watermarkMedia = document.getElementById('watermark-media');
        if (watermarkMedia) {
            watermarkMedia.addEventListener('change', updateWatermarkPreview);
        }

        // Initialize opacity slider if present
        var opacitySlider = document.getElementById('watermark-opacity');
        var opacityValue = document.getElementById('opacity-value');
        if (opacitySlider && opacityValue) {
            opacitySlider.addEventListener('input', function() {
                opacityValue.textContent = opacitySlider.value;
            });
        }
    }

    /**
     * Initialize position selector
     *
     * @param {HTMLElement} selector The position selector element
     */
    function initPositionSelector(selector) {
        var cells = selector.querySelectorAll('.position-cell');

        // Set up position selector cells
        cells.forEach(function(cell) {
            cell.addEventListener('click', function() {
                // Remove active class from all cells
                cells.forEach(function(c) {
                    c.classList.remove('active');
                });

                // Add active class to clicked cell
                this.classList.add('active');

                // Update hidden input with position value
                var positionInput = selector.querySelector('input[type="hidden"]');
                if (positionInput) {
                    positionInput.value = this.getAttribute('data-position');
                }
            });
        });
    }

    /**
     * Update watermark preview when file is selected
     */
    function updateWatermarkPreview() {
        var preview = document.getElementById('watermark-preview');
        if (!preview) {
            return;
        }

        var file = this.files[0];
        if (file) {
            var reader = new FileReader();
            reader.onload = function(e) {
                preview.src = e.target.result;
                preview.style.display = 'block';
            };
            reader.readAsDataURL(file);
        }
    }

    /**
     * Function to populate the watermark dropdown with available sets
     */
    function populateWatermarkDropdown(select, watermarkSets) {
        if (!select || !watermarkSets) {
            console.error('Watermarker: Cannot populate dropdown - missing select or watermarkSets');
            return Promise.resolve();
        }

        return new Promise(function(resolve) {
            // If dropdown is already populated and has at least as many options as we expect,
            // don't repopulate it
            const expectedOptions = watermarkSets.length + 2; // +2 for None and Default
            const currentOptions = select.options.length;

            if (currentOptions >= expectedOptions) {
                console.log(`Watermarker: Dropdown already has ${currentOptions} options (expected ${expectedOptions}), skipping population`);
                resolve();
                return;
            }

            console.log('Watermarker: Populating dropdown with sets:', watermarkSets);

            // Clear any existing options
            select.innerHTML = '';

            // Add "None" option
            var noneOption = document.createElement('option');
            noneOption.value = '';
            noneOption.textContent = 'None (no watermark)';
            select.appendChild(noneOption);

            // Add "Default" option
            var defaultOption = document.createElement('option');
            defaultOption.value = 'default';
            defaultOption.textContent = 'Default (inherit from parent)';
            select.appendChild(defaultOption);

            // Add watermark sets to dropdown
            if (watermarkSets && watermarkSets.length > 0) {
                watermarkSets.forEach(function(set) {
                    var option = document.createElement('option');
                    // Ensure value is a string
                    option.value = set.id.toString();
                    option.textContent = set.name;
                    select.appendChild(option);
                    console.log('Watermarker: Added option:', { value: option.value, text: option.textContent });
                });
            } else {
                console.warn('Watermarker: No watermark sets to add to dropdown');
            }

            // Log the final state of the dropdown
            console.log('Watermarker: Dropdown populated with options:',
                Array.from(select.options).map(opt => ({ value: opt.value, text: opt.textContent, index: opt.index })));

            // Resolve the promise after dropdown is populated
            resolve();
        });
    }
})();