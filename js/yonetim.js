// js/yonetim.js - Tüm yönetim paneli sayfalarında kullanılacak

// YÖNETİM PANELİ GENEL FONKSİYONLARI
document.addEventListener('DOMContentLoaded', function() {
    console.log('Yönetim Paneli Yüklendi - ibiR Cari');
    
    // 1. NAVBAR AKTİF LİNK KONTROLÜ
    const currentPage = window.location.pathname.split('/').pop();
    document.querySelectorAll('.navbar-yonetim .nav-link').forEach(link => {
        const linkHref = link.getAttribute('href');
        if (linkHref && linkHref.includes(currentPage)) {
            link.classList.add('active');
        }
    });
    
    // 2. DROPDOWN HOVER DESTEĞİ
    const dropdowns = document.querySelectorAll('.navbar-yonetim .dropdown');
    dropdowns.forEach(dropdown => {
        dropdown.addEventListener('mouseenter', function() {
            const toggle = this.querySelector('.dropdown-toggle');
            const menu = this.querySelector('.dropdown-menu');
            if (toggle && menu) {
                toggle.classList.add('show');
                menu.classList.add('show');
            }
        });
        
        dropdown.addEventListener('mouseleave', function() {
            const toggle = this.querySelector('.dropdown-toggle');
            const menu = this.querySelector('.dropdown-menu');
            if (toggle && menu) {
                toggle.classList.remove('show');
                menu.classList.remove('show');
            }
        });
    });
    
    // 3. FORM VALIDATION VE SUBMIT KONTROLÜ
    const forms = document.querySelectorAll('.form-yonetim');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            // Butonu disable et
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn && !submitBtn.disabled) {
                const originalText = submitBtn.innerHTML;
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>İşleniyor...';
                submitBtn.dataset.originalText = originalText;
                
                // 30 saniye sonra butonu tekrar aktif et
                setTimeout(() => {
                    if (submitBtn.disabled) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = submitBtn.dataset.originalText;
                        showYonetimToast('İşlem çok uzun sürüyor. Lütfen tekrar deneyin.', 'warning');
                    }
                }, 30000);
            }
            
            // Zorunlu alan kontrolü
            const requiredFields = this.querySelectorAll('[required]');
            let isValid = true;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    isValid = false;
                    field.classList.add('is-invalid');
                    
                    // Hata mesajı ekle
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
                showYonetimToast('Lütfen tüm zorunlu alanları doldurun.', 'warning');
                
                // Butonu tekrar aktif et
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = submitBtn.dataset.originalText;
                }
            }
        });
    });
    
    // 4. AUTO-DISMISS ALERTS
    const alerts = document.querySelectorAll('.alert-yonetim, .alert');
    alerts.forEach(alert => {
        if (alert.classList.contains('alert-auto-hide')) {
            setTimeout(() => {
                const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                bsAlert.close();
            }, 5000);
        }
    });
    
    // 5. TABLO SORTING (Eğer başlıklara data-sort eklersen)
    const sortableHeaders = document.querySelectorAll('th[data-sort]');
    sortableHeaders.forEach(header => {
        header.style.cursor = 'pointer';
        header.addEventListener('click', function() {
            const table = this.closest('table');
            const sortBy = this.dataset.sort;
            const isAsc = this.classList.contains('sort-asc');
            
            // Tüm başlıklardan sort class'larını kaldır
            sortableHeaders.forEach(h => {
                h.classList.remove('sort-asc', 'sort-desc');
            });
            
            // Yeni sıralama yönü
            this.classList.toggle('sort-asc', !isAsc);
            this.classList.toggle('sort-desc', isAsc);
            
            // Tabloyu sırala
            sortTable(table, sortBy, !isAsc);
        });
    });
    
    // 6. MODAL YÖNETİMİ
    window.openYonetimModal = function(modalId) {
        const modalElement = document.getElementById(modalId);
        if (modalElement) {
            const modal = new bootstrap.Modal(modalElement);
            modal.show();
        }
    };
    
    window.closeYonetimModal = function(modalId) {
        const modalElement = document.getElementById(modalId);
        if (modalElement) {
            const modal = bootstrap.Modal.getInstance(modalElement);
            if (modal) {
                modal.hide();
            }
        }
    };
    
    // 7. TARİH VE PARA FORMATLAMA
    const dateInputs = document.querySelectorAll('input[type="date"]');
    dateInputs.forEach(input => {
        if (!input.value) {
            input.valueAsDate = new Date();
        }
    });
    
    const moneyInputs = document.querySelectorAll('input[data-money]');
    moneyInputs.forEach(input => {
        input.addEventListener('blur', function() {
            const value = parseFloat(this.value);
            if (!isNaN(value)) {
                this.value = formatNumber(value);
            }
        });
        
        input.addEventListener('focus', function() {
            this.value = this.value.replace(/[^\d.-]/g, '');
        });
    });
    
    // 8. SEARCH FUNCTIONALITY
    const searchInputs = document.querySelectorAll('.search-input');
    searchInputs.forEach(input => {
        let searchTimeout;
        input.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                performSearch(this.value);
            }, 500);
        });
    });
    
    // 9. SIDEBAR TOGGLE (Eğer sidebar kullanıyorsan)
    const sidebarToggle = document.getElementById('sidebarToggle');
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function() {
            document.querySelector('.sidebar-yonetim').classList.toggle('collapsed');
            document.querySelector('.content-yonetim').classList.toggle('expanded');
        });
    }
    
    // 10. BULK ACTIONS
    const selectAllCheckbox = document.getElementById('selectAll');
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.item-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });
    }
    
    // 11. EXPORT BUTTONS
    document.querySelectorAll('.btn-export').forEach(btn => {
        btn.addEventListener('click', function() {
            const format = this.dataset.format || 'excel';
            const tableId = this.dataset.table || 'dataTable';
            exportData(tableId, format);
        });
    });
    
    // 12. INITIAL LOADING
    window.showLoading = function(element) {
        if (element) {
            const originalContent = element.innerHTML;
            element.innerHTML = '<div class="spinner-yonetim"></div>';
            element.dataset.originalContent = originalContent;
        }
    };
    
    window.hideLoading = function(element) {
        if (element && element.dataset.originalContent) {
            element.innerHTML = element.dataset.originalContent;
        }
    };
    
    // 13. MÜŞTERİ EKLEME FORMUNU BAŞLAT
    initMusteriEkleForm();
    
    // 14. Sayfa yüklendiğinde ilk yükleme
    setTimeout(() => {
        console.log('Yönetim paneli hazır!');
    }, 100);
});

