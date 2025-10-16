// JavaScript will be wrapped in jQuery(document).ready by PHP
console.log('RES DEBUG - JavaScript loaded');

let isRunning = false;
let logInterval = null;

// Initialize immediately since we're already in jQuery ready
console.log('RES DEBUG - Initializing admin JS');
// Set initial cron button state based on localized data
const toggleButton = $('#toggle-cron');
const toggleText = $('#cron-toggle-text');
const initialStatus = window.realEstateScraper.initial_cron_status;

toggleText.text(initialStatus.button_text);
toggleButton.removeClass('button-secondary button-danger').addClass(initialStatus.button_class);
$('p:contains(\'Next Run\') strong').parent().html('<strong>' + window.realEstateScraper.strings.nextRun + '</strong> ' + (initialStatus.next_run_display || 'N/A'));
$('p:contains(\'Last Run\') strong').parent().html('<strong>' + window.realEstateScraper.strings.lastRun + '</strong> ' + (initialStatus.last_run_display || 'N/A'));

bindEvents();
refreshLogs();

// Auto-refresh logs every 30 seconds when not running (moved outside jQuery ready for inline script)
setInterval(function () {
    if (!isRunning) {
        refreshLogs();
    }
}, 30000);


function bindEvents() {
    // Run scraper button
    $('#run-scraper-now').on('click', function (e) {
        e.preventDefault();
        runScraper();
    });

    // Test cron button
    $('#test-cron').on('click', function (e) {
        e.preventDefault();
        testCron();
    });

    // Refresh logs button
    $('#refresh-logs').on('click', function (e) {
        e.preventDefault();
        refreshLogs();
    });

    // Clean logs button
    $('#clean-logs').on('click', function (e) {
        e.preventDefault();
        cleanLogs();
    });
}

function runScraper() {
    if (isRunning) {
        return;
    }

    isRunning = true;
    const button = $('#run-scraper-now');
    const originalText = button.text();

    // Update button state
    button.addClass('res-button-loading').prop('disabled', true);

    // Clear logs container
    $('#live-logs').html('<div class="log-entry log-info">Starting scraper...</div>');

    // Start live log updates
    startLiveLogs();

    // Make AJAX request
    $.ajax({
        url: window.realEstateScraper.ajaxUrl,
        type: 'POST',
        data: {
            action: 'res_run_scraper',
            nonce: window.realEstateScraper.nonce
        },
        success: function (response) {
            if (response.success) {
                showMessage('success', response.message);
                if (response.stats) {
                    showStats(response.stats);
                }
            } else {
                showMessage('error', response.message);
            }
        },
        error: function (xhr, status, error) {
            showMessage('error', 'AJAX Error: ' + error);
        },
        complete: function () {
            // Reset button state
            button.removeClass('res-button-loading').prop('disabled', false);
            isRunning = false;

            // Stop live log updates
            stopLiveLogs();

            // Final log refresh
            setTimeout(refreshLogs, 1000);
        }
    });
}

function testCron() {
    const button = $('#test-cron');
    const originalText = button.text();

    button.addClass('res-button-loading').prop('disabled', true);

    $.ajax({
        url: window.realEstateScraper.ajaxUrl,
        type: 'POST',
        data: {
            action: 'res_test_cron',
            nonce: window.realEstateScraper.nonce
        },
        success: function (response) {
            if (response.success) {
                showMessage('success', response.message);
            } else {
                showMessage('error', response.message);
            }
        },
        error: function (xhr, status, error) {
            showMessage('error', 'AJAX Error: ' + error);
        },
        complete: function () {
            button.removeClass('res-button-loading').prop('disabled', false);
        }
    });
}

function refreshLogs() {
    $.ajax({
        url: window.realEstateScraper.ajaxUrl,
        type: 'POST',
        data: {
            action: 'res_get_logs',
            nonce: window.realEstateScraper.nonce
        },
        success: function (response) {
            if (response && response.length > 0) {
                displayLogs(response);
            } else {
                $('#live-logs').html('<div class="log-entry log-info">No logs available.</div>');
            }
        },
        error: function (xhr, status, error) {
            $('#live-logs').html('<div class="log-entry log-error">Error loading logs: ' + error + '</div>');
        }
    });
}

function cleanLogs() {
    if (!confirm(window.realEstateScraper.strings.confirm + ' This will delete log files older than 4 days.')) {
        return;
    }

    const button = $('#clean-logs');
    button.addClass('res-button-loading').prop('disabled', true);

    $.ajax({
        url: window.realEstateScraper.ajaxUrl,
        type: 'POST',
        data: {
            action: 'res_clean_logs',
            nonce: window.realEstateScraper.nonce
        },
        success: function (response) {
            if (response.success) {
                showMessage('success', response.message);
                refreshLogs();
            } else {
                showMessage('error', response.message);
            }
        },
        error: function (xhr, status, error) {
            showMessage('error', 'AJAX Error: ' + error);
        },
        complete: function () {
            button.removeClass('res-button-loading').prop('disabled', false);
        }
    });
}

function startLiveLogs() {
    // Refresh logs every 2 seconds while running
    logInterval = setInterval(function () {
        refreshLogs();
    }, 2000);
}

function stopLiveLogs() {
    if (logInterval) {
        clearInterval(logInterval);
        logInterval = null;
    }
}

function displayLogs(logs) {
    const container = $('#live-logs');
    let html = '';

    // Show last 50 log entries
    const recentLogs = logs.slice(-50);

    recentLogs.forEach(function (log) {
        if (log.trim()) {
            const logClass = getLogClass(log);
            html += '<div class="log-entry ' + logClass + '">' + escapeHtml(log) + '</div>';
        }
    });

    container.html(html);

    // Scroll to bottom
    container.scrollTop(container[0].scrollHeight);
}

function getLogClass(log) {
    if (log.includes('[ERROR]')) {
        return 'log-error';
    } else if (log.includes('[WARNING]')) {
        return 'log-warning';
    } else if (log.includes('[DEBUG]')) {
        return 'log-debug';
    } else {
        return 'log-info';
    }
}

function showMessage(type, message) {
    const messageClass = 'res-message-' + type;
    const messageHtml = '<div class="res-message ' + messageClass + '">' + escapeHtml(message) + '</div>';

    // Remove existing messages
    $('.res-message').remove();

    // Add new message
    $('.res-admin-container').prepend(messageHtml);

    // Auto-hide after 5 seconds
    setTimeout(function () {
        $('.res-message').fadeOut();
    }, 5000);
}

function showStats(stats) {
    let statsHtml = '<div class="res-message res-message-info"><strong>Scraper Statistics:</strong><br>';
    statsHtml += 'Total found: ' + stats.total_found + '<br>';
    statsHtml += 'New added: ' + stats.new_added + '<br>';
    statsHtml += 'Duplicates skipped: ' + stats.duplicates_skipped + '<br>';
    statsHtml += 'Errors: ' + stats.errors + '<br>';
    statsHtml += 'Execution time: ' + stats.execution_time + ' seconds</div>';

    $('.res-message').remove();
    $('.res-admin-container').prepend(statsHtml);

    setTimeout(function () {
        $('.res-message').fadeOut();
    }, 10000);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}