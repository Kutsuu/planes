const AIRPORT_ALIASES = {
    TLL: 'EETN',
    TALLINN: 'EETN',
    TALLINNAIRPORT: 'EETN',
    ARN: 'ESSA',
    STOCKHOLM: 'ESSA',
    ARLANDA: 'ESSA',
    STOCKHOLMARLANDA: 'ESSA',
    HEL: 'EFHK',
    HELSINKI: 'EFHK',
    RIX: 'EVRA',
    RIGA: 'EVRA',
    CPH: 'EKCH',
    COPENHAGEN: 'EKCH',
    OSL: 'ENGM',
    OSLO: 'ENGM',
    VNO: 'EYVI',
    VILNIUS: 'EYVI',
};

const AIRLINE_ALIASES = {
    BT: 'BTI',
    AIRBALTIC: 'BTI',
    SAS: 'SAS',
    SK: 'SAS',
    SCANDINAVIAN: 'SAS',
    FINNAIR: 'FIN',
    AY: 'FIN',
    LOT: 'LOT',
    LO: 'LOT',
    LUFTHANSA: 'DLH',
    LH: 'DLH',
    RYANAIR: 'RYR',
    FR: 'RYR',
    KLM: 'KLM',
    TURKISH: 'THY',
    TK: 'THY',
};

const modeTabs = document.querySelectorAll('.mode-tab');
const routeForm = document.querySelector('#routeForm');
const airportAirlineForm = document.querySelector('#airportAirlineForm');
const fromInput = document.querySelector('#fromInput');
const toInput = document.querySelector('#toInput');
const airportInput = document.querySelector('#airportInput');
const airlineInput = document.querySelector('#airlineInput');
const directionInput = document.querySelector('#directionInput');
const routeRefresh = document.querySelector('#routeRefresh');
const airlineRefresh = document.querySelector('#airlineRefresh');
const statusEl = document.querySelector('#status');
const resultsTitle = document.querySelector('#resultsTitle');
const summaryStats = document.querySelector('#summaryStats');
const resultsList = document.querySelector('#resultsList');
const scanForm = document.querySelector('#scanForm');
const scanAirports = document.querySelector('#scanAirports');
const scanRefresh = document.querySelector('#scanRefresh');
const scanStatus = document.querySelector('#scanStatus');
const scanResults = document.querySelector('#scanResults');

let activeMode = 'route';

modeTabs.forEach((tab) => {
    tab.addEventListener('click', () => setMode(tab.dataset.mode));
});

routeForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    await searchRoute();
});

airportAirlineForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    await searchAirportAirline();
});

scanForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    await scanAirportCache();
});

[fromInput, toInput, airportInput].forEach((input) => {
    input.addEventListener('input', () => {
        input.value = input.value.toUpperCase();
    });
});

airlineInput.addEventListener('input', () => {
    airlineInput.value = airlineInput.value.toUpperCase();
});

async function searchRoute() {
    const from = normalizeAirport(fromInput.value);
    const to = normalizeAirport(toInput.value);

    if (!isIcaoAirport(from) || !isIcaoAirport(to)) {
        setStatus('Sisesta lennujaama ICAO kood voi tuntud alias, naiteks EETN, TLL, ESSA voi ARN.', true);
        return;
    }

    setMainLoading(true);
    setStatus('Otsin marsruuti...');

    try {
        const data = await api(`api.php?action=planner-route&from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}&refresh=${routeRefresh.checked ? '1' : '0'}`);
        renderPlannerResults(data);
        setStatus(`${data.flights.length} lendu. Allikas: ${sourceLabel(data.source)}.`);
    } catch (error) {
        renderEmpty(resultsList, error.message);
        setStatus(error.message, true);
    } finally {
        setMainLoading(false);
    }
}

