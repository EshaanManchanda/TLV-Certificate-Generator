(function ($) {
    $(document).ready(function () {
        // Add click event listener to the Export Students button
        $('#export-students-button').on('click', function (e) {
            e.preventDefault(); // Prevent default form submission

            // Get the button element
            const button = $(this);

            // Show loading state on the button
            button.text('Exporting...').prop('disabled', true);

            // Trigger AJAX request to export students
            $.ajax({
                url: bulkExportAjax.ajax_url, // AJAX endpoint
                type: 'POST',
                data: {
                    action: 'bulk_export_students', // Action defined in PHP
                    security: bulkExportAjax.nonce, // Security nonce
                },
                xhrFields: {
                    responseType: 'blob', // Expect a binary response for file download
                },
                success: function (data, status, xhr) {
                    // Check for a valid response
                    if (xhr.status === 200) {
                        // Create a Blob object from the response data
                        const blob = new Blob([data], { type: 'text/csv' });

                        // Create a temporary download link
                        const link = document.createElement('a');
                        link.href = window.URL.createObjectURL(blob);
                        link.download = 'students_export.csv'; // Set the file name
                        document.body.appendChild(link);
                        link.click(); // Trigger the download
                        document.body.removeChild(link); // Clean up the link
                    } else {
                        alert('Error: Unable to export students.');
                    }

                    // Restore button state
                    button.text('Export to CSV').prop('disabled', false);
                },
                error: function (xhr, status, error) {
                    // Display error message
                    alert('Error exporting students: ' + (xhr.responseJSON?.data || error));

                    // Restore button state
                    button.text('Export to CSV').prop('disabled', false);
                },
            });
        });
    });
})(jQuery);
