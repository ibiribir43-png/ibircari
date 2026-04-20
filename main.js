/* main.js - Tüm sayfalarda kullanılacak ortak JavaScript */

// 1. DOM YÜKLENDİĞİNDE ÇALIŞACAK KODLAR
document.addEventListener('DOMContentLoaded', function() {
    
    // 2. NAVBAR SCROLL ETKİSİ
    const navbar = document.querySelector('.navbar');
    if (navbar) {
        window.addEventListener('scroll', function() {
            if (window.scrollY > 50) {
                navbar.classList.add('shadow-md');
                navbar.style.padding = '10px 0';
            } else {
                navbar.classList.remove('shadow-md');
                navbar.style.padding = '20px 0';
            }
        });
    }
    
    // 3. SMOOTH SCROLL (ANASAYFA İÇİN)
    const smoothScrollLinks = document.querySelectorAll('a[href^="#"]');
    smoothScrollLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const targetId = this.getAttribute('href');
            if (targetId === '#') return;
            
            const targetElement = document.querySelector(targetId);
            if (targetElement) {
                window.scrollTo({
                    top: targetElement.offsetTop - 80,
                    behavior: 'smooth'
                });
            }
        });
    });
    
    // 4. FORM VALIDATION (GENEL)
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            const requiredFields = this.querySelectorAll('[required]');
            let isValid = true;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    isValid = false;
                    field.classList.add('is-invalid');
                    
                    // Hata mesajı oluştur
                    let errorMsg = field.nextElementSibling;
                    if (!errorMsg || !errorMsg.classList.contains('invalid-feedback')) {
                        errorMsg = document.createElement('div');
                        errorMsg.className = 'invalid-feedback';
                        errorMsg.textContent = 'Bu alan zorunludur.';
                        field.parentNode.appendChild(errorMsg);
                    }
                } else {
                    field.classList.remove('is-invalid');
                    const errorMsg = field.nextElementSibling;
                    if (errorMsg && errorMsg.classList.contains('invalid-feedback')) {
                        errorMsg.remove();
                    }
                }
            });
            
            if (!isValid) {
                e.preventDefault();
                showToast('Lütfen tüm zorunlu alanları doldurun.', 'danger');
            }
        });
    });
    
    // 5. SAYI FORMATLAMA FONKSİYONU
    window.formatNumber = function(num) {
        return new Intl.NumberFormat('tr-TR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(num);
    };
    
    // 6. TARİH FORMATLAMA FONKSİYONU
    window.formatDate = function(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString('tr-TR', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric'
        });
    };
    
    // 7. TOAST (BİLDİRİM) SİSTEMİ
    window.showToast = function(message, type = 'info') {
        const toastContainer = document.getElementById('toast-container');
        let container;
        
        if (!toastContainer) {
            container = document.createElement('div');
            container.id = 'toast-container';
            container.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 9999;';
            document.body.appendChild(container);
        } else {
            container = toastContainer;
        }
        
        const toastId = 'toast-' + Date.now();
        const toast = document.createElement('div');
        toast.id = toastId;
        toast.className = `toast show`;
        toast.style.cssText = `
            background: ${type === 'success' ? '#d4edda' : type === 'error' ? '#f8d7da' : type === 'warning' ? '#fff3cd' : '#d1ecf1'};
            color: ${type === 'success' ? '#155724' : type === 'error' ? '#721c24' : type === 'warning' ? '#856404' : '#0c5460'};
            border: 1px solid ${type === 'success' ? '#c3e6cb' : type === 'error' ? '#f5c6cb' : type === 'warning' ? '#ffeaa7' : '#bee5eb'};
            border-radius: 8px;
            padding: 12px 20px;
            margin-bottom: 10px;
            min-width: 250px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
        `;
        
        toast.innerHTML = `
            <span>${message}</span>
            <button type="button" class="btn-close" style="margin-left: 10px;" onclick="document.getElementById('${toastId}').remove()"></button>
        `;
        
        container.appendChild(toast);
        
        // 5 saniye sonra otomatik kaldır
        setTimeout(() => {
            if (document.getElementById(toastId)) {
                document.getElementById(toastId).remove();
            }
        }, 5000);
    };
    
    // 8. MODAL YÖNETİMİ
    window.openModal = function(modalId) {
        const modalElement = document.getElementById(modalId);
        if (modalElement) {
            const modal = new bootstrap.Modal(modalElement);
            modal.show();
        }
    };
    
    window.closeModal = function(modalId) {
        const modalElement = document.getElementById(modalId);
        if (modalElement) {
            const modal = bootstrap.Modal.getInstance(modalElement);
            if (modal) {
                modal.hide();
            }
        }
    };
    
    // 9. INPUT MASK (TELEFON, PARA VB.)
    const phoneInputs = document.querySelectorAll('input[type="tel"]');
    phoneInputs.forEach(input => {
        input.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 10) {
                value = value.slice(0, 10);
            }
            
            if (value.length > 0) {
                value = value.replace(/^(\d{3})(\d{3})(\d{2})(\d{2})$/, '($1) $2 $3 $4');
            }
            
            e.target.value = value;
        });
    });
    
    // 10. PARA FORMATI INPUTLARI
    const moneyInputs = document.querySelectorAll('input[data-money]');
    moneyInputs.forEach(input => {
        input.addEventListener('blur', function(e) {
            let value = parseFloat(e.target.value.replace(/[^\d,.-]/g, '').replace(',', '.'));
            if (!isNaN(value)) {
                e.target.value = formatNumber(value);
            }
        });
        
        input.addEventListener('focus', function(e) {
            let value = parseFloat(e.target.value.replace(/[^\d,.-]/g, '').replace(',', '.'));
            if (!isNaN(value)) {
                e.target.value = value;
            }
        });
    });
    
    // 11. LOADING DURUMU YÖNETİMİ
    window.showLoading = function(button) {
        if (button) {
            const originalText = button.innerHTML;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>İşleniyor...';
            button.disabled = true;
            button.dataset.originalText = originalText;
        }
    };
    
    window.hideLoading = function(button) {
        if (button && button.dataset.originalText) {
            button.innerHTML = button.dataset.originalText;
            button.disabled = false;
        }
    };
    
    // 12. DARK MODE DESTEĞİ (BASİT)
    const darkModeToggle = document.getElementById('darkModeToggle');
    if (darkModeToggle) {
        darkModeToggle.addEventListener('click', function() {
            document.body.classList.toggle('dark-mode');
            localStorage.setItem('darkMode', document.body.classList.contains('dark-mode'));
            
            if (document.body.classList.contains('dark-mode')) {
                showToast('Karanlık mod etkinleştirildi.', 'info');
            } else {
                showToast('Aydınlık mod etkinleştirildi.', 'info');
            }
        });
        
        // Sayfa yüklendiğinde dark mode kontrolü
        if (localStorage.getItem('darkMode') === 'true') {
            document.body.classList.add('dark-mode');
        }
    }
    
    // 13. SAYFA YÜKLENİRKEN ANİMASYON
    const fadeElements = document.querySelectorAll('.fade-in');
    fadeElements.forEach(element => {
        element.style.opacity = '0';
        element.style.transform = 'translateY(20px)';
        element.style.transition = 'opacity 0.5s, transform 0.5s';
    });
    
    setTimeout(() => {
        fadeElements.forEach(element => {
            element.style.opacity = '1';
            element.style.transform = 'translateY(0)';
        });
    }, 100);
    
    // 14. COPY TO CLIPBOARD
    const copyButtons = document.querySelectorAll('[data-copy]');
    copyButtons.forEach(button => {
        button.addEventListener('click', function() {
            const textToCopy = this.getAttribute('data-copy');
            navigator.clipboard.writeText(textToCopy).then(() => {
                showToast('Panoya kopyalandı!', 'success');
            });
        });
    });
    
    // 15. AUTO-HIDE ALERTS
    const autoHideAlerts = document.querySelectorAll('.alert-auto-hide');
    autoHideAlerts.forEach(alert => {
        setTimeout(() => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }, 5000);
    });
});

