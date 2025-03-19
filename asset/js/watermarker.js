/**
 * Watermarker module JavaScript
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
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
    });

    /**
     * Initialize position selector
     *
     * @param {HTMLElement} selector
     */
    function initPositionSelector(selector) {
        var positions = [
            'top-left', 'top-center', 'top-right',
            'middle-left', 'center', 'middle-right',
            'bottom-left', 'bottom-center', 'bottom-right',
            'bottom-full'
        ];

        var positionInput = document.getElementById('watermark-position');
        var selectedPosition = positionInput.value;

        // Create position grid
        positions.forEach(function(position) {
            var option = document.createElement('div');
            option.className = 'watermark-position-option';
            option.dataset.position = position;

            if (position === selectedPosition) {
                option.classList.add('selected');
            }

            option.addEventListener('click', function() {
                // Update hidden input
                positionInput.value = position;

                // Update UI
                selector.querySelectorAll('.watermark-position-option').forEach(function(el) {
                    el.classList.remove('selected');
                });
                option.classList.add('selected');
            });

            selector.appendChild(option);
        });
    }

    /**
     * Update watermark preview when media is selected
     */
    function updateWatermarkPreview() {
        var mediaId = this.value;
        var previewContainer = document.getElementById('watermark-preview');

        if (!previewContainer) {
            previewContainer = document.createElement('div');
            previewContainer.id = 'watermark-preview';
            previewContainer.className = 'watermarker-preview';
            this.parentNode.appendChild(previewContainer);
        }

        if (!mediaId) {
            previewContainer.innerHTML = '';
            return;
        }

        // Fetch media info via API
        fetch('/api/media/' + mediaId)
            .then(function(response) {
                return response.json();
            })
            .then(function(data) {
                if (data && data.thumbnail_urls && data.thumbnail_urls.square) {
                    var img = document.createElement('img');
                    img.src = data.thumbnail_urls.square;
                    img.alt = 'Watermark Preview';

                    previewContainer.innerHTML = '';
                    previewContainer.appendChild(img);
                }
            })
            .catch(function(error) {
                console.error('Error fetching media:', error);
            });
    }
})();