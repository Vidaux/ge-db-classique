/* Monster Hunt Event tracker. Standalone browser-state tool; not generated from game data. */
(function() {
  "use strict";

  const COOKIE_NAME = "ge_classique_monster_hunt_v3";
  const LEGACY_COOKIE_NAMES = ["ge_classique_monster_hunt_v2", "ge_classique_monster_hunt_v1"];
  const FALLBACK_KEY = "ge_classique_monster_hunt_v3";
  const LEGACY_FALLBACK_KEYS = ["ge_classique_monster_hunt_v2"];
  const COOKIE_MAX_AGE = 60 * 60 * 24 * 60;
  const SERVER_UTC_OFFSET_MINUTES = 0;

  const zones = [
    "Lago Celeste",
    "Topolo Durga",
    "Rio Albi",
    "Old Port of Coimbra",
    "Desolate Cliff of Porto Bello",
    "Crater of Joaquin",
    "El Canon de Diabolica",
    "Ustiur Zona Dos",
    "Ferruccio Wall",
    "Deprimida Valley",
    "Tierra Putrefacta",
    "Zeia, Land of Day",
    "Zeia, Land of Night",
    "Al Quelt Moreza, Arcade",
    "Rion Dungeon Hollow",
    "Tetra Golden Road",
    "Porto Bello, The Hold",
    "M. Dr.Torsche, Grand Library",
    "Joaquin, Torture Chamber",
    "3F of Skeleton Dungeon"
  ];

  const tiers = [
    {
      id: "I",
      label: "[I] Angry",
      min: 10,
      max: 20,
      bosses: ["Angry Dilos Latemn", "Angry Treasure Golem", "Angry Chimera", "Angry Thoracotomy", "Angry Golden Spider"]
    },
    {
      id: "II",
      label: "[II] Wild",
      min: 30,
      max: 60,
      bosses: ["Wild Frogfish", "Wild King of Greed", "Wild Merman Eater", "Wild Elmorc", "Wild Gullfaxi"]
    },
    {
      id: "III",
      label: "[III] Evil",
      min: 60,
      max: 180,
      bosses: ["Evil Undertaker", "Evil Bribantra", "Evil Giant Keeper", "Evil Lava Leaf", "Evil General Guard"]
    },
    {
      id: "IV",
      label: "[IV] Chaos",
      min: 300,
      max: 600,
      bosses: ["Chaos Jormongand", "Chaos Argus", "Chaos Griffon", "Chaos Einschwer", "Chaos Medusa"]
    }
  ];

  const tiersById = {};

  tiers.forEach(function(tier) {
    tiersById[tier.id] = tier;
  });

  let state = { timers: {} };
  let cookieAvailable = true;
  let selectedTierId = tiers[0] ? tiers[0].id : "";

  function byId(id) {
    return document.getElementById(id);
  }

  function timerKey(zoneIndex, tierId) {
    return zoneIndex + "|" + tierId;
  }

  function normalize(value) {
    return String(value || "").trim().toLowerCase();
  }

  function findZoneIndex(value) {
    const requested = normalize(value);
    for (let i = 0; i < zones.length; i++) {
      if (normalize(zones[i]) === requested) return i;
    }
    return -1;
  }

  function findTier(value) {
    const requested = normalize(value);
    for (let i = 0; i < tiers.length; i++) {
      if (normalize(tiers[i].label) === requested || normalize(tiers[i].id) === requested) return tiers[i];
    }
    return null;
  }

  function encodeState(value) {
    return encodeURIComponent(JSON.stringify(value));
  }

  function decodeState(value) {
    try {
      return JSON.parse(decodeURIComponent(value));
    } catch (error) {
      return null;
    }
  }

  function readCookie(name) {
    const cookies = document.cookie ? document.cookie.split(";") : [];
    for (let i = 0; i < cookies.length; i++) {
      const part = cookies[i].trim();
      if (part.indexOf(name + "=") === 0) {
        return part.substring(name.length + 1);
      }
    }
    return "";
  }

  function writeCookie(name, value) {
    document.cookie = name + "=" + value + "; max-age=" + COOKIE_MAX_AGE + "; path=/; SameSite=Lax";
    return readCookie(name) === value;
  }

  function tierIdFromLegacyTimer(timer) {
    if (!timer || typeof timer !== "object") return "";
    if (timer.tierId && tiersById[timer.tierId]) return timer.tierId;
    if (timer.bossId) {
      for (let i = 0; i < tiers.length; i++) {
        if (tiers[i].bosses.some(function(name) { return normalize(name).replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "") === timer.bossId; })) {
          return tiers[i].id;
        }
      }
    }
    return "";
  }

  function mergeTimer(timers, timer) {
    const zoneIndex = Number(timer.zoneIndex);
    const tierId = tierIdFromLegacyTimer(timer);
    if (!Number.isFinite(zoneIndex) || zoneIndex < 0 || !zones[zoneIndex] || !tiersById[tierId]) return;
    const key = timerKey(zoneIndex, tierId);
    const existing = timers[key];
    const killedAt = Number(timer.killedAt || 0);
    if (!existing || killedAt > Number(existing.killedAt || 0)) {
      timers[key] = {
        zoneIndex: zoneIndex,
        tierId: tierId,
        killedAt: killedAt,
        note: timer.note || ""
      };
    }
  }

  function migrateLegacyEntries(legacyState) {
    const timers = {};
    if (!legacyState || typeof legacyState !== "object") return timers;
    if (legacyState.timers) {
      Object.keys(legacyState.timers).forEach(function(key) {
        mergeTimer(timers, legacyState.timers[key]);
      });
      return timers;
    }
    if (!legacyState.entries) return timers;
    Object.keys(legacyState.entries).forEach(function(key) {
      const parts = key.split("|");
      const zoneIndex = Number(parts[0]);
      const tierId = parts[1];
      const tier = tiers.find(function(item) { return item.id === tierId; });
      if (!tier || !Number.isFinite(zoneIndex)) return;
      const legacy = legacyState.entries[key] || {};
      timers[timerKey(zoneIndex, tier.id)] = {
        zoneIndex: zoneIndex,
        tierId: tier.id,
        killedAt: legacy.killedAt || 0,
        note: legacy.note || ""
      };
    });
    return timers;
  }

  function loadState() {
    const cookieValue = readCookie(COOKIE_NAME);
    const cookieState = cookieValue ? decodeState(cookieValue) : null;
    if (cookieState && typeof cookieState === "object") {
      state = { timers: migrateLegacyEntries(cookieState) };
      return;
    }

    for (let i = 0; i < LEGACY_COOKIE_NAMES.length; i++) {
      const legacyCookie = readCookie(LEGACY_COOKIE_NAMES[i]);
      const legacyState = legacyCookie ? decodeState(legacyCookie) : null;
      const migratedTimers = migrateLegacyEntries(legacyState);
      if (Object.keys(migratedTimers).length > 0) {
        state = { timers: migratedTimers };
        return;
      }
    }

    try {
      const fallback = window.localStorage ? window.localStorage.getItem(FALLBACK_KEY) : "";
      const fallbackState = fallback ? JSON.parse(fallback) : null;
      if (fallbackState && typeof fallbackState === "object") {
        state = { timers: migrateLegacyEntries(fallbackState) };
        return;
      }
      for (let i = 0; i < LEGACY_FALLBACK_KEYS.length; i++) {
        const legacyFallback = window.localStorage ? window.localStorage.getItem(LEGACY_FALLBACK_KEYS[i]) : "";
        const legacyFallbackState = legacyFallback ? JSON.parse(legacyFallback) : null;
        const migratedTimers = migrateLegacyEntries(legacyFallbackState);
        if (Object.keys(migratedTimers).length > 0) {
          state = { timers: migratedTimers };
          return;
        }
      }
    } catch (error) {
      state = { timers: {} };
    }
  }

  function saveState() {
    const compactTimers = {};
    Object.keys(state.timers).forEach(function(key) {
      const timer = state.timers[key] || {};
      if (timer.killedAt || timer.note) {
        compactTimers[key] = {
          zoneIndex: Number(timer.zoneIndex),
          tierId: timer.tierId,
          killedAt: Number(timer.killedAt || 0),
          note: timer.note || ""
        };
      }
    });
    state.timers = compactTimers;

    const encoded = encodeState(state);
    cookieAvailable = writeCookie(COOKIE_NAME, encoded);
    try {
      if (window.localStorage) {
        window.localStorage.setItem(FALLBACK_KEY, JSON.stringify(state));
      }
    } catch (error) {
      // Cookie storage is the primary path; localStorage is only a fallback for file URLs.
    }
    updateStorageStatus(encoded.length);
  }

  function formatDuration(ms) {
    if (ms <= 0) return "now";
    const totalSeconds = Math.ceil(ms / 1000);
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;
    if (hours > 0) return hours + "h " + minutes + "m";
    if (minutes > 0) return minutes + "m " + seconds + "s";
    return seconds + "s";
  }

  function formatClock(timestamp) {
    if (!timestamp) return "";
    const date = new Date(timestamp + SERVER_UTC_OFFSET_MINUTES * 60000);
    return pad(date.getUTCHours()) + ":" + pad(date.getUTCMinutes());
  }

  function pad(value) {
    return String(value).padStart(2, "0");
  }

  function toServerParts(timestamp) {
    if (!timestamp) return { date: "", time: "" };
    const date = new Date(timestamp + SERVER_UTC_OFFSET_MINUTES * 60000);
    return {
      date: [
        date.getUTCFullYear(),
        pad(date.getUTCMonth() + 1),
        pad(date.getUTCDate())
      ].join("-"),
      hour: pad(date.getUTCHours()),
      minute: pad(date.getUTCMinutes())
    };
  }

  function fromServerParts(dateValue, hourValue, minuteValue) {
    const dateMatch = /^(\d{4})-(\d{2})-(\d{2})$/.exec(dateValue || "");
    const hourText = String(hourValue || "").trim();
    const minuteText = String(minuteValue || "").trim();
    if (!dateMatch || !/^\d{1,2}$/.test(hourText) || !/^\d{1,2}$/.test(minuteText)) return 0;

    const hour = Number(hourText);
    const minute = Number(minuteText);
    if (hour < 0 || hour > 24 || minute < 0 || minute > 59 || (hour === 24 && minute !== 0)) return 0;

    let timestamp = Date.UTC(
      Number(dateMatch[1]),
      Number(dateMatch[2]) - 1,
      Number(dateMatch[3]),
      hour === 24 ? 0 : hour,
      minute
    );
    if (hour === 24) timestamp += 24 * 60 * 60000;
    return timestamp - SERVER_UTC_OFFSET_MINUTES * 60000;
  }

  function setServerEntryTime(timestamp) {
    const parts = toServerParts(timestamp);
    byId("huntServerDate").value = parts.date;
    byId("huntServerHour").value = parts.hour;
    byId("huntServerMinute").value = parts.minute;
  }

  function readServerEntryTime() {
    const timestamp = fromServerParts(
      byId("huntServerDate").value,
      byId("huntServerHour").value,
      byId("huntServerMinute").value
    );
    if (!timestamp) {
      window.alert("Enter server time as 24-hour HH and mm values.");
      byId("huntServerHour").focus();
    }
    return timestamp;
  }

  function readTrackedRowTime(row) {
    return fromServerParts(
      row.querySelector(".tracked-kill-date").value,
      row.querySelector(".tracked-kill-hour").value,
      row.querySelector(".tracked-kill-minute").value
    );
  }

  function bindTimePartInputs(hourInput, minuteInput) {
    const bindDigitsOnly = function(input, nextInput) {
      input.addEventListener("input", function() {
        input.value = input.value.replace(/\D/g, "").slice(0, 2);
        if (input.value.length === 2 && nextInput) {
          nextInput.focus();
          nextInput.select();
        }
      });
      input.addEventListener("blur", function() {
        if (input.value !== "") {
          input.value = pad(Number(input.value));
        }
      });
    };
    bindDigitsOnly(hourInput, minuteInput);
    bindDigitsOnly(minuteInput, null);
  }

  function statusFor(timer) {
    const tier = tiersById[timer.tierId];
    const killedAt = Number(timer.killedAt || 0);
    if (!tier || !killedAt) {
      return { className: "idle", text: "No kill logged", windowText: "" };
    }

    const now = Date.now();
    const earliest = killedAt + tier.min * 60000;
    const latest = killedAt + tier.max * 60000;
    const windowText = formatClock(earliest) + " - " + formatClock(latest);

    if (now < earliest) {
      return {
        className: "waiting",
        text: "Respawn in " + formatDuration(earliest - now),
        windowText: windowText
      };
    }
    if (now <= latest) {
      return {
        className: "open",
        text: "Window open",
        windowText: windowText
      };
    }
    return {
      className: "overdue",
      text: "Overdue by " + formatDuration(now - latest),
      windowText: windowText
    };
  }

  function renderZoneOptions() {
    const input = byId("huntZoneInput");
    if (!input) return;
    input.value = "";
  }

  function renderTierOptions() {
    const container = byId("huntTierButtons");
    if (!container) return;
    container.innerHTML = "";
    tiers.forEach(function(tier) {
      const button = document.createElement("button");
      button.type = "button";
      button.className = "tier-button" + (tier.id === selectedTierId ? " is-selected" : "");
      button.dataset.tierId = tier.id;
      button.setAttribute("role", "radio");
      button.setAttribute("aria-checked", tier.id === selectedTierId ? "true" : "false");
      button.textContent = tier.label;
      button.addEventListener("click", function() {
        selectedTierId = tier.id;
        renderTierOptions();
      });
      container.appendChild(button);
    });
  }

  function renderSuggestionButtons(containerId, input, values) {
    const container = byId(containerId);
    if (!container || !input) return;
    container.innerHTML = "";
    const query = normalize(input.value);
    const matches = values.filter(function(value) {
      const normalized = normalize(value);
      return !query || normalized.indexOf(query) !== -1;
    }).slice(0, 6);

    matches.forEach(function(value) {
      const button = document.createElement("button");
      button.type = "button";
      button.className = "suggestion-button";
      button.textContent = value;
      button.addEventListener("click", function() {
        input.value = value;
        renderZoneSuggestions();
      });
      container.appendChild(button);
    });
  }

  function renderZoneSuggestions() {
    renderSuggestionButtons("huntZoneSuggestions", byId("huntZoneInput"), zones);
  }

  function addOrUpdateTimer() {
    const zoneInput = byId("huntZoneInput");
    const zoneIndex = findZoneIndex(zoneInput.value);
    const tier = findTier(selectedTierId);
    if (zoneIndex < 0) {
      window.alert("Choose a zone from the suggestion list.");
      zoneInput.focus();
      return;
    }
    if (!tier) {
      window.alert("Choose a tier.");
      const firstTierButton = byId("huntTierButtons").querySelector("button");
      if (firstTierButton) firstTierButton.focus();
      return;
    }
    const killedAt = readServerEntryTime() || Date.now();
    const key = timerKey(zoneIndex, tier.id);
    const existing = state.timers[key] || {};

    state.timers[key] = {
      zoneIndex: zoneIndex,
      tierId: tier.id,
      killedAt: killedAt,
      note: existing.note || ""
    };

    zoneInput.value = zones[zoneIndex];
    selectedTierId = tier.id;
    renderTierOptions();
    setServerEntryTime(killedAt);
    saveState();
    renderTimers();
  }

  function getTimers() {
    return Object.keys(state.timers).map(function(key) {
      return state.timers[key];
    }).filter(function(timer) {
      return tiersById[timer.tierId] && zones[timer.zoneIndex];
    });
  }

  function spawnWindow(timer) {
    const tier = tiersById[timer.tierId];
    const killedAt = Number(timer.killedAt || 0);
    if (!tier || !killedAt) return { earliest: 0, latest: 0, text: "" };
    const earliest = killedAt + tier.min * 60000;
    const latest = killedAt + tier.max * 60000;
    return {
      earliest: earliest,
      latest: latest,
      text: formatClock(earliest) + " - " + formatClock(latest)
    };
  }

  function sortedUpcomingTimers() {
    return getTimers().sort(function(a, b) {
      const windowA = spawnWindow(a);
      const windowB = spawnWindow(b);
      if (windowA.earliest !== windowB.earliest) return windowA.earliest - windowB.earliest;
      return windowA.latest - windowB.latest;
    });
  }

  function sortedTrackedTimers() {
    return getTimers().sort(function(a, b) {
      return [zones[a.zoneIndex], a.tierId].join("|").localeCompare([zones[b.zoneIndex], b.tierId].join("|"));
    });
  }

  function renderTimers() {
    renderUpcomingSpawns();
    renderTrackedBosses();
  }

  function renderUpcomingSpawns() {
    const tbody = byId("upcomingRows");
    if (!tbody) return;
    tbody.innerHTML = "";

    const timers = sortedUpcomingTimers();
    const empty = byId("emptyUpcoming");
    if (empty) empty.hidden = timers.length !== 0;

    timers.forEach(function(timer) {
      const tier = tiersById[timer.tierId];
      const status = statusFor(timer);
      const window = spawnWindow(timer);
      const tr = document.createElement("tr");
      const key = timerKey(timer.zoneIndex, timer.tierId);
      tr.dataset.timerKey = key;

      tr.innerHTML =
        '<td class="tier-cell"></td>' +
        '<td class="zone-cell"></td>' +
        '<td class="window-cell"></td>' +
        '<td class="status-cell"><span class="tracker-status"></span><small></small></td>';

      tr.querySelector(".tier-cell").textContent = tier.label;
      tr.querySelector(".zone-cell").textContent = zones[timer.zoneIndex];
      tr.querySelector(".window-cell").textContent = window.text;
      const badge = tr.querySelector(".tracker-status");
      badge.className = "tracker-status " + status.className;
      badge.textContent = status.text;
      tr.querySelector(".status-cell small").textContent = tier.min + "-" + tier.max + " min";

      tbody.appendChild(tr);
    });
  }

  function renderTrackedBosses() {
    const tbody = byId("trackedRows");
    if (!tbody) return;
    tbody.innerHTML = "";

    const timers = sortedTrackedTimers();
    const empty = byId("emptyTracked");
    if (empty) empty.hidden = timers.length !== 0;

    timers.forEach(function(timer) {
      const tier = tiersById[timer.tierId];
      const tr = document.createElement("tr");
      const key = timerKey(timer.zoneIndex, timer.tierId);
      tr.dataset.timerKey = key;

      tr.innerHTML =
        '<td class="zone-cell"></td>' +
        '<td class="tier-cell"></td>' +
        '<td><input class="tracked-kill-date" type="date"></td>' +
        '<td><span class="time-entry table-time-entry"><input class="tracked-kill-hour" type="text" inputmode="numeric" maxlength="2" placeholder="19" aria-label="Kill hour"><span class="time-colon">:</span><input class="tracked-kill-minute" type="text" inputmode="numeric" maxlength="2" placeholder="09" aria-label="Kill minute"></span></td>' +
        '<td class="respawn-cell"></td>' +
        '<td class="tracker-actions"><button type="button" class="kill-now">Killed now</button><button type="button" class="clear-row">Clear</button></td>';

      tr.querySelector(".zone-cell").textContent = zones[timer.zoneIndex];
      tr.querySelector(".tier-cell").textContent = tier.label;
      tr.querySelector(".respawn-cell").textContent = tier.min + "-" + tier.max + " min";

      const parts = toServerParts(timer.killedAt || 0);
      const dateInput = tr.querySelector(".tracked-kill-date");
      const hourInput = tr.querySelector(".tracked-kill-hour");
      const minuteInput = tr.querySelector(".tracked-kill-minute");
      dateInput.value = parts.date;
      hourInput.value = parts.hour;
      minuteInput.value = parts.minute;
      bindTimePartInputs(hourInput, minuteInput);
      const updateTrackedTime = function() {
        const updatedAt = readTrackedRowTime(tr);
        if (!updatedAt) {
          window.alert("Enter server time as 24-hour HH and mm values.");
          hourInput.focus();
          return;
        }
        timer.killedAt = updatedAt;
        const updatedParts = toServerParts(timer.killedAt);
        dateInput.value = updatedParts.date;
        hourInput.value = updatedParts.hour;
        minuteInput.value = updatedParts.minute;
        saveState();
        renderUpcomingSpawns();
      };
      dateInput.addEventListener("change", updateTrackedTime);
      hourInput.addEventListener("change", updateTrackedTime);
      minuteInput.addEventListener("change", updateTrackedTime);

      tr.querySelector(".kill-now").addEventListener("click", function() {
        timer.killedAt = Date.now();
        setServerEntryTime(timer.killedAt);
        saveState();
        renderTimers();
      });

      tr.querySelector(".clear-row").addEventListener("click", function() {
        delete state.timers[key];
        saveState();
        renderTimers();
      });

      tbody.appendChild(tr);
    });
  }

  function updateStatuses() {
    renderUpcomingSpawns();
  }

  function clearAll() {
    if (!window.confirm("Clear all Monster Hunt tracker entries?")) return;
    state = { timers: {} };
    saveState();
    renderTimers();
  }

  function updateStorageStatus(encodedLength) {
    const status = byId("storageStatus");
    if (!status) return;
    const parts = [];
    parts.push(cookieAvailable ? "Cookie saved" : "Cookie unavailable, fallback saved");
    parts.push(Object.keys(state.timers).length + " tracked");
    parts.push(encodedLength + " bytes");
    if (encodedLength > 3500) {
      parts.push("trim notes soon");
    }
    status.textContent = parts.join(" - ");
  }

  function init() {
    loadState();
    renderZoneOptions();
    renderTierOptions();
    setServerEntryTime(Date.now());
    bindTimePartInputs(byId("huntServerHour"), byId("huntServerMinute"));
    renderZoneSuggestions();
    byId("huntZoneInput").addEventListener("input", renderZoneSuggestions);
    byId("huntServerNow").addEventListener("click", function() {
      setServerEntryTime(Date.now());
    });
    byId("addHuntTimer").addEventListener("click", addOrUpdateTimer);
    byId("clearAllTracker").addEventListener("click", clearAll);
    renderTimers();
    saveState();
    window.setInterval(updateStatuses, 1000);
  }

  document.addEventListener("DOMContentLoaded", init);
})();