// 16. GLOBAL AJAX HELPER
window.ajaxRequest = async function(url, method = 'GET', data = null) {
    showLoading(document.activeElement);
    
    try {
        const options = {
            method: method,
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        };
        
        if (data && (method === 'POST' || method === 'PUT')) {
            options.body = JSON.stringify(data);
        }
        
        const response = await fetch(url, options);
        const result = await response.json();
        
        hideLoading(document.activeElement);
        
        if (!response.ok) {
            throw new Error(result.message || 'Bir hata oluştu');
        }
        
        return result;
    } catch (error) {
        hideLoading(document.activeElement);
        showToast(error.message || 'İşlem başarısız oldu', 'danger');
        throw error;
    }
};

// 17. FORM SERIALIZE HELPER
window.serializeForm = function(formId) {
    const form = document.getElementById(formId);
    if (!form) return {};
    
    const formData = new FormData(form);
    const data = {};
    
    formData.forEach((value, key) => {
        if (data[key]) {
            if (!Array.isArray(data[key])) {
                data[key] = [data[key]];
            }
            data[key].push(value);
        } else {
            data[key] = value;
        }
    });
    
    return data;
};

// 18. PASSWORD TOGGLE
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('toggle-password')) {
        const input = e.target.previousElementSibling;
        if (input && input.type === 'password') {
            input.type = 'text';
            e.target.classList.remove('fa-eye');
            e.target.classList.add('fa-eye-slash');
        } else if (input) {
            input.type = 'password';
            e.target.classList.remove('fa-eye-slash');
            e.target.classList.add('fa-eye');
        }
    }
});

