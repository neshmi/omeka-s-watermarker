// Watermarker JavaScript - handles watermark settings functionality
console.log('Watermarker: Initializing watermark controls');

// Initialize watermark controls
$(document).ready(function() {
    // Find watermark data
    const watermarkData = $('#watermarker-data').data('watermarker');
    if (!watermarkData) {
        console.log('Watermarker: No watermark data found');
        return;
    }

    // Get resource type and ID from the form
    const resourceType = watermarkData.resourceType;
    const resourceId = watermarkData.resourceId;

    // Set resource type and ID on the select element
    const select = $('.watermark-select');
    if (select.length) {
        select.attr('data-resource-type', resourceType);
        select.attr('data-resource-id', resourceId);
    }

    // Add save button handler
    $('.watermark-save-button').on('click', function() {
        saveWatermarkSettings(select);
    });
});

/**
 * Save watermark settings
 */
function saveWatermarkSettings(select) {
    const resourceType = select.attr('data-resource-type');
    const resourceId = select.attr('data-resource-id');
    const watermarkSetId = select.val();

    if (!resourceType || !resourceId) {
        console.error('Watermarker: Missing resource information');
        return;
    }

    // Create the data to send
    const data = {
        resource_type: resourceType,
        resource_id: resourceId,
        watermark_set_id: watermarkSetId === 'default' ? null : watermarkSetId,
        explicitly_no_watermark: watermarkSetId === 'none'
    };

    // Send the request
    $.ajax({
        url: '/admin/watermarker-api/setAssignment',
        method: 'POST',
        data: data,
        success: function(response) {
            if (response.success) {
                // Update status message
                const status = $('.watermark-status');
                if (status.length) {
                    status.text('Watermark settings saved successfully.');
                    status.removeClass('error').addClass('success');
                }
            } else {
                console.error('Watermarker: Error saving settings:', response.message);
                const status = $('.watermark-status');
                if (status.length) {
                    status.text('Error saving watermark settings: ' + response.message);
                    status.removeClass('success').addClass('error');
                }
            }
        },
        error: function(xhr, status, error) {
            console.error('Watermarker: AJAX error:', error);
            const status = $('.watermark-status');
            if (status.length) {
                status.text('Error saving watermark settings. Please try again.');
                status.removeClass('success').addClass('error');
            }
        }
    });
}

/**
 * Load watermark info
 */
function loadWatermarkInfo() {
    const watermarkData = $('#watermarker-data').data('watermarker');
    if (!watermarkData) {
        console.log('Watermarker: No watermark data found');
        return;
    }

    const resourceType = watermarkData.resourceType;
    const resourceId = watermarkData.resourceId;

    if (!resourceType || !resourceId) {
        console.error('Watermarker: Missing resource information');
        return;
    }

    // Get current assignment
    $.ajax({
        url: '/admin/watermarker-api/getAssignment',
        method: 'GET',
        data: {
            resource_type: resourceType,
            resource_id: resourceId
        },
        success: function(response) {
            if (response.success) {
                const assignment = response.assignment;
                const select = $('.watermark-select');
                if (select.length) {
                    // Set the correct value
                    if (assignment.explicitly_no_watermark) {
                        select.val('none');
                    } else if (assignment.watermark_set_id === null) {
                        select.val('default');
                    } else {
                        select.val(assignment.watermark_set_id);
                    }

                    // Update status message
                    const status = $('.watermark-status');
                    if (status.length) {
                        if (assignment.explicitly_no_watermark) {
                            status.text('Watermarking explicitly disabled for this resource.');
                        } else if (assignment.watermark_set_id === null) {
                            status.text('Using default watermark settings.');
                        } else {
                            status.text('Using custom watermark set.');
                        }
                        status.removeClass('error').addClass('success');
                    }
                }
            } else {
                console.error('Watermarker: Error loading assignment:', response.message);
            }
        },
        error: function(xhr, status, error) {
            console.error('Watermarker: AJAX error:', error);
        }
    });
}