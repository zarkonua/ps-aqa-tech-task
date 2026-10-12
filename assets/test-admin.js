// Control page of the test-data API. Runner only: it needs the test-admin token and shows
// what the runs contain. Nothing here is linked from, or loaded by, the application itself.
const TOKEN_KEY = "qa_task_test_admin_token";
const statusEl = document.getElementById("status");
const tokenSection = document.getElementById("token-section");
const runsSection = document.getElementById("runs-section");
const runsListEl = document.getElementById("runs-list");
const manifestPanel = document.getElementById("manifest-panel");

function setStatus(message, isError = false) {
    statusEl.textContent = message;
    statusEl.classList.remove("status--ok", "status--error");
    if (message) {
        statusEl.classList.add(isError ? "status--error" : "status--ok");
    }
}

async function call(path, options = {}) {
    const response = await fetch(`/test-admin${path}`, {
        ...options,
        headers: {
            Accept: "application/json",
            Authorization: `Bearer ${sessionStorage.getItem(TOKEN_KEY) || ""}`,
            ...(options.body ? {"Content-Type": "application/json"} : {}),
        },
    });
    if (response.status === 204) {
        return null;
    }
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
        const violations = Array.isArray(payload.violations) ? payload.violations.map((v) => `${v.propertyPath}: ${v.message}`).join("; ") : "";
        const error = new Error(violations || payload.error || `Request failed: ${response.status}`);
        error.status = response.status;
        throw error;
    }
    return payload;
}

function showSignIn(message) {
    sessionStorage.removeItem(TOKEN_KEY);
    runsSection.classList.add("hidden");
    tokenSection.classList.remove("hidden");
    setStatus(message || "", Boolean(message));
    document.getElementById("admin-token").focus();
}

function confirmAction(message) {
    // A plain confirmation is enough here: this page is a tool for the person running the tests.
    return window.confirm(message);
}

function runRow(run) {
    const row = document.createElement("div");
    row.className = "share-row run-row";
    row.innerHTML = `
        <span class="share-email"></span>
        <span class="kanban-actions"></span>
    `;
    const offset = run.clock_offset_seconds;
    row.querySelector(".share-email").textContent =
        `${run.run_id} — ${run.fixture}, seed ${run.seed}, fixture v${run.fixture_version}, ${run.resets} reset${run.resets === 1 ? "" : "s"}`
        + `${offset ? `, clock +${offset}s (${run.clock_now})` : ""}, hash ${run.manifest_hash.slice(0, 12)}…`;

    const actions = row.querySelector(".kanban-actions");
    const add = (label, handler, className = "secondary") => {
        const button = document.createElement("button");
        button.type = "button";
        button.className = className;
        button.textContent = label;
        button.setAttribute("aria-label", `${label} ${run.run_id}`);
        button.addEventListener("click", async () => {
            button.disabled = true;
            try {
                await handler();
            } catch (error) {
                if (error.status === 401) {
                    showSignIn("The token was not accepted.");
                    return;
                }
                setStatus(error.message, true);
            } finally {
                button.disabled = false;
            }
        });
        actions.appendChild(button);
    };

    add("Manifest", async () => {
        const data = await call(`/runs/${run.run_id}/manifest`);
        document.getElementById("manifest-title").textContent = `Manifest of ${run.run_id}`;
        document.getElementById("manifest-body").textContent = JSON.stringify(data.manifest, null, 2);
        manifestPanel.classList.remove("hidden");
        manifestPanel.scrollIntoView({block: "nearest"});
    });
    add("Reset", async () => {
        if (!confirmAction(`Reset run ${run.run_id}? Everything tests changed in it is discarded.`)) {
            return;
        }
        const data = await call(`/runs/${run.run_id}/reset`, {method: "POST"});
        setStatus(`Run ${run.run_id} reset${data.mail_deleted === null ? "" : `; ${data.mail_deleted} captured mail(s) deleted`}.`);
        await loadRuns();
    });
    add("+1 hour", async () => {
        await call(`/runs/${run.run_id}/advance-clock`, {method: "POST", body: JSON.stringify({seconds: 3600})});
        setStatus(`Clock of ${run.run_id} moved one hour forward.`);
        await loadRuns();
    });
    add("+1 day", async () => {
        await call(`/runs/${run.run_id}/advance-clock`, {method: "POST", body: JSON.stringify({seconds: 86400})});
        setStatus(`Clock of ${run.run_id} moved one day forward.`);
        await loadRuns();
    });
    add("Delete", async () => {
        if (!confirmAction(`Delete run ${run.run_id} and all its data? This cannot be undone.`)) {
            return;
        }
        await call(`/runs/${run.run_id}`, {method: "DELETE"});
        manifestPanel.classList.add("hidden");
        setStatus(`Run ${run.run_id} deleted.`);
        await loadRuns();
    }, "danger");

    return row;
}

