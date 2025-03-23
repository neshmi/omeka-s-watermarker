console.log('Watermarker: Setting up watermark controls in advanced tab');

try {
    // Debug: Log all section elements in the page
    console.log('Watermarker: Checking available sections:');
    document.querySelectorAll('.section').forEach(function(section) {
        console.log('- Section found:', section.id, section);
    });

    // Debug: Try alternative selectors for the advanced tab
    console.log('Watermarker: Trying alternative advanced tab selectors:');
    const advancedTabSelectors = [
        '#advanced-section',
        '#section-advanced',
        '#advanced',
        '.section[aria-labelledby="advanced-label"]',
        '.section-advanced'
    ];

    advancedTabSelectors.forEach(function(selector) {
        const element = document.querySelector(selector);
        console.log(`- Selector "${selector}":`, element);
    });

    // First, find any watermarker data
    var watermarkerData = document.getElementById('watermarker-data');
    if (!watermarkerData) {
        console.error('Watermarker: No watermarker data found');
        return;
    }

    var data = JSON.parse(watermarkerData.getAttribute('data-watermarker'));
    console.log('Watermarker: Parsed watermarker data:', data);

    // Get the template HTML
    var templateDiv = document.getElementById('watermarker-template');
    if (!templateDiv) {
        console.error('Watermarker: Template div not found');
        return;
    }

    // Try to find the advanced tab with more flexible selectors
    var advancedTab = null;
    for (let selector of advancedTabSelectors) {
        advancedTab = document.querySelector(selector);
        if (advancedTab) {
            console.log('Watermarker: Found advanced tab with selector:', selector);
            break;
        }
    }

    // If we still can't find it, try to get the last section
    if (!advancedTab) {
        console.log('Watermarker: Advanced tab not found with known selectors, looking for last section');
        const sections = document.querySelectorAll('.section');
        if (sections.length > 0) {
            advancedTab = sections[sections.length - 1];
            console.log('Watermarker: Using last section as fallback:', advancedTab);
        }
    }

    if (!advancedTab) {
        console.error('Watermarker: Could not find advanced tab or any section');
        return;
    }

    // Check if watermark controls already exist
    var existingControls = document.getElementById('watermark-container');
    if (existingControls) {
        console.log('Watermarker: Watermark controls already exist');
    } else {
        console.log('Watermarker: Adding watermark controls to advanced tab');

        // Create a container for our controls
        var container = document.createElement('div');
        container.innerHTML = templateDiv.innerHTML;

        // Extract the fieldset from the container
        var fieldset = container.querySelector('#watermark-fieldset');
        if (!fieldset) {
            console.error('Watermarker: Fieldset not found in template');
            return;
        }

        // Add our fieldset to the advanced tab - force prepend to make it visible
        if (advancedTab.firstChild) {
            advancedTab.insertBefore(fieldset, advancedTab.firstChild);
            console.log('Watermarker: Added fieldset at the beginning of the advanced tab');
        } else {
            advancedTab.appendChild(fieldset);
            console.log('Watermarker: Added fieldset to empty advanced tab');
        }

        console.log('Watermarker: Controls added to advanced tab');

        // Set up the dropdown
        var select = fieldset.querySelector('#watermark-set-select');
        if (!select) {
            console.error('Watermarker: Select element not found in fieldset');
            return;
        }

        // Get resource info
        var resourceType = data.resourceType;
        var resourceId = data.resourceId;
        if (!resourceType || !resourceId) {
            console.error('Watermarker: Missing resource type or ID');
            return;
        }

        // Store the resource info on the select element
        select.setAttribute('data-resource-type', resourceType);
        select.setAttribute('data-resource-id', resourceId);

        // Populate the dropdown
        populateWatermarkDropdown(select, data.watermarkSets)
            .then(function() {
                // Load watermark info after dropdown is populated
                loadWatermarkInfo(resourceType, resourceId);
            });

        // Add save button handler
        var saveButton = fieldset.querySelector('.watermark-save-button');
        if (saveButton) {
            saveButton.addEventListener('click', function() {
                saveWatermarkSettings(select, data);
            });
        } else {
            console.error('Watermarker: Save button not found in fieldset');
        }
    }

    // Mark as set up
    tabsSetup = true;
    console.log('Watermarker: Watermark controls setup complete');

} catch (error) {
    console.error('Watermarker: Error setting up watermark controls:', error);
}