async function searchAirportAirline() {
    const airport = normalizeAirport(airportInput.value);
    const airline = normalizeAirline(airlineInput.value);

    if (!isIcaoAirport(airport)) {
        setStatus('Sisesta lennujaama ICAO kood voi tuntud alias, naiteks EETN voi TLL.', true);
        return;
    }

    if (!/^[A-Z0-9]{3}$/.test(airline)) {
        setStatus('Sisesta lennufirma ICAO kood voi tuntud alias, naiteks BTI, BT, SAS voi SK.', true);
        return;
    }

    setMainLoading(true);
    setStatus('Otsin lennufirma marsruute...');

    try {
        const params = new URLSearchParams({
            action: 'planner-airport-airline',
            airport,
            airline,
            direction: directionInput.value,
            refresh: airlineRefresh.checked ? '1' : '0',
        });
        const data = await api(`api.php?${params.toString()}`);
        renderPlannerResults(data);
        setStatus(`${data.flights.length} lendu. Allikas: ${sourceLabel(data.source)}.`);
    } catch (error) {
        renderEmpty(resultsList, error.message);
        setStatus(error.message, true);
    } finally {
        setMainLoading(false);
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

function setMode(mode) {
    activeMode = mode;
    modeTabs.forEach((tab) => tab.classList.toggle('active', tab.dataset.mode === mode));
    routeForm.classList.toggle('hidden', mode !== 'route');
    airportAirlineForm.classList.toggle('hidden', mode !== 'airline');
    setStatus('');
}

function renderPlannerResults(data) {
    resultsTitle.textContent = data.title || 'Tulemused';
    renderSummary(data);

    if (!data.flights.length) {
        renderEmpty(resultsList, 'Selle otsingu kohta lende ei leitud. Proovi teist lennujaama, lennufirmat voi varskenda API-st.');
        return;
    }

    resultsList.classList.remove('empty-state');
    resultsList.innerHTML = data.airlines.map(renderAirlineGroup).join('');
}

function renderSummary(data) {
    const airlines = data.airlines?.length || 0;
    const flights = data.flights?.length || 0;
    const source = sourceLabel(data.source);

    summaryStats.innerHTML = `
        <div class="summary-card">
            <span>Lennud</span>
            <strong>${flights}</strong>
        </div>
        <div class="summary-card">
            <span>Lennufirmad</span>
            <strong>${airlines}</strong>
        </div>
        <div class="summary-card">
            <span>Allikas</span>
            <strong>${escapeHtml(source)}</strong>
        </div>
    `;
}

function renderAirlineGroup(airline) {
    return `
        <article class="airline-card planner-group">
            <div class="airline-card-header">
                <div class="airline-main">
                    <div class="airline-name">${escapeHtml(airline.name || airline.icao || 'Unknown')}</div>
                    <div class="meta">${renderTags(airline)}</div>
                </div>
                <div class="result-count">${airline.flights.length} lendu</div>
            </div>
            <div class="flight-list">
                ${airline.flights.map(renderFlightCard).join('')}
            </div>
        </article>
    `;
}

function renderFlightCard(flight) {
    return `
        <div class="flight-card">
            <div class="flight-number-block">
                <span class="flight-label">Flight</span>
                <span class="flight-number">${escapeHtml(flight.number)}</span>
            </div>
            <div class="route-card">
                ${renderAirportBox('Valjub', flight.departure)}
                <div class="route-arrow">&rarr;</div>
                ${renderAirportBox('Saabub', flight.arrival)}
            </div>
            <div class="flight-side">
                <span class="flight-status">${escapeHtml(flight.status || 'Planned')}</span>
                ${flight.call_sign ? `<span class="call-sign">${escapeHtml(flight.call_sign)}</span>` : ''}
            </div>
        </div>
    `;
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

function normalizeAirport(value) {
    const key = String(value || '').trim().toUpperCase().replace(/[^A-Z0-9]/g, '');
    return AIRPORT_ALIASES[key] || key;
}

function normalizeAirline(value) {
    const key = String(value || '').trim().toUpperCase().replace(/[^A-Z0-9]/g, '');
    return AIRLINE_ALIASES[key] || key;
}

function isIcaoAirport(value) {
    return /^[A-Z0-9]{4}$/.test(value);
}

function airportCode(airport = {}) {
    return airport.icao || airport.iata || airport.name || '?';
}

function renderTags(item) {
    const tags = [];
    if (item.iata) tags.push(`<span class="tag">IATA ${escapeHtml(item.iata)}</span>`);
    if (item.icao) tags.push(`<span class="tag">ICAO ${escapeHtml(item.icao)}</span>`);
    if (item.callsign) tags.push(`<span class="tag">${escapeHtml(item.callsign)}</span>`);

    return tags.length ? tags.join('') : '<span class="tag">Kood puudub</span>';
}

function sourceLabel(source) {
    if (source === 'sqlite-cache') return 'SQLite cache';
    if (source === 'sqlite-airport-cache') return 'SQLite airport cache';
    if (source === 'error') return 'Viga';
    return 'AeroDataBox API';
}

function renderEmpty(target, message) {
    target.classList.add('empty-state');
    target.innerHTML = escapeHtml(message);
}

function setMainLoading(isLoading) {
    routeForm.querySelector('button[type="submit"]').disabled = isLoading;
    airportAirlineForm.querySelector('button[type="submit"]').disabled = isLoading;
}

function setScanLoading(isLoading) {
    scanForm.querySelector('button[type="submit"]').disabled = isLoading;
}

function setStatus(message, isError = false) {
    statusEl.textContent = message;
    statusEl.classList.toggle('error', isError);
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
