/**
 * Watermarker module JavaScript
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        // Add collapsible behavior to variant sections
        initCollapsibleVariants();
        
        // Handle position selectors for variants if present
        initVariantPositionSelectors();

        // Initialize preview for all watermark media selects
        initWatermarkPreviews();

        // Initialize opacity sliders
        initOpacitySliders();
        
        // Add visual feedback for variant selection
        initVariantHighlighting();
    });

    /**
     * Initialize position selectors for all variants
     */
    function initVariantPositionSelectors() {
        // Find all position select elements and create visual position selectors for them
        var positionSelects = document.querySelectorAll('select.watermark-position');
        positionSelects.forEach(function(select) {
            createVisualPositionSelector(select);
        });
    }

    /**
     * Create visual position selector for a select element
     *
     * @param {HTMLSelectElement} select The position select element
     */
    function createVisualPositionSelector(select) {
        var positions = [
            'top-left', 'top-right',
            'center',
            'bottom-left', 'bottom-right',
            'bottom-full'
        ];
        
        // Get the current selected value
        var selectedPosition = select.value;
        
        // Create the position grid container
        var selectorContainer = document.createElement('div');
        selectorContainer.className = 'watermark-position-selector';
        
        // Create position grid
        positions.forEach(function(position) {
            var option = document.createElement('div');
            option.className = 'watermark-position-option';
            option.dataset.position = position;
            option.title = formatPositionLabel(position);

            if (position === selectedPosition) {
                option.classList.add('selected');
            }

            option.addEventListener('click', function() {
                // Update select input
                select.value = position;
                
                // Trigger change event on select
                var event = new Event('change', { bubbles: true });
                select.dispatchEvent(event);

                // Update UI
                selectorContainer.querySelectorAll('.watermark-position-option').forEach(function(el) {
                    el.classList.remove('selected');
                });
                option.classList.add('selected');
            });

            selectorContainer.appendChild(option);
        });
        
        // Add the selector after the select element
        var fieldContainer = select.closest('.field');
        if (fieldContainer) {
            // Hide the actual select but keep it in the DOM for form submission
            select.style.display = 'none';
            fieldContainer.appendChild(selectorContainer);
            
            // Add CSS to the container
            selectorContainer.style.display = 'grid';
            selectorContainer.style.gridTemplateColumns = 'repeat(3, 1fr)';
            selectorContainer.style.gap = '5px';
            selectorContainer.style.maxWidth = '250px';
            selectorContainer.style.marginTop = '10px';
            
            // Add CSS for options
            var options = selectorContainer.querySelectorAll('.watermark-position-option');
            options.forEach(function(option) {
                option.style.display = 'flex';
                option.style.justifyContent = 'center';
                option.style.alignItems = 'center';
                option.style.backgroundColor = '#f7f7f7';
                option.style.border = '1px solid #dfdfdf';
                option.style.height = '40px';
                option.style.cursor = 'pointer';
                
                // Add icons based on position
                var icon = '';
                switch (option.dataset.position) {
                    case 'top-left': icon = '↖'; break;
                    case 'top-right': icon = '↗'; break;
                    case 'center': icon = '⦿'; break;
                    case 'bottom-left': icon = '↙'; break;
                    case 'bottom-right': icon = '↘'; break;
                    case 'bottom-full': 
                        icon = '⬇'; 
                        option.style.gridColumn = '1 / span 3';
                        option.style.backgroundColor = '#edfaff';
                        break;
                }
                option.innerHTML = icon;
                
                // Add hover effect
                option.addEventListener('mouseover', function() {
                    if (!option.classList.contains('selected')) {
                        option.style.backgroundColor = '#eaeaea';
                    }
                });
                option.addEventListener('mouseout', function() {
                    if (!option.classList.contains('selected')) {
                        option.style.backgroundColor = '#f7f7f7';
                        if (option.dataset.position === 'bottom-full') {
                            option.style.backgroundColor = '#edfaff';
                        }
                    }
                });
                
                // Style for selected option
                if (option.classList.contains('selected')) {
                    option.style.backgroundColor = '#d0e8ff';
                    option.style.borderColor = '#4287f5';
                }
            });
        }
    }

    /**
     * Initialize watermark previews for all media selects
     */
    function initWatermarkPreviews() {
        // Handle Omeka asset selects for previews
        var assetContainers = document.querySelectorAll('.asset-form-element');
        
        assetContainers.forEach(function(container) {
            // Find the select element inside
            var select = container.querySelector('select[name*="media_id"]');
            if (!select) return;
            
            // Create preview container if it doesn't exist
            var fieldContainer = container.closest('.field');
            if (!fieldContainer) return;
            
            var previewContainer = fieldContainer.querySelector('.watermarker-preview');
            if (!previewContainer) {
                previewContainer = document.createElement('div');
                previewContainer.className = 'watermarker-preview';
                fieldContainer.appendChild(previewContainer);
            }

            // Set initial preview if media is selected
            if (select.value) {
                updateWatermarkPreview(select, previewContainer);
            }

            // Add change event listener to the select
            select.addEventListener('change', function() {
                updateWatermarkPreview(select, previewContainer);
            });
            
            // Also try to find the sidebar links that Omeka adds
            var assetLinks = container.querySelectorAll('.asset-link');
            if (assetLinks.length > 0) {
                // Monitor for changes in the sidebar
                var observer = new MutationObserver(function(mutations) {
                    // Check if the select value has changed
                    updateWatermarkPreview(select, previewContainer);
                });
                
                // Observe changes to the asset container
                observer.observe(container, { 
                    childList: true, 
                    subtree: true 
                });
            }
        });
    }

    /**
     * Initialize opacity sliders
     */
    function initOpacitySliders() {
        var opacityInputs = document.querySelectorAll('input[name*="opacity"]');
        
        opacityInputs.forEach(function(input) {
            var container = input.closest('.field');
            var label = container.querySelector('label');
            
            // Create value display
            var valueDisplay = document.createElement('span');
            valueDisplay.className = 'opacity-value';
            valueDisplay.textContent = input.value;
            
            // Append to label if it exists, otherwise to container
            if (label) {
                label.appendChild(document.createTextNode(' '));
                label.appendChild(valueDisplay);
            } else {
                container.insertBefore(valueDisplay, input);
            }
            
            // Update value display on input
            input.addEventListener('input', function() {
                valueDisplay.textContent = input.value;
            });
        });
    }

    /**
     * Update watermark preview when media is selected
     * 
     * @param {HTMLSelectElement} select The media select element
     * @param {HTMLElement} previewContainer The container for the preview
     */
    function updateWatermarkPreview(select, previewContainer) {
        var mediaId = select.value;

        if (!mediaId) {
            previewContainer.innerHTML = '<span class="no-preview">No image selected</span>';
            return;
        }

        // For Omeka assets, we need to find the preview image URL
        var assetUrl = null;
        
        // Method 1: Find the asset container and look for the sidebar image
        var assetContainer = select.closest('.asset-form-element');
        if (assetContainer) {
            // First try to find the sidebar preview image
            var assetImg = assetContainer.querySelector('.asset-preview img');
            if (assetImg && assetImg.src) {
                assetUrl = assetImg.src;
            }
            
            // If no image, try to find the sidebar link
            if (!assetUrl) {
                var assetLink = assetContainer.querySelector('.asset-link');
                if (assetLink && assetLink.href) {
                    // Convert the admin URL to a files URL
                    assetUrl = assetLink.href.replace('/admin/asset/', '/files/asset/');
                }
            }
            
            // Check for the filename in the HTML
            if (!assetUrl) {
                var filenameEl = assetContainer.querySelector('.asset-filename');
                if (filenameEl && filenameEl.textContent) {
                    var filename = filenameEl.textContent.trim();
                    assetUrl = window.location.origin + '/files/asset/' + filename;
                }
            }
        }
        
        // Method 2: Check if it's an Omeka asset select with the option format
        if (!assetUrl && select.options && select.selectedIndex >= 0) {
            var optionText = select.options[select.selectedIndex].text;
            
            // Look for pattern like "Asset Name [filename.jpg]"
            var match = optionText.match(/\[([^\]]+)\]$/);
            if (match && match[1]) {
                var filename = match[1];
                var baseUrl = window.location.origin;
                assetUrl = baseUrl + '/files/asset/' + filename;
            }
        }
        
        // Method 3: Build direct URL from asset ID - this is a fallback
        if (!assetUrl) {
            var baseUrl = window.location.origin;
            // Try direct access to the asset file (will only work if the asset is public)
            assetUrl = baseUrl + '/files/asset/' + mediaId;
        }
        
        // If we have an asset URL, create and show the preview
        if (assetUrl) {
            var img = document.createElement('img');
            img.src = assetUrl;
            img.alt = 'Watermark Preview';
            img.onerror = function() {
                // Try once more with the ID as the asset path
                if (img.src.indexOf('/files/asset/') > -1) {
                    var baseUrl = window.location.origin;
                    img.src = baseUrl + '/files/asset/' + mediaId;
                    img.onerror = function() {
                        previewContainer.innerHTML = '<span class="no-preview">Unable to load preview</span>';
                    };
                } else {
                    previewContainer.innerHTML = '<span class="no-preview">Unable to load preview</span>';
                }
            };
            
            previewContainer.innerHTML = '';
            previewContainer.appendChild(img);
        } else {
            previewContainer.innerHTML = '<span class="no-preview">Asset preview not available</span>';
        }
    }

    /**
     * Format position label for display
     * 
     * @param {string} position Position value
     * @return {string} Formatted label
     */
    function formatPositionLabel(position) {
        var labels = {
            'top-left': 'Top Left',
            'top-right': 'Top Right',
            'center': 'Center',
            'bottom-left': 'Bottom Left',
            'bottom-right': 'Bottom Right',
            'bottom-full': 'Bottom Full Width'
        };
        
        return labels[position] || position;
    }
    
    /**
     * Initialize collapsible behavior for variant sections
     */
    function initCollapsibleVariants() {
        var variants = document.querySelectorAll('.watermarker-form-variant');
        
        variants.forEach(function(variant) {
            var header = variant.querySelector('.watermarker-form-variant-header');
            var body = variant.querySelector('.watermarker-form-variant-body');
            
            if (!header || !body) return;
            
            // Add toggle indicator
            var toggleIcon = document.createElement('span');
            toggleIcon.className = 'collapse-toggle';
            toggleIcon.innerHTML = '▼';
            toggleIcon.style.marginLeft = 'auto';
            toggleIcon.style.fontSize = '12px';
            toggleIcon.style.cursor = 'pointer';
            header.appendChild(toggleIcon);
            
            // Add click handler
            header.style.cursor = 'pointer';
            header.addEventListener('click', function(e) {
                // Don't toggle if clicking on form elements
                if (e.target.tagName === 'INPUT' || 
                    e.target.tagName === 'SELECT' || 
                    e.target.tagName === 'BUTTON') {
                    return;
                }
                
                if (body.style.display === 'none') {
                    body.style.display = '';
                    toggleIcon.innerHTML = '▼';
                    variant.classList.remove('collapsed');
                } else {
                    body.style.display = 'none';
                    toggleIcon.innerHTML = '►';
                    variant.classList.add('collapsed');
                }
            });
        });
    }
    
    /**
     * Initialize highlighting for variant sections
     */
    function initVariantHighlighting() {
        var variants = document.querySelectorAll('.watermarker-form-variant');
        
        variants.forEach(function(variant) {
            // Highlight the variant when clicked
            variant.addEventListener('click', function(event) {
                // Only add active class if interaction is inside form elements
                var target = event.target;
                var isFormElement = target.tagName === 'INPUT' || 
                                   target.tagName === 'SELECT' || 
                                   target.closest('select') || 
                                   target.closest('.field');
                
                if (isFormElement) {
                    variants.forEach(function(v) {
                        v.classList.remove('active');
                    });
                    variant.classList.add('active');
                }
            });
        });
    }
})();