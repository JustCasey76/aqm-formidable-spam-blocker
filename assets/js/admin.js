/**
 * AQM Formidable Forms Spam Blocker Admin JavaScript
 * Version: 2.1.72
 */
(function($) {
    // Define ajaxurl if it's not already defined (happens on some WordPress installs)
    if (typeof ajaxurl === 'undefined') {
        window.ajaxurl = '/wp-admin/admin-ajax.php';
    }
    
    // Define ffbAdminVars if it's not already defined
    if (typeof ffbAdminVars === 'undefined') {
        console.error('ffbAdminVars is not defined! Creating fallback object.');
        window.ffbAdminVars = {
            nonce: '',
            ajax_url: ajaxurl || '',
            searching: 'Searching...',
            clearing_cache: 'Clearing cache...'
        };
    }

    $(document).ready(function() {
        // Initialize tabs
        if ($.fn.tabs) {
            $('.ffb-tabs').tabs();
        }
        
        // Initialize Select2 for country dropdown with flag icons
        if ($.fn.select2) {
            // Standard dropdowns
            $('select').not('.country-select').select2();
            
            // Country dropdown with flags
            $('select[name="country"]').select2({
                templateResult: formatCountryOption,
                templateSelection: formatCountryOption
            });
        }
        
        // Format country options with flag icons
        function formatCountryOption(state) {
            if (!state.id) {
                return state.text; // For the "All Countries" option
            }
            
            var countryCode = state.id.toLowerCase();
            var $state = $(
                '<span><span class="fi fi-' + countryCode + '"></span> ' + state.text + '</span>'
            );
            
            return $state;
        }

        // Handle Create Table button
        $('#ffb-create-table-btn').on('click', function() {
            if (confirm('Are you sure you want to create or recreate the access log table? Any existing table will be replaced.')) {
                $('#ffb-create-table-form').submit();
            }
        });
        
        // Test API Key
        $('#ffb-test-api').on('click', function() {
            var button = $(this);
            var resultDiv = $('#ffb-api-test-result');
            var apiKey = $('input[name="ffb_api_key"]').val();
            
            if (!apiKey) {
                resultDiv.html('<div class="notice notice-error"><p>Please enter an API key first</p></div>');
                return;
            }
            
            button.prop('disabled', true);
            resultDiv.html('Testing API key...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'ffb_test_api_key',
                    nonce: ffbAdminVars.nonce,
                    api_key: apiKey
                },
                success: function(response) {
                    if (response.success) {
                        var html = '<div class="notice notice-success"><p>' + response.data.message + '</p>';
                        
                        // Add API response details
                        if (response.data.data) {
                            var data = response.data.data;
                            html += '<div style="margin-top: 10px;"><strong>API Response:</strong>';
                            html += '<pre style="background: #f8f8f8; padding: 10px; overflow: auto; max-height: 200px;">';
                            html += JSON.stringify(data, null, 2);
                            html += '</pre></div>';
                        }
                        
                        html += '</div>';
                        resultDiv.html(html);
                    } else {
                        resultDiv.html('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                    }
                },
                error: function() {
                    resultDiv.html('<div class="notice notice-error"><p>Failed to test API key. Please try again.</p></div>');
                },
                complete: function() {
                    button.prop('disabled', false);
                }
            });
        });
        
        // Check Location
        $('#ffb-check-location').on('click', function() {
            var button = $(this);
            var ip = $('#ffb-test-ip').val();
            var resultTable = $('#ffb-location-result table');
            
            if (!ip) {
                alert('Please enter an IP address');
                return;
            }
            
            button.prop('disabled', true);
            resultTable.hide();
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'ffb_check_location',
                    ip: ip,
                    nonce: ffbAdminVars.nonce
                },
                success: function(response) {
                    if (response.success && response.data) {
                        var data = response.data;
                        resultTable.find('.country-code').text(data.country_code || '');
                        resultTable.find('.country-name').text(data.country_name || '');
                        resultTable.find('.region-code').text(data.region_code || '');
                        resultTable.find('.region-name').text(data.region_name || '');
                        resultTable.find('.city').text(data.city || '');
                        resultTable.find('.zip').text(data.zip || '');
                        resultTable.find('.status').text(data.is_blocked ? 'Blocked' : 'Allowed');
                        resultTable.show();
                    } else {
                        alert('Failed to get location data: ' + (response.data || 'Unknown error'));
                    }
                },
                error: function() {
                    alert('Failed to check location. Please try again.');
                },
                complete: function() {
                    button.prop('disabled', false);
                }
            });
        });
        
        // Search IP
        $('#ffb-search-ip').on('click', function() {
            var button = $(this);
            var resultDiv = $('#ffb-ip-search-result');
            var ip = $('#ffb-ip-search').val();
            
            if (!ip) {
                resultDiv.html('<div class="notice notice-error"><p>Please enter an IP address</p></div>');
                return;
            }
            
            button.prop('disabled', true);
            resultDiv.html('<p><em>' + ffbAdminVars.searching + '</em></p>');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'ffb_search_ip',
                    nonce: ffbAdminVars.nonce,
                    ip: ip
                },
                success: function(response) {
                    if (response.success) {
                        if (response.data.found) {
                            var html = '<div class="notice notice-success"><p>IP found in cache!</p>';
                            
                            // Add geo data details
                            if (response.data.geo_data) {
                                var data = response.data.geo_data;
                                html += '<div style="margin-top: 10px;"><strong>Cached Geo Data:</strong>';
                                html += '<pre style="background: #f8f8f8; padding: 10px; overflow: auto; max-height: 200px;">';
                                html += JSON.stringify(data, null, 2);
                                html += '</pre></div>';
                            }
                            
                            html += '<button type="button" class="button ffb-delete-ip" data-ip="' + ip + '">Delete This IP From Cache</button>';
                            html += '</div>';
                            resultDiv.html(html);
                            
                            // Add click handler for delete button
                            $('.ffb-delete-ip').on('click', function() {
                                var deleteIp = $(this).data('ip');
                                deleteIpFromCache(deleteIp);
                            });
                        } else {
                            resultDiv.html('<div class="notice notice-warning"><p>IP not found in cache</p></div>');
                        }
                    } else {
                        resultDiv.html('<div class="notice notice-error"><p>Error: ' + response.data + '</p></div>');
                    }
                },
                error: function() {
                    resultDiv.html('<div class="notice notice-error"><p>Failed to search for IP. Please try again.</p></div>');
                },
                complete: function() {
                    button.prop('disabled', false);
                }
            });
        });
        
        // Delete IP from cache
        function deleteIpFromCache(ip) {
            var resultDiv = $('#ffb-ip-search-result');
            
            if (!confirm('Are you sure you want to delete this IP from the cache?')) {
                return;
            }
            
            resultDiv.html('<p><em>Deleting IP from cache...</em></p>');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'ffb_delete_ip',
                    nonce: ffbAdminVars.nonce,
                    ip: ip
                },
                success: function(response) {
                    if (response.success) {
                        resultDiv.html('<div class="notice notice-success"><p>Successfully deleted ' + response.data.count + ' records for IP ' + ip + '</p></div>');
                    } else {
                        resultDiv.html('<div class="notice notice-error"><p>Error: ' + response.data + '</p></div>');
                    }
                },
                error: function() {
                    resultDiv.html('<div class="notice notice-error"><p>Failed to delete IP. Please try again.</p></div>');
                }
            });
        }
        
        // Clear IP Cache
        $('#ffb-clear-cache').on('click', function() {
            var button = $(this);
            
            if (!confirm('Are you sure you want to clear all cached IP data? This cannot be undone.')) {
                return;
            }
            
            button.prop('disabled', true);
            button.text(ffbAdminVars.clearing_cache);
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'ffb_clear_cache',
                    nonce: ffbAdminVars.nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert('Successfully cleared ' + response.data.count + ' cached items');
                    } else {
                        alert('Error: ' + response.data);
                    }
                },
                error: function() {
                    alert('Failed to clear cache. Please try again.');
                },
                complete: function() {
                    button.prop('disabled', false);
                    button.text('Clear All Cache');
                }
            });
        });
        
        // Initialize API key copying
        $('#ffb-copy-api-key').on('click', function() {
            var apiKeyField = $('#ffb-api-key');
            apiKeyField.select();
            
            try {
                document.execCommand('copy');
                $(this).text('Copied!');
                
                setTimeout(function() {
                    $('#ffb-copy-api-key').text('Copy');
                }, 2000);
            } catch (err) {
                console.error('Failed to copy API key:', err);
            }
        });
        
        // Initialize IP test button
        $('#ffb-test-ip-btn').on('click', function() {
            var button = $(this);
            var ip = $('#ffb-test-ip-input').val();
            var resultDiv = $('#ffb-test-ip-result');
            
            if (!ip) {
                resultDiv.html('<div class="notice notice-error"><p>Please enter an IP address</p></div>');
                return;
            }
            
            button.prop('disabled', true);
            resultDiv.html('Testing IP...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'ffb_test_ip',
                    nonce: ffbAdminVars.nonce,
                    ip: ip
                },
                success: function(response) {
                    if (response.success) {
                        var result = response.data;
                        var statusClass = result.is_blocked ? 'notice-error' : 'notice-success';
                        var statusText = result.is_blocked ? 'Blocked' : 'Allowed';
                        
                        var html = '<div class="notice ' + statusClass + '">';
                        html += '<p>Status: <strong>' + statusText + '</strong></p>';
                        
                        if (result.geo_data) {
                            var geo = result.geo_data;
                            html += '<table class="widefat" style="margin-top: 10px;">';
                            html += '<tr><th>Country</th><td>' + (geo.country_name || 'Unknown') + ' (' + (geo.country_code || '?') + ')';
                            
                            // Add flag if country code is available
                            if (geo.country_code) {
                                html += ' <span class="fi fi-' + geo.country_code.toLowerCase() + '"></span>';
                            }
                            
                            html += '</td></tr>';
                            html += '<tr><th>Region</th><td>' + (geo.region_name || 'Unknown') + ' (' + (geo.region_code || '?') + ')</td></tr>';
                            html += '<tr><th>City</th><td>' + (geo.city || 'Unknown') + '</td></tr>';
                            html += '<tr><th>ZIP</th><td>' + (geo.zip || 'Unknown') + '</td></tr>';
                            html += '</table>';
                        }
                        
                        html += '</div>';
                        resultDiv.html(html);
                    } else {
                        resultDiv.html('<div class="notice notice-error"><p>Error: ' + (response.data || 'Unknown error') + '</p></div>');
                    }
                },
                error: function() {
                    resultDiv.html('<div class="notice notice-error"><p>Failed to test IP. Please try again.</p></div>');
                },
                complete: function() {
                    button.prop('disabled', false);
                }
            });
        });

        // On page load actions
        if ($('#ffb-log-table-container').length > 0) {
            refreshCounts();
        }
        
        // Hide API usage section that's no longer functional
        $('#ffb-api-usage-container').hide();
        
        // Hide any remnant API usage refresh buttons
        $('.ffb-refresh-usage-button').hide();
    });
    
    // Initialize refresh counts function
    function refreshCounts() {
        var blockedCountSpan = $('#ffb-blocked-count');
        var allowedCountSpan = $('#ffb-allowed-count');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'ffb_refresh_counts',
                nonce: ffbAdminVars.nonce
            },
            success: function(response) {
                if (response.success) {
                    blockedCountSpan.text(response.data.blocked);
                    allowedCountSpan.text(response.data.allowed);
                }
            }
        });
    }
})(jQuery);
