const form = document.getElementById('search-form');
const statusEl = document.getElementById('status');
const reportEl = document.getElementById('report');
const tableBody = document.querySelector('#report-table tbody');
const companyNameEl = document.getElementById('company-name');
const reportMetaEl = document.getElementById('report-meta');
const copyrightEl = document.getElementById('copyright');

const currentYear = new Date().getFullYear();
if (copyrightEl) {
    copyrightEl.textContent = `\u00a9 ${currentYear} ReadIDX. Dibuat untuk demonstrasi.`;
}

function formatNumber(value) {
    return new Intl.NumberFormat('id-ID', {
        maximumFractionDigits: 2,
    }).format(value);
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

form?.addEventListener('submit', async (event) => {
    event.preventDefault();

    const formData = new FormData(form);
    const payload = {
        ticker: formData.get('ticker'),
        year: formData.get('year'),
        quarter: formData.get('quarter'),
    };

    statusEl.textContent = 'Mengambil data...';
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

        statusEl.textContent = 'Laporan berhasil dimuat.';
        reportEl.classList.remove('hidden');
    } catch (error) {
        statusEl.textContent = error.message;
        reportEl.classList.add('hidden');
        reportEl.classList.add('error');
    }
});