async function loadRuns() {
    runsListEl.innerHTML = `<p class="share-empty">Loading…</p>`;
    try {
        const data = await call("/runs");
        document.getElementById("runs-count").textContent = `${data.items.length} of ${data.max}`;
        runsListEl.innerHTML = "";
        if (data.items.length === 0) {
            runsListEl.innerHTML = `<p class="share-empty">No runs yet.</p>`;
        }
        for (const run of data.items) {
            runsListEl.appendChild(runRow(run));
        }
    } catch (error) {
        if (error.status === 401 || error.status === 403) {
            showSignIn("The token was not accepted.");
            return;
        }
        runsListEl.innerHTML = `<p class="share-empty share-feedback--error">Could not load the runs.</p>`;
        const retry = document.createElement("button");
        retry.type = "button";
        retry.className = "secondary";
        retry.textContent = "Retry";
        retry.addEventListener("click", loadRuns);
        runsListEl.appendChild(retry);
    }
}

async function enter() {
    try {
        const data = await call("/fixtures");
        const select = document.getElementById("create-fixture");
        select.innerHTML = "";
        for (const fixture of data.fixtures) {
            const option = document.createElement("option");
            option.value = fixture.name;
            option.textContent = fixture.parts.length ? `${fixture.name} (${fixture.parts.join(", ")})` : fixture.name;
            select.appendChild(option);
        }
    } catch (error) {
        showSignIn(error.status === 401 || error.status === 403 ? "The token was not accepted." : error.message);
        return;
    }
    tokenSection.classList.add("hidden");
    runsSection.classList.remove("hidden");
    setStatus("");
    await loadRuns();
}

document.getElementById("token-form").addEventListener("submit", (event) => {
    event.preventDefault();
    const input = document.getElementById("admin-token");
    if (!input.value) {
        setStatus("Enter the test-admin token.", true);
        return;
    }
    sessionStorage.setItem(TOKEN_KEY, input.value);
    input.value = "";
    enter();
});

document.getElementById("forget-token").addEventListener("click", () => showSignIn(""));

document.getElementById("create-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const body = {
        fixture: document.getElementById("create-fixture").value,
        seed: Number.parseInt(document.getElementById("create-seed").value, 10),
    };
    const runId = document.getElementById("create-run-id").value.trim();
    if (runId) {
        body.run_id = runId;
    }
    const submit = event.target.querySelector('button[type="submit"]');
    submit.disabled = true;
    try {
        const run = await call("/runs", {method: "POST", body: JSON.stringify(body)});
        const note = document.getElementById("created-token");
        note.textContent = `Run ${run.run_id} created. Its run token, shown only now: ${run.run_token}`;
        note.classList.remove("hidden");
        document.getElementById("create-run-id").value = "";
        setStatus(`Run ${run.run_id} created.`);
        await loadRuns();
    } catch (error) {
        if (error.status === 401) {
            showSignIn("The token was not accepted.");
            return;
        }
        setStatus(error.message, true);
    } finally {
        submit.disabled = false;
    }
});

if (sessionStorage.getItem(TOKEN_KEY)) {
    enter();
} else {
    document.getElementById("admin-token").focus();
}