/**
 * Save watermark settings
 */
function saveWatermarkSettings(select, data) {
    var selectedValue = select.value;
    console.log('Watermarker: Save button clicked, selected value:', selectedValue);

    var formData = new FormData();
    formData.append('resource_id', data.resourceId);
    formData.append('resource_type', data.resourceType === 'item-set' ? 'item-set' : 'item');
    formData.append('watermark_set_id', selectedValue);

    fetch(data.assignUrl, {
        method: 'POST',
        body: formData,
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(function(response) {
        return response.json();
    })
    .then(function(json) {
        if (json.success) {
            // Show success message
            var fieldset = select.closest('#watermark-fieldset');
            var successMessage = document.createElement('div');
            successMessage.className = 'success messages';
            successMessage.textContent = json.message || 'Watermark settings saved successfully';

            // Insert at top of fieldset
            fieldset.insertBefore(successMessage, fieldset.firstChild);

            setTimeout(function() {
                successMessage.remove();
            }, 3000);

            // Reload the watermark info
            var resourceType = select.getAttribute('data-resource-type');
            var resourceId = select.getAttribute('data-resource-id');
            loadWatermarkInfo(resourceType, resourceId);
        } else {
            throw new Error(json.error || 'Failed to save watermark settings');
        }
    })
    .catch(function(error) {
        console.error('Watermarker: Error saving watermark settings:', error);
        var fieldset = select.closest('#watermark-fieldset');
        var errorMessage = document.createElement('div');
        errorMessage.className = 'error messages';
        errorMessage.textContent = 'Failed to save watermark settings: ' + error.message;

        // Insert at top of fieldset
        fieldset.insertBefore(errorMessage, fieldset.firstChild);

        setTimeout(function() {
            errorMessage.remove();
        }, 3000);
    });
}

function loadWatermarkInfo(resourceType, resourceId) {
    // Add a flag to prevent multiple simultaneous loads
    if (loadWatermarkInfo.isLoading) {
        console.log('Watermarker: Already loading watermark info, skipping');
        return;
    }
    loadWatermarkInfo.isLoading = true;

    console.log('Watermarker: Loading watermark info for:', resourceType, resourceId);

    fetch(`/admin/watermarker/info/${resourceType}/${resourceId}`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! Status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            console.log('Watermarker: Full response data:', data);

            if (data.success) {
                // Log all debug information
                if (data.debug) {
                    console.log('Watermarker: Debug Information:', {
                        'Raw Assignment': data.debug.raw_assignment,
                        'Watermark Set ID': data.debug.watermark_set_id,
                        'Explicitly No Watermark': data.debug.explicitly_no_watermark,
                        'Selected Value': data.debug.selected_value,
                        'SQL Query': data.debug.sql,
                        'Query Parameters': data.debug.params,
                        'All Assignments': data.debug.all_assignments,
                        'All Sets': data.debug.all_sets,
                        'Query Result': data.debug.query_result,
                        'Processed Values': data.debug.processed_values
                    });
                }

                // Update the status message in the fieldset
                const fieldset = document.getElementById('watermark-fieldset');
                if (!fieldset) {
                    console.error('Watermarker: Fieldset not found');
                    return;
                }

                const statusElement = fieldset.querySelector('#watermark-status');
                if (statusElement) {
                    statusElement.textContent = data.status || 'Current watermark settings loaded';
                }

                // Update the dropdown selection
                const selectElement = fieldset.querySelector('#watermark-set-select');
                if (selectElement) {
                    // Check if the dropdown has all options (specifically the watermark set option)
                    const expectedSetId = data.watermark_set_id ? data.watermark_set_id.toString() : null;
                    let hasAllOptions = false;

                    if (expectedSetId) {
                        // Check if the dropdown has the expected watermark set option
                        hasAllOptions = Array.from(selectElement.options).some(opt => opt.value === expectedSetId);
                    } else {
                        // If no specific set ID is expected, just check if we have at least 2 options
                        hasAllOptions = selectElement.options.length >= 2;
                    }

                    // If the dropdown is missing options and we have watermark sets data,
                    // try to find it in the watermarker data
                    if (!hasAllOptions) {
                        console.warn('Watermarker: Dropdown is missing options, trying to repopulate');

                        // Try to get watermark sets from the watermarker data element
                        const watermarkerData = document.getElementById('watermarker-data');
                        if (watermarkerData) {
                            try {
                                const dataAttr = watermarkerData.getAttribute('data-watermarker');
                                if (dataAttr) {
                                    const parsedData = JSON.parse(dataAttr);

                                    if (parsedData.watermarkSets && parsedData.watermarkSets.length > 0) {
                                        console.log('Watermarker: Repopulating dropdown from watermarker data');

                                        // Clear existing options
                                        selectElement.innerHTML = '';

                                        // Add "None" option
                                        const noneOption = document.createElement('option');
                                        noneOption.value = '';
                                        noneOption.textContent = 'None (no watermark)';
                                        selectElement.appendChild(noneOption);

                                        // Add "Default" option
                                        const defaultOption = document.createElement('option');
                                        defaultOption.value = 'default';
                                        defaultOption.textContent = 'Default (inherit from parent)';
                                        selectElement.appendChild(defaultOption);

                                        // Add watermark sets
                                        parsedData.watermarkSets.forEach(set => {
                                            const option = document.createElement('option');
                                            option.value = set.id.toString();
                                            option.textContent = set.name;
                                            selectElement.appendChild(option);
                                            console.log('Watermarker: Re-added option:', set.name, '(ID: ' + set.id + ')');
                                        });

                                        console.log('Watermarker: Dropdown repopulated with ' + selectElement.options.length + ' options');
                                    }
                                }
                            } catch (error) {
                                console.error('Watermarker: Error repopulating dropdown:', error);
                            }
                        }
                    }

                    // Get the value we need to select
                    let selectedValue = '';

                    // Check for explicitly no watermark first
                    if (data.explicitly_no_watermark) {
                        selectedValue = '';  // empty string = "None" option
                    }
                    // Check if we have a watermark set ID
                    else if (data.watermark_set_id !== null && data.watermark_set_id !== undefined) {
                        selectedValue = data.watermark_set_id.toString();
                    }
                    // Default value if nothing else applies
                    else {
                        selectedValue = 'default';
                    }

                    console.log('Watermarker: Selecting dropdown value:', selectedValue);

                    // Log all options for debugging
                    const options = Array.from(selectElement.options);
                    console.log('Watermarker: Dropdown has', options.length, 'options:',
                        options.map(opt => ({
                            index: opt.index,
                            value: opt.value,
                            text: opt.textContent
                        }))
                    );

                    // First try exact match on value
                    let found = false;
                    for (let i = 0; i < selectElement.options.length; i++) {
                        if (selectElement.options[i].value === selectedValue) {
                            selectElement.selectedIndex = i;
                            console.log(`Watermarker: Selected option at index ${i} with value "${selectedValue}"`);
                            found = true;
                            break;
                        }
                    }

                    // If not found and it's a numeric ID, try matching by numerical value
                    if (!found && !isNaN(parseInt(selectedValue))) {
                        const numericValue = parseInt(selectedValue);
                        console.log('Watermarker: Trying numeric match for value:', numericValue);

                        for (let i = 0; i < selectElement.options.length; i++) {
                            const optionValue = parseInt(selectElement.options[i].value);
                            if (!isNaN(optionValue) && optionValue === numericValue) {
                                selectElement.selectedIndex = i;
                                console.log(`Watermarker: Selected option at index ${i} with numeric value ${numericValue}`);
                                found = true;
                                break;
                            }
                        }
                    }

                    // If still not found, use default logic
                    if (!found) {
                        console.warn('Watermarker: Could not find matching option for value:', selectedValue);

                        // Default to appropriate option based on value
                        if (selectedValue === '') {
                            // Default to "None" (first option)
                            selectElement.selectedIndex = 0;
                            console.log('Watermarker: Defaulted to "None" option (index 0)');
                        } else if (selectedValue === 'default' || selectedValue === 'null') {
                            // Default to "Default" (second option)
                            selectElement.selectedIndex = 1;
                            console.log('Watermarker: Defaulted to "Default" option (index 1)');
                        } else {
                            console.log('Watermarker: Keeping current selection:', selectElement.selectedIndex);
                        }
                    }
                }
            } else {
                console.error('Watermarker: Error loading watermark info:', data.error || 'Unknown error');
            }
        })
        .catch(error => {
            console.error('Watermarker: Error fetching watermark info:', error);
        })
        .finally(() => {
            loadWatermarkInfo.isLoading = false;
        });
}