/**
 * Watermarker module JavaScript
 */
(function() {
    'use strict';

    // Create an immediate visible indicator that this script has loaded
    var loadIndicator = document.createElement('div');
    loadIndicator.id = 'watermarker-loaded-indicator';
    loadIndicator.style.cssText = 'position:fixed;top:10px;right:10px;background:blue;color:white;padding:10px;z-index:9999;font-family:sans-serif;';
    loadIndicator.textContent = 'Watermarker JS Loaded!';

    // Make sure this runs immediately, not waiting for DOMContentLoaded
    if (document.body) {
        document.body.appendChild(loadIndicator);
    } else {
        // If document.body isn't available yet, use a different approach
        window.addEventListener('DOMContentLoaded', function() {
            document.body.appendChild(loadIndicator);
        });
    }

    // Log to console immediately
    console.log('WATERMARKER SCRIPT LOADED - ' + new Date().toISOString());

    document.addEventListener('DOMContentLoaded', function() {
        console.log('Watermarker: DOM loaded');

        // Update the indicator
        var indicator = document.getElementById('watermarker-loaded-indicator');
        if (indicator) {
            indicator.textContent = 'Watermarker: DOM ready!';
            indicator.style.background = 'green';
        }

        // Check what page we're on
        var url = window.location.href;
        console.log('Watermarker: Current URL:', url);

        // Determine if we're on an edit page
        var isEditPage = url.includes('/edit');
        console.log('Watermarker: Is edit page:', isEditPage);

        // Check for tabs container and form
        var tabs = document.querySelector('ul.tabs');
        var form = document.querySelector('form.edit');
        console.log('Watermarker: Found tabs container:', !!tabs);
        console.log('Watermarker: Found edit form:', !!form);

        // Make sure we only have one form submit button visible
        var formSubmits = document.querySelectorAll('input[type="submit"]');
        if (formSubmits.length > 1) {
            // Hide all but the last form submit button
            for (var i = 0; i < formSubmits.length - 1; i++) {
                formSubmits[i].style.display = 'none';
            }
        }

        // Special handling for edit pages
        if (isEditPage) {
            console.log('Watermarker: Setting up edit page tabs');
            // Wait a short moment for the DOM to be fully ready
            setTimeout(setupEditPageTabs, 100);
        }

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
     * Set up tabs on edit pages
     */
    function setupEditPageTabs() {
        console.log('Watermarker JS: Setting up edit page tabs');

        // First, find any watermarker data
        var watermarkerData = document.getElementById('watermarker-data');
        if (!watermarkerData) {
            console.log('Watermarker JS: No watermarker data found');
            return;
        }

        try {
            var data = JSON.parse(watermarkerData.getAttribute('data-watermarker'));
            console.log('Watermarker JS: Found data', data);

            // Find the section-nav element - try multiple possible selectors
            var sectionNav = document.getElementById('section-nav');

            if (!sectionNav) {
                console.log('Watermarker JS: Looking for alternative tab containers...');
                // Try other common selectors
                sectionNav = document.querySelector('.section-nav');

                if (!sectionNav) {
                    sectionNav = document.querySelector('.tabs');
                }

                if (!sectionNav) {
                    // Attempt to find any navigation elements that might be tab containers
                    var possibleNavs = document.querySelectorAll('nav, .tabs-nav, .ui-tabs-nav, .tabbed-nav');
                    if (possibleNavs.length > 0) {
                        sectionNav = possibleNavs[0];
                        console.log('Watermarker JS: Found alternative navigation element:', sectionNav);
                    }
                }

                // If still not found, create our own tab container
                if (!sectionNav) {
                    console.log('Watermarker JS: Creating new tab navigation structure');

                    // Create section-nav element
                    sectionNav = document.createElement('div');
                    sectionNav.id = 'section-nav';
                    sectionNav.className = 'section-nav';

                    // Create tabs list
                    var tabsList = document.createElement('ul');
                    sectionNav.appendChild(tabsList);

                    // Find a good place to insert this - look for a form or header
                    var form = document.querySelector('form');
                    if (form) {
                        form.parentNode.insertBefore(sectionNav, form);
                    } else {
                        // Try to find a header element
                        var header = document.querySelector('header, .page-header, .section-header');
                        if (header) {
                            header.parentNode.insertBefore(sectionNav, header.nextSibling);
                        } else {
                            // Last resort - just append to body
                            document.body.appendChild(sectionNav);
                        }
                    }

                    console.log('Watermarker JS: Created new tab navigation structure');
                }
            }

            // Find the tabs list
            var tabsList = sectionNav.querySelector('ul');
            if (!tabsList) {
                console.log('Watermarker JS: No tabs list found, creating one');
                tabsList = document.createElement('ul');
                sectionNav.appendChild(tabsList);
            }

            // Find all active sections to create appropriate tabs for them
            var existingSections = document.querySelectorAll('section');
            var hasExistingTabs = tabsList.querySelectorAll('li').length > 0;

            if (existingSections.length > 0 && !hasExistingTabs) {
                console.log('Watermarker JS: Creating standard tabs for existing sections');
                existingSections.forEach(function(section) {
                    if (section.id && section.id !== 'watermark-section') {
                        var sectionTitle = section.querySelector('h2, h3, legend, .section-title');
                        var title = sectionTitle ? sectionTitle.textContent.trim() : 'Section';

                        // Create tab for this section
                        var li = document.createElement('li');
                        li.innerHTML = '<a href="#' + section.id + '">' + title + '</a>';
                        tabsList.appendChild(li);

                        // Add click handler for this tab
                        var tabLink = li.querySelector('a');
                        tabLink.addEventListener('click', function(e) {
                            e.preventDefault();
                            e.stopPropagation();

                            // Hide all sections
                            document.querySelectorAll('section').forEach(function(s) {
                                s.style.display = 'none';
                            });

                            // Show clicked section
                            section.style.display = 'block';

                            // Update active tab
                            tabsList.querySelectorAll('li').forEach(function(tab) {
                                tab.classList.remove('active');
                            });
                            li.classList.add('active');

                            // Update URL hash
                            history.pushState(null, null, '#' + section.id);
                        });
                    }
                });
            }

            // Create watermark tab if it doesn't exist
            var existingTab = false;
            tabsList.querySelectorAll('li a').forEach(function(a) {
                if (a.textContent.trim() === 'Watermark') {
                    existingTab = true;
                }
            });

            if (!existingTab) {
                var li = document.createElement('li');
                li.innerHTML = '<a href="#watermark-section">Watermark</a>';
                tabsList.appendChild(li);
                console.log('Watermarker JS: Watermark tab created');
            }

            // Create watermark section if it doesn't exist
            var watermarkSection = document.getElementById('watermark-section');
            if (!watermarkSection) {
                // Get the template HTML from the template div
                var templateContent = '';
                var templateDiv = document.getElementById('watermarker-template');
                if (templateDiv) {
                    templateContent = templateDiv.innerHTML;
                } else {
                    // Fallback template if not found
                    templateContent = '<div class="field">' +
                        '<div class="field-meta">' +
                        '<label>Watermark Settings</label>' +
                        '</div>' +
                        '<div class="inputs">' +
                        '<div class="value">' +
                        '<p class="watermark-status"></p>' +
                        '<a href="" class="button watermark-edit-link" target="_blank">Edit Watermark Settings</a>' +
                        '</div>' +
                        '</div>' +
                        '</div>';
                }

                watermarkSection = document.createElement('section');
                watermarkSection.id = 'watermark-section';
                watermarkSection.className = 'section';
                watermarkSection.style.display = 'none';

                watermarkSection.innerHTML = templateContent;

                // Update content with data from the data attribute
                var statusP = watermarkSection.querySelector('.watermark-status');
                if (statusP && data.watermarkInfo) {
                    statusP.textContent = data.watermarkInfo;
                } else if (statusP) {
                    statusP.textContent = 'Using default watermark settings';
                }

                var editLink = watermarkSection.querySelector('.watermark-edit-link');
                if (editLink && data.assignUrl) {
                    editLink.href = data.assignUrl;
                }

                // Add item-set specific text if needed
                if (data.resourceType === 'item-set') {
                    var infoP = document.createElement('p');
                    infoP.textContent = 'All items in this item set will inherit these watermark settings unless they have their own settings.';
                    var value = watermarkSection.querySelector('.value');
                    if (value) {
                        value.insertBefore(infoP, value.firstChild);
                    }
                }

                // Find a place to insert it
                var sections = document.querySelectorAll('section');
                if (sections.length > 0) {
                    var lastSection = sections[sections.length - 1];
                    lastSection.parentNode.insertBefore(watermarkSection, lastSection.nextSibling);
                } else {
                    var form = document.querySelector('form');
                    if (form) {
                        form.appendChild(watermarkSection);
                    } else {
                        document.body.appendChild(watermarkSection);
                    }
                }
                console.log('Watermarker JS: Watermark section created');

                // Add a visual style to make the section obvious
                watermarkSection.style.border = '1px solid #ccc';
                watermarkSection.style.padding = '15px';
                watermarkSection.style.marginTop = '15px';
            }

            // Add click handler for tab
            var watermarkTab = document.querySelector('a[href="#watermark-section"]');
            if (watermarkTab) {
                watermarkTab.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();

                    // Hide all sections
                    document.querySelectorAll('section').forEach(function(section) {
                        section.style.display = 'none';
                    });

                    // Show watermark section
                    watermarkSection.style.display = 'block';

                    // Update active tab
                    tabsList.querySelectorAll('li').forEach(function(li) {
                        li.classList.remove('active');
                    });
                    watermarkTab.parentNode.classList.add('active');

                    // Update URL hash
                    history.pushState(null, null, '#watermark-section');
                    console.log('Watermarker JS: Watermark tab clicked');
                });

                // Check if watermark tab should be active based on URL hash
                if (window.location.hash === '#watermark-section') {
                    // Simulate a click on the watermark tab
                    watermarkTab.click();
                }
            }

            // If there's only one tab present, set it as active by default
            if (tabsList.querySelectorAll('li').length === 1) {
                var onlyTab = tabsList.querySelector('li');
                if (onlyTab) {
                    onlyTab.classList.add('active');
                    // Show the corresponding section
                    var href = onlyTab.querySelector('a').getAttribute('href');
                    var section = document.querySelector(href);
                    if (section) {
                        section.style.display = 'block';
                    }
                }
            }

            // Update indicator color to indicate success
            loadIndicator.style.background = 'green';
            loadIndicator.textContent = 'Watermarker Tab Ready';

        } catch (error) {
            console.error('Watermarker JS: Error setting up tabs', error);
            loadIndicator.style.background = 'red';
            loadIndicator.textContent = 'Watermarker Error: ' + error.message;
        }
    }

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

    // Wait until DOM is fully ready before attempting to set up tabs
    document.addEventListener('DOMContentLoaded', function() {
        // Try immediately first
        setupEditPageTabs();

        // Try again after a delay for dynamic content loading
        setTimeout(setupEditPageTabs, 1000);

        // And try one more time after a longer delay
        setTimeout(setupEditPageTabs, 3000);
    });

    // Also try when window is fully loaded
    window.addEventListener('load', function() {
        setupEditPageTabs();
    });

    // If we're on a page with specific DOM elements that should be monitored for changes
    if (typeof MutationObserver !== 'undefined') {
        // Monitor the main content area for changes
        var contentObserver = new MutationObserver(function(mutations) {
            setupEditPageTabs();
        });

        // Start observing once DOM is ready
        document.addEventListener('DOMContentLoaded', function() {
            var content = document.querySelector('#content, main, .main, .page');
            if (content) {
                contentObserver.observe(content, { childList: true, subtree: true });
            }
        });
    }

    /**
     * Create a visible indicator
     */
    function createIndicator() {
        var indicator = document.createElement("div");
        indicator.style.cssText = "position:fixed;top:5px;right:5px;background:blue;color:white;padding:5px;z-index:9999;font-size:12px;";
        indicator.textContent = "Watermarker JS Active";
        document.body.appendChild(indicator);
        console.log("Watermarker indicator created");
    }
})();