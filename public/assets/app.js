const form = document.getElementById('search-form');
const statusEl = document.getElementById('status');
const reportEl = document.getElementById('report');
const tableBody = document.querySelector('#report-table tbody');
const companyNameEl = document.getElementById('company-name');
const reportMetaEl = document.getElementById('report-meta');
const listStatusEl = document.getElementById('list-status');
const reportsTableBody = document.querySelector('#reports-table tbody');
const uploadForm = document.getElementById('upload-form');
const uploadStatusEl = document.getElementById('upload-status');
const uploadYearInput = document.getElementById('upload-year');
const searchYearInput = document.getElementById('year');
const copyrightEl = document.getElementById('copyright');

const currentYear = new Date().getFullYear();
if (copyrightEl) {
    copyrightEl.textContent = `\u00a9 ${currentYear} ReadIDX. Dibuat untuk demonstrasi.`;
}

function setStatus(element, message, type = 'info') {
    if (!element) {
        return;
    }

    element.textContent = message;
    element.classList.remove('error', 'success');

    if (type === 'error') {
        element.classList.add('error');
    } else if (type === 'success') {
        element.classList.add('success');
    }
}

function formatNumber(value) {
    return new Intl.NumberFormat('id-ID', {
        maximumFractionDigits: 2,
    }).format(value);
}

function formatDateTime(value) {
    if (!value) {
        return '-';
    }

    const normalized = value.replace(' ', 'T');
    const date = new Date(normalized);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleString('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

async function fetchReport(payload) {
    const response = await fetch('api/report.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(payload),
    });

    if (!response.ok) {
        const error = await response.json().catch(() => ({ message: 'Terjadi kesalahan.' }));
        throw new Error(error.message || 'Terjadi kesalahan.');
    }

    const data = await response.json();
    if (data.status !== 'success') {
        throw new Error(data.message || 'Terjadi kesalahan.');
    }

    return data.data;
}

async function fetchReportsList() {
    const response = await fetch('api/reports.php');

    if (!response.ok) {
        throw new Error('Gagal mengambil daftar laporan.');
    }

    const data = await response.json();
    if (data.status !== 'success') {
        throw new Error(data.message || 'Gagal mengambil daftar laporan.');
    }

    return data.data;
}

function renderReportList(reports) {
    if (!reportsTableBody || !listStatusEl) {
        return;
    }

    reportsTableBody.innerHTML = '';

    if (!Array.isArray(reports) || reports.length === 0) {
        setStatus(listStatusEl, 'Belum ada laporan keuangan yang tersimpan.');
        return;
    }

    setStatus(listStatusEl, `Menampilkan ${reports.length} laporan yang tersimpan.`);

    reports.forEach((report) => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${report.company.ticker}</td>
            <td>${report.company.name}</td>
            <td class="numeric">${report.fiscal_year}</td>
            <td class="numeric">Q${report.fiscal_quarter}</td>
            <td class="numeric">${report.line_count}</td>
            <td>${report.source_file || '-'}</td>
            <td>${formatDateTime(report.last_updated_at || report.created_at)}</td>
            <td><button type="button" class="link-button js-view-report" data-ticker="${report.company.ticker}" data-year="${report.fiscal_year}" data-quarter="${report.fiscal_quarter}">Lihat</button></td>
        `;
        reportsTableBody.appendChild(row);
    });
}

async function loadReportList() {
    if (!listStatusEl) {
        return;
    }

    setStatus(listStatusEl, 'Memuat daftar laporan...');

    try {
        const reports = await fetchReportsList();
        renderReportList(reports);
    } catch (error) {
        setStatus(listStatusEl, error.message, 'error');
    }
}

form?.addEventListener('submit', async (event) => {
    event.preventDefault();

    const formData = new FormData(form);
    const payload = {
        ticker: formData.get('ticker'),
        year: formData.get('year'),
        quarter: formData.get('quarter'),
    };

    setStatus(statusEl, 'Mengambil data...');
    reportEl.classList.add('hidden');
    reportEl.classList.remove('error');

    try {
        const report = await fetchReport(payload);

        companyNameEl.textContent = `${report.company.name} (${report.company.ticker})`;
        reportMetaEl.textContent = `Tahun ${report.fiscal_year} • Kuartal ${report.fiscal_quarter}`;

        tableBody.innerHTML = '';
        report.lines.forEach((line) => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${line.line_item}</td>
                <td class="numeric">${formatNumber(line.value)}</td>
                <td>${line.unit}</td>
            `;
            tableBody.appendChild(row);
        });

        setStatus(statusEl, 'Laporan berhasil dimuat.', 'success');
        reportEl.classList.remove('hidden');
    } catch (error) {
        setStatus(statusEl, error.message, 'error');
        reportEl.classList.add('hidden');
        reportEl.classList.add('error');
    }
});

reportsTableBody?.addEventListener('click', (event) => {
    const button = event.target.closest('.js-view-report');
    if (!button || !(button instanceof HTMLButtonElement)) {
        return;
    }

    const ticker = button.dataset.ticker || '';
    const year = button.dataset.year || '';
    const quarter = button.dataset.quarter || '';

    if (form) {
        const tickerInput = form.querySelector('#ticker');
        const yearInput = form.querySelector('#year');
        const quarterSelect = form.querySelector('#quarter');

        if (tickerInput instanceof HTMLInputElement) {
            tickerInput.value = ticker;
        }
        if (yearInput instanceof HTMLInputElement) {
            yearInput.value = year;
        }
        if (quarterSelect instanceof HTMLSelectElement) {
            quarterSelect.value = quarter;
        }

        form.requestSubmit();
        window.scrollTo({ top: form.offsetTop - 80, behavior: 'smooth' });
    }
});

uploadForm?.addEventListener('submit', async (event) => {
    event.preventDefault();

    const formData = new FormData(uploadForm);

    setStatus(uploadStatusEl, 'Mengunggah dan memproses laporan...');

    try {
        const response = await fetch('api/upload.php', {
            method: 'POST',
            body: formData,
        });

        if (!response.ok) {
            const error = await response.json().catch(() => ({ message: 'Gagal mengunggah laporan.' }));
            throw new Error(error.message || 'Gagal mengunggah laporan.');
        }

        const data = await response.json();
        if (data.status !== 'success') {
            throw new Error(data.message || 'Gagal mengunggah laporan.');
        }

        setStatus(uploadStatusEl, data.message || 'Laporan berhasil diproses.', 'success');

        if (form) {
            const tickerInput = form.querySelector('#ticker');
            const yearInput = form.querySelector('#year');
            const quarterSelect = form.querySelector('#quarter');

            if (tickerInput instanceof HTMLInputElement) {
                tickerInput.value = data.data.company.ticker;
            }
            if (yearInput instanceof HTMLInputElement) {
                yearInput.value = data.data.fiscal_year;
            }
            if (quarterSelect instanceof HTMLSelectElement) {
                quarterSelect.value = String(data.data.fiscal_quarter);
            }

            form.requestSubmit();
        }

        loadReportList();
    } catch (error) {
        setStatus(uploadStatusEl, error.message, 'error');
    }
});

if (uploadYearInput) {
    uploadYearInput.value = currentYear;
}

if (searchYearInput) {
    searchYearInput.value = currentYear;
}

loadReportList();
