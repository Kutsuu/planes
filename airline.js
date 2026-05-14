const airlineForm = document.querySelector('#airlineForm');
const airlineInput = document.querySelector('#airlineInput');
const airlineStatus = document.querySelector('#airlineStatus');
const airlineTitle = document.querySelector('#airlineTitle');
const airlineFlights = document.querySelector('#airlineFlights');
const scanForm = document.querySelector('#scanForm');
const scanAirports = document.querySelector('#scanAirports');
const scanRefresh = document.querySelector('#scanRefresh');
const scanStatus = document.querySelector('#scanStatus');
const scanResults = document.querySelector('#scanResults');

airlineForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    await searchAirlineFlights(airlineInput.value);
});

airlineInput.addEventListener('input', () => {
    airlineInput.value = airlineInput.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 3);
});

scanForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    await scanAirportCache();
});

async function searchAirlineFlights(rawCode) {
    const icao = rawCode.trim().toUpperCase();

    if (!/^[A-Z0-9]{3}$/.test(icao)) {
        setAirlineStatus('Sisesta 3-kohaline lennufirma ICAO kood, naiteks SAS, BTI voi KLM.', true);
        return;
    }

    setAirlineLoading(true);
    setAirlineStatus('Parin flight numbreid ja marsruute...');

    try {
        const data = await api(`api.php?action=airline-flights&icao=${encodeURIComponent(icao)}`);
        renderAirlineFlights(data);
        setAirlineStatus(`${data.flights.length} lendu. Allikas: ${sourceLabel(data.source)}.`);
    } catch (error) {
        renderEmpty(airlineFlights, error.message);
        setAirlineStatus(error.message, true);
    } finally {
        setAirlineLoading(false);
    }
}

async function scanAirportCache() {
    const airports = scanAirports.value.trim().toUpperCase();

    if (!airports) {
        setScanStatus('Sisesta vahemalt uks lennujaama ICAO kood.', true);
        return;
    }

    setScanLoading(true);
    setScanStatus('Skaneerin lennujaamu...');
    scanResults.innerHTML = '';

    try {
        const data = await api('api.php?action=scan-airports', {
            method: 'POST',
            body: JSON.stringify({
                airports,
                refresh: scanRefresh.checked,
            }),
        });

        renderScanResults(data);
        setScanStatus(`${data.totals.airports} lennujaama, ${data.totals.flights} lendu. API paringuid: ${data.totals.api_requests}.`);

        const currentCode = airlineInput.value.trim().toUpperCase();
        if (/^[A-Z0-9]{3}$/.test(currentCode)) {
            await searchAirlineFlights(currentCode);
        }
    } catch (error) {
        setScanStatus(error.message, true);
    } finally {
        setScanLoading(false);
    }
}

async function api(url, options = {}) {
    if (window.location.protocol === 'file:') {
        throw new Error('Ava leht XAMPP Apache kaudu: http://localhost/planes/');
    }

    let response;
    try {
        response = await fetch(url, {
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            ...options,
        });
    } catch (error) {
        throw new Error('Ei saa api.php-ga uhendust. Kontrolli, et XAMPP Apache tootab ja ava http://localhost/planes/.');
    }

    const data = await response.json().catch(() => ({}));

    if (!response.ok || data.error) {
        throw new Error(data.error || 'Paring ebaonnestus.');
    }

    return data;
}

function renderAirlineFlights(data) {
    const airline = data.airline || {};
    const titleParts = [airline.icao || airlineInput.value.toUpperCase()];
    if (airline.name && airline.name !== airline.icao) {
        titleParts.push(airline.name);
    }
    airlineTitle.textContent = titleParts.join(' - ');

    if (!data.flights.length) {
        renderEmpty(airlineFlights, 'Selle lennufirma kohta flight numbreid ei leitud.');
        return;
    }

    airlineFlights.classList.remove('empty-state');
    airlineFlights.innerHTML = data.flights.map((flight) => `
        <div class="flight-card large">
            <div class="flight-number-block">
                <span class="flight-label">Flight</span>
                <span class="flight-number">${escapeHtml(flight.number)}</span>
            </div>
            <div class="route-card">
                ${renderAirportBox('Valjub', flight.departure)}
                <div class="route-arrow">&rarr;</div>
                ${renderAirportBox('Saabub', flight.arrival)}
            </div>
            <div class="flight-status">${escapeHtml(flight.status || flight.call_sign || 'Teadmata')}</div>
        </div>
    `).join('');
}

function renderScanResults(data) {
    scanResults.innerHTML = (data.airports || []).map((item) => `
        <div class="scan-result ${item.source === 'error' ? 'error' : ''}">
            <div>
                <strong>${escapeHtml(item.icao)}</strong>
                <span>${escapeHtml(sourceLabel(item.source))}</span>
            </div>
            <div>${Number(item.airlines)} lennufirmat</div>
            <div>${Number(item.flights)} lendu</div>
            ${item.error ? `<div class="scan-error">${escapeHtml(item.error)}</div>` : ''}
        </div>
    `).join('');
}

function renderAirportBox(label, airport = {}) {
    return `
        <div class="airport-box">
            <span class="airport-label">${label}</span>
            <span class="airport-code">${escapeHtml(airportCode(airport))}</span>
            <span class="airport-name">${escapeHtml(airport.name || airport.time_local || '')}</span>
        </div>
    `;
}

function airportCode(airport = {}) {
    return airport.icao || airport.iata || airport.name || '?';
}

function sourceLabel(source) {
    if (source === 'sqlite-cache') return 'SQLite vahemalu';
    if (source === 'sqlite-airport-cache') return 'SQLite lennujaama vahemalu';
    if (source === 'error') return 'Viga';
    return 'AeroDataBox API';
}

function renderEmpty(target, message) {
    target.classList.add('empty-state');
    target.innerHTML = escapeHtml(message);
}

function setAirlineLoading(isLoading) {
    airlineForm.querySelector('button').disabled = isLoading;
}

function setScanLoading(isLoading) {
    scanForm.querySelector('button').disabled = isLoading;
}

function setAirlineStatus(message, isError = false) {
    airlineStatus.textContent = message;
    airlineStatus.classList.toggle('error', isError);
}

function setScanStatus(message, isError = false) {
    scanStatus.textContent = message;
    scanStatus.classList.toggle('error', isError);
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

const initialCode = new URLSearchParams(window.location.search).get('icao');
if (initialCode) {
    airlineInput.value = initialCode.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 3);
    searchAirlineFlights(airlineInput.value);
}
