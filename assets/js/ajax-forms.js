/**
 * Project Hub - AJAX Form Handler
 * Intercepts form submissions and processes them without page refresh.
 */

document.addEventListener('DOMContentLoaded', () => {
    // Intercept all forms with class 'ajax-form'
    document.body.addEventListener('submit', async (e) => {
        if (e.target.classList.contains('ajax-form')) {
            e.preventDefault();
            const form = e.target;
            const submitBtn = form.querySelector('[type="submit"]');
            const originalBtnContent = submitBtn ? submitBtn.innerHTML : '';
            
            // Show loading state
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';
            }

            const formData = new FormData(form);
            
            // CRITICAL: Include the clicked button's name/value so PHP isset($_POST['action']) works
            if (e.submitter && e.submitter.name) {
                formData.append(e.submitter.name, e.submitter.value || '1');
            }
            
            formData.append('is_ajax', '1');

            try {
                const response = await fetch(form.action || window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                const text = await response.text();
                let result;
                try {
                    result = JSON.parse(text);
                } catch (e) {
                    console.error('Raw Server Response:', text);
                    showGlobalAlert('Server returned an invalid response. This often happens due to a PHP fatal error or session timeout. Check console for details.', 'error');
                    return;
                }
                
                if (result.status === 'success') {
                    showGlobalAlert(result.message, 'success');
                    if (result.redirect) {
                        setTimeout(() => window.location.href = result.redirect, 1500);
                    } else if (result.reload) {
                        setTimeout(() => window.location.reload(), 1500);
                    } else if (result.callback) {
                        // Handle custom callbacks if needed
                        if (typeof window[result.callback] === 'function') {
                            window[result.callback](result.data);
                        }
                    }
                    
                    // Reset form if requested
                    if (result.reset) form.reset();
                    
                    // Close modal if requested
                    if (result.closeModal && typeof closeSimpleModal === 'function') {
                        closeSimpleModal(result.closeModal);
                    }
                    if (result.closeMaster && typeof closeMasterModal === 'function') {
                        closeMasterModal();
                    }
                } else {
                    showGlobalAlert(result.message || 'An error occurred.', 'error');
                }
            } catch (error) {
                console.error('AJAX Fetch Error:', error);
                showGlobalAlert('Network error or server unreachable. Please try again.', 'error');
            }
 finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalBtnContent;
                }
            }
        }
    });
});

/**
 * Shows a beautiful floating alert message
 */
function showGlobalAlert(message, type = 'success') {
    // Check if alert box exists, or create it
    let alertContainer = document.getElementById('global-alert-container');
    if (!alertContainer) {
        alertContainer = document.createElement('div');
        alertContainer.id = 'global-alert-container';
        alertContainer.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 9999; display: flex; flex-direction: column; gap: 10px; max-width: 400px; width: 90%;';
        document.body.appendChild(alertContainer);
    }

    const alert = document.createElement('div');
    const bgColor = type === 'success' ? '#D1FAE5' : '#FEE2E2';
    const textColor = type === 'success' ? '#065F46' : '#991B1B';
    const borderColor = type === 'success' ? '#A7F3D0' : '#FECACA';
    const icon = type === 'success' ? 'fa-check-circle' : 'fa-circle-exclamation';

    alert.style.cssText = `
        background: ${bgColor};
        color: ${textColor};
        border: 1px solid ${borderColor};
        padding: 15px 20px;
        border-radius: 12px;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        display: flex;
        align-items: center;
        gap: 12px;
        font-size: 14px;
        font-weight: 500;
        animation: slideInRight 0.3s ease-out forwards;
    `;

    alert.innerHTML = `
        <i class="fa-solid ${icon}" style="font-size: 18px;"></i>
        <span style="flex: 1;">${message}</span>
        <i class="fa-solid fa-xmark" style="cursor: pointer; opacity: 0.5;" onclick="this.parentElement.remove()"></i>
    `;

    alertContainer.appendChild(alert);

    // Auto-remove after 5 seconds
    setTimeout(() => {
        alert.style.animation = 'slideOutRight 0.3s ease-in forwards';
        setTimeout(() => alert.remove(), 300);
    }, 5000);
}

// Add animation keyframes
const style = document.createElement('style');
style.textContent = `
    @keyframes slideInRight {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    @keyframes slideOutRight {
        from { transform: translateX(0); opacity: 1; }
        to { transform: translateX(100%); opacity: 0; }
    }
`;
document.head.appendChild(style);