// MÜŞTERİ EKLEME FORM ÖZELLİKLERİ
function initMusteriEkleForm() {
    const form = document.querySelector('form[method="POST"]');
    if (!form) return;
    
    // Telefon formatı
    const telefonInput = form.querySelector('input[name="telefon"]');
    if (telefonInput) {
        telefonInput.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 0) {
                value = value.match(new RegExp('.{1,4}', 'g')).join(' ');
            }
            e.target.value = value;
        });
    }
    
    // Tarih inputlarına bugünü varsayılan yap
    const tarihInputs = form.querySelectorAll('input[type="date"]');
    tarihInputs.forEach(input => {
        if (!input.value && input.name === 'anlasma_tarihi') {
            input.valueAsDate = new Date();
        }
        
        // Gelecek tarih kontrolü (hizmet tarihi için)
        if (input.name === 'hizmet_tarihi') {
            input.addEventListener('change', function() {
                const selectedDate = new Date(this.value);
                const today = new Date();
                today.setHours(0,0,0,0);
                
                if (selectedDate < today) {
                    showYonetimToast('Hizmet tarihi geçmiş bir tarih olamaz!', 'warning');
                    this.value = '';
                }
            });
        }
    });
    
    // Ad Soyad büyük harf başlangıç
    const adSoyadInput = form.querySelector('input[name="ad_soyad"]');
    if (adSoyadInput) {
        adSoyadInput.addEventListener('blur', function() {
            if (this.value) {
                // Her kelimenin baş harfini büyük yap
                this.value = this.value.toLowerCase()
                    .split(' ')
                    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
                    .join(' ');
            }
        });
    }
    
    // TC/Vergi No formatı
    const tcInput = form.querySelector('input[name="tc_vergi_no"]');
    if (tcInput) {
        tcInput.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            e.target.value = value;
            
            // TC no 11, Vergi no 10 haneli olmalı
            if (value.length > 0) {
                if (value.length === 11 || value.length === 10) {
                    e.target.classList.remove('is-invalid');
                    e.target.classList.add('is-valid');
                } else {
                    e.target.classList.remove('is-valid');
                    e.target.classList.add('is-invalid');
                }
            } else {
                e.target.classList.remove('is-valid', 'is-invalid');
            }
        });
    }
    
    // Form gönderim animasyonu
    form.addEventListener('submit', function(e) {
        const submitBtn = this.querySelector('button[type="submit"]');
        if (submitBtn) {
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Kaydediliyor...';
            submitBtn.disabled = true;
            
            // 10 saniye sonra zaman aşımı
            setTimeout(() => {
                if (submitBtn.disabled) {
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                    showYonetimToast('İşlem zaman aşımına uğradı. Lütfen tekrar deneyin.', 'warning');
                }
            }, 10000);
        }
        
        // Gerekli alan kontrolü
        const requiredFields = this.querySelectorAll('[required]');
        let missingFields = [];
        
        requiredFields.forEach(field => {
            if (!field.value.trim()) {
                missingFields.push(field.name);
                field.classList.add('is-invalid');
            } else {
                field.classList.remove('is-invalid');
            }
        });
        
        if (missingFields.length > 0) {
            e.preventDefault();
            showYonetimToast('Zorunlu alanları doldurun: ' + missingFields.map(f => {
                const labels = {
                    'ad_soyad': 'Ad Soyad',
                    'telefon': 'Telefon'
                };
                return labels[f] || f;
            }).join(', '), 'error');
            
            // Butonu geri yükle
            if (submitBtn) {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }
            
            // İlk hatalı alana focus
            if (requiredFields[0]) {
                requiredFields[0].focus();
            }
        }
    });
}