// 19. AUTO-SAVE DRAFT (FORM İÇİN)
const autoSaveForms = document.querySelectorAll('[data-autosave]');
autoSaveForms.forEach(form => {
    const inputs = form.querySelectorAll('input, textarea, select');
    const saveKey = form.dataset.autosave;
    
    // Kaydedilmiş veriyi yükle
    const savedData = localStorage.getItem(saveKey);
    if (savedData) {
        try {
            const data = JSON.parse(savedData);
            inputs.forEach(input => {
                if (data[input.name]) {
                    input.value = data[input.name];
                }
            });
        } catch (e) {
            console.error('Auto-save verisi yüklenemedi:', e);
        }
    }
    
    // Değişiklikleri dinle
    inputs.forEach(input => {
        input.addEventListener('input', function() {
            const formData = {};
            inputs.forEach(inp => {
                if (inp.name) {
                    formData[inp.name] = inp.value;
                }
            });
            localStorage.setItem(saveKey, JSON.stringify(formData));
        });
    });
    
    // Form gönderildiğinde temizle
    form.addEventListener('submit', function() {
        localStorage.removeItem(saveKey);
    });
});

// 20. PRINT FUNCTIONALITY
window.printPage = function(elementId = null) {
    if (elementId) {
        const element = document.getElementById(elementId);
        if (element) {
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                    <head>
                        <title>Yazdır</title>
                        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
                        <style>
                            @media print {
                                .no-print { display: none !important; }
                                body { font-size: 12pt; }
                            }
                        </style>
                    </head>
                    <body>
                        ${element.innerHTML}
                        <script>
                            window.onload = function() {
                                window.print();
                                window.onafterprint = function() {
                                    window.close();
                                };
                            };
                        <\/script>
                    </body>
                </html>
            `);
            printWindow.document.close();
            return;
        }
    }
    
    window.print();
};