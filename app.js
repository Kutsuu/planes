const form = document.querySelector('#searchForm');
const input = document.querySelector('#icaoInput');
const statusEl = document.querySelector('#status');
const airportTitle = document.querySelector('#airportTitle');
const airlineList = document.querySelector('#airlineList');
const saveAirportBtn = document.querySelector('#saveAirportBtn');
const favoriteAirports = document.querySelector('#favoriteAirports');
const favoriteAirlines = document.querySelector('#favoriteAirlines');

let currentAirport = null;
let currentAirlines = [];

form.addEventListener('submit', async (event) => {
    event.preventDefault();
    await searchAirlines(input.value);
});

saveAirportBtn.addEventListener('click', async () => {
    if (!currentAirport) return;

    await api('api.php?action=favorite-airport', {
        method: 'POST',
        body: JSON.stringify(currentAirport),
    });
    await loadFavorites();
    setStatus(`${currentAirport.icao} salvestati lemmikutesse.`);
});

favoriteAirports.addEventListener('click', async (event) => {
    const searchBtn = event.target.closest('[data-search-airport]');
    const deleteBtn = event.target.closest('[data-delete-airport]');

    if (searchBtn) {
        input.value = searchBtn.dataset.searchAirport;
        await searchAirlines(searchBtn.dataset.searchAirport);
    }

    if (deleteBtn) {
        await api(`api.php?action=favorite-airport&icao=${encodeURIComponent(deleteBtn.dataset.deleteAirport)}`, {
            method: 'DELETE',
        });
        await loadFavorites();
    }
});

favoriteAirlines.addEventListener('click', async (event) => {
    const deleteBtn = event.target.closest('[data-delete-airline]');

    if (!deleteBtn) return;

    await api(`api.php?action=favorite-airline&id=${encodeURIComponent(deleteBtn.dataset.deleteAirline)}`, {
        method: 'DELETE',
    });
    await loadFavorites();
});

airlineList.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-save-airline]');

    if (!button) return;

    const airline = currentAirlines.find((item) => item.key === button.dataset.saveAirline);
    if (!airline) return;

    await api('api.php?action=favorite-airline', {
        method: 'POST',
        body: JSON.stringify(airline),
    });
    await loadFavorites();
    setStatus(`${airline.name} salvestati lemmikutesse.`);
});

input.addEventListener('input', () => {
    input.value = input.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 4);
});

async function searchAirlines(rawIcao) {
    const icao = rawIcao.trim().toUpperCase();

    if (!/^[A-Z0-9]{4}$/.test(icao)) {
        setStatus('Sisesta 4-kohaline ICAO kood, näiteks EETN.', true);
        return;
    }

    setLoading(true);
    setStatus('Pärin lennufirmasid...');

    try {
        const data = await api(`api.php?action=airlines&icao=${encodeURIComponent(icao)}`);
        currentAirport = {
            icao: data.airport.icao,
            name: data.airport.name || '',
        };
        currentAirlines = (data.airlines || []).map((airline, index) => ({
            ...airline,
            key: `${airline.name}|${airline.iata || ''}|${airline.icao || ''}|${index}`,
        }));
        renderResults(data);
        setStatus(`${currentAirlines.length} lennufirmat. Allikas: ${data.source === 'sqlite-cache' ? 'SQLite vahemälu' : 'AeroDataBox API'}.`);
    } catch (error) {
        currentAirport = null;
        currentAirlines = [];
        saveAirportBtn.classList.add('hidden');
        renderEmpty(airlineList, error.message);
        setStatus(error.message, true);
    } finally {
        setLoading(false);
    }
}

async function loadFavorites() {
    try {
        const data = await api('api.php?action=favorites');
        renderFavoriteAirports(data.airports || []);
        renderFavoriteAirlines(data.airlines || []);
    } catch (error) {
        setStatus(error.message, true);
    }
}

async function api(url, options = {}) {
    const response = await fetch(url, {
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
        },
        ...options,
    });
    const data = await response.json().catch(() => ({}));

    if (!response.ok || data.error) {
        throw new Error(data.error || 'Päring ebaõnnestus.');
    }

    return data;
}

function renderResults(data) {
    const airportName = data.airport.name ? ` - ${data.airport.name}` : '';
    airportTitle.textContent = `${data.airport.icao}${airportName}`;
    saveAirportBtn.classList.remove('hidden');

    if (!currentAirlines.length) {
        renderEmpty(airlineList, 'Selle ICAO koodi kohta lennufirmasid ei leitud.');
        return;
    }

    airlineList.classList.remove('empty-state');
    airlineList.innerHTML = currentAirlines.map((airline) => `
        <article class="airline-card">
            <div class="airline-main">
                <div class="airline-name">${escapeHtml(airline.name)}</div>
                <div class="meta">${renderTags(airline)}</div>
            </div>
            <button class="secondary" type="button" data-save-airline="${escapeAttr(airline.key)}">Salvesta</button>
        </article>
    `).join('');
}

function renderFavoriteAirports(items) {
    if (!items.length) {
        renderEmpty(favoriteAirports, 'Lemmikuid veel ei ole.');
        return;
    }

    favoriteAirports.classList.remove('empty-state');
    favoriteAirports.innerHTML = items.map((item) => `
        <div class="favorite-item">
            <div class="favorite-main">
                <div class="favorite-name">${escapeHtml(item.icao)}</div>
                ${item.name ? `<div class="meta">${escapeHtml(item.name)}</div>` : ''}
            </div>
            <div class="favorite-actions">
                <button class="secondary" type="button" data-search-airport="${escapeAttr(item.icao)}">Ava</button>
                <button class="ghost" type="button" data-delete-airport="${escapeAttr(item.icao)}">Kustuta</button>
            </div>
        </div>
    `).join('');
}

function renderFavoriteAirlines(items) {
    if (!items.length) {
        renderEmpty(favoriteAirlines, 'Lemmikuid veel ei ole.');
        return;
    }

    favoriteAirlines.classList.remove('empty-state');
    favoriteAirlines.innerHTML = items.map((item) => `
        <div class="favorite-item">
            <div class="favorite-main">
                <div class="favorite-name">${escapeHtml(item.name)}</div>
                <div class="meta">${renderTags(item)}</div>
            </div>
            <button class="ghost" type="button" data-delete-airline="${Number(item.id)}">Kustuta</button>
        </div>
    `).join('');
}

function renderTags(item) {
    const tags = [];
    if (item.iata) tags.push(`<span class="tag">IATA ${escapeHtml(item.iata)}</span>`);
    if (item.icao) tags.push(`<span class="tag">ICAO ${escapeHtml(item.icao)}</span>`);
    if (item.callsign) tags.push(`<span class="tag">${escapeHtml(item.callsign)}</span>`);

    return tags.length ? tags.join('') : '<span class="tag">Kood puudub</span>';
}

function renderEmpty(target, message) {
    target.classList.add('empty-state');
    target.innerHTML = escapeHtml(message);
}

function setLoading(isLoading) {
    form.querySelector('button').disabled = isLoading;
}

function setStatus(message, isError = false) {
    statusEl.textContent = message;
    statusEl.classList.toggle('error', isError);
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function escapeAttr(value) {
    return escapeHtml(value).replace(/`/g, '&#096;');
}

loadFavorites();