// YARDIMCI FONKSİYONLAR
function sortTable(table, columnIndex, ascending = true) {
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    
    rows.sort((a, b) => {
        const aValue = a.children[columnIndex].textContent.trim();
        const bValue = b.children[columnIndex].textContent.trim();
        
        if (!isNaN(aValue) && !isNaN(bValue)) {
            return ascending ? aValue - bValue : bValue - aValue;
        }
        
        return ascending 
            ? aValue.localeCompare(bValue)
            : bValue.localeCompare(aValue);
    });
    
    // Sıralanmış satırları ekle
    rows.forEach(row => tbody.appendChild(row));
}

function performSearch(query) {
    const searchableTables = document.querySelectorAll('.searchable-table');
    searchableTables.forEach(table => {
        const rows = table.querySelectorAll('tbody tr');
        const searchLower = query.toLowerCase();
        
        rows.forEach(row => {
            const rowText = row.textContent.toLowerCase();
            row.style.display = rowText.includes(searchLower) ? '' : 'none';
        });
    });
}

function exportData(tableId, format) {
    const table = document.getElementById(tableId);
    if (!table) return;
    
    let content, mimeType, extension;
    
    switch(format) {
        case 'excel':
            content = table.outerHTML;
            mimeType = 'application/vnd.ms-excel';
            extension = 'xls';
            break;
        case 'csv':
            content = tableToCSV(table);
            mimeType = 'text/csv';
            extension = 'csv';
            break;
        case 'pdf':
            // PDF için jsPDF kütüphanesi gerekli
            window.print();
            return;
        default:
            return;
    }
    
    const blob = new Blob([content], { type: mimeType });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `export_${Date.now()}.${extension}`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
    
    showYonetimToast(`${format.toUpperCase()} formatında dışa aktarıldı.`, 'success');
}

function tableToCSV(table) {
    const rows = table.querySelectorAll('tr');
    const csv = [];
    
    rows.forEach(row => {
        const rowData = [];
        const cells = row.querySelectorAll('th, td');
        
        cells.forEach(cell => {
            let cellText = cell.textContent.trim();
            // CSV için özel karakterleri temizle
            cellText = cellText.replace(/"/g, '""');
            if (cellText.includes(',') || cellText.includes('"') || cellText.includes('\n')) {
                cellText = `"${cellText}"`;
            }
            rowData.push(cellText);
        });
        
        csv.push(rowData.join(','));
    });
    
    return csv.join('\n');
}

// SAYI FORMATLAMA
function formatNumber(num) {
    return new Intl.NumberFormat('tr-TR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }).format(num);
}

// TELEFON FORMATLAMA
function formatPhone(phone) {
    if (!phone) return '';
    const cleaned = phone.replace(/\D/g, '');
    
    // Türkiye telefon formatı: 0xxx xxx xx xx
    if (cleaned.length === 10) {
        // 10 haneli numara (0xxxxxxxxx)
        return cleaned.replace(/(\d{1})(\d{3})(\d{3})(\d{2})(\d{1})/, '$1$2 $3 $4 $5');
    } else if (cleaned.length === 11) {
        // 11 haneli numara (0xxxxxxxxxx)
        return cleaned.replace(/(\d{1})(\d{3})(\d{3})(\d{2})(\d{2})/, '$1$2 $3 $4 $5');
    }
    return phone;
}

// TARİH FORMATLAMA
function formatDate(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    return date.toLocaleDateString('tr-TR');
}

// YÖNETİM PANELİ ÖZEL TOAST
function showYonetimToast(message, type = 'info') {
    const toastContainer = document.getElementById('toast-container-yonetim');
    let container;
    
    if (!toastContainer) {
        container = document.createElement('div');
        container.id = 'toast-container-yonetim';
        container.style.cssText = `
            position: fixed;
            top: 80px;
            right: 20px;
            z-index: 9999;
            max-width: 350px;
        `;
        document.body.appendChild(container);
    } else {
        container = toastContainer;
    }
    
    const toastId = 'toast-' + Date.now();
    const toast = document.createElement('div');
    toast.id = toastId;
    toast.className = 'toast show fade-in';
    toast.style.cssText = `
        background: white;
        border-left: 4px solid ${type === 'success' ? '#28a745' : type === 'error' ? '#dc3545' : type === 'warning' ? '#ffc107' : '#17a2b8'};
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 10px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        display: flex;
        align-items: center;
        justify-content: space-between;
        animation: slideInLeft 0.3s ease-out;
    `;
    
    const icon = type === 'success' ? 'fa-check-circle' :
                 type === 'error' ? 'fa-exclamation-circle' :
                 type === 'warning' ? 'fa-exclamation-triangle' : 'fa-info-circle';
    
    const iconColor = type === 'success' ? '#28a745' :
                     type === 'error' ? '#dc3545' :
                     type === 'warning' ? '#d39e00' : '#17a2b8';
    
    toast.innerHTML = `
        <div class="d-flex align-items-center">
            <i class="fas ${icon} fa-lg me-3" style="color: ${iconColor}"></i>
            <span>${message}</span>
        </div>
        <button type="button" class="btn-close btn-close-sm" onclick="document.getElementById('${toastId}').remove()"></button>
    `;
    
    container.appendChild(toast);
    
    // 5 saniye sonra otomatik kaldır
    setTimeout(() => {
        if (document.getElementById(toastId)) {
            toast.style.animation = 'fadeOut 0.3s ease-out';
            setTimeout(() => {
                if (document.getElementById(toastId)) {
                    document.getElementById(toastId).remove();
                }
            }, 300);
        }
    }, 5000);
}

// Sayfa çıkışında temizlik
window.addEventListener('beforeunload', function() {
    // Form submit butonlarını temizle
    document.querySelectorAll('button[type="submit"]').forEach(btn => {
        if (btn.disabled && btn.dataset.originalText) {
            btn.disabled = false;
            btn.innerHTML = btn.dataset.originalText;
        }
    });
});

// Sayfa görünürlüğü değiştiğinde
document.addEventListener('visibilitychange', function() {
    if (document.visibilityState === 'visible') {
        console.log('Yönetim paneline dönüldü');
    }
});